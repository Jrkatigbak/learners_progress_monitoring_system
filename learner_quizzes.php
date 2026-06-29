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

// Reusable escaping helper for learner quiz screens.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function learnerCanOpenQuiz(PDO $pdo, int $learnerId, int $quizId): ?array
{
    // Learners can open quizzes from their direct class or from approved class enrollment.
    $statement = $pdo->prepare(
        "SELECT class_quizzes.*, classes.class_name, classes.id AS class_id
         FROM class_quizzes
         INNER JOIN classes ON classes.id = class_quizzes.class_id AND classes.deleted_at IS NULL
         INNER JOIN learners ON learners.id = :learner_id AND learners.deleted_at IS NULL
         LEFT JOIN courses ON courses.course_code = CONCAT('CLASS-', classes.id) AND courses.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = learners.id
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         WHERE class_quizzes.id = :quiz_id
           AND class_quizzes.status = 'Active'
           AND class_quizzes.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         LIMIT 1"
    );
    $statement->execute([
        'learner_id' => $learnerId,
        'quiz_id' => $quizId,
    ]);

    $quiz = $statement->fetch();

    return $quiz ?: null;
}

$success = $_GET['success'] ?? '';
$errors = [];
$quizId = (int) ($_GET['quiz_id'] ?? $_POST['quiz_id'] ?? 0);

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
$activeQuiz = null;
$questions = [];
$existingAttempt = null;

if ($learner && $quizId > 0) {
    $activeQuiz = learnerCanOpenQuiz($pdo, (int) $learner['id'], $quizId);

    if ($activeQuiz) {
        $questionStatement = $pdo->prepare(
            'SELECT quiz_questions.*
             FROM quiz_questions
             WHERE quiz_questions.quiz_id = :quiz_id
               AND quiz_questions.deleted_at IS NULL
             ORDER BY quiz_questions.position, quiz_questions.id'
        );
        $questionStatement->execute(['quiz_id' => $quizId]);
        $questions = $questionStatement->fetchAll();

        if ($questions) {
            $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $questions);
            $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
            $choiceStatement = $pdo->prepare(
                "SELECT *
                 FROM quiz_choices
                 WHERE question_id IN ({$placeholders})
                   AND deleted_at IS NULL
                 ORDER BY position, id"
            );
            $choiceStatement->execute($questionIds);
            $choicesByQuestion = [];

            foreach ($choiceStatement->fetchAll() as $choice) {
                $choicesByQuestion[(int) $choice['question_id']][] = $choice;
            }

            foreach ($questions as $index => $question) {
                $questions[$index]['choices'] = $choicesByQuestion[(int) $question['id']] ?? [];
            }
        }

        $attemptStatement = $pdo->prepare('SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1');
        $attemptStatement->execute([
            'quiz_id' => $quizId,
            'learner_id' => (int) $learner['id'],
        ]);
        $existingAttempt = $attemptStatement->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_quiz') {
    if (!$learner) {
        $errors[] = 'Your login is not linked to a learner profile yet.';
    }

    if (!$activeQuiz) {
        $errors[] = 'Choose a valid quiz.';
    }

    if ($existingAttempt) {
        $errors[] = 'This quiz was already submitted.';
    }

    if (!$questions) {
        $errors[] = 'This quiz has no questions yet.';
    }

    if (!$errors) {
        $answers = $_POST['answers'] ?? [];
        $startedAt = (int) ($_POST['started_at'] ?? time());
        $correctByQuestion = [];
        $choiceQuestionMap = [];

        foreach ($questions as $question) {
            foreach ($question['choices'] as $choice) {
                $choiceQuestionMap[(int) $choice['id']] = (int) $question['id'];

                if ((int) $choice['is_correct'] === 1) {
                    $correctByQuestion[(int) $question['id']] = (int) $choice['id'];
                }
            }
        }

        $correctCount = 0;
        $totalQuestions = count($questions);

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $selectedChoiceId = (int) ($answers[$questionId] ?? 0);

            if ($selectedChoiceId > 0
                && ($choiceQuestionMap[$selectedChoiceId] ?? 0) === $questionId
                && ($correctByQuestion[$questionId] ?? 0) === $selectedChoiceId) {
                $correctCount++;
            }
        }

        $score = $totalQuestions > 0 ? round(($correctCount / $totalQuestions) * 100, 2) : 0;

        $pdo->beginTransaction();

        // Quiz attempts are stored once per learner so the first computed grade remains the official score.
        $attemptStatement = $pdo->prepare(
            'INSERT INTO quiz_attempts
                (quiz_id, learner_id, score, total_questions, correct_answers, started_at, submitted_at)
             VALUES
                (:quiz_id, :learner_id, :score, :total_questions, :correct_answers, :started_at, :submitted_at)'
        );
        $attemptStatement->execute([
            'quiz_id' => (int) $activeQuiz['id'],
            'learner_id' => (int) $learner['id'],
            'score' => $score,
            'total_questions' => $totalQuestions,
            'correct_answers' => $correctCount,
            'started_at' => date('Y-m-d H:i:s', $startedAt),
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);
        $attemptId = (int) $pdo->lastInsertId();

        $answerStatement = $pdo->prepare(
            'INSERT INTO quiz_attempt_answers (attempt_id, question_id, choice_id, is_correct)
             VALUES (:attempt_id, :question_id, :choice_id, :is_correct)'
        );

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $selectedChoiceId = (int) ($answers[$questionId] ?? 0);
            $isCorrect = $selectedChoiceId > 0 && ($correctByQuestion[$questionId] ?? 0) === $selectedChoiceId;

            $answerStatement->execute([
                'attempt_id' => $attemptId,
                'question_id' => $questionId,
                'choice_id' => $selectedChoiceId > 0 ? $selectedChoiceId : null,
                'is_correct' => $isCorrect ? 1 : 0,
            ]);
        }

        $gradeStatement = $pdo->prepare(
            'INSERT INTO learner_grades
                (task_id, learner_id, class_id, teacher_id, grade_title, score, max_score, remarks, graded_at, created_by_user_id)
             VALUES
                (NULL, :learner_id, :class_id, NULL, :grade_title, :score, 100, :remarks, :graded_at, :created_by_user_id)'
        );
        $gradeStatement->execute([
            'learner_id' => (int) $learner['id'],
            'class_id' => (int) $activeQuiz['class_id'],
            'grade_title' => 'Quiz: ' . $activeQuiz['title'],
            'score' => $score,
            'remarks' => $correctCount . ' of ' . $totalQuestions . ' correct',
            'graded_at' => date('Y-m-d'),
            'created_by_user_id' => (int) $currentUser['id'],
        ]);

        $pdo->commit();

        header('Location: learner_quizzes.php?quiz_id=' . (int) $activeQuiz['id'] . '&success=submitted');
        exit;
    }
}

