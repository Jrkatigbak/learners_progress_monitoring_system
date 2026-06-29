<?php

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/classes/SmtpMailer.php';
require_once __DIR__ . '/includes/enrollment_helpers.php';

$mailerConfig = require __DIR__ . '/config/Mailer.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$errors = [];
$success = false;
$emailStatus = '';
$class = null;

if ($token !== '') {
    $classStatement = $pdo->prepare('SELECT * FROM classes WHERE enrollment_token = :token AND status = "Active" AND deleted_at IS NULL LIMIT 1');
    $classStatement->execute(['token' => $token]);
    $class = $classStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $class) {
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($contactNumber === '') {
        $errors[] = 'Contact number is required.';
    }

    if (!$errors) {
        $existingStatement = $pdo->prepare(
            'SELECT status
             FROM class_enrollment_requests
             WHERE class_id = :class_id AND email = :email AND deleted_at IS NULL
             LIMIT 1'
        );
        $existingStatement->execute([
            'class_id' => (int) $class['id'],
            'email' => $email,
        ]);
        $existingStatus = (string) ($existingStatement->fetchColumn() ?: '');

        if ($existingStatus === 'Pending') {
            $errors[] = 'You are already registered. Please wait for administrator approval.';
        } elseif ($existingStatus === 'Approved') {
            $errors[] = 'This email is already approved for this class.';
        }

        if (!$errors) {
            $otherClassStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM class_enrollment_requests
                 WHERE email = :email
                   AND class_id <> :class_id
                   AND status IN ("Pending", "Approved")
                   AND deleted_at IS NULL'
            );
            $otherClassStatement->execute([
                'email' => $email,
                'class_id' => (int) $class['id'],
            ]);

            $assignedClassStatement = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM learners
                 LEFT JOIN course_enrollments
                    ON course_enrollments.learner_id = learners.id
                   AND course_enrollments.deleted_at IS NULL
                 WHERE learners.email = :email
                   AND learners.deleted_at IS NULL
                   AND (learners.class_id IS NOT NULL OR course_enrollments.id IS NOT NULL)'
            );
            $assignedClassStatement->execute(['email' => $email]);

            // Public enrollment is class-exclusive, so stop duplicate registrations early.
            if ((int) $otherClassStatement->fetchColumn() > 0 || (int) $assignedClassStatement->fetchColumn() > 0) {
                $errors[] = 'This email is already registered or enrolled in another class.';
            }
        }

        if (!$errors) {
            // Public registration creates a pending request only; admin approval handles learner account creation.
            $requestStatement = $pdo->prepare(
                'INSERT INTO class_enrollment_requests
                    (class_id, first_name, middle_name, last_name, email, contact_number, status, token)
                 VALUES
                    (:class_id, :first_name, :middle_name, :last_name, :email, :contact_number, "Pending", :token)
                 ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name),
                    middle_name = VALUES(middle_name),
                    last_name = VALUES(last_name),
                    contact_number = VALUES(contact_number),
                    status = "Pending",
                    learner_id = NULL,
                    reviewed_at = NULL,
                    reviewed_by_user_id = NULL'
            );
            $requestStatement->execute([
                'class_id' => (int) $class['id'],
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'email' => $email,
                'contact_number' => $contactNumber,
                'token' => kiwiGenerateUniqueToken($pdo, 'class_enrollment_requests', 'token'),
            ]);

            $mailer = new SmtpMailer($mailerConfig);
            $fullName = trim($firstName . ' ' . $middleName . ' ' . $lastName);
            $emailSent = kiwiSendEnrollmentReceivedEmail($mailer, $email, $fullName, (string) $class['class_name']);
            $emailStatus = $emailSent ? 'sent' : 'failed';
            $success = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Class Enrollment</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-grade-status-remarks" rel="stylesheet">
</head>
<body class="auth-page enrollment-public-page">
  <main class="enrollment-form-shell">
    <section class="enrollment-form-header">
      <div class="enrollment-form-banner">
        <?php if ($class && !empty($class['banner_image'])): ?>
          <img src="<?php echo e($class['banner_image']); ?>" alt="<?php echo e($class['class_name']); ?> wallpaper">
        <?php else: ?>
          <div class="enrollment-form-banner-placeholder">
            <i class="fa-solid fa-chalkboard-user"></i>
          </div>
        <?php endif; ?>
      </div>
      <div class="enrollment-form-title">
        <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo">
        <div>
          <p class="eyebrow">Class Enrollment</p>
          <h1><?php echo $class ? e($class['class_name']) : 'Enrollment link unavailable'; ?></h1>
          <p><?php echo $class ? 'Submit your registration details and wait for administrator approval.' : 'This enrollment link is invalid or no longer active.'; ?></p>
          <?php if ($class && !empty($class['description'])): ?>
            <p class="enrollment-class-description"><?php echo nl2br(e((string) $class['description'])); ?></p>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="auth-card enrollment-form-card">
      <div class="card border-0 shadow-lg">
        <div class="card-body p-4 p-md-5">
          <?php if (!$class): ?>
            <div class="empty-state">
              <i class="fa-solid fa-link-slash"></i>
              <p>Enrollment link is invalid or inactive.</p>
            </div>
          <?php elseif ($success): ?>
            <div class="empty-state">
              <i class="fa-solid fa-circle-check"></i>
              <p>Your registration was received. Please check your email and wait for administrator approval.</p>
              <?php if ($emailStatus === 'failed'): ?>
                <span class="text-secondary small">The confirmation email could not be sent, but your registration was saved.</span>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <div class="mb-4">
              <span class="section-kicker">Register</span>
              <h2 class="h3 fw-bold mb-1">Learner information</h2>
              <p class="text-secondary mb-0">Use an active email address for enrollment updates.</p>
            </div>

            <?php if ($errors): ?>
              <div class="alert alert-danger" role="alert">
                <?php foreach ($errors as $error): ?>
                  <div><?php echo e($error); ?></div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <form method="post" class="module-form">
              <input type="hidden" name="token" value="<?php echo e($token); ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label" for="first_name">First name</label>
                  <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e((string) ($_POST['first_name'] ?? '')); ?>" required>
                </div>
                <div class="col-md-6">
                  <label class="form-label" for="middle_name">Middle name</label>
                  <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo e((string) ($_POST['middle_name'] ?? '')); ?>">
                </div>
                <div class="col-12">
                  <label class="form-label" for="last_name">Last name</label>
                  <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e((string) ($_POST['last_name'] ?? '')); ?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label" for="email">Email</label>
                  <input type="email" class="form-control" id="email" name="email" value="<?php echo e((string) ($_POST['email'] ?? '')); ?>" required>
                </div>
                <div class="col-12">
                  <label class="form-label" for="contact_number">Contact number</label>
                  <input type="text" class="form-control" id="contact_number" name="contact_number" value="<?php echo e((string) ($_POST['contact_number'] ?? '')); ?>" required>
                </div>
              </div>
              <button type="submit" class="btn btn-primary btn-lg w-100 mt-4">
                <i class="fa-solid fa-paper-plane me-2"></i>Submit Registration
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </section>
  </main>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260629-grade-status-remarks"></script>
</body>
</html>
