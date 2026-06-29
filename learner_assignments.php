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

// Reusable escaping helper for learner assignment pages.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function learnerCanOpenAssignment(PDO $pdo, int $learnerId, int $assignmentId): ?array
{
    // Learners can open assignments from their direct class or approved class enrollment.
    $statement = $pdo->prepare(
        "SELECT class_assignments.*, classes.class_name, classes.id AS class_id
         FROM class_assignments
         INNER JOIN classes ON classes.id = class_assignments.class_id AND classes.deleted_at IS NULL
         INNER JOIN learners ON learners.id = :learner_id AND learners.deleted_at IS NULL
         LEFT JOIN courses ON courses.course_code = CONCAT('CLASS-', classes.id) AND courses.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = learners.id
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         WHERE class_assignments.id = :assignment_id
           AND class_assignments.status = 'Active'
           AND class_assignments.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         LIMIT 1"
    );
    $statement->execute([
        'learner_id' => $learnerId,
        'assignment_id' => $assignmentId,
    ]);

    $assignment = $statement->fetch();

    return $assignment ?: null;
}

function deleteSubmissionFile(string $filePath): void
{
    if ($filePath === '' || strpos($filePath, 'uploads/submissions/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $filePath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

$success = $_GET['success'] ?? '';
$errors = [];
$assignmentId = (int) ($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
$submissionDirectory = __DIR__ . '/uploads/submissions';
$submissionPathPrefix = 'uploads/submissions/';

if (!is_dir($submissionDirectory)) {
    // Learner submissions live separately from teacher assignment attachments.
    mkdir($submissionDirectory, 0777, true);
}

if (is_dir($submissionDirectory)) {
    chmod($submissionDirectory, 0777);
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
$activeAssignment = null;
$existingSubmission = null;

if ($learner && $assignmentId > 0) {
    $activeAssignment = learnerCanOpenAssignment($pdo, (int) $learner['id'], $assignmentId);

    if ($activeAssignment) {
        $submissionStatement = $pdo->prepare('SELECT * FROM assignment_submissions WHERE assignment_id = :assignment_id AND learner_id = :learner_id AND deleted_at IS NULL LIMIT 1');
        $submissionStatement->execute([
            'assignment_id' => (int) $activeAssignment['id'],
            'learner_id' => (int) $learner['id'],
        ]);
        $existingSubmission = $submissionStatement->fetch() ?: null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_assignment') {
    $submissionText = trim($_POST['submission_text'] ?? '');
    $filePath = $existingSubmission['file_path'] ?? null;
    $originalFilename = $existingSubmission['original_filename'] ?? null;

    if (!$learner) {
        $errors[] = 'Your login is not linked to a learner profile yet.';
    }

    if (!$activeAssignment) {
        $errors[] = 'Choose a valid assignment.';
    }

    $hasSubmissionUpload = isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] !== UPLOAD_ERR_NO_FILE;
    if ($submissionText === '' && !$hasSubmissionUpload && empty($filePath)) {
        $errors[] = 'Add a written answer or upload your assignment file.';
    }

    if ($hasSubmissionUpload && !$errors) {
        if ($_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Submission file could not be uploaded.';
        } elseif (!is_writable($submissionDirectory)) {
            $errors[] = 'Submission upload folder is not writable.';
        } else {
            $originalFilename = basename((string) $_FILES['submission_file']['name']);
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            $blockedExtensions = ['php', 'phtml', 'phar', 'cgi', 'pl', 'sh'];

            if (in_array($extension, $blockedExtensions, true)) {
                $errors[] = 'This file type is not allowed for submissions.';
            } else {
                $safeExtension = $extension !== '' ? $extension : 'bin';
                $filename = 'submission-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $safeExtension;
                $targetPath = $submissionDirectory . '/' . $filename;

                if (move_uploaded_file($_FILES['submission_file']['tmp_name'], $targetPath)) {
                    if (!empty($filePath)) {
                        deleteSubmissionFile((string) $filePath);
                    }

                    $filePath = $submissionPathPrefix . $filename;
                } else {
                    $errors[] = 'Submission file could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        // One row per learner/assignment is updated when the learner resubmits.
        $submissionStatement = $pdo->prepare(
            'INSERT INTO assignment_submissions
                (assignment_id, learner_id, submission_text, file_path, original_filename, submitted_at)
             VALUES
                (:assignment_id, :learner_id, :submission_text, :file_path, :original_filename, :submitted_at)
             ON DUPLICATE KEY UPDATE
                submission_text = VALUES(submission_text),
                file_path = VALUES(file_path),
                original_filename = VALUES(original_filename),
                submitted_at = VALUES(submitted_at)'
        );
        $submissionStatement->execute([
            'assignment_id' => (int) $activeAssignment['id'],
            'learner_id' => (int) $learner['id'],
            'submission_text' => $submissionText !== '' ? $submissionText : null,
            'file_path' => $filePath,
            'original_filename' => $originalFilename,
            'submitted_at' => date('Y-m-d H:i:s'),
        ]);

        header('Location: learner_assignments.php?assignment_id=' . (int) $activeAssignment['id'] . '&success=submitted');
        exit;
    }
}

$assignmentRows = [];
if ($learner && !$activeAssignment) {
    $assignmentListStatement = $pdo->prepare(
        "SELECT class_assignments.*,
                classes.class_name,
                assignment_submissions.submitted_at
         FROM class_assignments
         INNER JOIN classes ON classes.id = class_assignments.class_id AND classes.deleted_at IS NULL
         INNER JOIN learners ON learners.id = :learner_id AND learners.deleted_at IS NULL
         LEFT JOIN courses ON courses.course_code = CONCAT('CLASS-', classes.id) AND courses.deleted_at IS NULL
         LEFT JOIN course_enrollments
           ON course_enrollments.course_id = courses.id
          AND course_enrollments.learner_id = learners.id
          AND course_enrollments.enrollment_status IN ('Enrolled', 'In Progress', 'Completed')
          AND course_enrollments.deleted_at IS NULL
         LEFT JOIN assignment_submissions
           ON assignment_submissions.assignment_id = class_assignments.id
          AND assignment_submissions.learner_id = learners.id
          AND assignment_submissions.deleted_at IS NULL
         WHERE class_assignments.status = 'Active'
           AND class_assignments.deleted_at IS NULL
           AND (
             learners.class_id = classes.id
             OR course_enrollments.id IS NOT NULL
           )
         ORDER BY class_assignments.due_date IS NULL, class_assignments.due_date ASC, class_assignments.created_at DESC"
    );
    $assignmentListStatement->execute(['learner_id' => (int) $learner['id']]);
    $assignmentRows = $assignmentListStatement->fetchAll();
}

$successMessages = [
    'submitted' => 'Assignment submitted successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Assignments</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-grade-score-autosave" rel="stylesheet">
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
        <a class="active" href="learner_assignments.php"><i class="fa-solid fa-file-pen"></i> Assignments</a>
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
          <h1 class="h3 mb-0">Assignments</h1>
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
        <?php elseif ($activeAssignment): ?>
          <div class="panel-card">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
              <div>
                <span class="section-kicker"><?php echo e($activeAssignment['class_name']); ?></span>
                <h2 class="h5 mb-0"><?php echo e($activeAssignment['title']); ?></h2>
              </div>
              <a href="learner_assignments.php" class="btn btn-sm btn-outline-secondary">Back to Assignments</a>
            </div>

            <div class="assignment-detail">
              <?php if (!empty($activeAssignment['due_date'])): ?>
                <span class="badge text-bg-warning mb-3">Due <?php echo e(date('M d, Y', strtotime($activeAssignment['due_date']))); ?></span>
              <?php endif; ?>
              <?php if (!empty($activeAssignment['instructions'])): ?>
                <p><?php echo nl2br(e($activeAssignment['instructions'])); ?></p>
              <?php endif; ?>
              <?php if (!empty($activeAssignment['attachment_path'])): ?>
                <a class="btn btn-sm btn-outline-primary" href="<?php echo e($activeAssignment['attachment_path']); ?>" target="_blank" rel="noopener">
                  <i class="fa-solid fa-download me-2"></i>Open Assignment Attachment
                </a>
              <?php endif; ?>
            </div>

            <?php if ($existingSubmission): ?>
              <div class="alert alert-success mt-4">
                Submitted <?php echo e(date('M d, Y h:i A', strtotime($existingSubmission['submitted_at']))); ?>. You can resubmit if you need to update your work.
              </div>
            <?php endif; ?>

            <form method="post" enctype="multipart/form-data" class="module-form mt-4">
              <input type="hidden" name="action" value="submit_assignment">
              <input type="hidden" name="assignment_id" value="<?php echo (int) $activeAssignment['id']; ?>">
              <div class="mb-3">
                <label class="form-label" for="submission_text">Your answer</label>
                <textarea class="form-control" id="submission_text" name="submission_text" rows="6" placeholder="Type your answer or notes here."><?php echo e((string) ($existingSubmission['submission_text'] ?? '')); ?></textarea>
              </div>
              <div class="mb-3">
                <label class="form-label" for="submission_file">Upload file</label>
                <input type="file" class="form-control" id="submission_file" name="submission_file">
                <?php if (!empty($existingSubmission['file_path'])): ?>
                  <div class="form-text">
                    Current file:
                    <a href="<?php echo e($existingSubmission['file_path']); ?>" target="_blank" rel="noopener"><?php echo e($existingSubmission['original_filename'] ?: 'Open file'); ?></a>
                  </div>
                <?php endif; ?>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-paper-plane me-2"></i><?php echo $existingSubmission ? 'Resubmit Assignment' : 'Submit Assignment'; ?>
              </button>
            </form>
          </div>
        <?php elseif (!$assignmentRows): ?>
          <div class="empty-state">
            <i class="fa-solid fa-file-pen"></i>
            <p>No assignments are available yet.</p>
          </div>
        <?php else: ?>
          <div class="quiz-card-grid">
            <?php foreach ($assignmentRows as $assignment): ?>
              <article class="quiz-card assignment-card">
                <div class="quiz-card-icon">
                  <i class="fa-solid fa-file-pen"></i>
                </div>
                <div class="quiz-card-body">
                  <span class="material-type"><?php echo e($assignment['class_name']); ?></span>
                  <h3><?php echo e($assignment['title']); ?></h3>
                  <?php if (!empty($assignment['instructions'])): ?>
                    <p><?php echo e($assignment['instructions']); ?></p>
                  <?php endif; ?>
                  <div class="quiz-stats">
                    <span><?php echo !empty($assignment['due_date']) ? 'Due ' . e(date('M d, Y', strtotime($assignment['due_date']))) : 'No due date'; ?></span>
                    <?php if ($assignment['submitted_at']): ?>
                      <span><strong>Submitted</strong></span>
                    <?php endif; ?>
                  </div>
                  <a class="btn btn-sm <?php echo $assignment['submitted_at'] ? 'btn-outline-secondary' : 'btn-primary'; ?> mt-3" href="learner_assignments.php?assignment_id=<?php echo (int) $assignment['id']; ?>">
                    <?php echo $assignment['submitted_at'] ? 'View Submission' : 'Open Assignment'; ?>
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
  <script src="js/app.js?v=20260629-grade-score-autosave"></script>
</body>
</html>
