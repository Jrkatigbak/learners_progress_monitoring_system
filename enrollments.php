<?php

require_once __DIR__ . '/includes/auth_guard.php';

// Reusable escaping helper for this module's form and table output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_course') {
        $courseCode = trim($_POST['course_code'] ?? '');
        $courseName = trim($_POST['course_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'Active';

        if ($courseCode === '') {
            $errors[] = 'Course code is required.';
        }

        if ($courseName === '') {
            $errors[] = 'Course name is required.';
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $errors[] = 'Choose a valid course status.';
        }

        if (!$errors) {
            try {
                // Create a course so it becomes available in the enrollment form.
                $courseStatement = $pdo->prepare(
                    'INSERT INTO courses (course_code, course_name, description, status)
                     VALUES (:course_code, :course_name, :description, :status)'
                );
                $courseStatement->execute([
                    'course_code' => $courseCode,
                    'course_name' => $courseName,
                    'description' => $description !== '' ? $description : null,
                    'status' => $status,
                ]);

                header('Location: enrollments.php?success=course_created');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Course code already exists.';
            }
        }
    }

    if ($action === 'enroll') {
        $learnerId = (int) ($_POST['learner_id'] ?? 0);
        $courseId = (int) ($_POST['course_id'] ?? 0);
        $enrollmentStatus = $_POST['enrollment_status'] ?? 'Enrolled';

        if ($learnerId <= 0) {
            $errors[] = 'Choose a learner.';
        }

        if ($courseId <= 0) {
            $errors[] = 'Choose a course.';
        }

        if (!in_array($enrollmentStatus, ['Enrolled', 'In Progress', 'Completed', 'Dropped'], true)) {
            $errors[] = 'Choose a valid enrollment status.';
        }

        if (!$errors) {
            try {
                // Enroll the learner once per course and keep status editable through re-submit.
                $enrollmentStatement = $pdo->prepare(
                    'INSERT INTO course_enrollments (learner_id, course_id, enrollment_status)
                     VALUES (:learner_id, :course_id, :enrollment_status)
                     ON DUPLICATE KEY UPDATE
                        enrollment_status = VALUES(enrollment_status)'
                );
                $enrollmentStatement->execute([
                    'learner_id' => $learnerId,
                    'course_id' => $courseId,
                    'enrollment_status' => $enrollmentStatus,
                ]);

                header('Location: enrollments.php?success=enrolled');
                exit;
            } catch (PDOException $exception) {
                $errors[] = 'Enrollment could not be saved.';
            }
        }
    }

    if ($action === 'delete_enrollment') {
        // Delete enrollment through POST so a normal page visit cannot remove records.
        $deleteStatement = $pdo->prepare('DELETE FROM course_enrollments WHERE id = :id');
        $deleteStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        header('Location: enrollments.php?success=deleted');
        exit;
    }
}

