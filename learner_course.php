<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/evaluations.php';
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

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, phone, status, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

$courseId = max(0, (int) ($_GET['course_id'] ?? 0));
$course = null;
$evaluationColumns = kiwiClassEvaluationColumns($pdo);
$certificateColumns = kiwiClassCertificateColumns($pdo);

if ($learner && $courseId > 0) {
    $evaluationSelect = kiwiClassEvaluationColumnsReady($evaluationColumns)
        ? ', classes.class_type,
                classes.seminar_title,
                classes.seminar_presenter,
                classes.seminar_date,
                classes.seminar_venue'
        : ', "Course" AS class_type,
                NULL AS seminar_title,
                NULL AS seminar_presenter,
                NULL AS seminar_date,
                NULL AS seminar_venue';
    $certificateSelect = kiwiClassCertificateReady($certificateColumns)
        ? ', classes.certificate_template_image'
        : ', NULL AS certificate_template_image';
    $courseStatement = $pdo->prepare(
        "SELECT courses.id,
                courses.course_code,
                courses.course_name,
                COALESCE(NULLIF(courses.banner_image, ''), classes.banner_image) AS banner_image,
                courses.description,
                course_enrollments.enrollment_status,
                course_enrollments.enrolled_at,
                classes.id AS class_id,
                classes.class_name
                {$evaluationSelect}
                {$certificateSelect}
         FROM course_enrollments
         INNER JOIN courses ON courses.id = course_enrollments.course_id AND courses.deleted_at IS NULL
         LEFT JOIN classes ON courses.course_code = CONCAT('CLASS-', classes.id) AND classes.deleted_at IS NULL
         WHERE course_enrollments.learner_id = :learner_id
           AND courses.id = :course_id
           AND courses.status = 'Active'
           AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
           AND course_enrollments.deleted_at IS NULL
         LIMIT 1"
    );
    $courseStatement->execute([
        'learner_id' => (int) $learner['id'],
        'course_id' => $courseId,
    ]);
    $course = $courseStatement->fetch() ?: null;
}

if (!$learner || !$course) {
    header('Location: enrolled_courses.php');
    exit;
}

$classId = (int) ($course['class_id'] ?? 0);
$learnerName = trim($learner['first_name'] . ' ' . $learner['last_name']);
$learnerInitials = strtoupper(substr($learnerName, 0, 1));
$certificateReady = $classId > 0 && kiwiClassCertificateReady($certificateColumns) && !empty($course['certificate_template_image']);
$classmates = [];
$topics = [];
$materials = [];
$quizzes = [];
$assignments = [];
$gradeItems = [];
$evaluationSubmitted = false;
$evaluationReady = kiwiClassEvaluationColumnsReady($evaluationColumns) && kiwiClassEvaluationsTableReady($pdo);