$quizRows = [];
if ($learner && !$activeQuiz) {
    $quizListStatement = $pdo->prepare(
        "SELECT class_quizzes.*,
                classes.class_name,
                quiz_attempts.score,
                quiz_attempts.correct_answers,
                quiz_attempts.total_questions,
                quiz_attempts.submitted_at,
                COUNT(quiz_questions.id) AS question_count
         FROM class_quizzes
         INNER JOIN classes ON classes.id = class_quizzes.class_id AND classes.deleted_at IS NULL
         INNER JOIN learners ON learners.id = :learner_id AND learners.deleted_at IS NULL
         LEFT JOIN courses ON courses.course_code = CONCAT('CLASS-', classes.id) AND courses.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = learners.id
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         LEFT JOIN quiz_attempts
           ON quiz_attempts.quiz_id = class_quizzes.id
          AND quiz_attempts.learner_id = learners.id
          AND quiz_attempts.deleted_at IS NULL
         LEFT JOIN quiz_questions ON quiz_questions.quiz_id = class_quizzes.id AND quiz_questions.deleted_at IS NULL
         WHERE class_quizzes.status = 'Active'
           AND class_quizzes.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         GROUP BY class_quizzes.id, quiz_attempts.id
         ORDER BY class_quizzes.created_at DESC, class_quizzes.id DESC"
    );
    $quizListStatement->execute(['learner_id' => (int) $learner['id']]);
    $quizRows = $quizListStatement->fetchAll();
}

