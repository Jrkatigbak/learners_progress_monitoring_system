<?php

require_once __DIR__ . '/includes/admin_guard.php';
require_once __DIR__ . '/classes/SmtpMailer.php';

$mailerConfig = require __DIR__ . '/config/Mailer.php';

// Reusable escaping helper for teacher forms and tables.
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = $_GET['success'] ?? '';
$search = trim($_GET['search'] ?? '');
$editingTeacher = null;
$teacherUploadDirectory = __DIR__ . '/uploads/teachers';
$teacherUploadPathPrefix = 'uploads/teachers/';

if (!is_dir($teacherUploadDirectory)) {
    // Teacher photos stay in their own folder so masterlist uploads are easy to manage.
    mkdir($teacherUploadDirectory, 0777, true);
}

if (is_dir($teacherUploadDirectory)) {
    // Some deployed hosts disallow chmod even when uploads already work, so keep page output warning-free.
    @chmod($teacherUploadDirectory, 0777);
}

function deleteTeacherPhoto(string $photoPath): void
{
    if ($photoPath === '' || strpos($photoPath, 'uploads/teachers/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photoPath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

if (isset($_GET['edit'])) {
    $editStatement = $pdo->prepare('SELECT * FROM teachers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $editStatement->execute(['id' => (int) $_GET['edit']]);
    $editingTeacher = $editStatement->fetch() ?: null;
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

function generateTeacherPassword(): string
{
    // Reset credentials use a fresh generated password instead of the reusable default.
    return 'Teacher-' . bin2hex(random_bytes(3)) . '-' . random_int(100, 999);
}

function syncTeacherLogin(PDO $pdo, string $name, string $email): ?string
{
    if ($email === '') {
        return null;
    }

    $teacherPassword = 'Teacher@12345';
    // Teacher portal accounts are keyed by email plus role, so learner accounts can use the same email.
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, 'teacher')
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            deleted_at = NULL"
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($teacherPassword, PASSWORD_DEFAULT),
    ]);

    return $teacherPassword;
}

function resetTeacherLogin(PDO $pdo, string $name, string $email): string
{
    $teacherPassword = generateTeacherPassword();
    $statement = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role)
         VALUES (:name, :email, :password_hash, 'teacher')
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            password_hash = VALUES(password_hash),
            deleted_at = NULL"
    );
    $statement->execute([
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($teacherPassword, PASSWORD_DEFAULT),
    ]);

    return $teacherPassword;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reset_credentials') {
        $teacherId = (int) ($_POST['id'] ?? 0);
        $teacherStatement = $pdo->prepare('SELECT full_name, email FROM teachers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $teacherStatement->execute(['id' => $teacherId]);
        $teacher = $teacherStatement->fetch() ?: null;

        if (!$teacher) {
            $errors[] = 'Select a valid teacher.';
        }

        $teacherEmail = trim((string) ($teacher['email'] ?? ''));
        $teacherName = trim((string) ($teacher['full_name'] ?? ''));

        if ($teacherEmail === '' || !filter_var($teacherEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Teacher needs a valid email before credentials can be resent.';
        }

        if (!$errors) {
            // Reset the teacher portal password before sending the new credentials.
            $teacherPassword = resetTeacherLogin($pdo, $teacherName, $teacherEmail);
            $mailer = new SmtpMailer($mailerConfig);
            $emailStatus = sendTeacherCredentialEmail($mailer, $teacherEmail, $teacherName, $teacherPassword, appLoginUrl()) ? 'sent' : 'failed';
            $mailError = $emailStatus === 'failed' ? '&mail_error=' . urlencode($mailer->getLastError()) : '';

            header('Location: teachers.php?success=credentials_reset&email=' . $emailStatus . $mailError);
            exit;
        }
    }

    if ($action === 'delete') {
        $teacherId = (int) ($_POST['id'] ?? 0);
        $classCountStatement = $pdo->prepare(
            'SELECT COUNT(DISTINCT class_id)
             FROM (
                SELECT id AS class_id FROM classes WHERE teacher_id = :legacy_teacher_id AND deleted_at IS NULL
                UNION
                SELECT active_classes.id AS class_id
                FROM class_teachers
                INNER JOIN classes AS active_classes
                  ON active_classes.id = class_teachers.class_id
                 AND active_classes.deleted_at IS NULL
                WHERE class_teachers.teacher_id = :assigned_teacher_id
                  AND class_teachers.deleted_at IS NULL
             ) AS assigned_classes'
        );
        $classCountStatement->execute([
            'legacy_teacher_id' => $teacherId,
            'assigned_teacher_id' => $teacherId,
        ]);

        if ((int) $classCountStatement->fetchColumn() > 0) {
            header('Location: teachers.php?success=in_use');
            exit;
        }

        $photoStatement = $pdo->prepare('SELECT email, profile_photo FROM teachers WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $photoStatement->execute(['id' => $teacherId]);
        $teacherToDelete = $photoStatement->fetch() ?: [];
        $emailToDelete = (string) ($teacherToDelete['email'] ?? '');

        // Soft delete only unassigned teachers so existing class history remains intact.
        $deleteStatement = $pdo->prepare('UPDATE teachers SET deleted_at = NOW(), status = "Inactive" WHERE id = :id AND deleted_at IS NULL');
        $deleteStatement->execute(['id' => $teacherId]);

        if ($emailToDelete !== '') {
            $userDeleteStatement = $pdo->prepare("UPDATE users SET deleted_at = NOW() WHERE email = :email AND role = 'teacher' AND deleted_at IS NULL");
            $userDeleteStatement->execute(['email' => $emailToDelete]);
        }

        header('Location: teachers.php?success=deleted');
        exit;
    }

    $id = (int) ($_POST['id'] ?? 0);
    $teacherCode = trim($_POST['teacher_code'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $specialization = trim($_POST['specialization'] ?? '');
    $status = $_POST['status'] ?? 'Active';
    $existingPhoto = trim($_POST['existing_photo'] ?? '');
    $profilePhoto = $existingPhoto;

    if ($teacherCode === '') {
        $errors[] = 'Teacher code is required.';
    }

    if ($fullName === '') {
        $errors[] = 'Teacher name is required.';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid teacher email.';
    }

    if (!in_array($status, ['Active', 'Inactive'], true)) {
        $errors[] = 'Choose a valid teacher status.';
    }

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Teacher profile picture could not be uploaded.';
        } elseif (!is_writable($teacherUploadDirectory)) {
            $errors[] = 'Teacher photo upload folder is not writable.';
        } else {
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $mimeType = mime_content_type($_FILES['profile_photo']['tmp_name']);

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Teacher profile picture must be JPG, PNG, WEBP, or GIF.';
            } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Teacher profile picture must be 2MB or smaller.';
            } else {
                // Store teacher photos with generated names to avoid collisions.
                $filename = 'teacher-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $teacherUploadDirectory . '/' . $filename;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                    $profilePhoto = $teacherUploadPathPrefix . $filename;
                    deleteTeacherPhoto($existingPhoto);
                } else {
                    $errors[] = 'Teacher profile picture could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                // Update the masterlist record and keep assigned class teacher text in sync.
                $statement = $pdo->prepare(
                    'UPDATE teachers
                     SET teacher_code = :teacher_code,
                         full_name = :full_name,
                         email = :email,
                         phone = :phone,
                         specialization = :specialization,
                         profile_photo = :profile_photo,
                         status = :status
                     WHERE id = :id'
                );
                $statement->execute([
                    'teacher_code' => $teacherCode,
                    'full_name' => $fullName,
                    'email' => $email !== '' ? $email : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'specialization' => $specialization !== '' ? $specialization : null,
                    'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
                    'status' => $status,
                    'id' => $id,
                ]);

                $classTeacherStatement = $pdo->prepare('UPDATE classes SET teacher = :teacher WHERE teacher_id = :teacher_id');
                $classTeacherStatement->execute([
                    'teacher' => $fullName,
                    'teacher_id' => $id,
                ]);

                $emailStatus = 'skipped';
                $teacherPassword = syncTeacherLogin($pdo, $fullName, $email);

                if ($teacherPassword !== null) {
                    $mailer = new SmtpMailer($mailerConfig);
                    $emailStatus = sendTeacherCredentialEmail($mailer, $email, $fullName, $teacherPassword, appLoginUrl()) ? 'sent' : 'failed';
                }

                $mailError = $emailStatus === 'failed' && isset($mailer) ? '&mail_error=' . urlencode($mailer->getLastError()) : '';
                header('Location: teachers.php?success=updated&email=' . $emailStatus . $mailError);
                exit;
            }

            // Create a teacher that can immediately be selected from the Add Class dropdown.
            $statement = $pdo->prepare(
                'INSERT INTO teachers (teacher_code, full_name, email, phone, specialization, profile_photo, status)
                 VALUES (:teacher_code, :full_name, :email, :phone, :specialization, :profile_photo, :status)'
            );
            $statement->execute([
                'teacher_code' => $teacherCode,
                'full_name' => $fullName,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                'specialization' => $specialization !== '' ? $specialization : null,
                'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
                'status' => $status,
            ]);

            $emailStatus = 'skipped';
            $teacherPassword = syncTeacherLogin($pdo, $fullName, $email);

            if ($teacherPassword !== null) {
                $mailer = new SmtpMailer($mailerConfig);
                $emailStatus = sendTeacherCredentialEmail($mailer, $email, $fullName, $teacherPassword, appLoginUrl()) ? 'sent' : 'failed';
            }

            $mailError = $emailStatus === 'failed' && isset($mailer) ? '&mail_error=' . urlencode($mailer->getLastError()) : '';
            header('Location: teachers.php?success=created&email=' . $emailStatus . $mailError);
            exit;
        } catch (PDOException $exception) {
            $errors[] = 'Teacher code already exists. Please use a new teacher code.';
        }
    }

    $editingTeacher = [
        'id' => $id,
        'teacher_code' => $teacherCode,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'specialization' => $specialization,
        'profile_photo' => $profilePhoto,
        'status' => $status,
    ];
}