if ($classId > 0) {
    $classmatesStatement = $pdo->prepare(
        "SELECT learners.id,
                learners.learner_number,
                learners.first_name,
                learners.last_name,
                learners.email,
                learners.profile_photo
         FROM course_enrollments
         INNER JOIN learners ON learners.id = course_enrollments.learner_id AND learners.deleted_at IS NULL
         WHERE course_enrollments.course_id = :course_id
           AND learners.id <> :learner_id
           AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
           AND course_enrollments.deleted_at IS NULL
         ORDER BY learners.first_name, learners.last_name"
    );
    $classmatesStatement->execute([
        'course_id' => $courseId,
        'learner_id' => (int) $learner['id'],
    ]);
    $classmates = $classmatesStatement->fetchAll();

    $topicStatement = $pdo->prepare(
        'SELECT id, name, description, banner_image
         FROM class_material_folders
         WHERE class_id = :class_id
           AND deleted_at IS NULL
         ORDER BY sort_order ASC, created_at DESC, id DESC'
    );
    $topicStatement->execute(['class_id' => $classId]);
    $topics = $topicStatement->fetchAll();

    $materialStatement = $pdo->prepare(
        'SELECT class_learning_materials.id,
                class_learning_materials.title,
                class_learning_materials.description,
                class_learning_materials.material_type,
                class_learning_materials.file_path,
                class_learning_materials.external_url,
                class_material_folders.name AS topic_name
         FROM class_learning_materials
         LEFT JOIN class_material_folders
           ON class_material_folders.id = class_learning_materials.folder_id
          AND class_material_folders.deleted_at IS NULL
         WHERE class_learning_materials.class_id = :class_id
           AND class_learning_materials.deleted_at IS NULL
         ORDER BY class_learning_materials.created_at DESC, class_learning_materials.id DESC'
    );
    $materialStatement->execute(['class_id' => $classId]);
    $materials = $materialStatement->fetchAll();

    $quizStatement = $pdo->prepare(
        'SELECT class_quizzes.id,
                class_quizzes.title,
                class_quizzes.description,
                class_quizzes.timer_minutes,
                class_material_folders.name AS topic_name
         FROM class_quizzes
         LEFT JOIN class_material_folders
           ON class_material_folders.id = class_quizzes.folder_id
          AND class_material_folders.deleted_at IS NULL
         WHERE class_quizzes.class_id = :class_id
           AND class_quizzes.status = "Active"
           AND class_quizzes.deleted_at IS NULL
         ORDER BY class_quizzes.created_at DESC, class_quizzes.id DESC'
    );
    $quizStatement->execute(['class_id' => $classId]);
    $quizzes = $quizStatement->fetchAll();

    $assignmentStatement = $pdo->prepare(
        'SELECT class_assignments.id,
                class_assignments.title,
                class_assignments.instructions,
                class_assignments.due_date,
                class_material_folders.name AS topic_name
         FROM class_assignments
         LEFT JOIN class_material_folders
           ON class_material_folders.id = class_assignments.folder_id
          AND class_material_folders.deleted_at IS NULL
         WHERE class_assignments.class_id = :class_id
           AND class_assignments.status = "Active"
           AND class_assignments.deleted_at IS NULL
         ORDER BY class_assignments.due_date IS NULL, class_assignments.due_date ASC, class_assignments.created_at DESC'
    );
    $assignmentStatement->execute(['class_id' => $classId]);
    $assignments = $assignmentStatement->fetchAll();

    $gradeItemStatement = $pdo->prepare(
        'SELECT class_tasks.id,
                class_tasks.task_title,
                class_tasks.task_date,
                class_tasks.description,
                class_material_folders.name AS topic_name
         FROM class_tasks
         LEFT JOIN class_material_folders
           ON class_material_folders.id = class_tasks.folder_id
          AND class_material_folders.deleted_at IS NULL
         WHERE class_tasks.class_id = :class_id
           AND class_tasks.deleted_at IS NULL
         ORDER BY class_tasks.task_date DESC, class_tasks.id DESC'
    );
    $gradeItemStatement->execute(['class_id' => $classId]);
    $gradeItems = $gradeItemStatement->fetchAll();

    if ($evaluationReady) {
        $evaluationStatement = $pdo->prepare('SELECT id FROM class_evaluations WHERE class_id = :class_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1');
        $evaluationStatement->execute([
            'class_id' => $classId,
            'learner_id' => (int) $learner['id'],
        ]);
        $evaluationSubmitted = (bool) $evaluationStatement->fetchColumn();
    }
}

