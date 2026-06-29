<?php

require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/includes/class_status.php';

if (!kiwiCan($pdo, 'dashboard.view')) {
    $fallbackPaths = [
        'classes.manage' => 'classes.php',
        'grades.manage' => 'grades.php',
        'users.manage' => 'user_management.php',
        'settings.manage' => 'settings.php',
    ];

    foreach ($fallbackPaths as $permission => $path) {
        if (kiwiCan($pdo, $permission)) {
            header('Location: ' . $path);
            exit;
        }
    }
}

// Dashboard counters come from the live modules instead of fixed placeholders.
$classCount = (int) $pdo->query('SELECT COUNT(*) FROM classes WHERE deleted_at IS NULL')->fetchColumn();
$activeClassCount = (int) $pdo->query("SELECT COUNT(*) FROM classes WHERE status = 'Active' AND deleted_at IS NULL")->fetchColumn();
$pendingEnrollmentCount = (int) $pdo->query("SELECT COUNT(*) FROM class_enrollment_requests WHERE status = 'Pending' AND deleted_at IS NULL")->fetchColumn();
$approvedEnrollmentCount = (int) $pdo->query("SELECT COUNT(*) FROM class_enrollment_requests WHERE status = 'Approved' AND deleted_at IS NULL")->fetchColumn();
$adminStaffCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('teacher', 'learner') AND deleted_at IS NULL")->fetchColumn();
$teacherCount = (int) $pdo->query('SELECT COUNT(*) FROM teachers WHERE deleted_at IS NULL')->fetchColumn();
$learnerCount = (int) $pdo->query('SELECT COUNT(*) FROM learners WHERE deleted_at IS NULL')->fetchColumn();
$classRows = $pdo->query(
    'SELECT classes.*,
            (
                SELECT COUNT(*)
                FROM class_enrollment_requests
                WHERE class_enrollment_requests.class_id = classes.id
                  AND class_enrollment_requests.status = "Pending"
                  AND class_enrollment_requests.deleted_at IS NULL
            ) AS pending_count,
            (
                SELECT COUNT(*)
                FROM course_enrollments
                INNER JOIN courses ON courses.id = course_enrollments.course_id
                WHERE courses.course_code = CONCAT("CLASS-", classes.id)
                  AND course_enrollments.enrollment_status IN ("Enrolled", "In Progress", "Completed")
                  AND courses.deleted_at IS NULL
                  AND course_enrollments.deleted_at IS NULL
            ) AS enrolled_count
     FROM classes
     WHERE classes.deleted_at IS NULL
     ORDER BY classes.sort_order ASC, classes.created_at DESC, classes.id DESC
     LIMIT 6'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo htmlspecialchars(kiwiSystemBrandName(), ENT_QUOTES, 'UTF-8'); ?> | Dashboard</title>
  <link rel="icon" type="image/png" href="<?php echo htmlspecialchars(kiwiSystemLogo(), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(kiwiSystemLogo(), ENT_QUOTES, 'UTF-8'); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-class-role-permissions" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'dashboard'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name in the dashboard header for clear page context. -->
          <p class="eyebrow mb-1"><?php echo htmlspecialchars(kiwiSystemName(), ENT_QUOTES, 'UTF-8'); ?></p>
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
            <h2>Classes at a glance</h2>
            <p>Monitor class records, public registration requests, and enrollment activity from one dashboard.</p>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <a href="logout.php" class="btn btn-outline-light"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a>
          </div>
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
              <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-circle-check"></i></span>
              <p>Active Classes</p>
              <h3><?php echo $activeClassCount; ?></h3>
              <small class="text-secondary">Ready for enrollment</small>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-warning-subtle text-warning"><i class="fa-solid fa-user-clock"></i></span>
              <p>Pending Requests</p>
              <h3><?php echo $pendingEnrollmentCount; ?></h3>
              <small class="text-secondary">Awaiting approval</small>
            </div>
          </div>
          <div class="col-md-6 col-xl-3">
            <div class="metric-card">
              <span class="metric-icon bg-info-subtle text-info"><i class="fa-solid fa-file-signature"></i></span>
              <p>Approved Requests</p>
              <h3><?php echo $approvedEnrollmentCount; ?></h3>
              <small class="text-success"><i class="fa-solid fa-check"></i> Registered through links</small>
            </div>
          </div>
          <?php if (kiwiCan($pdo, 'classes.manage')): ?>
            <div class="col-md-6 col-xl-3">
              <a class="metric-card metric-card-link" href="teachers.php">
                <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-user-tie"></i></span>
                <p>Teachers Masterlist</p>
                <h3><?php echo $teacherCount; ?></h3>
                <small class="text-secondary">Global teacher records</small>
              </a>
            </div>
            <div class="col-md-6 col-xl-3">
              <a class="metric-card metric-card-link" href="learners.php">
                <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-users"></i></span>
                <p>Learners Masterlist</p>
                <h3><?php echo $learnerCount; ?></h3>
                <small class="text-secondary">Global learner records</small>
              </a>
            </div>
          <?php endif; ?>
          <?php if (kiwiCan($pdo, 'users.manage')): ?>
            <div class="col-md-6 col-xl-3">
              <a class="metric-card metric-card-link" href="user_management.php">
                <span class="metric-icon bg-primary-subtle text-primary"><i class="fa-solid fa-users-gear"></i></span>
                <p>User Management</p>
                <h3><?php echo $adminStaffCount; ?></h3>
                <small class="text-secondary">Manage system access</small>
              </a>
            </div>
          <?php endif; ?>
        </div>

        <div class="row g-4">
          <div class="col-12">
            <div class="panel-card">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <span class="section-kicker">Classes</span>
                  <h2 class="h5 mb-0">Class enrollment overview</h2>
                </div>
                <a href="classes.php" class="btn btn-sm btn-primary"><i class="fa-solid fa-plus me-2"></i>Manage Classes</a>
              </div>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead>
                    <tr>
                      <th>Class</th>
                      <th>Status</th>
                      <th>Pending</th>
                      <th>Enrolled</th>
                      <th>Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$classRows): ?>
                      <tr><td colspan="5" class="text-center text-secondary py-5">No classes created yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($classRows as $classRow): ?>
                      <tr>
                        <td>
                          <strong><?php echo htmlspecialchars($classRow['class_name'], ENT_QUOTES, 'UTF-8'); ?></strong><br>
                          <span class="text-secondary small">Created <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $classRow['created_at'])), ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td><span class="badge <?php echo htmlspecialchars(kiwiClassStatusBootstrapClass((string) $classRow['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($classRow['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                        <td><strong><?php echo (int) $classRow['pending_count']; ?></strong></td>
                        <td><strong><?php echo (int) $classRow['enrolled_count']; ?></strong></td>
                        <td>
                          <a href="class_workspace.php?class_id=<?php echo (int) $classRow['id']; ?>&tool=learners" class="btn btn-sm btn-outline-primary">Open</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260629-class-role-permissions"></script>
</body>
</html>