if ($search !== '') {
    // Search by core teacher identity fields.
    $teacherStatement = $pdo->prepare(
        "SELECT teachers.*,
                (
                    SELECT COUNT(DISTINCT assigned_classes.class_id)
                    FROM (
                        SELECT id AS class_id, teacher_id FROM classes WHERE teacher_id IS NOT NULL AND deleted_at IS NULL
                        UNION
                        SELECT active_classes.id AS class_id, class_teachers.teacher_id
                        FROM class_teachers
                        INNER JOIN classes AS active_classes
                          ON active_classes.id = class_teachers.class_id
                         AND active_classes.deleted_at IS NULL
                        WHERE class_teachers.deleted_at IS NULL
                    ) AS assigned_classes
                    WHERE assigned_classes.teacher_id = teachers.id
                ) AS class_count
         FROM teachers
         WHERE teachers.deleted_at IS NULL
           AND (
            teachers.teacher_code LIKE :teacher_code_search
            OR teachers.full_name LIKE :full_name_search
            OR teachers.email LIKE :email_search
            OR teachers.specialization LIKE :specialization_search
           )
         GROUP BY teachers.id
         ORDER BY teachers.created_at DESC, teachers.id DESC"
    );
    $searchTerm = '%' . $search . '%';
    $teacherStatement->execute([
        'teacher_code_search' => $searchTerm,
        'full_name_search' => $searchTerm,
        'email_search' => $searchTerm,
        'specialization_search' => $searchTerm,
    ]);
} else {
    $teacherStatement = $pdo->query(
        'SELECT teachers.*,
                (
                    SELECT COUNT(DISTINCT assigned_classes.class_id)
                    FROM (
                        SELECT id AS class_id, teacher_id FROM classes WHERE teacher_id IS NOT NULL AND deleted_at IS NULL
                        UNION
                        SELECT active_classes.id AS class_id, class_teachers.teacher_id
                        FROM class_teachers
                        INNER JOIN classes AS active_classes
                          ON active_classes.id = class_teachers.class_id
                         AND active_classes.deleted_at IS NULL
                        WHERE class_teachers.deleted_at IS NULL
                    ) AS assigned_classes
                    WHERE assigned_classes.teacher_id = teachers.id
                ) AS class_count
         FROM teachers
         WHERE teachers.deleted_at IS NULL
         GROUP BY teachers.id
         ORDER BY teachers.created_at DESC, teachers.id DESC'
    );
}

