<?php

require_once __DIR__ . '/includes/admin_guard.php';

// Reusable escaping helper for this module's form and table output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$search = trim($_GET['search'] ?? '');
$showAssignModal = false;

function syncClassEnrollmentCourse(PDO $pdo, int $classId): ?array
{
    // Enrollment records still use the existing course table, so each class gets a hidden backing row.
    $classStatement = $pdo->prepare(
        'SELECT id, class_name, status, description
         FROM classes
         WHERE id = :id
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $classStatement->execute(['id' => $classId]);
    $class = $classStatement->fetch();

    if (!$class) {
        return null;
    }

    $courseCode = 'CLASS-' . (int) $class['id'];
    $courseStatement = $pdo->prepare(
        'INSERT INTO courses (course_code, course_name, description, status)
         VALUES (:course_code, :course_name, :description, :status)
         ON DUPLICATE KEY UPDATE
            course_name = VALUES(course_name),
            description = VALUES(description),
            status = VALUES(status),
            deleted_at = NULL'
    );
    $courseStatement->execute([
        'course_code' => $courseCode,
        'course_name' => $class['class_name'],
        'description' => $class['description'] ?? null,
        'status' => $class['status'],
    ]);

    $lookupStatement = $pdo->prepare('SELECT id FROM courses WHERE course_code = :course_code AND deleted_at IS NULL LIMIT 1');
    $lookupStatement->execute(['course_code' => $courseCode]);
    $courseId = (int) $lookupStatement->fetchColumn();

    return [
        'class' => $class,
        'course_id' => $courseId,
        'course_code' => $courseCode,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectClass = (int) ($_POST['class_id'] ?? 0);

    if ($action === 'enroll') {
        $showAssignModal = true;
        $learnerId = (int) ($_POST['learner_id'] ?? 0);
        $classId = $redirectClass;
        $enrollmentStatus = $_POST['enrollment_status'] ?? 'Enrolled';
        $classCourse = null;

        if ($learnerId <= 0) {
            $errors[] = 'Choose a learner.';
        }

        if ($classId <= 0) {
            $errors[] = 'Choose a class.';
        } else {
            $classCourse = syncClassEnrollmentCourse($pdo, $classId);

            if (!$classCourse) {
                $errors[] = 'Choose a valid class.';
            }
        }

        if (!in_array($enrollmentStatus, ['Pending', 'Enrolled', 'In Progress', 'Completed', 'Dropped', 'Disapproved'], true)) {
            $errors[] = 'Choose a valid enrollment status.';
        }

        if (!$errors) {
            try {
                // Enroll the learner once per class and keep status editable through re-submit.
                $enrollmentStatement = $pdo->prepare(
                    'INSERT INTO course_enrollments (learner_id, course_id, enrollment_status)
                     VALUES (:learner_id, :course_id, :enrollment_status)
                     ON DUPLICATE KEY UPDATE
                        enrollment_status = VALUES(enrollment_status),
                        deleted_at = NULL'
                );
                $enrollmentStatement->execute([
                    'learner_id' => $learnerId,
                    'course_id' => (int) $classCourse['course_id'],
                    'enrollment_status' => $enrollmentStatus,
                ]);

                header('Location: enrollments.php?class_id=' . $classId . '&success=enrolled');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Enrollment could not be saved.';
            }
        }
    }

    if ($action === 'set_status') {
        $enrollmentId = (int) ($_POST['id'] ?? 0);
        $newStatus = $_POST['enrollment_status'] ?? '';

        if ($enrollmentId <= 0 || !in_array($newStatus, ['Pending', 'Enrolled', 'In Progress', 'Completed', 'Dropped', 'Disapproved'], true)) {
            $errors[] = 'Choose a valid enrollment action.';
        }

        if (!$errors) {
            // Admin approval changes the learner request without creating a duplicate row.
            $statusStatement = $pdo->prepare(
                'UPDATE course_enrollments
                 SET enrollment_status = :enrollment_status
                 WHERE id = :id AND deleted_at IS NULL'
            );
            $statusStatement->execute([
                'enrollment_status' => $newStatus,
                'id' => $enrollmentId,
            ]);

            header('Location: enrollments.php?success=status_updated' . ($redirectClass > 0 ? '&class_id=' . $redirectClass : ''));
            exit;
        }
    }

    if ($action === 'delete_enrollment') {
        // Soft delete enrollment through POST so a normal page visit cannot remove records.
        $deleteStatement = $pdo->prepare('UPDATE course_enrollments SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
        $deleteStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        header('Location: enrollments.php?success=deleted' . ($redirectClass > 0 ? '&class_id=' . $redirectClass : ''));
        exit;
    }
}

$learners = $pdo->query(
    "SELECT id, learner_number, first_name, last_name
     FROM learners
     WHERE deleted_at IS NULL
     ORDER BY first_name, last_name"
)->fetchAll();
$classIdFilter = (int) ($_GET['class_id'] ?? ($_POST['class_id'] ?? 0));
$courseIdFilter = 0;
$selectedClass = null;

if ($classIdFilter > 0) {
    $selectedClassCourse = syncClassEnrollmentCourse($pdo, $classIdFilter);

    if ($selectedClassCourse) {
        $selectedClass = $selectedClassCourse['class'];
        $courseIdFilter = (int) $selectedClassCourse['course_id'];
    }
}

$classes = $pdo->query(
    "SELECT classes.id,
            classes.class_name,
            classes.status,
            courses.id AS course_id,
            COALESCE(courses.course_code, CONCAT('CLASS-', classes.id)) AS course_code,
            COUNT(course_enrollments.id) AS request_count,
            SUM(CASE WHEN course_enrollments.enrollment_status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
            SUM(CASE WHEN course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed') THEN 1 ELSE 0 END) AS approved_count
     FROM classes
     LEFT JOIN courses ON courses.course_code = CONCAT('CLASS-', classes.id) AND courses.deleted_at IS NULL
     LEFT JOIN course_enrollments ON course_enrollments.course_id = courses.id AND course_enrollments.deleted_at IS NULL
     WHERE classes.deleted_at IS NULL
     GROUP BY classes.id, classes.class_name, classes.status, courses.id, courses.course_code
     ORDER BY classes.class_name"
)->fetchAll();

if (!$selectedClass && $classIdFilter > 0) {
    foreach ($classes as $class) {
        if ((int) $class['id'] === $classIdFilter) {
            $selectedClass = $class;
            break;
        }
    }
}

$enrollmentSql = "SELECT course_enrollments.id,
            course_enrollments.enrollment_status,
            course_enrollments.enrolled_at,
            course_enrollments.course_id,
            learners.learner_number,
            learners.first_name,
            learners.last_name,
            learners.email,
            courses.course_code,
            courses.course_name
     FROM course_enrollments
     INNER JOIN learners ON learners.id = course_enrollments.learner_id AND learners.deleted_at IS NULL
     INNER JOIN courses ON courses.id = course_enrollments.course_id AND courses.deleted_at IS NULL
     LEFT JOIN classes ON courses.course_code = CONCAT('CLASS-', classes.id) AND classes.deleted_at IS NULL";

if ($courseIdFilter > 0 && $selectedClass) {
    $enrollmentWhere = ' WHERE courses.id = :course_id AND course_enrollments.deleted_at IS NULL';
    $enrollmentParams = ['course_id' => $courseIdFilter];

    if ($search !== '') {
        // Search only within visible learner and enrollment fields.
        $enrollmentWhere .= ' AND (
            learners.learner_number LIKE :search_number
            OR learners.first_name LIKE :search_first_name
            OR learners.last_name LIKE :search_last_name
            OR course_enrollments.enrollment_status LIKE :search_status
        )';
        $searchTerm = '%' . $search . '%';
        $enrollmentParams['search_number'] = $searchTerm;
        $enrollmentParams['search_first_name'] = $searchTerm;
        $enrollmentParams['search_last_name'] = $searchTerm;
        $enrollmentParams['search_status'] = $searchTerm;
    }

    $enrollmentsStatement = $pdo->prepare($enrollmentSql . $enrollmentWhere . ' ORDER BY course_enrollments.enrolled_at DESC, course_enrollments.id DESC');
    $enrollmentsStatement->execute($enrollmentParams);
    $enrollments = $enrollmentsStatement->fetchAll();
} else {
    $enrollments = [];
}
$successMessages = [
    'enrolled' => 'Learner enrolled to class successfully.',
    'status_updated' => 'Enrollment status updated successfully.',
    'deleted' => 'Enrollment removed successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Enrollments</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-roles-permissions" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'enrollments'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name for clear module context. -->
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Enroll Learners</h1>
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

        <div class="row g-4 mb-4">
          <div class="col-xl-12">
            <div class="panel-card h-100">
              <span class="section-kicker">Class First</span>
              <h2 class="h5 mb-4">Select class</h2>
              <form method="get" class="module-form">
                <div class="row g-3 align-items-end">
                  <div class="col-lg-9">
                    <label class="form-label" for="class_id_filter">Class</label>
                    <select class="form-select" id="class_id_filter" name="class_id" required>
                      <option value="">Choose class to manage</option>
                      <?php foreach ($classes as $class): ?>
                        <option value="<?php echo (int) $class['id']; ?>" <?php echo (int) $class['id'] === $classIdFilter ? 'selected' : ''; ?>>
                          <?php echo e($class['class_name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-lg-3 d-grid">
                    <button type="submit" class="btn btn-primary">
                      <i class="fa-solid fa-folder-open me-2"></i>Open Class
                    </button>
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>

        <?php if ($selectedClass): ?>
        <div class="panel-card">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
              <span class="section-kicker">Enrollment Records</span>
              <h2 class="h5 mb-0">Learners in <?php echo e($selectedClass['class_name']); ?></h2>
            </div>
            <div class="d-flex flex-wrap align-items-center gap-2">
              <span class="badge text-bg-primary"><?php echo count($enrollments); ?> total</span>
              <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignLearnerModal">
                <i class="fa-solid fa-plus me-2"></i>Add Students
              </button>
            </div>
          </div>

          <form method="get" class="search-bar mb-4">
            <input type="hidden" name="class_id" value="<?php echo (int) $selectedClass['id']; ?>">
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search learner, number, or status">
              <button type="submit" class="btn btn-primary">Search</button>
              <?php if ($search !== ''): ?>
                <a href="enrollments.php?class_id=<?php echo (int) $selectedClass['id']; ?>" class="btn btn-outline-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$enrollments): ?>
            <div class="empty-state">
              <i class="fa-solid fa-user-graduate"></i>
              <p>No learner enrollments yet.</p>
            </div>
          <?php else: ?>
          <div class="learner-grid enrollment-card-grid">
            <?php foreach ($enrollments as $enrollment): ?>
              <?php
                $status = $enrollment['enrollment_status'];
                $fullName = trim($enrollment['first_name'] . ' ' . $enrollment['last_name']);
                $initials = strtoupper(substr($enrollment['first_name'], 0, 1) . substr($enrollment['last_name'], 0, 1));
                $statusClass = 'is-active';

                if (in_array($status, ['Pending', 'In Progress'], true)) {
                    $statusClass = 'is-hold';
                } elseif (in_array($status, ['Disapproved', 'Dropped'], true)) {
                    $statusClass = 'is-danger';
                } elseif ($status === 'Completed') {
                    $statusClass = 'is-completed';
                }
              ?>
              <article class="learner-card enrollment-card">
                <div class="learner-card-body">
                  <span class="learner-status-pill <?php echo $statusClass; ?>"><?php echo e($status); ?></span>
                  <span class="enrollment-date"><?php echo e(date('M d, Y', strtotime($enrollment['enrolled_at']))); ?></span>
                  <div class="learner-card-media">
                    <div class="learner-photo-placeholder"><?php echo e($initials); ?></div>
                  </div>
                  <h3><?php echo e($fullName); ?></h3>
                  <p class="learner-number"><?php echo e($enrollment['learner_number']); ?></p>
                </div>
                <footer class="learner-card-footer enrollment-card-footer">
                  <div class="enrollment-card-actions">
                    <?php if ($status !== 'Enrolled'): ?>
                      <form method="post">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?php echo (int) $enrollment['id']; ?>">
                        <input type="hidden" name="class_id" value="<?php echo $classIdFilter; ?>">
                        <input type="hidden" name="enrollment_status" value="Enrolled">
                        <button type="submit" class="btn btn-sm btn-success">Approve</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa-solid fa-chalkboard-user"></i>
            <p>Select a class first to assign learners and view its learner list.</p>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <?php if ($selectedClass): ?>
    <div class="modal fade" id="assignLearnerModal" tabindex="-1" aria-labelledby="assignLearnerModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <span class="section-kicker">Add Students</span>
              <h2 class="modal-title h5" id="assignLearnerModalLabel">Assign learner to <?php echo e($selectedClass['class_name']); ?></h2>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <form method="post" class="module-form">
            <div class="modal-body">
              <input type="hidden" name="action" value="enroll">
              <input type="hidden" name="class_id" value="<?php echo (int) $selectedClass['id']; ?>">
              <div class="mb-3">
                <label class="form-label" for="learner_id">Learner</label>
                <select class="form-select" id="learner_id" name="learner_id" required>
                  <option value="">Choose learner</option>
                  <?php foreach ($learners as $learner): ?>
                    <option value="<?php echo (int) $learner['id']; ?>">
                      <?php echo e($learner['first_name'] . ' ' . $learner['last_name'] . ' - ' . $learner['learner_number']); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label" for="enrollment_status">Status</label>
                <select class="form-select" id="enrollment_status" name="enrollment_status">
                  <option value="Pending">Pending</option>
                  <option value="Enrolled">Enrolled</option>
                  <option value="In Progress">In Progress</option>
                  <option value="Completed">Completed</option>
                  <option value="Dropped">Dropped</option>
                  <option value="Disapproved">Disapproved</option>
                </select>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-floppy-disk me-2"></i>Save
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($showAssignModal && $errors && $selectedClass): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('assignLearnerModal')).show();
      });
    </script>
  <?php endif; ?>
  <script src="js/app.js?v=20260629-roles-permissions"></script>
</body>
</html>
