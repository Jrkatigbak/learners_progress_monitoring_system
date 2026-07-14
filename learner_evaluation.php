<?php

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/evaluations.php';
require_once __DIR__ . '/includes/learner_course_sidebar.php';

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

$errors = [];
$success = $_GET['success'] ?? '';
$courseId = max(0, (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0));
$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, last_name, email, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;
$course = null;

if ($learner && $courseId > 0) {
    $courseStatement = $pdo->prepare(
        "SELECT courses.id AS course_id,
                courses.course_name,
                courses.description,
                COALESCE(NULLIF(courses.banner_image, ''), classes.banner_image) AS banner_image,
                classes.*
         FROM course_enrollments
         INNER JOIN courses ON courses.id = course_enrollments.course_id AND courses.deleted_at IS NULL
         INNER JOIN classes ON courses.course_code = CONCAT('CLASS-', classes.id) AND classes.deleted_at IS NULL
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

$classId = (int) $course['id'];
$learnerName = trim($learner['first_name'] . ' ' . $learner['last_name']);
$learnerInitials = strtoupper(substr($learnerName, 0, 1));
$learnerCourseContext = kiwiLearnerCourseContext($pdo, (int) $learner['id'], $courseId);
$evaluationColumns = kiwiClassEvaluationColumns($pdo);
$evaluationColumnsReady = kiwiClassEvaluationColumnsReady($evaluationColumns);
$evaluationTableReady = kiwiClassEvaluationsTableReady($pdo);
$evaluationEnabled = kiwiEvaluationEnabled($course, $evaluationColumns);
$ratingSections = kiwiEvaluationRatingItems();
$existingEvaluation = null;

if (!$evaluationColumnsReady || !$evaluationTableReady || !$evaluationEnabled) {
    http_response_code(403);
    $disabledMessage = !$evaluationEnabled
        ? 'Evaluation form is currently disabled for this class.'
        : 'Evaluation form is not ready yet.';
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Evaluation unavailable</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260714-evaluation-access" rel="stylesheet">
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
      <?php kiwiRenderLearnerCourseSidebar($learnerCourseContext, $learnerName, 'evaluation', (int) $learner['id']); ?>
    </aside>
    <main class="main-panel">
      <header class="topbar">
        <div>
          <span class="section-kicker">Class Portal</span>
          <h1 class="h3 mb-0">Evaluation unavailable</h1>
        </div>
      </header>
      <section class="content-shell">
        <div class="empty-state">
          <i class="fa-solid fa-clipboard-check"></i>
          <p><?php echo e($disabledMessage); ?></p>
        </div>
      </section>
    </main>
  </div>
</body>
</html>
    <?php
    exit;
}

if ($evaluationTableReady) {
    $existingStatement = $pdo->prepare('SELECT * FROM class_evaluations WHERE class_id = :class_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1');
    $existingStatement->execute([
        'class_id' => $classId,
        'learner_id' => (int) $learner['id'],
    ]);
    $existingEvaluation = $existingStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$evaluationColumnsReady || !$evaluationTableReady) {
        $errors[] = 'Evaluation form is not ready yet.';
    }

    $ratings = [];
    foreach ($ratingSections as $section) {
        foreach ($section['items'] as $field => $label) {
            $value = (int) ($_POST[$field] ?? 0);
            if ($value < 1 || $value > 5) {
                $errors[] = 'Complete all rating items from 1 to 5.';
                break 2;
            }
            $ratings[$field] = $value;
        }
    }

    $overallRating = $_POST['overall_rating'] ?? '';
    $recommend = $_POST['recommend'] ?? '';
    $feedbackUseful = trim((string) ($_POST['feedback_useful'] ?? ''));
    $feedbackImprovements = trim((string) ($_POST['feedback_improvements'] ?? ''));
    $feedbackTopics = trim((string) ($_POST['feedback_topics'] ?? ''));
    $attendeeName = trim((string) ($_POST['attendee_name'] ?? ''));
    $attendeeEmail = trim((string) ($_POST['attendee_email'] ?? ''));

    if (!in_array($overallRating, ['Excellent', 'Good', 'Satisfactory', 'Unsatisfactory'], true)) {
        $errors[] = 'Choose an overall rating.';
    }

    if (!in_array($recommend, ['Yes', 'No', 'Maybe'], true)) {
        $errors[] = 'Choose a recommendation answer.';
    }

    if ($attendeeEmail !== '' && !filter_var($attendeeEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid optional attendee email.';
    }

    if (!$errors) {
        $existingId = (int) ($existingEvaluation['id'] ?? 0);
        $params = array_merge($ratings, [
            'class_id' => $classId,
            'learner_id' => (int) $learner['id'],
            'overall_rating' => $overallRating,
            'recommend' => $recommend,
            'feedback_useful' => $feedbackUseful !== '' ? $feedbackUseful : null,
            'feedback_improvements' => $feedbackImprovements !== '' ? $feedbackImprovements : null,
            'feedback_topics' => $feedbackTopics !== '' ? $feedbackTopics : null,
            'attendee_name' => $attendeeName !== '' ? $attendeeName : null,
            'attendee_email' => $attendeeEmail !== '' ? $attendeeEmail : null,
        ]);

        if ($existingId > 0) {
            $params['id'] = $existingId;
            $statement = $pdo->prepare(
                'UPDATE class_evaluations
                 SET content_objectives_clear = :content_objectives_clear,
                     content_relevant = :content_relevant,
                     content_organized = :content_organized,
                     content_depth = :content_depth,
                     presenter_knowledge = :presenter_knowledge,
                     presenter_style = :presenter_style,
                     presenter_questions = :presenter_questions,
                     presenter_pace = :presenter_pace,
                     logistics_venue = :logistics_venue,
                     logistics_technology = :logistics_technology,
                     logistics_registration = :logistics_registration,
                     logistics_materials = :logistics_materials,
                     overall_rating = :overall_rating,
                     recommend = :recommend,
                     feedback_useful = :feedback_useful,
                     feedback_improvements = :feedback_improvements,
                     feedback_topics = :feedback_topics,
                     attendee_name = :attendee_name,
                     attendee_email = :attendee_email,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $statement->execute($params);
        } else {
            $statement = $pdo->prepare(
                'INSERT INTO class_evaluations
                    (class_id, learner_id, content_objectives_clear, content_relevant, content_organized, content_depth,
                     presenter_knowledge, presenter_style, presenter_questions, presenter_pace,
                     logistics_venue, logistics_technology, logistics_registration, logistics_materials,
                     overall_rating, recommend, feedback_useful, feedback_improvements, feedback_topics, attendee_name, attendee_email)
                 VALUES
                    (:class_id, :learner_id, :content_objectives_clear, :content_relevant, :content_organized, :content_depth,
                     :presenter_knowledge, :presenter_style, :presenter_questions, :presenter_pace,
                     :logistics_venue, :logistics_technology, :logistics_registration, :logistics_materials,
                     :overall_rating, :recommend, :feedback_useful, :feedback_improvements, :feedback_topics, :attendee_name, :attendee_email)'
            );
            $statement->execute($params);
        }

        header('Location: learner_evaluation.php?course_id=' . $courseId . '&success=saved');
        exit;
    }
}

function postedOrExisting(string $field, ?array $existingEvaluation, string $fallback = ''): string
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return (string) ($_POST[$field] ?? $fallback);
    }

    return (string) ($existingEvaluation[$field] ?? $fallback);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Evaluation</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260714-evaluation-access" rel="stylesheet">
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
      <?php kiwiRenderLearnerCourseSidebar($learnerCourseContext, $learnerName, 'evaluation', (int) $learner['id']); ?>
    </aside>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Class Portal</p>
          <h1 class="h3 mb-0"><?php echo e(kiwiEvaluationFormTitle($course)); ?></h1>
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
        <a class="btn btn-sm btn-outline-secondary mb-3" href="learner_course.php?course_id=<?php echo $courseId; ?>">
          <i class="fa-solid fa-arrow-left me-2"></i>Back to Course
        </a>

        <?php if ($success === 'saved'): ?>
          <div class="alert alert-success" role="alert">Evaluation submitted successfully.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <?php if (!$evaluationColumnsReady || !$evaluationTableReady): ?>
          <div class="empty-state">
            <i class="fa-solid fa-clipboard-check"></i>
            <p>The evaluation form is not ready yet.</p>
          </div>
        <?php else: ?>
          <form method="post" class="evaluation-form-card">
            <input type="hidden" name="course_id" value="<?php echo $courseId; ?>">
            <div class="evaluation-form-head">
              <h2><?php echo e(kiwiEvaluationFormTitle($course)); ?></h2>
              <div class="evaluation-details">
                <p><strong><?php echo ($course['class_type'] ?? 'Course') === 'Seminar' ? 'Seminar Title' : 'Course Title'; ?>:</strong> <?php echo e((string) ($course['seminar_title'] ?? $course['course_name'])); ?></p>
                <p><strong><?php echo ($course['class_type'] ?? 'Course') === 'Seminar' ? 'Presenter/Speaker' : 'Teacher'; ?>:</strong> <?php echo e((string) ($course['seminar_presenter'] ?? '')); ?></p>
                <p><strong>Date:</strong> <?php echo !empty($course['seminar_date']) ? e(date('M d, Y', strtotime((string) $course['seminar_date']))) : 'N/A'; ?> <strong class="ms-3">Venue/Platform:</strong> <?php echo e((string) ($course['seminar_venue'] ?? 'N/A')); ?></p>
              </div>
              <p class="mb-0"><em>Thank you for participating. Your feedback helps us improve future events. Please rate each item by checking the number that best matches your experience.</em></p>
            </div>

            <div class="evaluation-scale">
              <strong>Rating Scale:</strong>
              <span>5 = Strongly Agree (Excellent)</span>
              <span>4 = Agree (Good)</span>
              <span>3 = Neutral (Satisfactory)</span>
              <span>2 = Disagree (Poor)</span>
              <span>1 = Strongly Disagree (Very Poor)</span>
            </div>

            <?php foreach ($ratingSections as $sectionIndex => $section): ?>
              <section class="evaluation-section">
                <h3><?php echo ((int) $sectionIndex + 1) . '. ' . e($section['title']); ?></h3>
                <div class="evaluation-rating-table">
                  <div class="evaluation-rating-row evaluation-rating-header">
                    <strong>Criteria</strong>
                    <span>5</span><span>4</span><span>3</span><span>2</span><span>1</span>
                  </div>
                  <?php foreach ($section['items'] as $field => $label): ?>
                    <div class="evaluation-rating-row">
                      <label><?php echo e($label); ?></label>
                      <?php for ($score = 5; $score >= 1; $score--): ?>
                        <input type="radio" name="<?php echo e($field); ?>" value="<?php echo $score; ?>" <?php echo postedOrExisting($field, $existingEvaluation) === (string) $score ? 'checked' : ''; ?> required>
                      <?php endfor; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              </section>
            <?php endforeach; ?>

            <section class="evaluation-section">
              <h3>4. Overall Assessment</h3>
              <div class="row g-4">
                <div class="col-md-6">
                  <label class="form-label">Overall, how would you rate this <?php echo ($course['class_type'] ?? 'Course') === 'Seminar' ? 'seminar' : 'course'; ?>?</label>
                  <?php foreach (['Excellent', 'Good', 'Satisfactory', 'Unsatisfactory'] as $option): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="overall_rating" id="overall_<?php echo e($option); ?>" value="<?php echo e($option); ?>" <?php echo postedOrExisting('overall_rating', $existingEvaluation) === $option ? 'checked' : ''; ?> required>
                      <label class="form-check-label" for="overall_<?php echo e($option); ?>"><?php echo e($option); ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="col-md-6">
                  <label class="form-label">Would you recommend this <?php echo ($course['class_type'] ?? 'Course') === 'Seminar' ? 'seminar' : 'course'; ?> to colleagues?</label>
                  <?php foreach (['Yes', 'No', 'Maybe'] as $option): ?>
                    <div class="form-check">
                      <input class="form-check-input" type="radio" name="recommend" id="recommend_<?php echo e($option); ?>" value="<?php echo e($option); ?>" <?php echo postedOrExisting('recommend', $existingEvaluation) === $option ? 'checked' : ''; ?> required>
                      <label class="form-check-label" for="recommend_<?php echo e($option); ?>"><?php echo e($option); ?></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </section>

            <section class="evaluation-section">
              <h3>5. Open-Ended Feedback</h3>
              <label class="form-label" for="feedback_useful">What did you find most useful or valuable?</label>
              <textarea class="form-control mb-3" id="feedback_useful" name="feedback_useful" rows="2"><?php echo e(postedOrExisting('feedback_useful', $existingEvaluation)); ?></textarea>
              <label class="form-label" for="feedback_improvements">What specific improvements could be made?</label>
              <textarea class="form-control mb-3" id="feedback_improvements" name="feedback_improvements" rows="2"><?php echo e(postedOrExisting('feedback_improvements', $existingEvaluation)); ?></textarea>
              <label class="form-label" for="feedback_topics">What related topics would you like covered in the future?</label>
              <textarea class="form-control" id="feedback_topics" name="feedback_topics" rows="2"><?php echo e(postedOrExisting('feedback_topics', $existingEvaluation)); ?></textarea>
            </section>

            <section class="evaluation-section">
              <h3>Optional Attendee Information</h3>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="attendee_name">Name</label>
                  <input type="text" class="form-control" id="attendee_name" name="attendee_name" value="<?php echo e(postedOrExisting('attendee_name', $existingEvaluation, $learnerName)); ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="attendee_email">Email</label>
                  <input type="email" class="form-control" id="attendee_email" name="attendee_email" value="<?php echo e(postedOrExisting('attendee_email', $existingEvaluation, (string) $learner['email'])); ?>">
                </div>
              </div>
            </section>

            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-paper-plane me-2"></i>Submit Evaluation
            </button>
          </form>
        <?php endif; ?>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="js/app.js?v=20260714-evaluation-access"></script>
</body>
</html>