$teacherRows = $teacherStatement->fetchAll();
$formTeacher = $editingTeacher ?: [
    'id' => 0,
    'teacher_code' => '',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'specialization' => '',
    'profile_photo' => '',
    'status' => 'Active',
];
$successMessages = [
    'created' => 'Teacher added successfully.',
    'updated' => 'Teacher updated successfully.',
    'deleted' => 'Teacher deleted successfully.',
    'in_use' => 'Teacher is assigned to a class. Deactivate or reassign classes before deleting.',
    'credentials_reset' => 'Teacher credentials were reset.',
];
$emailStatus = $_GET['email'] ?? '';
$mailError = trim((string) ($_GET['mail_error'] ?? ''));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Teachers</title>
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
    <?php $activeSidebarItem = 'teachers'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Learners Progress Monitoring System</p>
          <h1 class="h3 mb-0">Teachers Masterlist</h1>
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
        <?php if (isset($successMessages[$success])): ?>
          <div class="alert <?php echo $success === 'in_use' ? 'alert-warning' : 'alert-success'; ?>" role="alert"><?php echo e($successMessages[$success]); ?></div>
        <?php endif; ?>

        <?php if (in_array($emailStatus, ['sent', 'failed', 'skipped'], true)): ?>
          <div class="alert <?php echo $emailStatus === 'failed' ? 'alert-warning' : 'alert-info'; ?>" role="alert">
            <?php if ($emailStatus === 'sent'): ?>
              Teacher portal login credentials were emailed successfully.
            <?php elseif ($emailStatus === 'failed'): ?>
              Teacher portal login was created, but the credential email could not be sent.
              <?php if ($mailError !== ''): ?>
                <div class="small mt-1"><?php echo e($mailError); ?></div>
              <?php endif; ?>
            <?php else: ?>
              Teacher was saved without portal credentials because no email was provided.
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="panel-card">
          <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
            <div>
              <span class="section-kicker">Masterlist</span>
              <h2 class="h5 mb-0">Teacher records</h2>
            </div>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#teacherModal">
              <i class="fa-solid fa-plus me-2"></i>Add Teacher
            </button>
          </div>

          <form method="get" class="search-bar mb-4">
            <div class="input-group">
              <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
              <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search teachers, code, email, or specialization">
              <button type="submit" class="btn btn-primary">Search</button>
              <?php if ($search !== ''): ?>
                <a href="teachers.php" class="btn btn-outline-secondary">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$teacherRows): ?>
            <div class="empty-state">
              <i class="fa-solid fa-user-tie"></i>
              <p>No teachers found.</p>
            </div>
          <?php endif; ?>

          <div class="teacher-card-grid">
            <?php foreach ($teacherRows as $teacher): ?>
              <?php
                $teacherInitials = strtoupper(substr($teacher['full_name'], 0, 1));
              ?>
              <article class="teacher-card">
                <div class="teacher-card-body">
                  <div class="teacher-card-media">
                    <?php if (!empty($teacher['profile_photo'])): ?>
                      <button type="button" class="learner-photo-viewer-button" data-bs-toggle="modal" data-bs-target="#teacherPhotoModal" data-photo="<?php echo e($teacher['profile_photo']); ?>" data-name="<?php echo e($teacher['full_name']); ?>" aria-label="View <?php echo e($teacher['full_name']); ?> profile picture">
                        <img src="<?php echo e($teacher['profile_photo']); ?>" alt="<?php echo e($teacher['full_name']); ?> profile picture">
                      </button>
                    <?php else: ?>
                      <span><?php echo e($teacherInitials); ?></span>
                    <?php endif; ?>
                  </div>
                  <h3><?php echo e($teacher['full_name']); ?></h3>
                  <p class="learner-number"><?php echo e($teacher['teacher_code']); ?></p>
                  <div class="teacher-card-meta">
                    <?php if (!empty($teacher['specialization'])): ?>
                      <span><i class="fa-solid fa-certificate"></i><?php echo e($teacher['specialization']); ?></span>
                    <?php endif; ?>
                  </div>
                </div>
                <footer class="teacher-card-footer">
                  <div class="teacher-class-count">
                    <strong><?php echo (int) $teacher['class_count']; ?></strong>
                    <span><?php echo (int) $teacher['class_count'] === 1 ? 'Assigned class' : 'Assigned classes'; ?></span>
                  </div>
                  <div class="learner-icon-actions">
                    <form method="post" action="teachers.php" class="credential-reset-form" data-confirm-message="Reset password and email new login credentials to this teacher?">
                      <input type="hidden" name="action" value="reset_credentials">
                      <input type="hidden" name="id" value="<?php echo (int) $teacher['id']; ?>">
                      <button type="submit" class="learner-icon-button teacher-reset-button" aria-label="Reset and resend teacher credentials" title="Reset and resend credentials">
                        <i class="fa-solid fa-key"></i>
                      </button>
                    </form>
                    <a class="learner-icon-button" href="teachers.php?edit=<?php echo (int) $teacher['id']; ?>" aria-label="Edit teacher">
                      <i class="fa-solid fa-pen"></i>
                    </a>
                    <form method="post" action="teachers.php" onsubmit="return confirm('Delete this teacher?');">
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?php echo (int) $teacher['id']; ?>">
                      <button type="submit" class="learner-icon-button is-danger" aria-label="Delete teacher">
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

  <div class="modal fade" id="teacherPhotoModal" tabindex="-1" aria-labelledby="learnerPhotoModalLabel" aria-hidden="true">
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

  <div class="modal fade" id="teacherModal" tabindex="-1" aria-labelledby="teacherModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <span class="section-kicker"><?php echo ((int) $formTeacher['id'] > 0) ? 'Edit Teacher' : 'Add Teacher'; ?></span>
            <h2 class="modal-title h5" id="teacherModalLabel"><?php echo ((int) $formTeacher['id'] > 0) ? 'Update teacher details' : 'Create teacher record'; ?></h2>
          </div>
          <a href="teachers.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="btn-close" aria-label="Close"></a>
        </div>
        <form method="post" action="teachers.php" enctype="multipart/form-data" class="module-form">
          <div class="modal-body">
            <input type="hidden" name="id" value="<?php echo (int) $formTeacher['id']; ?>">
            <input type="hidden" name="existing_photo" value="<?php echo e($formTeacher['profile_photo'] ?? ''); ?>">
            <div class="mb-3">
              <label class="form-label" for="profile_photo">Profile picture</label>
              <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/png,image/jpeg,image/webp,image/gif">
              <?php if (!empty($formTeacher['profile_photo'])): ?>
                <div class="teacher-photo-preview mt-3">
                  <img src="<?php echo e($formTeacher['profile_photo']); ?>" alt="<?php echo e($formTeacher['full_name']); ?> profile picture">
                  <span>Current photo will stay unless a new one is uploaded.</span>
                </div>
              <?php else: ?>
                <div class="small text-secondary mt-2">JPG, PNG, WEBP, or GIF up to 2MB.</div>
              <?php endif; ?>
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="teacher_code">Teacher code</label>
                <input type="text" class="form-control" id="teacher_code" name="teacher_code" value="<?php echo e($formTeacher['teacher_code']); ?>" required>
              </div>
              <div class="col-md-8">
                <label class="form-label" for="full_name">Teacher name</label>
                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo e($formTeacher['full_name']); ?>" required>
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo e($formTeacher['email'] ?? ''); ?>">
                <div class="small text-secondary mt-2">Used for teacher portal login. Default password: Teacher@12345.</div>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Phone</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e($formTeacher['phone'] ?? ''); ?>">
              </div>
            </div>
            <div class="row g-3 mt-1">
              <div class="col-md-8">
                <label class="form-label" for="specialization">Specialization</label>
                <input type="text" class="form-control" id="specialization" name="specialization" value="<?php echo e($formTeacher['specialization'] ?? ''); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                  <option value="Active" <?php echo $formTeacher['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                  <option value="Inactive" <?php echo $formTeacher['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <a class="btn btn-outline-secondary" href="teachers.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">
              <i class="fa-solid fa-floppy-disk me-2"></i><?php echo ((int) $formTeacher['id'] > 0) ? 'Update Teacher' : 'Add Teacher'; ?>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php if ($success === 'credentials_reset' && in_array($emailStatus, ['sent', 'failed'], true)): ?>
    <?php $credentialResetText = $emailStatus === 'sent'
        ? 'The teacher password was reset and the new login credentials were sent by email.'
        : 'The teacher password was reset, but the credential email could not be sent.' . ($mailError !== '' ? ' ' . $mailError : ''); ?>
    <script>
      window.credentialResetNotice = {
        icon: <?php echo json_encode($emailStatus === 'sent' ? 'success' : 'error'); ?>,
        title: <?php echo json_encode($emailStatus === 'sent' ? 'Credentials sent' : 'Email not sent'); ?>,
        text: <?php echo json_encode($credentialResetText); ?>
      };
    </script>
  <?php endif; ?>
  <?php if ($errors || (int) $formTeacher['id'] > 0): ?>
    <script>
      window.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('teacherModal')).show();
      });
    </script>
  <?php endif; ?>
  <script src="js/app.js?v=20260629-grade-score-autosave"></script>
</body>
</html>