$learners = $pdo->query(
    "SELECT id, learner_number, first_name, last_name
     FROM learners
     ORDER BY first_name, last_name"
)->fetchAll();
$courses = $pdo->query(
    "SELECT id, course_code, course_name, status
     FROM courses
     ORDER BY course_name"
)->fetchAll();
$enrollments = $pdo->query(
    "SELECT course_enrollments.id,
            course_enrollments.enrollment_status,
            course_enrollments.enrolled_at,
            learners.learner_number,
            learners.first_name,
            learners.last_name,
            courses.course_code,
            courses.course_name
     FROM course_enrollments
     INNER JOIN learners ON learners.id = course_enrollments.learner_id
     INNER JOIN courses ON courses.id = course_enrollments.course_id
     ORDER BY course_enrollments.enrolled_at DESC, course_enrollments.id DESC"
)->fetchAll();
$successMessages = [
    'course_created' => 'Course added successfully.',
    'enrolled' => 'Learner enrolled successfully.',
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
  <link href="css/style.css" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <!-- Keep the system name visible in the enrollment navigation. -->
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
        <a href="classes.php"><i class="fa-solid fa-chalkboard-user"></i> Classes</a>
        <a href="learners.php"><i class="fa-solid fa-users"></i> Learners</a>
        <a class="active" href="enrollments.php"><i class="fa-solid fa-book-open-reader"></i> Enrollments</a>
        <a href="#"><i class="fa-solid fa-chart-simple"></i> Reports</a>
        <a href="#"><i class="fa-solid fa-gear"></i> Settings</a>
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
          <div class="col-xl-5">
            <div class="panel-card h-100">
              <span class="section-kicker">Enroll</span>
              <h2 class="h5 mb-4">Assign learner to course</h2>
              <form method="post" class="module-form">
                <input type="hidden" name="action" value="enroll">
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
                <div class="mb-3">
                  <label class="form-label" for="course_id">Course</label>
                  <select class="form-select" id="course_id" name="course_id" required>
                    <option value="">Choose course</option>
                    <?php foreach ($courses as $course): ?>
                      <option value="<?php echo (int) $course['id']; ?>">
                        <?php echo e($course['course_code'] . ' - ' . $course['course_name']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-4">
                  <label class="form-label" for="enrollment_status">Status</label>
                  <select class="form-select" id="enrollment_status" name="enrollment_status">
                    <option value="Enrolled">Enrolled</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Completed">Completed</option>
                    <option value="Dropped">Dropped</option>
                  </select>
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fa-solid fa-user-plus me-2"></i>Enroll Learner
                </button>
              </form>
            </div>
          </div>

          <div class="col-xl-7">
            <div class="panel-card h-100">
              <span class="section-kicker">Courses</span>
              <h2 class="h5 mb-4">Add course</h2>
              <form method="post" class="module-form">
                <input type="hidden" name="action" value="add_course">
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label" for="course_code">Course code</label>
                    <input type="text" class="form-control" id="course_code" name="course_code" required>
                  </div>
                  <div class="col-md-5">
                    <label class="form-label" for="course_name">Course name</label>
                    <input type="text" class="form-control" id="course_name" name="course_name" required>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                      <option value="Active">Active</option>
                      <option value="Inactive">Inactive</option>
                    </select>
                  </div>
                </div>
                <div class="my-3">
                  <label class="form-label" for="description">Description</label>
                  <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-primary">
                  <i class="fa-solid fa-plus me-2"></i>Add Course
                </button>
              </form>
            </div>
          </div>
        </div>

        <div class="panel-card">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
              <span class="section-kicker">Enrollment Records</span>
              <h2 class="h5 mb-0">Current course enrollments</h2>
            </div>
            <span class="badge text-bg-primary"><?php echo count($enrollments); ?> total</span>
          </div>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Learner</th>
                  <th>Course</th>
                  <th>Status</th>
                  <th>Date</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$enrollments): ?>
                  <tr>
                    <td colspan="5" class="text-center text-secondary py-5">No learner enrollments yet.</td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($enrollments as $enrollment): ?>
                  <tr>
                    <td>
                      <strong><?php echo e($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></strong><br>
                      <span class="text-secondary small"><?php echo e($enrollment['learner_number']); ?></span>
                    </td>
                    <td>
                      <strong><?php echo e($enrollment['course_name']); ?></strong><br>
                      <span class="text-secondary small"><?php echo e($enrollment['course_code']); ?></span>
                    </td>
                    <td><span class="badge text-bg-success"><?php echo e($enrollment['enrollment_status']); ?></span></td>
                    <td><?php echo e(date('M d, Y', strtotime($enrollment['enrolled_at']))); ?></td>
                    <td class="text-end">
                      <form method="post" onsubmit="return confirm('Remove this enrollment?');">
                        <input type="hidden" name="action" value="delete_enrollment">
                        <input type="hidden" name="id" value="<?php echo (int) $enrollment['id']; ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">
                          <i class="fa-solid fa-trash"></i>
                          <span class="visually-hidden">Remove</span>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js"></script>
</body>
</html>
