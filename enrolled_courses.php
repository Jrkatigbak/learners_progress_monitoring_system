<?php

require_once __DIR__ . '/includes/auth_guard.php';

if ($auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

// Reusable escaping helper for learner-facing cards.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, phone, status, profile_photo
     FROM learners
     WHERE email = :email
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

$courseRows = [];
$classmatesByCourse = [];
$approvedStatuses = ['Enrolled', 'In Progress', 'Completed'];

if ($learner) {
    // Only approved/active enrollments should open course classmates to the learner.
    $coursesStatement = $pdo->prepare(
        "SELECT courses.id,
                courses.course_code,
                courses.course_name,
                courses.banner_image,
                courses.description,
                course_enrollments.enrollment_status,
                course_enrollments.enrolled_at
         FROM course_enrollments
         INNER JOIN courses ON courses.id = course_enrollments.course_id
         WHERE course_enrollments.learner_id = :learner_id
           AND courses.status = 'Active'
           AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
         ORDER BY courses.course_name"
    );
    $coursesStatement->execute(['learner_id' => (int) $learner['id']]);
    $courseRows = $coursesStatement->fetchAll();

    if ($courseRows) {
        $courseIds = array_map(static fn (array $course): int => (int) $course['id'], $courseRows);
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

        // Classmates are learners approved into the same course.
        $classmatesStatement = $pdo->prepare(
            "SELECT course_enrollments.course_id,
                    learners.id,
                    learners.learner_number,
                    learners.first_name,
                    learners.last_name,
                    learners.email,
                    learners.profile_photo
             FROM course_enrollments
             INNER JOIN learners ON learners.id = course_enrollments.learner_id
             WHERE course_enrollments.course_id IN ({$placeholders})
               AND learners.id <> ?
               AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
             ORDER BY learners.first_name, learners.last_name"
        );
        $classmatesStatement->execute([...$courseIds, (int) $learner['id']]);

        foreach ($classmatesStatement->fetchAll() as $classmate) {
            $classmatesByCourse[(int) $classmate['course_id']][] = $classmate;
        }
    }
}

$learnerName = $learner ? trim($learner['first_name'] . ' ' . $learner['last_name']) : $currentUser['name'];
$learnerInitials = strtoupper(substr($learnerName, 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Enrolled Class</title>
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
      <a class="sidebar-brand" href="learner_dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="learner_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="available_courses.php"><i class="fa-solid fa-book-open"></i> Available Class</a>
        <a class="active" href="enrolled_courses.php"><i class="fa-solid fa-book-open-reader"></i> Enrolled Class</a>
        <a href="learner_quizzes.php"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <a href="learner_assignments.php"><i class="fa-solid fa-file-pen"></i> Assignments</a>
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
          <h1 class="h3 mb-0">Enrolled Class</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e($learnerInitials); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($learnerName); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e($currentUser['email']); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <div class="hero-panel learner-hero">
          <div>
            <span class="section-kicker">Learner Portal</span>
            <h2><?php echo e($learnerName); ?></h2>
            <p>View your approved classes and classmates.</p>
          </div>
          <a href="available_courses.php" class="btn btn-outline-light"><i class="fa-solid fa-plus me-2"></i>Find Class</a>
        </div>

        <?php if (!$learner): ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p>Your login is not linked to a learner profile yet.</p>
          </div>
        <?php elseif (!$courseRows): ?>
          <div class="empty-state">
            <i class="fa-solid fa-book-open"></i>
            <p>No approved enrolled classes yet.</p>
          </div>
        <?php else: ?>
          <div class="course-grid">
            <?php foreach ($courseRows as $course): ?>
              <?php
                $courseId = (int) $course['id'];
                $classmates = $classmatesByCourse[$courseId] ?? [];
              ?>
              <article class="course-card">
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
                    <span class="badge text-bg-success"><?php echo e($course['enrollment_status']); ?></span>
                  </div>
                  <h2><?php echo e($course['course_name']); ?></h2>
                  <?php if (!empty($course['description'])): ?>
                    <p><?php echo e($course['description']); ?></p>
                  <?php endif; ?>
                  <div class="course-meta">
                    <span><i class="fa-regular fa-calendar"></i><?php echo e(date('M d, Y', strtotime($course['enrolled_at']))); ?></span>
                    <span><i class="fa-solid fa-users"></i><?php echo count($classmates); ?> classmates</span>
                  </div>
                  <a class="btn btn-sm btn-outline-primary mb-3" href="learner_quizzes.php">
                    <i class="fa-solid fa-circle-question me-2"></i>Open Quizzes
                  </a>
                  <a class="btn btn-sm btn-outline-primary mb-3" href="learner_assignments.php">
                    <i class="fa-solid fa-file-pen me-2"></i>Open Assignments
                  </a>
                  <div class="classmate-list">
                    <h3>Classmates</h3>
                    <?php if (!$classmates): ?>
                      <p class="small mb-0">No classmates approved yet.</p>
                    <?php else: ?>
                      <div class="classmate-grid">
                        <?php foreach ($classmates as $classmate): ?>
                          <?php
                            $classmateName = trim($classmate['first_name'] . ' ' . $classmate['last_name']);
                            $classmateInitials = strtoupper(substr($classmate['first_name'], 0, 1) . substr($classmate['last_name'], 0, 1));
                          ?>
                          <div class="classmate-chip">
                            <?php if (!empty($classmate['profile_photo'])): ?>
                              <img src="<?php echo e($classmate['profile_photo']); ?>" alt="<?php echo e($classmateName); ?>">
                            <?php else: ?>
                              <span><?php echo e($classmateInitials); ?></span>
                            <?php endif; ?>
                            <div>
                              <strong><?php echo e($classmateName); ?></strong>
                              <small><?php echo e($classmate['learner_number']); ?></small>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
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
  <script src="js/app.js"></script>
</body>
</html>
