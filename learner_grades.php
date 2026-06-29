<?php

require_once __DIR__ . '/includes/auth_guard.php';

if ($auth->isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

if ($auth->isTeacher()) {
    header('Location: teacher_dashboard.php');
    exit;
}

// Reusable escaping helper for learner grade output.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

$learnerName = $learner ? trim($learner['first_name'] . ' ' . $learner['last_name']) : $currentUser['name'];
$learnerInitials = strtoupper(substr($learnerName, 0, 1));
$gradeRows = [];
$gradesByClass = [];
$learnerGradeColumns = [];

foreach ($pdo->query('DESCRIBE learner_grades') as $columnRow) {
    $learnerGradeColumns[(string) $columnRow['Field']] = true;
}
$otherRemarksSelect = isset($learnerGradeColumns['other_remarks'])
    ? 'learner_grades.other_remarks'
    : 'NULL AS other_remarks';

if ($learner) {
    $gradeStatement = $pdo->prepare(
        "SELECT learner_grades.id,
                learner_grades.score,
                learner_grades.max_score,
                learner_grades.remarks,
                {$otherRemarksSelect},
                learner_grades.graded_at,
                learner_grades.grade_title,
                classes.id AS class_id,
                classes.class_name,
                classes.banner_image,
                class_tasks.id AS task_id,
                class_tasks.task_title,
                class_tasks.task_date,
                class_material_folders.id AS topic_id,
                class_material_folders.name AS topic_name,
                class_material_folders.description AS topic_description,
                class_material_folders.banner_image AS topic_banner
         FROM learner_grades
         INNER JOIN classes ON classes.id = learner_grades.class_id AND classes.deleted_at IS NULL
         INNER JOIN learners ON learners.id = learner_grades.learner_id AND learners.deleted_at IS NULL
         LEFT JOIN class_tasks
           ON class_tasks.id = learner_grades.task_id
          AND class_tasks.deleted_at IS NULL
         LEFT JOIN class_material_folders
           ON class_material_folders.id = class_tasks.folder_id
          AND class_material_folders.deleted_at IS NULL
         LEFT JOIN courses
           ON courses.course_code = CONCAT('CLASS-', classes.id)
          AND courses.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = learners.id
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         WHERE learner_grades.learner_id = :learner_id
           AND learner_grades.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         ORDER BY classes.class_name,
                  COALESCE(class_material_folders.sort_order, 999999),
                  class_material_folders.name,
                  class_tasks.task_date DESC,
                  learner_grades.graded_at DESC,
                  learner_grades.id DESC"
    );
    $gradeStatement->execute(['learner_id' => (int) $learner['id']]);
    $gradeRows = $gradeStatement->fetchAll();

    foreach ($gradeRows as $grade) {
        $classId = (int) $grade['class_id'];
        $topicId = (int) ($grade['topic_id'] ?? 0);
        $topicKey = $topicId > 0 ? (string) $topicId : 'unassigned';

        if (!isset($gradesByClass[$classId])) {
            $gradesByClass[$classId] = [
                'class_name' => (string) $grade['class_name'],
                'banner_image' => (string) ($grade['banner_image'] ?? ''),
                'grades' => [],
                'topics' => [],
                'total_score' => 0.0,
                'total_max' => 0.0,
            ];
        }

        if (!isset($gradesByClass[$classId]['topics'][$topicKey])) {
            $gradesByClass[$classId]['topics'][$topicKey] = [
                'topic_name' => $topicId > 0 ? (string) $grade['topic_name'] : 'Unassigned Topic',
                'topic_description' => (string) ($grade['topic_description'] ?? ''),
                'topic_banner' => (string) ($grade['topic_banner'] ?? ''),
                'grades' => [],
                'total_score' => 0.0,
                'total_max' => 0.0,
            ];
        }

        $gradesByClass[$classId]['topics'][$topicKey]['grades'][] = $grade;
        $gradesByClass[$classId]['topics'][$topicKey]['total_score'] += (float) $grade['score'];
        $gradesByClass[$classId]['topics'][$topicKey]['total_max'] += (float) $grade['max_score'];
        $gradesByClass[$classId]['total_score'] += (float) $grade['score'];
        $gradesByClass[$classId]['total_max'] += (float) $grade['max_score'];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Grades</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-grade-status-remarks" rel="stylesheet">
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
        <a href="enrolled_courses.php"><i class="fa-solid fa-book-open-reader"></i> Enrolled Class</a>
        <a href="learner_quizzes.php"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <a href="learner_assignments.php"><i class="fa-solid fa-file-pen"></i> Assignments</a>
        <a class="active" href="learner_grades.php"><i class="fa-solid fa-star"></i> Grades</a>
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
          <h1 class="h3 mb-0">Grades</h1>
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
            <li><span class="dropdown-item-text text-secondary small"><?php echo e((string) $currentUser['email']); ?></span></li>
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
            <p>Review your grades by topic and grade item.</p>
          </div>
          <a href="enrolled_courses.php" class="btn btn-outline-light"><i class="fa-solid fa-book-open-reader me-2"></i>Enrolled Class</a>
        </div>

        <?php if (!$learner): ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-exclamation"></i>
            <p>Your login is not linked to a learner profile yet.</p>
          </div>
        <?php elseif (!$gradesByClass): ?>
          <div class="empty-state">
            <i class="fa-solid fa-star"></i>
            <p>No grades have been posted yet.</p>
          </div>
        <?php else: ?>
          <div class="learner-grade-stack">
            <?php foreach ($gradesByClass as $classGrades): ?>
              <?php
                $classAverage = $classGrades['total_max'] > 0 ? round(($classGrades['total_score'] / $classGrades['total_max']) * 100, 2) : 0;
              ?>
              <article class="learner-grade-class-card">
                <div class="learner-grade-class-head">
                  <div class="learner-grade-class-banner">
                    <?php if (!empty($classGrades['banner_image'])): ?>
                      <img src="<?php echo e($classGrades['banner_image']); ?>" alt="<?php echo e($classGrades['class_name']); ?> banner">
                    <?php else: ?>
                      <i class="fa-solid fa-book-open-reader"></i>
                    <?php endif; ?>
                  </div>
                  <div>
                    <span class="section-kicker">Class Grades</span>
                    <h2><?php echo e($classGrades['class_name']); ?></h2>
                    <p><?php echo count($classGrades['topics']); ?> topic<?php echo count($classGrades['topics']) === 1 ? '' : 's'; ?></p>
                  </div>
                  <div class="learner-grade-average">
                    <strong><?php echo e(number_format($classAverage, 2)); ?>%</strong>
                    <span>Average</span>
                  </div>
                </div>

                <div class="learner-topic-grade-grid">
                  <?php foreach ($classGrades['topics'] as $topicGrades): ?>
                    <?php
                      $topicAverage = $topicGrades['total_max'] > 0 ? round(($topicGrades['total_score'] / $topicGrades['total_max']) * 100, 2) : 0;
                    ?>
                    <section class="learner-topic-grade-card">
                      <div class="learner-topic-grade-head">
                        <div class="learner-topic-grade-banner">
                          <?php if (!empty($topicGrades['topic_banner'])): ?>
                            <img src="<?php echo e($topicGrades['topic_banner']); ?>" alt="<?php echo e($topicGrades['topic_name']); ?> topic banner">
                          <?php else: ?>
                            <i class="fa-solid fa-folder"></i>
                          <?php endif; ?>
                        </div>
                        <div>
                          <span class="section-kicker">Topic</span>
                          <h3><?php echo e($topicGrades['topic_name']); ?></h3>
                          <?php if (!empty($topicGrades['topic_description'])): ?>
                            <p><?php echo e($topicGrades['topic_description']); ?></p>
                          <?php endif; ?>
                        </div>
                        <div class="learner-topic-average">
                          <strong><?php echo e(number_format($topicAverage, 2)); ?>%</strong>
                          <span><?php echo count($topicGrades['grades']); ?> item<?php echo count($topicGrades['grades']) === 1 ? '' : 's'; ?></span>
                        </div>
                      </div>

                      <div class="learner-grade-item-list">
                        <?php foreach ($topicGrades['grades'] as $grade): ?>
                          <?php
                            $maxScore = (float) ($grade['max_score'] ?: 100);
                            $gradePercent = $maxScore > 0 ? round(((float) $grade['score'] / $maxScore) * 100, 2) : 0;
                            $gradeTitle = (string) ($grade['task_title'] ?: $grade['grade_title']);
                          ?>
                          <div class="learner-grade-item">
                            <div>
                              <strong><?php echo e($gradeTitle); ?></strong>
                              <span>
                                <?php echo e(!empty($grade['graded_at']) ? date('M d, Y', strtotime($grade['graded_at'])) : 'No date'); ?>
                                <?php if (!empty($grade['remarks'])): ?>
                                  · <?php echo e((string) $grade['remarks']); ?>
                                <?php endif; ?>
                                <?php if (!empty($grade['other_remarks'])): ?>
                                  · <?php echo e((string) $grade['other_remarks']); ?>
                                <?php endif; ?>
                              </span>
                            </div>
                            <div class="learner-grade-score">
                              <strong><?php echo e(number_format((float) $grade['score'], 2)); ?> / <?php echo e(number_format($maxScore, 0)); ?></strong>
                              <span><?php echo e(number_format($gradePercent, 2)); ?>%</span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </section>
                  <?php endforeach; ?>
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
  <script src="js/app.js?v=20260629-grade-status-remarks"></script>
</body>
</html>