$successMessages = [
    'submitted' => 'Quiz submitted and grade computed.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Quizzes</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-class-role-permissions" rel="stylesheet">
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
        <a class="active" href="learner_quizzes.php"><i class="fa-solid fa-circle-question"></i> Quizzes</a>
        <a href="learner_assignments.php"><i class="fa-solid fa-file-pen"></i> Assignments</a>
        <a href="learner_grades.php"><i class="fa-solid fa-star"></i> Grades</a>
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
          <h1 class="h3 mb-0">Quizzes</h1>
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
        <?php elseif ($activeQuiz): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker"><?php echo e($activeQuiz['class_name']); ?></span>
                <h2 class="h5 mb-0"><?php echo e($activeQuiz['title']); ?></h2>
              </div>
              <a href="learner_quizzes.php" class="btn btn-sm btn-outline-secondary">Back to Quizzes</a>
            </div>

            <?php if ($existingAttempt): ?>
              <div class="quiz-result-card">
                <span class="metric-icon bg-success-subtle text-success"><i class="fa-solid fa-check"></i></span>
                <h3><?php echo e(number_format((float) $existingAttempt['score'], 2)); ?>%</h3>
                <p><?php echo (int) $existingAttempt['correct_answers']; ?> of <?php echo (int) $existingAttempt['total_questions']; ?> correct</p>
                <small class="text-secondary">Submitted <?php echo e(date('M d, Y h:i A', strtotime($existingAttempt['submitted_at']))); ?></small>
              </div>
            <?php elseif (!$questions): ?>
              <div class="empty-state">
                <i class="fa-solid fa-circle-question"></i>
                <p>This quiz has no questions yet.</p>
              </div>
            <?php else: ?>
              <div class="quiz-timer-bar mb-4">
                <span><i class="fa-regular fa-clock me-2"></i>Time remaining</span>
                <strong id="quizTimer" data-minutes="<?php echo (int) $activeQuiz['timer_minutes']; ?>">--:--</strong>
              </div>
              <form method="post" id="quizTakeForm" class="module-form">
                <input type="hidden" name="action" value="submit_quiz">
                <input type="hidden" name="quiz_id" value="<?php echo (int) $activeQuiz['id']; ?>">
                <input type="hidden" name="started_at" value="<?php echo time(); ?>">
                <?php foreach ($questions as $questionIndex => $question): ?>
                  <div class="quiz-take-question">
                    <h3><?php echo ($questionIndex + 1) . '. ' . e($question['question_text']); ?></h3>
                    <div class="quiz-choice-list">
                      <?php foreach ($question['choices'] as $choice): ?>
                        <label class="quiz-choice">
                          <input type="radio" name="answers[<?php echo (int) $question['id']; ?>]" value="<?php echo (int) $choice['id']; ?>">
                          <span><?php echo e($choice['choice_text']); ?></span>
                        </label>
                      <?php endforeach; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary">
                  <i class="fa-solid fa-paper-plane me-2"></i>Submit Quiz
                </button>
              </form>
            <?php endif; ?>
          </div>
        <?php elseif (!$quizRows): ?>
          <div class="empty-state">
            <i class="fa-solid fa-circle-question"></i>
            <p>No quizzes are available yet.</p>
          </div>
        <?php else: ?>
          <div class="quiz-card-grid">
            <?php foreach ($quizRows as $quiz): ?>
              <article class="quiz-card">
                <div class="quiz-card-icon">
                  <i class="fa-solid fa-circle-question"></i>
                </div>
                <div class="quiz-card-body">
                  <span class="material-type"><?php echo e($quiz['class_name']); ?></span>
                  <h3><?php echo e($quiz['title']); ?></h3>
                  <?php if (!empty($quiz['description'])): ?>
                    <p><?php echo e($quiz['description']); ?></p>
                  <?php endif; ?>
                  <div class="quiz-stats">
                    <span><strong><?php echo (int) $quiz['question_count']; ?></strong> questions</span>
                    <span><strong><?php echo (int) $quiz['timer_minutes']; ?></strong> min</span>
                    <?php if ($quiz['submitted_at']): ?>
                      <span><strong><?php echo e(number_format((float) $quiz['score'], 1)); ?>%</strong> score</span>
                    <?php endif; ?>
                  </div>
                  <a class="btn btn-sm <?php echo $quiz['submitted_at'] ? 'btn-outline-secondary' : 'btn-primary'; ?> mt-3" href="learner_quizzes.php?quiz_id=<?php echo (int) $quiz['id']; ?>">
                    <?php echo $quiz['submitted_at'] ? 'View Result' : 'Take Quiz'; ?>
                  </a>
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
  <script>
    (function () {
      var timer = document.getElementById('quizTimer');
      var form = document.getElementById('quizTakeForm');

      if (!timer || !form) {
        return;
      }

      var remaining = parseInt(timer.dataset.minutes, 10) * 60;

      function renderTimer() {
        var minutes = Math.floor(remaining / 60);
        var seconds = remaining % 60;
        timer.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');

        if (remaining <= 0) {
          form.submit();
          return;
        }

        remaining--;
        window.setTimeout(renderTimer, 1000);
      }

      renderTimer();
    })();
  </script>
  <script src="js/app.js?v=20260629-class-role-permissions"></script>
</body>
</html>
