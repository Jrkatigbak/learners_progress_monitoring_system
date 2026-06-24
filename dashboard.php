<?php

require_once __DIR__ . '/includes/auth_guard.php';

// Dashboard counters come from the live modules instead of fixed placeholders.
$classCount = (int) $pdo->query('SELECT COUNT(*) FROM classes')->fetchColumn();
$activeClassCount = (int) $pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'Active'")->fetchColumn();
$learnerCount = (int) $pdo->query('SELECT COUNT(*) FROM learners')->fetchColumn();
$completedLearnerCount = (int) $pdo->query("SELECT COUNT(*) FROM learners WHERE status = 'Completed'")->fetchColumn();
$averageProgress = (int) round((float) $pdo->query('SELECT COALESCE(AVG(progress_percent), 0) FROM learners')->fetchColumn());
// Course enrollment count is shown as a first-class dashboard metric.
$enrollmentCount = (int) $pdo->query('SELECT COUNT(*) FROM course_enrollments')->fetchColumn();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Dashboard</title>
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
          <!-- Keep the system name visible in the main dashboard navigation. -->
          <small>Learners Progress Monitoring System</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a class="active" href="dashboard.php"><i class="fa-solid fa-grid-2"></i> Dashboard</a>
        <a href="classes.php"><i class="fa-solid fa-chalkboard-user"></i> Classes</a>
        <a href="learners.php"><i class="fa-solid fa-users"></i> Learners</a>
        <a href="enrollments.php"><i class="fa-solid fa-book-open-reader"></i> Enrollments</a>
        <a href="#"><i class="fa-solid fa-chart-simple"></i> Reports</a>
        <a href="#"><i class="fa-solid fa-gear"></i> Settings</a>
      </nav>
      <div class="sidebar-footer">
        <p class="mb-1">Logged in as</p>
        <strong><?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
      </div>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name in the dashboard header for clear page context. -->
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Dashboard</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo strtoupper(substr($currentUser['name'], 0, 1)); ?></span>
            <span class="d-none d-sm-inline"><?php echo htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8'); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8'); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <div class="hero-panel">
          <div>
            <span class="section-kicker">Today</span>
            <h2>Learner progress at a glance</h2>
            <p>Monitor the most important learner activity, progress updates, and pending monitoring work from one dashboard.</p>
          </div>
          <a href="logout.php" class="btn btn-outline-light"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a>
        </div>

        <div class="row g-4 mb-4">
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-chalkboard-user"></i></span>
              <p>Total Classes</p>
              <h3><?php echo $classCount; ?></h3>
              <small class="text-success"><i class="fa-solid fa-circle-check"></i> <?php echo $activeClassCount; ?> active</small>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-users"></i></span>
              <p>Active Learners</p>
              <h3><?php echo $learnerCount; ?></h3>
              <small class="text-success"><i class="fa-solid fa-circle-check"></i> <?php echo $completedLearnerCount; ?> completed</small>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-clock"></i></span>
              <p>Average Progress</p>
              <h3><?php echo $averageProgress; ?>%</h3>
              <small class="text-secondary">Across all learners</small>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-info-subtle text-info"><i class="fa-solid fa-file-signature"></i></span>
              <p>Enrollments</p>
              <h3><?php echo $enrollmentCount; ?></h3>
              <small class="text-success"><i class="fa-solid fa-check"></i> Course assignments</small>
            </div>
          </div>
        </div>

        <div class="row g-4">
          <div class="col-xl-8">
            <div class="panel-card">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <span class="section-kicker">Classes</span>
                  <h2 class="h5 mb-0">Recent class activity</h2>
                </div>
                <a href="classes.php#classForm" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-2"></i>Add Class</a>
              </div>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th>Class</th>
                      <th>Section</th>
                      <th>Status</th>
                      <th>Adviser</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><strong>Work Immersion</strong><br><span class="text-secondary small">Updated today</span></td>
                      <td>Grade 12 - ICT A</td>
                      <td><span class="badge text-bg-success">Active</span></td>
                      <td>Maria Santos</td>
                    </tr>
                    <tr>
                      <td><strong>Practicum Program</strong><br><span class="text-secondary small">Yesterday</span></td>
                      <td>BSIT 4A</td>
                      <td><span class="badge text-bg-success">Active</span></td>
                      <td>Juan Dela Cruz</td>
                    </tr>
                    <tr>
                      <td><strong>Career Readiness</strong><br><span class="text-secondary small">Jun 12</span></td>
                      <td>Grade 12 - ABM B</td>
                      <td><span class="badge text-bg-warning">Pending</span></td>
                      <td>Elena Reyes</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="col-xl-4">
            <div class="panel-card h-100">
              <span class="section-kicker">Priorities</span>
              <h2 class="h5 mb-4">Action list</h2>
              <div class="task-item">
                <i class="fa-solid fa-circle-check text-success"></i>
                <div>
                  <strong>Review class list</strong>
                  <p>Keep sections and advisers updated.</p>
                </div>
              </div>
              <div class="task-item">
                <i class="fa-solid fa-circle-exclamation text-warning"></i>
                <div>
                  <strong>Check learner updates</strong>
                  <p>Three class progress reports need review.</p>
                </div>
              </div>
              <div class="task-item">
                <i class="fa-solid fa-calendar-check text-primary"></i>
                <div>
                  <strong>Prepare weekly report</strong>
                  <p>Export learner progress summary by Friday.</p>
                </div>
              </div>
            </div>
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
