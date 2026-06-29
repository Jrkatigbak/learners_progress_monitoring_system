<?php

require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/enrollment_helpers.php';

kiwiRequirePermission($pdo, 'classes.manage');

// Reusable escaping helper for table and form output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$search = trim($_GET['search'] ?? '');
$editingClass = null;
$classUploadDirectory = __DIR__ . '/uploads/classes';
$classUploadPathPrefix = 'uploads/classes/';

if (!is_dir($classUploadDirectory)) {
    // Class wallpapers are stored separately from learner photos and course banners.
    mkdir($classUploadDirectory, 0777, true);
}

if (is_dir($classUploadDirectory)) {
    // Hosted environments can reject chmod even when uploads work, so keep page output warning-free.
    @chmod($classUploadDirectory, 0777);
}

function deleteClassBanner(string $bannerPath): void
{
    if ($bannerPath === '' || strpos($bannerPath, 'uploads/classes/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $bannerPath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function normalizeClassSortOrder(PDO $pdo): void
{
    // Keep class ordering compact so up/down swaps stay predictable after deletes or old rows.
    $classRows = $pdo->query('SELECT id FROM classes WHERE deleted_at IS NULL ORDER BY sort_order ASC, created_at DESC, id DESC')->fetchAll();
    $orderStatement = $pdo->prepare('UPDATE classes SET sort_order = :sort_order WHERE id = :id');

    foreach ($classRows as $index => $classRow) {
        $orderStatement->execute([
            'sort_order' => $index + 1,
            'id' => (int) $classRow['id'],
        ]);
    }
}

function ensureClassEnrollmentTokens(PDO $pdo): void
{
    // Older classes created before public enrollment links need a token before their cards can share a URL.
    $missingTokenRows = $pdo->query('SELECT id FROM classes WHERE deleted_at IS NULL AND (enrollment_token IS NULL OR enrollment_token = "")')->fetchAll();
    $updateStatement = $pdo->prepare('UPDATE classes SET enrollment_token = :enrollment_token WHERE id = :id');

    foreach ($missingTokenRows as $classRow) {
        $updateStatement->execute([
            'enrollment_token' => kiwiGenerateUniqueToken($pdo, 'classes', 'enrollment_token'),
            'id' => (int) $classRow['id'],
        ]);
    }
}

if (isset($_GET['edit'])) {
    $editStatement = $pdo->prepare('SELECT * FROM classes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit']]);
    $editingClass = $editStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $bannerStatement = $pdo->prepare('SELECT banner_image FROM classes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $bannerStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        // Deleting a class now hides it while keeping the row for audit/history.
        $classIdToDelete = (int) ($_POST['id'] ?? 0);
        $deleteStatement = $pdo->prepare('UPDATE classes SET deleted_at = NOW(), status = "Inactive" WHERE id = :id AND deleted_at IS NULL');
        $deleteStatement->execute(['id' => $classIdToDelete]);
        $courseDeleteStatement = $pdo->prepare('UPDATE courses SET deleted_at = NOW(), status = "Inactive" WHERE course_code = :course_code AND deleted_at IS NULL');
        $courseDeleteStatement->execute(['course_code' => 'CLASS-' . $classIdToDelete]);
        normalizeClassSortOrder($pdo);

        header('Location: classes.php?success=deleted');
        exit;
    }

    if ($action === 'move_class') {
        normalizeClassSortOrder($pdo);

        $classId = (int) ($_POST['id'] ?? 0);
        $direction = $_POST['direction'] ?? '';
        $classStatement = $pdo->prepare('SELECT id, sort_order FROM classes WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $classStatement->execute(['id' => $classId]);
        $classToMove = $classStatement->fetch() ?: null;

        if ($classToMove && in_array($direction, ['up', 'down'], true)) {
            // Swap with the nearest class in the requested direction for a predictable manual order.
            $neighborSql = $direction === 'up'
                ? 'SELECT id, sort_order FROM classes WHERE deleted_at IS NULL AND sort_order < :sort_order ORDER BY sort_order DESC, id DESC LIMIT 1'
                : 'SELECT id, sort_order FROM classes WHERE deleted_at IS NULL AND sort_order > :sort_order ORDER BY sort_order ASC, id ASC LIMIT 1';
            $neighborStatement = $pdo->prepare($neighborSql);
            $neighborStatement->execute(['sort_order' => (int) $classToMove['sort_order']]);
            $neighbor = $neighborStatement->fetch() ?: null;

            if ($neighbor) {
                $swapStatement = $pdo->prepare(
                    'UPDATE classes
                     SET sort_order = CASE
                        WHEN id = :class_id THEN :neighbor_order
                        WHEN id = :neighbor_id THEN :class_order
                        ELSE sort_order
                     END
                     WHERE id IN (:class_id_filter, :neighbor_id_filter)'
                );
                $swapStatement->execute([
                    'class_id' => (int) $classToMove['id'],
                    'neighbor_order' => (int) $neighbor['sort_order'],
                    'neighbor_id' => (int) $neighbor['id'],
                    'class_order' => (int) $classToMove['sort_order'],
                    'class_id_filter' => (int) $classToMove['id'],
                    'neighbor_id_filter' => (int) $neighbor['id'],
                ]);
            }
        }

        header('Location: classes.php?success=reordered');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $className = trim($_POST['class_name'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $description = trim($_POST['description'] ?? '');
    $existingBanner = trim($_POST['existing_banner'] ?? '');
    $bannerImage = $existingBanner;

    if ($className === '') {
        $errors[] = 'Class name is required.';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $errors[] = 'Choose a valid status.';
    }

    if (isset($_FILES['banner_image']) && $_FILES['banner_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['banner_image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Class wallpaper could not be uploaded.';
        } elseif (!is_writable($classUploadDirectory)) {
            $errors[] = 'Class wallpaper upload folder is not writable.';
        } else {
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];
            $mimeType = mime_content_type($_FILES['banner_image']['tmp_name']);

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Class wallpaper must be JPG, PNG, or WEBP.';
            } elseif ($_FILES['banner_image']['size'] > 4 * 1024 * 1024) {
                $errors[] = 'Class wallpaper must be 4MB or smaller.';
            } else {
                // Store class wallpapers with generated names to avoid filename collisions.
                $filename = 'class-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $classUploadDirectory . '/' . $filename;

                if (move_uploaded_file($_FILES['banner_image']['tmp_name'], $targetPath)) {
                    $bannerImage = $classUploadPathPrefix . $filename;
                    deleteClassBanner($existingBanner);
                } else {
                    $errors[] = 'Class wallpaper could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        if ($id > 0) {
            // Update the selected class while preserving its original creation date.
            $statement = $pdo->prepare(
                'UPDATE classes
                 SET class_name = :class_name,
                     banner_image = :banner_image,
                     status = :status,
                     description = :description
                 WHERE id = :id'
            );
            $statement->execute([
                'class_name' => $className,
                'banner_image' => $bannerImage !== '' ? $bannerImage : null,
                'status' => $status,
                'description' => $description !== '' ? $description : null,
                'id' => $id,
            ]);

            header('Location: classes.php?success=updated');
            exit;
        }

        // Create a new class record from the Add Class form.
        $nextSortOrder = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM classes WHERE deleted_at IS NULL')->fetchColumn();
        $statement = $pdo->prepare(
            'INSERT INTO classes (class_name, teacher_id, teacher, enrollment_token, banner_image, status, description, sort_order)
             VALUES (:class_name, NULL, :teacher, :enrollment_token, :banner_image, :status, :description, :sort_order)'
        );
        $statement->execute([
            'class_name' => $className,
            'teacher' => '',
            'enrollment_token' => kiwiGenerateUniqueToken($pdo, 'classes', 'enrollment_token'),
            'banner_image' => $bannerImage !== '' ? $bannerImage : null,
            'status' => $status,
            'description' => $description !== '' ? $description : null,
            'sort_order' => $nextSortOrder,
        ]);

        header('Location: classes.php?success=created');
        exit;
    }

    $editingClass = [
        'id' => $id,
        'class_name' => $className,
        'banner_image' => $bannerImage,
        'status' => $status,
        'description' => $description,
    ];
}

normalizeClassSortOrder($pdo);
ensureClassEnrollmentTokens($pdo);

if ($search !== '') {
    // Search keeps the class list focused without changing the add/edit modal state.
    $classesStatement = $pdo->prepare(
        "SELECT classes.*,
                COALESCE(
                    (
                        SELECT GROUP_CONCAT(DISTINCT assigned_teachers.full_name ORDER BY assigned_teachers.full_name SEPARATOR ', ')
                        FROM class_teachers
                        INNER JOIN teachers AS assigned_teachers ON assigned_teachers.id = class_teachers.teacher_id
                        WHERE class_teachers.class_id = classes.id
                          AND class_teachers.deleted_at IS NULL
                          AND assigned_teachers.deleted_at IS NULL
                    ),
                    teachers.full_name,
                    classes.teacher
                ) AS display_teacher
                ,
                (
                    SELECT COUNT(DISTINCT assigned_teachers.id)
                    FROM class_teachers
                    INNER JOIN teachers AS assigned_teachers ON assigned_teachers.id = class_teachers.teacher_id
                    WHERE class_teachers.class_id = classes.id
                      AND class_teachers.deleted_at IS NULL
                      AND assigned_teachers.deleted_at IS NULL
                ) + CASE WHEN classes.teacher_id IS NOT NULL AND teachers.deleted_at IS NULL THEN 1 ELSE 0 END AS teacher_count,
                (
                    SELECT COUNT(DISTINCT learners.id)
                    FROM learners
                    LEFT JOIN course_enrollments
                      ON course_enrollments.learner_id = learners.id
                     AND course_enrollments.deleted_at IS NULL
                    LEFT JOIN courses
                      ON courses.id = course_enrollments.course_id
                     AND courses.deleted_at IS NULL
                    WHERE learners.deleted_at IS NULL
                      AND (
                        learners.class_id = classes.id
                        OR courses.course_code = CONCAT('CLASS-', classes.id)
                      )
                ) AS learner_count,
                (
                    SELECT COUNT(*)
                    FROM class_enrollment_requests
                    WHERE class_enrollment_requests.class_id = classes.id
                      AND class_enrollment_requests.status = 'Pending'
                      AND class_enrollment_requests.deleted_at IS NULL
                ) AS pending_enrollment_count
         FROM classes
         LEFT JOIN teachers ON teachers.id = classes.teacher_id
         WHERE classes.deleted_at IS NULL
           AND (
            classes.class_name LIKE :class_name_search
            OR classes.teacher LIKE :teacher_search
            OR teachers.full_name LIKE :teacher_master_search
            OR EXISTS (
                SELECT 1
                FROM class_teachers
                INNER JOIN teachers AS assigned_teachers ON assigned_teachers.id = class_teachers.teacher_id
                WHERE class_teachers.class_id = classes.id
                  AND assigned_teachers.full_name LIKE :assigned_teacher_search
            )
            OR classes.status LIKE :status_search
            OR classes.description LIKE :description_search
           )
         ORDER BY classes.sort_order ASC, classes.created_at DESC, classes.id DESC"
    );
    $searchTerm = '%' . $search . '%';
    $classesStatement->execute([
        'class_name_search' => $searchTerm,
        'teacher_search' => $searchTerm,
        'teacher_master_search' => $searchTerm,
        'assigned_teacher_search' => $searchTerm,
        'status_search' => $searchTerm,
        'description_search' => $searchTerm,
    ]);
} else {
    $classesStatement = $pdo->query(
        'SELECT classes.*,
                COALESCE(
                    (
                        SELECT GROUP_CONCAT(DISTINCT assigned_teachers.full_name ORDER BY assigned_teachers.full_name SEPARATOR ", ")
                        FROM class_teachers
                        INNER JOIN teachers AS assigned_teachers ON assigned_teachers.id = class_teachers.teacher_id
                        WHERE class_teachers.class_id = classes.id
                          AND class_teachers.deleted_at IS NULL
                          AND assigned_teachers.deleted_at IS NULL
                    ),
                    teachers.full_name,
                    classes.teacher
                ) AS display_teacher
                ,
                (
                    SELECT COUNT(DISTINCT assigned_teachers.id)
                    FROM class_teachers
                    INNER JOIN teachers AS assigned_teachers ON assigned_teachers.id = class_teachers.teacher_id
                    WHERE class_teachers.class_id = classes.id
                      AND class_teachers.deleted_at IS NULL
                      AND assigned_teachers.deleted_at IS NULL
                ) + CASE WHEN classes.teacher_id IS NOT NULL AND teachers.deleted_at IS NULL THEN 1 ELSE 0 END AS teacher_count,
                (
                    SELECT COUNT(DISTINCT learners.id)
                    FROM learners
                    LEFT JOIN course_enrollments
                      ON course_enrollments.learner_id = learners.id
                     AND course_enrollments.deleted_at IS NULL
                    LEFT JOIN courses
                      ON courses.id = course_enrollments.course_id
                     AND courses.deleted_at IS NULL
                    WHERE learners.deleted_at IS NULL
                      AND (
                        learners.class_id = classes.id
                        OR courses.course_code = CONCAT("CLASS-", classes.id)
                      )
                ) AS learner_count,
                (
                    SELECT COUNT(*)
                    FROM class_enrollment_requests
                    WHERE class_enrollment_requests.class_id = classes.id
                      AND class_enrollment_requests.status = "Pending"
                      AND class_enrollment_requests.deleted_at IS NULL
                ) AS pending_enrollment_count
         FROM classes
         LEFT JOIN teachers ON teachers.id = classes.teacher_id
         WHERE classes.deleted_at IS NULL
         ORDER BY classes.sort_order ASC, classes.created_at DESC, classes.id DESC'
    );
}
$classRows = $classesStatement->fetchAll();

$formClass = $editingClass ?: [
    'id' => 0,
    'class_name' => '',
    'banner_image' => '',
    'status' => 'Active',
    'description' => '',
];
$successMessages = [
    'created' => 'Class added successfully.',
    'updated' => 'Class updated successfully.',
    'deleted' => 'Class deleted successfully.',
    'reordered' => 'Class order updated successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | Classes</title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=class-reorder-controls" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'classes'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name for clear module context. -->
          <p class="eyebrow mb-1"><?php echo e(kiwiSystemName()); ?></p>
          <h1 class="h3 mb-0">Classes</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e(strtoupper(substr($currentUser['name'], 0, 1))); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($currentUser['name']); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e($currentUser['email']); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <?php if (isset($successMessages[$success])): ?>
          <div class="alert alert-success" role="alert"><?php echo e($successMessages[$success]); ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="panel-card">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
              <span class="section-kicker">Records</span>
              <h2 class="h5 mb-0">Class list</h2>
            </div>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#classModal">
              <i class="fa-solid fa-plus me-2"></i>Add Class
            </button>
          </div>

          <form method="get" class="search-bar mb-4">
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search classes or assigned teacher">
              <button type="submit" class="btn btn-primary">Search</button>
              <?php if ($search !== ''): ?>
                <a href="classes.php" class="btn btn-outline-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$classRows): ?>
            <div class="empty-state">
              <i class="fa-solid fa-chalkboard-user"></i>
              <p>No classes found.</p>
            </div>
          <?php else: ?>
            <div class="class-card-grid">
              <?php foreach ($classRows as $classIndex => $classRow): ?>
                <article class="class-card">
                  <?php $enrollmentLink = kiwiEnrollmentUrl((string) $classRow['enrollment_token']); ?>
                  <a class="class-card-open-link" href="class_workspace.php?class_id=<?php echo (int) $classRow['id']; ?>&tool=dashboard" aria-label="Open <?php echo e($classRow['class_name']); ?>">
                    <div class="class-wallpaper">
                      <?php if (!empty($classRow['banner_image'])): ?>
                        <img src="<?php echo e($classRow['banner_image']); ?>" alt="<?php echo e($classRow['class_name']); ?> wallpaper">
                      <?php else: ?>
                        <div class="class-wallpaper-placeholder">
                          <i class="fa-solid fa-chalkboard-user"></i>
                        </div>
                      <?php endif; ?>
                      <span class="class-status-badge <?php echo $classRow['status'] === 'Active' ? 'is-active' : 'is-inactive'; ?>">
                        <?php echo e($classRow['status']); ?>
                      </span>
                    </div>
                    <div class="class-card-body">
                      <h3><?php echo e($classRow['class_name']); ?></h3>
                      <div class="class-stat-badges">
                        <span><i class="fa-solid fa-user-tie"></i><?php echo (int) ($classRow['teacher_count'] ?? 0); ?> teacher<?php echo (int) ($classRow['teacher_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                        <span><i class="fa-solid fa-users"></i><?php echo (int) ($classRow['learner_count'] ?? 0); ?> learner<?php echo (int) ($classRow['learner_count'] ?? 0) === 1 ? '' : 's'; ?></span>
                      </div>
                      <?php if (!empty($classRow['description'])): ?>
                        <p class="class-description"><?php echo e($classRow['description']); ?></p>
                      <?php endif; ?>
                      <?php if ((int) ($classRow['pending_enrollment_count'] ?? 0) > 0): ?>
                        <p class="class-description text-primary fw-bold mt-2">
                          <i class="fa-solid fa-user-clock me-1"></i><?php echo (int) $classRow['pending_enrollment_count']; ?> pending registration<?php echo (int) $classRow['pending_enrollment_count'] === 1 ? '' : 's'; ?>
                        </p>
                      <?php endif; ?>
                    </div>
                  </a>
                  <footer class="class-card-footer">
                    <?php if ($search === ''): ?>
                      <div class="class-order-actions" aria-label="Reorder <?php echo e($classRow['class_name']); ?>">
                        <form method="post">
                          <input type="hidden" name="action" value="move_class">
                          <input type="hidden" name="id" value="<?php echo (int) $classRow['id']; ?>">
                          <input type="hidden" name="direction" value="up">
                          <button type="submit" class="learner-icon-button" aria-label="Move class up" <?php echo $classIndex === 0 ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-arrow-up"></i>
                          </button>
                        </form>
                        <form method="post">
                          <input type="hidden" name="action" value="move_class">
                          <input type="hidden" name="id" value="<?php echo (int) $classRow['id']; ?>">
                          <input type="hidden" name="direction" value="down">
                          <button type="submit" class="learner-icon-button" aria-label="Move class down" <?php echo $classIndex === count($classRows) - 1 ? 'disabled' : ''; ?>>
                            <i class="fa-solid fa-arrow-down"></i>
                          </button>
                        </form>
                      </div>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-primary" href="class_workspace.php?class_id=<?php echo (int) $classRow['id']; ?>&tool=dashboard">
                      <i class="fa-solid fa-folder-open me-2"></i>Open Class
                    </a>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e($enrollmentLink); ?>" target="_blank" rel="noopener">
                      <i class="fa-solid fa-link me-2"></i>Enroll Link
                    </a>
                    <button type="button" class="learner-icon-button copy-enrollment-link" data-link="<?php echo e($enrollmentLink); ?>" aria-label="Copy enrollment link" title="Copy enrollment link">
                      <i class="fa-solid fa-copy"></i>
                    </button>
                    <a class="learner-icon-button" href="classes.php?edit=<?php echo (int) $classRow['id']; ?>" aria-label="Edit class">
                      <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" onsubmit="return confirm('Delete this class?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int) $classRow['id']; ?>">
                      <button type="submit" class="learner-icon-button is-danger" aria-label="Delete class">
                        <i class="fa-solid fa-trash"></i>
                      </button>
                    </form>
                  </footer>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <div class="modal fade" id="classModal" tabindex="-1" aria-labelledby="classModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker"><?php echo ((int) $formClass['id'] > 0) ? 'Edit Class' : 'Add Class'; ?></span>
            <h2 class="modal-title h5" id="classModalLabel"><?php echo ((int) $formClass['id'] > 0) ? 'Update class details' : 'Create class record'; ?></h2>
          </div>
          <a href="classes.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="btn-close" aria-label="Close"></a>
        </div>
        <form method="post" enctype="multipart/form-data" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo (int) $formClass['id']; ?>">
            <input type="hidden" name="existing_banner" value="<?php echo e($formClass['banner_image'] ?? ''); ?>">
            <div class="mb-3">
              <label class="form-label" for="banner_image">Class wallpaper</label>
              <input type="file" class="form-control" id="banner_image" name="banner_image" accept="image/png,image/jpeg,image/webp">
              <div class="small text-secondary mt-2">Best size: 1600 x 600 px. JPG, PNG, or WEBP up to 4MB.</div>
              <?php if (!empty($formClass['banner_image'])): ?>
                <div class="small text-secondary mt-1">Current wallpaper will stay unless a new one is uploaded.</div>
              <?php endif; ?>
            </div>
            <div class="mb-3">
              <label class="form-label" for="class_name">Class name</label>
              <input type="text" class="form-control" id="class_name" name="class_name" value="<?php echo e($formClass['class_name']); ?>" required>
            </div>
            <div class="mb-3">
              <label class="form-label" for="status">Status</label>
              <select class="form-select" id="status" name="status">
                <option value="Active" <?php echo $formClass['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                <option value="Inactive" <?php echo $formClass['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
              </select>
            </div>
            <div class="mb-3">
              <label class="form-label" for="description">Description</label>
              <textarea class="form-control" id="description" name="description" rows="4"><?php echo e($formClass['description'] ?? ''); ?></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline-secondary" href="classes.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i><?php echo ((int) $formClass['id'] > 0) ? 'Update Class' : 'Add Class'; ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php if ($errors || (int) $formClass['id'] > 0): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('classModal')).show();
      });
    </script>
  <?php endif; ?>
  <script src="js/app.js"></script>
</body>
</html>
