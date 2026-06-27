<?php

require_once __DIR__ . '/includes/teacher_guard.php';

// Reusable escaping helper for the teacher portal.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$teacherStatement = $pdo->prepare('SELECT * FROM teachers WHERE email = :email LIMIT 1');
$teacherStatement->execute(['email' => $currentUser['email']]);
$teacher = $teacherStatement->fetch() ?: null;

$classes = [];
$learners = [];
$recentGrades = [];

if ($teacher) {
    $classesStatement = $pdo->prepare(
        "SELECT classes.*,
                COUNT(DISTINCT learners.id) AS learner_count
         FROM classes
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id
         LEFT JOIN learners ON learners.class_id = classes.id
         WHERE classes.teacher_id = :teacher_id
            OR class_teachers.teacher_id = :assigned_teacher_id
         GROUP BY classes.id
         ORDER BY classes.class_name"
    );
    $classesStatement->execute([
        'teacher_id' => (int) $teacher['id'],
        'assigned_teacher_id' => (int) $teacher['id'],
    ]);
    $classes = $classesStatement->fetchAll();

    $learnersStatement = $pdo->prepare(
        "SELECT learners.*,
                classes.class_name
         FROM learners
         INNER JOIN classes ON classes.id = learners.class_id
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id
         WHERE classes.teacher_id = :teacher_id
            OR class_teachers.teacher_id = :assigned_teacher_id
         ORDER BY classes.class_name, learners.first_name, learners.last_name"
    );
    $learnersStatement->execute([
        'teacher_id' => (int) $teacher['id'],
        'assigned_teacher_id' => (int) $teacher['id'],
    ]);
    $learners = $learnersStatement->fetchAll();

    $gradesStatement = $pdo->prepare(
        "SELECT learner_grades.*,
                learners.first_name,
                learners.last_name,
                learners.learner_number,
                classes.class_name
         FROM learner_grades
         INNER JOIN learners ON learners.id = learner_grades.learner_id
         INNER JOIN classes ON classes.id = learner_grades.class_id
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id
         WHERE classes.teacher_id = :teacher_id
            OR class_teachers.teacher_id = :assigned_teacher_id
         ORDER BY learner_grades.graded_at DESC, learner_grades.id DESC
         LIMIT 6"
    );
    $gradesStatement->execute([
        'teacher_id' => (int) $teacher['id'],
        'assigned_teacher_id' => (int) $teacher['id'],
    ]);
    $recentGrades = $gradesStatement->fetchAll();
}

$teacherName = $teacher['full_name'] ?? $currentUser['name'];
$initials = strtoupper(substr($teacherName, 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Teacher Portal</title>
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
      <a class="sidebar-brand" href="teacher_dashboard.php">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <span>
          <strong>Kiwi Digital</strong>
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a class="active" href="teacher_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="grades.php"><i class="fa-solid fa-star"></i> Grades</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo e($teacherName); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Teacher Portal</p>
          <h1 class="h3 mb-0">Assigned Classes</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e($initials); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($teacherName); ?></span>
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
            <span class="section-kicker">Welcome</span>
            <h2><?php echo e($teacherName); ?></h2>
            <p>View your assigned classes and add grades to learners.</p>
          </div>
          <a href="grades.php" class="btn btn-outline-light"><i class="fa-solid fa-star me-2"></i>Add Grade</a>
        </div>

        <?php if (!$teacher): ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p>Your login is not linked to a teacher profile yet.</p>
          </div>
        <?php else: ?>
          <div class="row g-4 mb-4">
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-chalkboard-user"></i></span>
                <p>Assigned Classes</p>
                <h3><?php echo count($classes); ?></h3>
                <small class="text-secondary">Classes assigned to you</small>
              </div>
            </div>
            <div class="col-md-6 col-xl-3">
              <div class="metric-card">
                <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-users"></i></span>
                <p>Learners</p>
                <h3><?php echo count($learners); ?></h3>
                <small class="text-secondary">Across assigned classes</small>
              </div>
            </div>
          </div>

          <div class="class-card-grid mb-4">
            <?php foreach ($classes as $class): ?>
              <article class="class-card">
                <div class="class-wallpaper">
                  <?php if (!empty($class['banner_image'])): ?>
                    <img src="<?php echo e($class['banner_image']); ?>" alt="<?php echo e($class['class_name']); ?> wallpaper">
                  <?php else: ?>
                    <div class="class-wallpaper-placeholder">
                      <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                  <?php endif; ?>
                  <span class="class-status-badge <?php echo $class['status'] === 'Active' ? 'is-active' : 'is-inactive'; ?>"><?php echo e($class['status']); ?></span>
                </div>
                <div class="class-card-body">
                  <h3><?php echo e($class['class_name']); ?></h3>
                  <p class="class-teacher"><i class="fa-solid fa-users"></i><?php echo (int) $class['learner_count']; ?> learners</p>
                  <?php if (!empty($class['description'])): ?>
                    <p class="class-description"><?php echo e($class['description']); ?></p>
                  <?php endif; ?>
                </div>
                <footer class="class-card-footer">
                  <a class="btn btn-sm btn-primary" href="class_workspace.php?class_id=<?php echo (int) $class['id']; ?>&tool=dashboard">
                    <i class="fa-solid fa-door-open me-2"></i>Open Class
                  </a>
                </footer>
              </article>
            <?php endforeach; ?>
          </div>

          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker">Recent</span>
                <h2 class="h5 mb-0">Recent grades</h2>
              </div>
              <a href="grades.php" class="btn btn-sm btn-outline-primary">Open Grades</a>
            </div>
            <div class="table-responsive">
              <table class="table align-middle">
                <thead>
                  <tr>
                    <th>Learner</th>
                    <th>Class</th>
                    <th>Grade</th>
                    <th>Score</th>
                    <th>Date</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (!$recentGrades): ?>
                    <tr>
                      <td colspan="5" class="text-center text-secondary py-5">No grades recorded yet.</td>
                    </tr>
                  <?php endif; ?>
                  <?php foreach ($recentGrades as $grade): ?>
                    <tr>
                      <td>
                        <strong><?php echo e($grade['first_name'] . ' ' . $grade['last_name']); ?></strong><br>
                        <span class="text-secondary small"><?php echo e($grade['learner_number']); ?></span>
                      </td>
                      <td><?php echo e($grade['class_name']); ?></td>
                      <td><?php echo e($grade['grade_title']); ?></td>
                      <td><strong><?php echo e(number_format((float) $grade['score'], 2)); ?></strong> / <?php echo e(number_format((float) $grade['max_score'], 2)); ?></td>
                      <td><?php echo e(date('M d, Y', strtotime($grade['graded_at']))); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
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