$moduleCards = [
    ['label' => 'Topics', 'icon' => 'fa-list-check', 'count' => count($topics), 'url' => '#topics'],
    ['label' => 'Materials', 'icon' => 'fa-folder-open', 'count' => count($materials), 'url' => '#materials'],
    ['label' => 'Quizzes', 'icon' => 'fa-circle-question', 'count' => count($quizzes), 'url' => 'learner_quizzes.php?course_id=' . $courseId],
    ['label' => 'Assignments', 'icon' => 'fa-file-pen', 'count' => count($assignments), 'url' => 'learner_assignments.php?course_id=' . $courseId],
    ['label' => 'Grades', 'icon' => 'fa-star', 'count' => count($gradeItems), 'url' => 'learner_grades.php?course_id=' . $courseId],
    ['label' => 'Evaluation', 'icon' => 'fa-clipboard-check', 'count' => $evaluationSubmitted ? 1 : 0, 'url' => $evaluationReady ? 'learner_evaluation.php?course_id=' . $courseId : '#evaluation'],
    ['label' => 'Classmates', 'icon' => 'fa-users', 'count' => count($classmates), 'url' => '#classmates'],
    ['label' => 'Certificates', 'icon' => 'fa-award', 'count' => $certificateReady ? 1 : 0, 'url' => $certificateReady ? 'certificate.php?class_id=' . $classId . '&learner_id=' . (int) $learner['id'] : '#certificates'],
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | <?php echo e($course['course_name']); ?></title>
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
        <a class="active" href="learner_course.php?course_id=<?php echo $courseId; ?>"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <?php foreach ($moduleCards as $module): ?>
          <a href="<?php echo e($module['url']); ?>">
            <i class="fa-solid <?php echo e($module['icon']); ?>"></i>
            <?php echo e($module['label']); ?>
            <span class="sidebar-nav-count"><?php echo (int) $module['count']; ?></span>
          </a>
        <?php endforeach; ?>
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
          <p class="eyebrow mb-1">Class Portal</p>
          <h1 class="h3 mb-0"><?php echo e($course['course_name']); ?></h1>
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
        <article class="learner-course-hero">
          <div class="learner-course-hero-media">
            <?php if (!empty($course['banner_image'])): ?>
              <img src="<?php echo e($course['banner_image']); ?>" alt="<?php echo e($course['course_name']); ?> banner">
            <?php else: ?>
              <div class="course-wallpaper-placeholder">
                <i class="fa-solid fa-book-open-reader"></i>
              </div>
            <?php endif; ?>
          </div>
          <div class="learner-course-hero-content">
            <span class="course-code"><?php echo e($course['course_code']); ?></span>
            <h2><?php echo e($course['course_name']); ?></h2>
            <?php if (!empty($course['description'])): ?>
              <p><?php echo e($course['description']); ?></p>
            <?php endif; ?>
            <div class="course-meta">
              <span><i class="fa-solid fa-circle-check"></i><?php echo e($course['enrollment_status']); ?></span>
              <span><i class="fa-regular fa-calendar"></i><?php echo e(date('M d, Y', strtotime($course['enrolled_at']))); ?></span>
            </div>
          </div>
        </article>

        <section class="learner-course-section" id="topics">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Topics</span>
              <h2>Course topics</h2>
            </div>
          </div>
          <?php if (!$topics): ?>
            <div class="empty-state compact"><i class="fa-solid fa-list-check"></i><p>No topics yet.</p></div>
          <?php else: ?>
            <div class="learner-course-topic-grid">
              <?php foreach ($topics as $topic): ?>
                <article class="learner-course-topic-card">
                  <?php if (!empty($topic['banner_image'])): ?>
                    <img src="<?php echo e($topic['banner_image']); ?>" alt="<?php echo e($topic['name']); ?> banner">
                  <?php endif; ?>
                  <div>
                    <h3><?php echo e($topic['name']); ?></h3>
                    <?php if (!empty($topic['description'])): ?>
                      <p><?php echo e($topic['description']); ?></p>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="materials">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Materials</span>
              <h2>Learning materials</h2>
            </div>
          </div>
          <?php if (!$materials): ?>
            <div class="empty-state compact"><i class="fa-solid fa-folder-open"></i><p>No materials yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <?php foreach ($materials as $material): ?>
                <?php $materialLink = $material['external_url'] ?: $material['file_path']; ?>
                <article class="learner-course-list-item">
                  <i class="fa-solid <?php echo $material['material_type'] === 'youtube' ? 'fa-circle-play' : ($material['material_type'] === 'link' ? 'fa-link' : 'fa-file-lines'); ?>"></i>
                  <div>
                    <span><?php echo e($material['topic_name'] ?: 'Unassigned'); ?></span>
                    <h3><?php echo e($material['title']); ?></h3>
                    <?php if (!empty($material['description'])): ?>
                      <p><?php echo e($material['description']); ?></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($materialLink)): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo e($materialLink); ?>" target="_blank" rel="noopener">
                      Open
                    </a>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="quizzes">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Quizzes</span>
              <h2>Available quizzes</h2>
            </div>
          </div>
          <?php if (!$quizzes): ?>
            <div class="empty-state compact"><i class="fa-solid fa-circle-question"></i><p>No quizzes yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <?php foreach ($quizzes as $quiz): ?>
                <article class="learner-course-list-item">
                  <i class="fa-solid fa-circle-question"></i>
                  <div>
                    <span><?php echo e($quiz['topic_name'] ?: 'Unassigned'); ?></span>
                    <h3><?php echo e($quiz['title']); ?></h3>
                    <?php if (!empty($quiz['description'])): ?>
                      <p><?php echo e($quiz['description']); ?></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($quiz['timer_minutes'])): ?>
                    <strong><?php echo (int) $quiz['timer_minutes']; ?> min</strong>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="assignments">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Assignments</span>
              <h2>Available assignments</h2>
            </div>
          </div>
          <?php if (!$assignments): ?>
            <div class="empty-state compact"><i class="fa-solid fa-file-pen"></i><p>No assignments yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <?php foreach ($assignments as $assignment): ?>
                <article class="learner-course-list-item">
                  <i class="fa-solid fa-file-pen"></i>
                  <div>
                    <span><?php echo e($assignment['topic_name'] ?: 'Unassigned'); ?></span>
                    <h3><?php echo e($assignment['title']); ?></h3>
                    <?php if (!empty($assignment['instructions'])): ?>
                      <p><?php echo e($assignment['instructions']); ?></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($assignment['due_date'])): ?>
                    <strong><?php echo e(date('M d, Y', strtotime($assignment['due_date']))); ?></strong>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="grades">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Grades</span>
              <h2>Grade items</h2>
            </div>
          </div>
          <?php if (!$gradeItems): ?>
            <div class="empty-state compact"><i class="fa-solid fa-star"></i><p>No grade items yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <?php foreach ($gradeItems as $gradeItem): ?>
                <article class="learner-course-list-item">
                  <i class="fa-solid fa-star"></i>
                  <div>
                    <span><?php echo e($gradeItem['topic_name'] ?: 'Unassigned'); ?></span>
                    <h3><?php echo e($gradeItem['task_title']); ?></h3>
                    <?php if (!empty($gradeItem['description'])): ?>
                      <p><?php echo e($gradeItem['description']); ?></p>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($gradeItem['task_date'])): ?>
                    <strong><?php echo e(date('M d, Y', strtotime($gradeItem['task_date']))); ?></strong>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="evaluation">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Evaluation</span>
              <h2><?php echo e(kiwiEvaluationFormTitle($course)); ?></h2>
            </div>
          </div>
          <?php if (!$evaluationReady): ?>
            <div class="empty-state compact"><i class="fa-solid fa-clipboard-check"></i><p>Evaluation form is not ready yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <article class="learner-course-list-item">
                <i class="fa-solid fa-clipboard-check"></i>
                <div>
                  <span><?php echo e((string) ($course['class_type'] ?? 'Course')); ?></span>
                  <h3><?php echo e((string) ($course['seminar_title'] ?? $course['course_name'])); ?></h3>
                  <p><?php echo $evaluationSubmitted ? 'Your response has been submitted. You may update it anytime.' : 'Share your feedback using the evaluation form.'; ?></p>
                </div>
                <strong><?php echo $evaluationSubmitted ? 'Submitted' : 'Pending'; ?></strong>
                <a class="btn btn-sm btn-primary" href="learner_evaluation.php?course_id=<?php echo $courseId; ?>">
                  <?php echo $evaluationSubmitted ? 'Update Evaluation' : 'Answer Evaluation'; ?>
                </a>
              </article>
            </div>
          <?php endif; ?>
        </section>

        <section class="learner-course-section" id="classmates">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Classmates</span>
              <h2>Approved classmates</h2>
            </div>
          </div>
          <?php if (!$classmates): ?>
            <div class="empty-state compact"><i class="fa-solid fa-users"></i><p>No classmates approved yet.</p></div>
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
        </section>

        <section class="learner-course-section" id="certificates">
          <div class="section-heading-row">
            <div>
              <span class="section-kicker">Certificates</span>
              <h2>Course certificate</h2>
            </div>
          </div>
          <?php if (!$certificateReady): ?>
            <div class="empty-state compact"><i class="fa-solid fa-award"></i><p>No certificate is available yet.</p></div>
          <?php else: ?>
            <div class="learner-course-list">
              <article class="learner-course-list-item">
                <i class="fa-solid fa-award"></i>
                <div>
                  <span><?php echo e($course['course_code']); ?></span>
                  <h3><?php echo e($course['course_name']); ?></h3>
                  <p>Your certificate is ready to preview or download.</p>
                </div>
                <a class="btn btn-sm btn-primary" href="certificate.php?class_id=<?php echo $classId; ?>&learner_id=<?php echo (int) $learner['id']; ?>" target="_blank" rel="noopener">
                  View
                </a>
              </article>
            </div>
          <?php endif; ?>
        </section>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260713-learner-dashboard-nav"></script>
</body>
</html>
