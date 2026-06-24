<?php

require_once __DIR__ . '/includes/admin_guard.php';

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
    chmod($classUploadDirectory, 0777);
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

if (isset($_GET['edit'])) {
    $editStatement = $pdo->prepare('SELECT * FROM classes WHERE id = :id LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit']]);
    $editingClass = $editStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $bannerStatement = $pdo->prepare('SELECT banner_image FROM classes WHERE id = :id LIMIT 1');
        $bannerStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $bannerToDelete = (string) ($bannerStatement->fetchColumn() ?: '');

        // Delete stays as a POST action so a simple page visit cannot remove data.
        $deleteStatement = $pdo->prepare('DELETE FROM classes WHERE id = :id');
        $deleteStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        deleteClassBanner($bannerToDelete);

        header('Location: classes.php?success=deleted');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $className = trim($_POST['class_name'] ?? '');
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $teacher = '';
    $status = $_POST['status'] ?? 'Active';
    $description = trim($_POST['description'] ?? '');
    $existingBanner = trim($_POST['existing_banner'] ?? '');
    $bannerImage = $existingBanner;

    if ($className === '') {
        $errors[] = 'Class name is required.';
    }

    if ($teacherId <= 0) {
        $errors[] = 'Choose a teacher.';
    } else {
        // Classes store teacher_id for relationships and teacher text for older views.
        $teacherStatement = $pdo->prepare('SELECT full_name FROM teachers WHERE id = :id AND status = :status LIMIT 1');
        $teacherStatement->execute([
            'id' => $teacherId,
            'status' => 'Active',
        ]);
        $teacher = (string) ($teacherStatement->fetchColumn() ?: '');

        if ($teacher === '') {
            $errors[] = 'Choose an active teacher from the masterlist.';
        }
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
                     teacher = :teacher,
                     teacher_id = :teacher_id,
                     banner_image = :banner_image,
                     status = :status,
                     description = :description
                 WHERE id = :id'
            );
            $statement->execute([
                'class_name' => $className,
                'teacher' => $teacher,
                'teacher_id' => $teacherId,
                'banner_image' => $bannerImage !== '' ? $bannerImage : null,
                'status' => $status,
                'description' => $description !== '' ? $description : null,
                'id' => $id,
            ]);

            header('Location: classes.php?success=updated');
            exit;
        }

        // Create a new class record from the Add Class form.
        $statement = $pdo->prepare(
            'INSERT INTO classes (class_name, teacher_id, teacher, banner_image, status, description)
             VALUES (:class_name, :teacher_id, :teacher, :banner_image, :status, :description)'
        );
        $statement->execute([
            'class_name' => $className,
            'teacher_id' => $teacherId,
            'teacher' => $teacher,
            'banner_image' => $bannerImage !== '' ? $bannerImage : null,
            'status' => $status,
            'description' => $description !== '' ? $description : null,
        ]);

        header('Location: classes.php?success=created');
        exit;
    }

    $editingClass = [
        'id' => $id,
        'class_name' => $className,
        'teacher_id' => $teacherId,
        'teacher' => $teacher,
        'banner_image' => $bannerImage,
        'status' => $status,
        'description' => $description,
    ];
}

if ($search !== '') {
    // Search keeps the class list focused without changing the add/edit modal state.
    $classesStatement = $pdo->prepare(
        "SELECT classes.*,
                COALESCE(teachers.full_name, classes.teacher) AS display_teacher
         FROM classes
         LEFT JOIN teachers ON teachers.id = classes.teacher_id
         WHERE classes.class_name LIKE :class_name_search
            OR classes.teacher LIKE :teacher_search
            OR teachers.full_name LIKE :teacher_master_search
            OR classes.status LIKE :status_search
            OR classes.description LIKE :description_search
         ORDER BY classes.created_at DESC, classes.id DESC"
    );
    $searchTerm = '%' . $search . '%';
    $classesStatement->execute([
        'class_name_search' => $searchTerm,
        'teacher_search' => $searchTerm,
        'teacher_master_search' => $searchTerm,
        'status_search' => $searchTerm,
        'description_search' => $searchTerm,
    ]);
} else {
    $classesStatement = $pdo->query(
        'SELECT classes.*,
                COALESCE(teachers.full_name, classes.teacher) AS display_teacher
         FROM classes
         LEFT JOIN teachers ON teachers.id = classes.teacher_id
         ORDER BY classes.created_at DESC, classes.id DESC'
    );
}
$classRows = $classesStatement->fetchAll();
$teachers = $pdo->query(
    "SELECT id, teacher_code, full_name, specialization
     FROM teachers
     WHERE status = 'Active'
     ORDER BY full_name"
)->fetchAll();

if ($editingClass && empty($editingClass['teacher_id'])) {
    // Older class rows may only have teacher text, so match them to the masterlist for edit forms.
    foreach ($teachers as $teacherOption) {
        if ((string) $teacherOption['full_name'] === (string) ($editingClass['teacher'] ?? '')) {
            $editingClass['teacher_id'] = (int) $teacherOption['id'];
            break;
        }
    }
}

$formClass = $editingClass ?: [
    'id' => 0,
    'class_name' => '',
    'teacher_id' => 0,
    'teacher' => '',
    'banner_image' => '',
    'status' => 'Active',
    'description' => '',
];
$successMessages = [
    'created' => 'Class added successfully.',
    'updated' => 'Class updated successfully.',
    'deleted' => 'Class deleted successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Classes</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <!-- Keep the system name visible in the module navigation. -->
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a class="active" href="classes.php"><i class="fa-solid fa-chalkboard-user"></i> Classes</a>
        <a href="teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers</a>
        <a href="learners.php"><i class="fa-solid fa-users"></i> Learners</a>
        <a href="grades.php"><i class="fa-solid fa-star"></i> Grades</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo e($currentUser['name']); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name for clear module context. -->
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
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
              <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search classes or teacher">
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
              <?php foreach ($classRows as $classRow): ?>
                <article class="class-card">
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
                      <p class="class-teacher"><i class="fa-solid fa-user-tie"></i><?php echo e($classRow['display_teacher'] ?? $classRow['teacher'] ?? ''); ?></p>
                      <?php if (!empty($classRow['description'])): ?>
                        <p class="class-description"><?php echo e($classRow['description']); ?></p>
                      <?php endif; ?>
                    </div>
                  </a>
                  <footer class="class-card-footer">
                    <a class="btn btn-sm btn-primary" href="class_workspace.php?class_id=<?php echo (int) $classRow['id']; ?>&tool=dashboard">
                      <i class="fa-solid fa-folder-open me-2"></i>Open Class
                    </a>
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
              <label class="form-label" for="teacher">Teacher</label>
              <select class="form-select" id="teacher" name="teacher_id" required>
                <option value="">Choose teacher</option>
                <?php foreach ($teachers as $teacherOption): ?>
                  <option value="<?php echo (int) $teacherOption['id']; ?>" <?php echo (int) ($formClass['teacher_id'] ?? 0) === (int) $teacherOption['id'] ? 'selected' : ''; ?>>
                    <?php echo e($teacherOption['full_name'] . ' - ' . $teacherOption['teacher_code']); ?>
                    <?php echo !empty($teacherOption['specialization']) ? e(' (' . $teacherOption['specialization'] . ')') : ''; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <?php if (!$teachers): ?>
                <div class="small text-danger mt-2">Add an active teacher in the Teachers masterlist first.</div>
              <?php endif; ?>
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
