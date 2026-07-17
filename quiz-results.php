<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/permissions.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$quizId = (int) ($_GET['quiz_id'] ?? 0);

if ($auth->isLearner()) {
    header('Location: learner_dashboard.php');
    exit;
}

$quizStatement = $pdo->prepare(
    'SELECT class_quizzes.*,
            classes.id AS class_id,
            classes.class_name,
            class_material_folders.name AS topic_name
     FROM class_quizzes
     INNER JOIN classes ON classes.id = class_quizzes.class_id AND classes.deleted_at IS NULL
     LEFT JOIN class_material_folders ON class_material_folders.id = class_quizzes.folder_id AND class_material_folders.deleted_at IS NULL
     WHERE class_quizzes.id = :quiz_id
       AND class_quizzes.deleted_at IS NULL
     LIMIT 1'
);
$quizStatement->execute(['quiz_id' => $quizId]);
$quiz = $quizStatement->fetch() ?: null;

if (!$quiz) {
    http_response_code(404);
    echo 'Quiz not found.';
    exit;
}

$classId = (int) $quiz['class_id'];
$isSystemAdmin = (($currentUser['role'] ?? '') === 'admin');

if ($auth->isAdminSideUser()) {
    if (!$isSystemAdmin && !kiwiCan($pdo, 'class_quizzes.view')) {
        http_response_code(403);
        echo 'You do not have permission to view quiz results.';
        exit;
    }
} elseif ($auth->isTeacher()) {
    $teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE email = :email AND deleted_at IS NULL LIMIT 1');
    $teacherStatement->execute(['email' => $currentUser['email']]);
    $teacherId = (int) $teacherStatement->fetchColumn();

    $assignedStatement = $pdo->prepare(
        'SELECT classes.id
         FROM classes
         LEFT JOIN class_teachers ON class_teachers.class_id = classes.id AND class_teachers.deleted_at IS NULL
         WHERE classes.id = :class_id
           AND classes.deleted_at IS NULL
           AND (classes.teacher_id = :teacher_id OR class_teachers.teacher_id = :assigned_teacher_id)
         LIMIT 1'
    );
    $assignedStatement->execute([
        'class_id' => $classId,
        'teacher_id' => $teacherId,
        'assigned_teacher_id' => $teacherId,
    ]);

    if (!$teacherId || !$assignedStatement->fetch()) {
        http_response_code(403);
        echo 'You can only view quiz results for assigned classes.';
        exit;
    }
} else {
    http_response_code(403);
    echo 'You do not have permission to view quiz results.';
    exit;
}

$attemptStatement = $pdo->prepare(
    'SELECT quiz_attempts.*,
            learners.learner_number,
            learners.first_name,
            learners.last_name,
            learners.email
     FROM quiz_attempts
     INNER JOIN learners ON learners.id = quiz_attempts.learner_id AND learners.deleted_at IS NULL
     WHERE quiz_attempts.quiz_id = :quiz_id
       AND quiz_attempts.deleted_at IS NULL
     ORDER BY quiz_attempts.submitted_at DESC, quiz_attempts.id DESC'
);
$attemptStatement->execute(['quiz_id' => $quizId]);
$attempts = $attemptStatement->fetchAll();

$answerRowsByAttempt = [];
if ($attempts) {
    $answerStatement = $pdo->prepare(
        'SELECT quiz_attempts.id AS attempt_id,
                quiz_questions.id AS question_id,
                quiz_questions.question_text,
                quiz_questions.position AS question_position,
                selected_choice.choice_text AS selected_choice,
                correct_choice.choice_text AS correct_choice,
                quiz_attempt_answers.is_correct
         FROM quiz_attempts
         INNER JOIN quiz_questions
           ON quiz_questions.quiz_id = quiz_attempts.quiz_id
          AND quiz_questions.deleted_at IS NULL
         LEFT JOIN quiz_attempt_answers
           ON quiz_attempt_answers.attempt_id = quiz_attempts.id
          AND quiz_attempt_answers.question_id = quiz_questions.id
          AND quiz_attempt_answers.deleted_at IS NULL
         LEFT JOIN quiz_choices AS selected_choice
           ON selected_choice.id = quiz_attempt_answers.choice_id
          AND selected_choice.deleted_at IS NULL
         LEFT JOIN quiz_choices AS correct_choice
           ON correct_choice.question_id = quiz_questions.id
          AND correct_choice.is_correct = 1
          AND correct_choice.deleted_at IS NULL
         WHERE quiz_attempts.quiz_id = :quiz_id
           AND quiz_attempts.deleted_at IS NULL
         ORDER BY quiz_attempts.id DESC, quiz_questions.position, quiz_questions.id'
    );
    $answerStatement->execute(['quiz_id' => $quizId]);

    foreach ($answerStatement->fetchAll() as $row) {
        $attemptId = (int) $row['attempt_id'];
        $answerRowsByAttempt[$attemptId][] = $row;
    }
}

$averageScore = 0.0;
if ($attempts) {
    $averageScore = array_sum(array_map(static fn (array $attempt): float => (float) $attempt['score'], $attempts)) / count($attempts);
}

