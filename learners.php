<?php

require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/classes/SmtpMailer.php';

$mailerConfig = require __DIR__ . '/config/Mailer.php';

// Reusable escaping helper for cards and forms.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$search = trim($_GET['search'] ?? '');
$editingLearner = null;
$uploadDirectory = __DIR__ . '/uploads/learners';
$uploadPathPrefix = 'uploads/learners/';

if (!is_dir($uploadDirectory)) {
    // Keep learner uploads available to Apache/XAMPP even when the folder is recreated on macOS.
    mkdir($uploadDirectory, 0777, true);
}

if (is_dir($uploadDirectory)) {
    chmod($uploadDirectory, 0777);
}

if (!is_writable($uploadDirectory)) {
    $errors[] = 'Learner image upload folder is not writable.';
}

function deleteLearnerPhoto(string $photoPath): void
{
    if ($photoPath === '' || strpos($photoPath, 'uploads/learners/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photoPath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

function generateLearnerPassword(): string
{
    // Generated passwords include mixed character groups for first-login security.
    return 'Kiwi-' . bin2hex(random_bytes(3)) . '-' . random_int(100, 999);
}

function appLoginUrl(): string
{
    // Build the login URL from the current host so localhost and live domains both work.
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');

    return $scheme . '://' . $host . ($basePath ? $basePath : '') . '/login.php';
}

function sendLearnerCredentialEmail(SmtpMailer $mailer, string $email, string $name, string $password, string $loginUrl): bool
{
    $subject = 'Learners Progress Monitoring System login credentials';
    $textBody = "Hello {$name},\n\n"
        . "Your Learners Progress Monitoring System account has been created.\n\n"
        . "Login link: {$loginUrl}\n"
        . "Email: {$email}\n"
        . "Password: {$password}\n\n"
        . "Please keep these credentials secure.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . e($name) . ',</p>'
        . '<p>Your <strong>Learners Progress Monitoring System</strong> account has been created.</p>'
        . '<p><strong>Login link:</strong> <a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a><br>'
        . '<strong>Email:</strong> ' . e($email) . '<br>'
        . '<strong>Password:</strong> ' . e($password) . '</p>'
        . '<p>Please keep these credentials secure.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function sendTeacherCredentialEmail(SmtpMailer $mailer, string $email, string $name, string $password, string $loginUrl): bool
{
    $subject = 'Teacher Account login credentials';
    $textBody = "Hello {$name},\n\n"
        . "Your teacher account has been created for the Teacher Portal.\n\n"
        . "Account type: Teacher\n"
        . "Login link: {$loginUrl}\n"
        . "Email: {$email}\n"
        . "Password: {$password}\n\n"
        . "This email can also be used for a learner account with separate credentials.\n\n"
        . "Kiwi Digital Tech";
    $htmlBody = '<p>Hello ' . e($name) . ',</p>'
        . '<p>Your <strong>teacher account</strong> has been created for the <strong>Teacher Portal</strong>.</p>'
        . '<p><strong>Account type:</strong> Teacher<br>'
        . '<strong>Login link:</strong> <a href="' . e($loginUrl) . '">' . e($loginUrl) . '</a><br>'
        . '<strong>Email:</strong> ' . e($email) . '<br>'
        . '<strong>Password:</strong> ' . e($password) . '</p>'
        . '<p>This email can also be used for a learner account with separate credentials.</p>'
        . '<p>Kiwi Digital Tech</p>';

    return $mailer->send($email, $name, $subject, $htmlBody, $textBody);
}

function generateTeacherCodeFromLearner(PDO $pdo, string $learnerNumber): string
{
    // Teacher codes generated from learners stay readable while remaining unique.
    $cleanLearnerNumber = trim(preg_replace('/[^A-Z0-9]+/', '-', strtoupper($learnerNumber)), '-');
    $prefix = $cleanLearnerNumber !== '' ? 'TCH-' . $cleanLearnerNumber : 'TCH-' . date('Ymd');
    $checkStatement = $pdo->prepare('SELECT COUNT(*) FROM teachers WHERE teacher_code = :teacher_code');
    $candidate = substr($prefix, 0, 50);
    $counter = 1;

    while (true) {
        $checkStatement->execute(['teacher_code' => $candidate]);

        if ((int) $checkStatement->fetchColumn() === 0) {
            return $candidate;
        }

        $suffix = '-' . str_pad((string) $counter, 2, '0', STR_PAD_LEFT);
        $candidate = substr($prefix, 0, 50 - strlen($suffix)) . $suffix;
        $counter++;
    }
}

function createTeacherFromLearner(PDO $pdo, string $fullName, string $email, string $phone, string $profilePhoto, string $learnerNumber): ?array
{
    if ($email === '') {
        return null;
    }

    $teacherPassword = 'Teacher@12345';
    $existingTeacherStatement = $pdo->prepare('SELECT id, profile_photo FROM teachers WHERE email = :email LIMIT 1');
    $existingTeacherStatement->execute(['email' => $email]);
    $existingTeacher = $existingTeacherStatement->fetch() ?: null;

    if ($existingTeacher) {
        // Reuse the existing teacher masterlist row for this email instead of creating duplicates.
        $teacherStatement = $pdo->prepare(
            'UPDATE teachers
             SET full_name = :full_name,
                 phone = :phone,
                 profile_photo = :profile_photo,
                 status = :status,
                 deleted_at = NULL
             WHERE id = :id'
        );
        $teacherStatement->execute([
            'full_name' => $fullName,
            'phone' => $phone !== '' ? $phone : null,
            'profile_photo' => $profilePhoto !== '' ? $profilePhoto : ($existingTeacher['profile_photo'] ?? null),
            'status' => 'Active',
            'id' => (int) $existingTeacher['id'],
        ]);
    } else {
        $teacherStatement = $pdo->prepare(
            'INSERT INTO teachers (teacher_code, full_name, email, phone, specialization, profile_photo, status)
             VALUES (:teacher_code, :full_name, :email, :phone, :specialization, :profile_photo, :status)'
        );
        $teacherStatement->execute([
            'teacher_code' => generateTeacherCodeFromLearner($pdo, $learnerNumber),
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'specialization' => 'Learner promoted to teacher',
            'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
            'status' => 'Active',
        ]);
    }

    // Teacher portal accounts are keyed by email plus role, so learner and teacher logins can share an email.
    $userStatement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, 'teacher')
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            deleted_at = NULL"
    );
    $userStatement->execute([
        'name' => $fullName,
        'email' => $email,
        'password_hash' => password_hash($teacherPassword, PASSWORD_DEFAULT),
    ]);

    return [
        'name' => $fullName,
        'email' => $email,
        'password' => $teacherPassword,
    ];
}

if (isset($_GET['edit'])) {
    $editStatement = $pdo->prepare('SELECT * FROM learners WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit']]);
    $editingLearner = $editStatement->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        // Soft delete the learner row through POST so records remain recoverable.
        $photoStatement = $pdo->prepare('SELECT email, profile_photo FROM learners WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $photoStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        $learnerToDelete = $photoStatement->fetch() ?: [];
        $emailToDelete = (string) ($learnerToDelete['email'] ?? '');

        $deleteStatement = $pdo->prepare('UPDATE learners SET deleted_at = NOW(), status = "On Hold" WHERE id = :id AND deleted_at IS NULL');
        $deleteStatement->execute(['id' => (int) ($_POST['id'] ?? 0)]);

        if ($emailToDelete !== '') {
            // Hide only learner-role accounts so an admin account is never affected by accident.
            $userDeleteStatement = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE email = :email AND role = 'learner' AND deleted_at IS NULL");
            $userDeleteStatement->execute(['email' => $emailToDelete]);
        }

        header('Location: learners.php?success=deleted');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $learnerNumber = trim($_POST['learner_number'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');
    $existingPhoto = trim($_POST['existing_photo'] ?? '');
    $profilePhoto = $existingPhoto;
    $makeTeacher = isset($_POST['make_teacher']);

    if ($learnerNumber === '') {
        $errors[] = 'Learner number is required.';
    }

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid learner email.';
    }

    if ($makeTeacher && $email === '') {
        $errors[] = 'Learner email is required to create the teacher account and send credentials.';
    }

    if (!in_array($status, ['Active', 'On Hold', 'Completed'], true)) {
        $errors[] = 'Choose a valid learner status.';
    }

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Profile picture could not be uploaded.';
        } else {
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $mimeType = mime_content_type($_FILES['profile_photo']['tmp_name']);

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Profile picture must be JPG, PNG, WEBP, or GIF.';
            } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile picture must be 2MB or smaller.';
            } else {
                // Store profile photos with generated names to avoid collisions.
                $filename = 'learner-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $uploadDirectory . '/' . $filename;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                    $profilePhoto = $uploadPathPrefix . $filename;
                    deleteLearnerPhoto($existingPhoto);
                } else {
                    $errors[] = 'Profile picture could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            $fullName = trim($firstName . ' ' . $lastName);
            $teacherCredential = null;
            $teacherEmailStatus = '';

            if ($id > 0) {
                $pdo->beginTransaction();

                // Update learner profile details while keeping the existing photo when no new file is uploaded.
                $statement = $pdo->prepare(
                    'UPDATE learners
                     SET learner_number = :learner_number,
                         first_name = :first_name,
                         last_name = :last_name,
                         email = :email,
                         phone = :phone,
                         status = :status,
                         profile_photo = :profile_photo,
                         notes = :notes
                     WHERE id = :id'
                );
                $statement->execute([
                    'learner_number' => $learnerNumber,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'status' => $status,
                    'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
                    'notes' => $notes !== '' ? $notes : null,
                    'id' => $id,
                ]);

                if ($makeTeacher) {
                    $teacherCredential = createTeacherFromLearner($pdo, $fullName, $email, $phone, $profilePhoto, $learnerNumber);
                }

                $pdo->commit();

                if ($teacherCredential !== null) {
                    $mailer = new SmtpMailer($mailerConfig);
                    $teacherEmailSent = sendTeacherCredentialEmail($mailer, $teacherCredential['email'], $teacherCredential['name'], $teacherCredential['password'], appLoginUrl());
                    $teacherEmailStatus = $teacherEmailSent ? 'sent' : 'failed';
                }

                $redirectUrl = 'learners.php?success=updated';
                if ($teacherEmailStatus !== '') {
                    $redirectUrl .= '&teacher_email=' . $teacherEmailStatus;
                    if ($teacherEmailStatus === 'failed' && isset($mailer)) {
                        $redirectUrl .= '&teacher_mail_error=' . urlencode($mailer->getLastError());
                    }
                }

                header('Location: ' . $redirectUrl);
                exit;
            }

            $pdo->beginTransaction();

            // Create a learner record from the Add Learner form.
            $statement = $pdo->prepare(
                'INSERT INTO learners
                    (learner_number, first_name, last_name, email, phone, status, profile_photo, notes)
                 VALUES
                    (:learner_number, :first_name, :last_name, :email, :phone, :status, :profile_photo, :notes)'
            );
            $statement->execute([
                'learner_number' => $learnerNumber,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'status' => $status,
                'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            $emailStatus = 'skipped';

            if ($email !== '') {
                $learnerPassword = generateLearnerPassword();
                $passwordHash = password_hash($learnerPassword, PASSWORD_DEFAULT);

                // Create or refresh the learner's login account when an email is provided.
                $userStatement = $pdo->prepare(
                    'INSERT INTO users (name, email, password_hash, role)
                     VALUES (:name, :email, :password_hash, :role)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        password_hash = VALUES(password_hash),
            deleted_at = NULL'
                );
                $userStatement->execute([
                    'name' => $fullName,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'role' => 'learner',
                ]);
            }

            if ($makeTeacher) {
                $teacherCredential = createTeacherFromLearner($pdo, $fullName, $email, $phone, $profilePhoto, $learnerNumber);
            }

            $pdo->commit();

            if ($email !== '') {
                $mailer = new SmtpMailer($mailerConfig);
                $emailSent = sendLearnerCredentialEmail($mailer, $email, $fullName, $learnerPassword, appLoginUrl());
                $emailStatus = $emailSent ? 'sent' : 'failed';
                $learnerMailError = $emailStatus === 'failed' ? $mailer->getLastError() : '';
            }

            if ($teacherCredential !== null) {
                $mailer = isset($mailer) ? $mailer : new SmtpMailer($mailerConfig);
                $teacherEmailSent = sendTeacherCredentialEmail($mailer, $teacherCredential['email'], $teacherCredential['name'], $teacherCredential['password'], appLoginUrl());
                $teacherEmailStatus = $teacherEmailSent ? 'sent' : 'failed';
                $teacherMailError = $teacherEmailStatus === 'failed' ? $mailer->getLastError() : '';
            }

            $redirectUrl = 'learners.php?success=created&email=' . $emailStatus;
            if (!empty($learnerMailError)) {
                $redirectUrl .= '&mail_error=' . urlencode($learnerMailError);
            }
            if ($teacherEmailStatus !== '') {
                $redirectUrl .= '&teacher_email=' . $teacherEmailStatus;
                if (!empty($teacherMailError)) {
                    $redirectUrl .= '&teacher_mail_error=' . urlencode($teacherMailError);
                }
            }

            header('Location: ' . $redirectUrl);
            exit;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors[] = 'Learner number already exists. Please use a new learner number.';
        }
    }

    $editingLearner = [
        'id' => $id,
        'learner_number' => $learnerNumber,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'status' => $status,
        'profile_photo' => $profilePhoto,
        'notes' => $notes,
        'make_teacher' => $makeTeacher,
    ];
}

if ($search !== '') {
    // Search covers the visible learner identity fields.
    $learnersStatement = $pdo->prepare(
        "SELECT learners.*
         FROM learners
         WHERE learners.deleted_at IS NULL
           AND (
            learners.learner_number LIKE :learner_number_search
            OR learners.first_name LIKE :first_name_search
            OR learners.last_name LIKE :last_name_search
           )
         ORDER BY learners.created_at DESC, learners.id DESC"
    );
    $searchTerm = '%' . $search . '%';
    $learnersStatement->execute([
        'learner_number_search' => $searchTerm,
        'first_name_search' => $searchTerm,
        'last_name_search' => $searchTerm,
    ]);
} else {
    $learnersStatement = $pdo->query(
        'SELECT learners.*
         FROM learners
         WHERE learners.deleted_at IS NULL
         ORDER BY learners.created_at DESC, learners.id DESC'
    );
}

$learnerRows = $learnersStatement->fetchAll();
$formLearner = $editingLearner ?: [
    'id' => 0,
    'learner_number' => '',
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'phone' => '',
    'status' => 'Active',
    'profile_photo' => '',
    'notes' => '',
    'make_teacher' => false,
];
$successMessages = [
    'created' => 'Learner added successfully.',
    'updated' => 'Learner updated successfully.',
    'deleted' => 'Learner deleted successfully.',
];
$emailStatus = $_GET['email'] ?? '';
$teacherEmailStatus = $_GET['teacher_email'] ?? '';
$mailError = trim((string) ($_GET['mail_error'] ?? ''));
$teacherMailError = trim((string) ($_GET['teacher_mail_error'] ?? ''));
$toastIcon = '';
$toastTitle = '';
$toastText = '';

if ($errors) {
    $toastIcon = 'error';
    $toastTitle = 'Cannot save learner';
    $toastText = implode("\n", $errors);
} elseif (isset($successMessages[$success])) {
    $toastIcon = 'success';
    $toastTitle = $successMessages[$success];

    if ($emailStatus === 'sent') {
        $toastText = 'Login credentials were emailed to the learner.';
    } elseif ($emailStatus === 'failed') {
        $toastIcon = 'warning';
        $toastText = 'Learner login was created, but the credential email could not be sent.' . ($mailError !== '' ? "\n" . $mailError : '');
    } elseif ($emailStatus === 'skipped') {
        $toastIcon = 'info';
        $toastText = 'Learner was added without login credentials because no email was provided.';
    }

    if ($teacherEmailStatus === 'sent') {
        $toastText = trim($toastText . "\nTeacher account was created and teacher credentials were emailed.");
    } elseif ($teacherEmailStatus === 'failed') {
        $toastIcon = 'warning';
        $toastText = trim($toastText . "\nTeacher account was created, but the teacher credential email could not be sent." . ($teacherMailError !== '' ? "\n" . $teacherMailError : ''));
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Learners</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=learner-card-photo-large" rel="stylesheet">
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'learners'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <!-- Repeat the system name for clear module context. -->
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Learners Management</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e(strtoupper(substr($currentUser['name'], 0, 1))); ?></span>
            <span class="d-none d-sm-inline"><?php echo e($currentUser['name']); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e($currentUser['email']); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <div class="panel-card learner-directory-panel">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <span class="section-kicker">Directory</span>
                  <h2 class="h5 mb-0">Learner cards</h2>
                </div>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#learnerModal"><i class="fa-solid fa-plus me-2"></i>Add Learner</button>
              </div>
              <form method="get" class="search-bar mb-4">
                <div class="input-group">
                  <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                  <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search learners or number">
                  <button type="submit" class="btn btn-primary">Search</button>
                  <?php if ($search !== ''): ?>
                    <a href="learners.php" class="btn btn-outline-secondary">Clear</a>
                  <?php endif; ?>
                </div>
              </form>

              <?php if (!$learnerRows): ?>
                <div class="empty-state">
                  <i class="fa-solid fa-users"></i>
                  <p>No learners found.</p>
                </div>
              <?php endif; ?>

              <div class="learner-grid">
                <?php foreach ($learnerRows as $learner): ?>
                  <?php
                    $fullName = trim($learner['first_name'] . ' ' . $learner['last_name']);
                    $initials = strtoupper(substr($learner['first_name'], 0, 1) . substr($learner['last_name'], 0, 1));
                    $photo = $learner['profile_photo'] ?? '';
                    $progress = (int) $learner['progress_percent'];
                  ?>
                  <article class="learner-card">
                    <div class="learner-card-body">
                      <div class="learner-card-media">
                        <?php if ($photo !== ''): ?>
                          <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#learnerPhotoModal" data-photo="<?php echo e($photo); ?>" data-name="<?php echo e($fullName); ?>" aria-label="View <?php echo e($fullName); ?> profile picture">
                            <img src="<?php echo e($photo); ?>" alt="<?php echo e($fullName); ?>">
                          </button>
                        <?php else: ?>
                          <div class="learner-photo-placeholder"><?php echo e($initials); ?></div>
                        <?php endif; ?>
                      </div>
                      <h3><?php echo e($fullName); ?></h3>
                      <p class="learner-number"><?php echo e($learner['learner_number']); ?></p>
                      <?php if (!empty($learner['phone'])): ?>
                      <div class="learner-meta">
                        <?php if (!empty($learner['phone'])): ?>
                          <span><i class="fa-solid fa-phone"></i><?php echo e($learner['phone']); ?></span>
                        <?php endif; ?>
                      </div>
                      <?php endif; ?>
                    </div>
                    <footer class="learner-card-footer">
                      <div class="d-flex align-items-center justify-content-between mb-2">
                        <strong>Progress</strong>
                        <span><?php echo $progress; ?>%</span>
                      </div>
                      <div class="progress learner-progress" role="progressbar" aria-label="Learner progress" aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?php echo $progress; ?>%"></div>
                      </div>
                      <div class="learner-icon-actions">
                        <a class="learner-icon-button" href="learners.php?edit=<?php echo (int) $learner['id']; ?>" aria-label="Edit learner">
                          <i class="fa-solid fa-pen"></i>
                        </a>
                        <form method="post" onsubmit="return confirm('Delete this learner?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo (int) $learner['id']; ?>">
                          <button type="submit" class="learner-icon-button is-danger" aria-label="Delete learner">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      </div>
                    </footer>
                  </article>
                <?php endforeach; ?>
              </div>
        </div>
      </section>
    </main>
  </div>

  <div class="modal fade" id="learnerPhotoModal" tabindex="-1" aria-labelledby="learnerPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content image-preview-modal">
        <div class="modal-header">
          <h2 class="modal-title h5" id="learnerPhotoModalLabel">Profile picture</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <img src="" alt="" id="learnerPhotoPreview">
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="learnerModal" tabindex="-1" aria-labelledby="learnerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker"><?php echo ((int) $formLearner['id'] > 0) ? 'Edit Learner' : 'Add Learner'; ?></span>
            <h2 class="modal-title h5" id="learnerModalLabel"><?php echo ((int) $formLearner['id'] > 0) ? 'Update learner profile' : 'Create learner profile'; ?></h2>
          </div>
          <a href="learners.php" class="btn-close" aria-label="Close"></a>
        </div>
        <form method="post" enctype="multipart/form-data" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo (int) $formLearner['id']; ?>">
            <input type="hidden" name="existing_photo" value="<?php echo e($formLearner['profile_photo'] ?? ''); ?>">
            <div class="mb-3">
              <label class="form-label" for="profile_photo">Profile picture</label>
              <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/png,image/jpeg,image/webp,image/gif">
              <?php if (!empty($formLearner['profile_photo'])): ?>
                <div class="small text-secondary mt-2">Current photo will stay unless a new one is uploaded.</div>
              <?php endif; ?>
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="learner_number">Learner number</label>
                <input type="text" class="form-control" id="learner_number" name="learner_number" value="<?php echo e($formLearner['learner_number']); ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="first_name">First name</label>
                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e($formLearner['first_name']); ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="last_name">Last name</label>
                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e($formLearner['last_name']); ?>" required>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($formLearner['email'] ?? ''); ?>">
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($formLearner['phone'] ?? ''); ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                  <option value="Active" <?php echo $formLearner['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                  <option value="On Hold" <?php echo $formLearner['status'] === 'On Hold' ? 'selected' : ''; ?>>On Hold</option>
                  <option value="Completed" <?php echo $formLearner['status'] === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                </select>
              </div>
            </div>
            <div class="form-check teacher-convert-check mt-3">
              <input class="form-check-input" type="checkbox" id="make_teacher" name="make_teacher" value="1" <?php echo !empty($formLearner['make_teacher']) ? 'checked' : ''; ?>>
              <label class="form-check-label fw-semibold" for="make_teacher">Make this learner a teacher as well</label>
              <div class="form-text">Creates a teacher masterlist record and sends separate Teacher Portal login credentials.</div>
            </div>
            <div class="mt-3">
              <label class="form-label" for="notes">Notes</label>
              <textarea class="form-control" id="notes" name="notes" rows="4"><?php echo e($formLearner['notes'] ?? ''); ?></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline-secondary" href="learners.php">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i><?php echo ((int) $formLearner['id'] > 0) ? 'Update Learner' : 'Add Learner'; ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php if ($errors || (int) $formLearner['id'] > 0): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('learnerModal')).show();
      });
    </script>
  <?php endif; ?>
  <?php if ($toastIcon !== ''): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        Swal.fire({
          icon: <?php echo json_encode($toastIcon); ?>,
          title: <?php echo json_encode($toastTitle); ?>,
          text: <?php echo json_encode($toastText); ?>,
          confirmButtonColor: '#f58220'
        });
      });
    </script>
  <?php endif; ?>
  <script src="js/app.js?v=photo-viewer"></script>
</body>
</html>
