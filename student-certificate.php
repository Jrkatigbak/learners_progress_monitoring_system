<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/learner_course_sidebar.php';
require_once __DIR__ . '/includes/certificates.php';

if ($auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

if ($auth->isTeacher()) {
    header('Location: teacher_dashboard.php');
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$courseId = max(0, (int) ($_GET['course_id'] ?? 0));
$classId = max(0, (int) ($_GET['class_id'] ?? 0));

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

if (!$learner) {
    header('Location: learner_dashboard.php');
    exit;
}

$learnerId = (int) $learner['id'];
$learnerName = trim((string) $learner['first_name'] . ' ' . (string) $learner['last_name']);
$learnerInitials = strtoupper(substr((string) $learner['first_name'], 0, 1) . substr((string) $learner['last_name'], 0, 1));
$learnerCourseContext = kiwiLearnerCourseContext($pdo, $learnerId, $courseId, $classId);

if (!$learnerCourseContext) {
    header('Location: enrolled_courses.php');
    exit;
}

$courseId = (int) $learnerCourseContext['course_id'];
$classId = (int) $learnerCourseContext['class_id'];

if (empty($learnerCourseContext['certificate_ready'])) {
    $certificateAvailable = false;
} else {
    $certificateAvailable = true;
}

$certificateUrl = 'certificate.php?class_id=' . $classId . '&learner_id=' . $learnerId;
$downloadUrl = $certificateUrl . '&download=1';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Certificate</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260717-student-certificate" rel="stylesheet">
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
      <?php kiwiRenderLearnerCourseSidebar($learnerCourseContext, $learnerName, 'certificates', $learnerId); ?>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Class Portal</p>
          <h1 class="h3 mb-0">Certificate</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar">
              <?php if (!empty($learner['profile_photo'])): ?>
                <img src="<?php echo e((string) $learner['profile_photo']); ?>" alt="<?php echo e($learnerName); ?>">
              <?php else: ?>
                <?php echo e($learnerInitials !== '' ? $learnerInitials : 'L'); ?>
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
        <section class="learner-course-section">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Certificates</span>
              <h2><?php echo e((string) $learnerCourseContext['course_name']); ?></h2>
            </div>
            <?php if ($certificateAvailable): ?>
              <a class="btn btn-primary" href="<?php echo e($downloadUrl); ?>" download>
                <i class="fa-solid fa-download me-2"></i>Download Certificate
              </a>
            <?php endif; ?>
          </div>

          <?php if (!$certificateAvailable): ?>
            <div class="empty-state compact">
              <i class="fa-solid fa-lock"></i>
              <p>Certificate viewing and downloads are currently disabled for this class.</p>
            </div>
          <?php else: ?>
            <div class="student-certificate-preview">
              <img src="<?php echo e($certificateUrl); ?>" alt="<?php echo e($learnerName); ?> certificate">
            </div>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260717-student-certificate"></script>
</body>
</html>
