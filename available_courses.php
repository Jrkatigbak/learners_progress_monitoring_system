<?php

require_once __DIR__ . '/includes/auth_guard.php';

if ($auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// Reusable escaping helper for learner-facing course cards.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$success = $_GET['success'] ?? '';
$errors = [];

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_enrollment') {
    if (!$learner) {
        $errors[] = 'Your login is not linked to a learner profile yet.';
    }

    $courseId = (int) ($_POST['course_id'] ?? 0);

    if ($courseId <= 0) {
        $errors[] = 'Choose a valid class.';
    }

    if (!$errors) {
        $courseStatement = $pdo->prepare("SELECT id FROM courses WHERE id = :id AND status = 'Active' AND deleted_at IS NULL LIMIT 1");
        $courseStatement->execute(['id' => $courseId]);

        if (!$courseStatement->fetch()) {
            $errors[] = 'This class is not available.';
        }
    }

    if (!$errors) {
        // Learner requests always start as Pending so admin approval controls final enrollment.
        $enrollStatement = $pdo->prepare(
            "INSERT INTO course_enrollments (learner_id, course_id, enrollment_status)
             VALUES (:learner_id, :course_id, 'Pending')
             ON DUPLICATE KEY UPDATE
                enrollment_status = CASE
                    WHEN enrollment_status IN ('Disapproved', 'Dropped') THEN 'Pending'
                    ELSE enrollment_status
                END"
                . ', deleted_at = NULL'
        );
        $enrollStatement->execute([
            'learner_id' => (int) $learner['id'],
            'course_id' => $courseId,
        ]);

        header('Location: available_courses.php?success=requested');
        exit;
    }
}

$courseStatement = $pdo->prepare(
    "SELECT courses.id,
            courses.course_code,
            courses.course_name,
            courses.banner_image,
            courses.description,
            courses.status,
            course_enrollments.enrollment_status,
            course_enrollments.enrolled_at
     FROM courses
     LEFT JOIN course_enrollments
       ON course_enrollments.course_id = courses.id
      AND course_enrollments.learner_id = :learner_id
      AND course_enrollments.deleted_at IS NULL
     WHERE courses.status = 'Active'
       AND courses.deleted_at IS NULL
     ORDER BY courses.course_name"
);
$courseStatement->execute(['learner_id' => $learner ? (int) $learner['id'] : 0]);
$courseRows = $courseStatement->fetchAll();

$learnerName = $learner ? trim($learner['first_name'] . ' ' . $learner['last_name']) : $currentUser['name'];
$learnerInitials = strtoupper(substr($learnerName, 0, 1));
$successMessages = [
    'requested' => 'Class enrollment request sent. Please wait for admin approval.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Available Class</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260713-learner-dashboard-nav" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="learner_dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="learner_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo e($learnerName); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Available Class</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar">
              <?php if (!empty($learner["profile_photo"])): ?>
                <img src="<?php echo e((string) $learner["profile_photo"]); ?>" alt="<?php echo e($learnerName); ?>">
              <?php else: ?>
                <?php echo e($learnerInitials); ?>
              <?php endif; ?>
            </span>
            <span class="d-none d-sm-inline"><?php echo e($learnerName); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e($currentUser['email']); ?></span></li>
            <li><a class="dropdown-item" href="learner_profile.php"><i class="fa-solid fa-user-pen me-2"></i>Update Profile</a></li>
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

        <?php if (!$learner): ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p>Your login is not linked to a learner profile yet.</p>
          </div>
        <?php elseif (!$courseRows): ?>
          <div class="empty-state">
            <i class="fa-solid fa-book-open"></i>
            <p>No available classes yet.</p>
          </div>
        <?php else: ?>
          <div class="course-grid">
            <?php foreach ($courseRows as $course): ?>
              <?php $status = $course['enrollment_status'] ?? ''; ?>
              <article class="course-card course-catalog-card">
                <div class="course-wallpaper">
                  <?php if (!empty($course['banner_image'])): ?>
                    <img src="<?php echo e($course['banner_image']); ?>" alt="<?php echo e($course['course_name']); ?> wallpaper">
                  <?php else: ?>
                    <div class="course-wallpaper-placeholder">
                      <i class="fa-solid fa-book-open-reader"></i>
                    </div>
                  <?php endif; ?>
                </div>
                <div class="course-card-content">
                  <div class="course-card-header">
                    <span class="course-code"><?php echo e($course['course_code']); ?></span>
                    <?php if ($status !== ''): ?>
                      <span class="badge <?php echo $status === 'Pending' ? 'text-bg-warning' : ($status === 'Disapproved' ? 'text-bg-danger' : 'text-bg-success'); ?>"><?php echo e($status); ?></span>
                    <?php else: ?>
                      <span class="badge text-bg-light">Open</span>
                    <?php endif; ?>
                  </div>
                  <h2><?php echo e($course['course_name']); ?></h2>
                  <?php if (!empty($course['description'])): ?>
                    <p><?php echo e($course['description']); ?></p>
                  <?php endif; ?>
                  <form method="post">
                    <input type="hidden" name="action" value="request_enrollment">
                    <input type="hidden" name="course_id" value="<?php echo (int) $course['id']; ?>">
                    <?php if ($status === '' || in_array($status, ['Disapproved', 'Dropped'], true)): ?>
                      <button type="submit" class="btn btn-primary w-100">
                        <i class="fa-solid fa-paper-plane me-2"></i><?php echo $status === 'Disapproved' ? 'Request Again' : 'Enroll'; ?>
                      </button>
                    <?php else: ?>
                      <button type="button" class="btn btn-outline-secondary w-100" disabled>
                        <?php echo $status === 'Pending' ? 'Waiting for Approval' : 'Already Enrolled'; ?>
                      </button>
                    <?php endif; ?>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260713-learner-dashboard-nav"></script>
</body>
</html>