$userName = (string) ($currentUser['name'] ?? 'User');
$userInitial = strtoupper(substr($userName, 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | Quiz Results</title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260717-quiz-results" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <aside class="sidebar">
      <a class="sidebar-brand" href="<?php echo $auth->isTeacher() ? 'teacher_dashboard.php' : 'classes.php'; ?>">
        <img src="<?php echo e(kiwiSystemLogo()); ?>" alt="<?php echo e(kiwiSystemBrandName()); ?>" class="brand-logo">
        <span>
          <strong><?php echo e((string) $quiz['class_name']); ?></strong>
          <small>Quiz Results</small>
        </span>
      </a>
      <nav class="sidebar-nav">
        <a href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=dashboard"><i class="fa-solid fa-gauge-high"></i> Class Dashboard</a>
        <a class="active" href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=quizzes"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <a href="class_workspace.php?class_id=<?php echo $classId; ?>&tool=grades"><i class="fa-solid fa-star"></i> Grades</a>
        <a href="<?php echo $auth->isTeacher() ? 'teacher_dashboard.php' : 'classes.php'; ?>"><i class="fa-solid fa-arrow-left"></i> Back to Classes</a>
      </nav>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Quiz Results</p>
          <h1 class="h3 mb-0"><?php echo e((string) $quiz['title']); ?></h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e($userInitial !== '' ? $userInitial : 'U'); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($userName); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e((string) ($currentUser['email'] ?? '')); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <div class="panel-card">
          <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
            <div>
              <span class="section-kicker">Results</span>
              <h2 class="h5 mb-1"><?php echo e((string) $quiz['title']); ?></h2>
              <p class="text-secondary mb-0">
                <?php echo !empty($quiz['topic_name']) ? e((string) $quiz['topic_name']) . ' · ' : ''; ?>
                <?php echo count($attempts); ?> attempt<?php echo count($attempts) === 1 ? '' : 's'; ?>
              </p>
            </div>
            <div class="quiz-result-summary">
              <span><strong><?php echo count($attempts); ?></strong> attempts</span>
              <span><strong><?php echo number_format($averageScore, 1); ?></strong> average</span>
            </div>
          </div>

          <?php if (!$attempts): ?>
            <div class="empty-state">
              <i class="fa-solid fa-circle-question"></i>
              <p>No learner has submitted this quiz yet.</p>
            </div>
          <?php else: ?>
            <div class="table-responsive quiz-results-table-wrap">
              <table class="table align-middle quiz-results-table">
                <thead>
                  <tr>
                    <th>Learner</th>
                    <th>Score</th>
                    <th>Correct</th>
                    <th>Wrong</th>
                    <th>Submitted</th>
                    <th class="text-end">Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($attempts as $attempt): ?>
                    <?php
                      $attemptId = (int) $attempt['id'];
                      $learnerName = trim((string) $attempt['first_name'] . ' ' . (string) $attempt['last_name']);
                      $totalQuestions = (int) ($attempt['total_questions'] ?? 0);
                      $correctAnswers = (int) ($attempt['correct_answers'] ?? 0);
                      $wrongAnswers = max(0, $totalQuestions - $correctAnswers);
                      $collapseId = 'quizAttempt' . $attemptId;
                    ?>
                    <tr>
                      <td>
                        <strong><?php echo e($learnerName); ?></strong>
                        <span><?php echo e((string) $attempt['learner_number']); ?> · <?php echo e((string) $attempt['email']); ?></span>
                      </td>
                      <td><span class="badge text-bg-primary"><?php echo number_format((float) $attempt['score'], 2); ?></span></td>
                      <td><span class="badge text-bg-success"><?php echo $correctAnswers; ?></span></td>
                      <td><span class="badge text-bg-danger"><?php echo $wrongAnswers; ?></span></td>
                      <td><?php echo e(date('M d, Y g:i A', strtotime((string) $attempt['submitted_at']))); ?></td>
                      <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo e($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo e($collapseId); ?>">
                          Show / Hide
                        </button>
                      </td>
                    </tr>
                    <tr class="quiz-result-detail-row">
                      <td colspan="6">
                        <div class="collapse" id="<?php echo e($collapseId); ?>">
                          <div class="quiz-answer-detail-list">
                            <?php foreach ($answerRowsByAttempt[$attemptId] ?? [] as $answer): ?>
                              <?php $isCorrect = (int) ($answer['is_correct'] ?? 0) === 1; ?>
                              <article class="quiz-answer-detail <?php echo $isCorrect ? 'is-correct' : 'is-wrong'; ?>">
                                <div>
                                  <strong><?php echo e((string) $answer['question_text']); ?></strong>
                                  <p class="mb-1">Learner answer: <?php echo e((string) ($answer['selected_choice'] ?? 'No answer')); ?></p>
                                  <p class="mb-0">Correct answer: <?php echo e((string) ($answer['correct_choice'] ?? 'No answer key')); ?></p>
                                </div>
                                <span class="badge <?php echo $isCorrect ? 'text-bg-success' : 'text-bg-danger'; ?>">
                                  <?php echo $isCorrect ? 'Correct' : 'Wrong'; ?>
                                </span>
                              </article>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260717-quiz-results"></script>
</body>
</html>
