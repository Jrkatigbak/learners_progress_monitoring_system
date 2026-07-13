<?php

require_once __DIR__ . '/includes/auth_guard.php';

if (!$auth->isLearner()) {
    header('Location: ' . $auth->redirectPath());
    exit;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function learnerProfileName(array $learner, array $currentUser): string
{
    $name = trim((string) ($learner['first_name'] ?? '') . ' ' . (string) ($learner['last_name'] ?? ''));

    return $name !== '' ? $name : (string) ($currentUser['name'] ?? 'Learner');
}

function deleteLearnerProfilePhoto(string $photoPath): void
{
    if ($photoPath === '' || strpos($photoPath, 'uploads/learners/') !== 0) {
        return;
    }

    $fullPath = __DIR__ . '/' . $photoPath;

    if (is_file($fullPath)) {
        unlink($fullPath);
    }
}

$errors = [];
$success = $_GET['success'] ?? '';
$uploadDirectory = __DIR__ . '/uploads/learners';
$uploadPathPrefix = 'uploads/learners/';

if (!is_dir($uploadDirectory)) {
    mkdir($uploadDirectory, 0777, true);
}

if (is_dir($uploadDirectory)) {
    @chmod($uploadDirectory, 0777);
}

$learnerStatement = $pdo->prepare(
    'SELECT id, learner_number, first_name, middle_name, last_name, email, phone, status, profile_photo
     FROM learners
     WHERE email = :email
       AND deleted_at IS NULL
     LIMIT 1'
);
$learnerStatement->execute(['email' => $currentUser['email']]);
$learner = $learnerStatement->fetch() ?: null;

if (!$learner) {
    http_response_code(404);
    echo 'Learner profile not found.';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = trim((string) ($_POST['first_name'] ?? ''));
    $middleName = trim((string) ($_POST['middle_name'] ?? ''));
    $lastName = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $profilePhoto = (string) ($learner['profile_photo'] ?? '');

    if ($firstName === '') {
        $errors[] = 'First name is required.';
    }

    if ($lastName === '') {
        $errors[] = 'Last name is required.';
    }

    if ($email === '') {
        $errors[] = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } else {
        $emailCheck = $pdo->prepare(
            'SELECT id
             FROM learners
             WHERE email = :email
               AND id <> :id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $emailCheck->execute([
            'email' => $email,
            'id' => (int) $learner['id'],
        ]);

        if ($emailCheck->fetch()) {
            $errors[] = 'That email is already used by another learner.';
        }

        $userEmailCheck = $pdo->prepare(
            'SELECT id
             FROM users
             WHERE email = :email
               AND id <> :id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $userEmailCheck->execute([
            'email' => $email,
            'id' => (int) $currentUser['id'],
        ]);

        if ($userEmailCheck->fetch()) {
            $errors[] = 'That email is already used by another user account.';
        }
    }

    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Profile picture could not be uploaded.';
        } elseif (!is_writable($uploadDirectory)) {
            $errors[] = 'Profile picture upload folder is not writable.';
        } else {
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $mimeType = mime_content_type($_FILES['profile_photo']['tmp_name']);

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Upload a JPG, PNG, WEBP, or GIF profile picture.';
            } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Profile picture must be 2MB or smaller.';
            } else {
                $filename = 'learner-' . (int) $learner['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
                $targetPath = $uploadDirectory . '/' . $filename;

                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $targetPath)) {
                    deleteLearnerProfilePhoto($profilePhoto);
                    $profilePhoto = $uploadPathPrefix . $filename;
                } else {
                    $errors[] = 'Profile picture could not be saved.';
                }
            }
        }
    }

    if (!$errors) {
        $displayName = trim($firstName . ' ' . $lastName);

        $pdo->beginTransaction();

        $updateLearner = $pdo->prepare(
            'UPDATE learners
             SET first_name = :first_name,
                 middle_name = :middle_name,
                 last_name = :last_name,
                 email = :email,
                 phone = :phone,
                 profile_photo = :profile_photo
             WHERE id = :id'
        );
        $updateLearner->execute([
            'first_name' => $firstName,
            'middle_name' => $middleName !== '' ? $middleName : null,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone !== '' ? $phone : null,
            'profile_photo' => $profilePhoto !== '' ? $profilePhoto : null,
            'id' => (int) $learner['id'],
        ]);

        $updateUser = $pdo->prepare(
            'UPDATE users
             SET name = :name,
                 email = :email
             WHERE id = :id AND role = "learner"'
        );
        $updateUser->execute([
            'name' => $displayName,
            'email' => $email,
            'id' => (int) $currentUser['id'],
        ]);

        $pdo->commit();

        $_SESSION['user']['name'] = $displayName;
        $_SESSION['user']['email'] = $email;

        header('Location: learner_profile.php?success=updated');
        exit;
    }
}

$learnerName = learnerProfileName($learner, $currentUser);
$learnerInitials = strtoupper(substr((string) ($learner['first_name'] ?? $learnerName), 0, 1));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Learner Profile</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260713-learner-profile" rel="stylesheet">
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
        <a href="enrolled_courses.php"><i class="fa-solid fa-book-open-reader"></i> Enrolled Class</a>
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
          <h1 class="h3 mb-0">My Profile</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar">
              <?php if (!empty($learner['profile_photo'])): ?>
                <img src="<?php echo e((string) $learner['profile_photo']); ?>" alt="<?php echo e($learnerName); ?>">
              <?php else: ?>
                <?php echo e($learnerInitials); ?>
              <?php endif; ?>
            </span>
            <span class="d-none d-sm-inline"><?php echo e($learnerName); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e((string) $currentUser['email']); ?></span></li>
            <li><a class="dropdown-item" href="learner_profile.php"><i class="fa-solid fa-user-pen me-2"></i>Update Profile</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <?php if ($success === 'updated'): ?>
          <div class="alert alert-success" role="alert">Profile updated successfully.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="panel-card learner-profile-panel">
          <div class="learner-profile-summary">
            <div class="learner-profile-photo">
              <?php if (!empty($learner['profile_photo'])): ?>
                <img src="<?php echo e((string) $learner['profile_photo']); ?>" alt="<?php echo e($learnerName); ?>">
              <?php else: ?>
                <span><?php echo e($learnerInitials); ?></span>
              <?php endif; ?>
            </div>
            <div>
              <span class="section-kicker">Learner Profile</span>
              <h2><?php echo e($learnerName); ?></h2>
              <p class="mb-0"><?php echo e((string) $learner['email']); ?></p>
              <small><?php echo e((string) $learner['learner_number']); ?></small>
            </div>
          </div>

          <form method="post" enctype="multipart/form-data" class="module-form mt-4">
            <div class="mb-3">
              <label class="form-label" for="profile_photo">Profile picture</label>
              <input type="file" class="form-control" id="profile_photo" name="profile_photo" accept="image/jpeg,image/png,image/webp,image/gif">
              <div class="form-text">JPG, PNG, WEBP, or GIF up to 2MB.</div>
            </div>
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label" for="first_name">First name</label>
                <input type="text" class="form-control" id="first_name" name="first_name" value="<?php echo e((string) ($learner['first_name'] ?? '')); ?>" required>
              </div>
              <div class="col-md-4">
                <label class="form-label" for="middle_name">Middle name</label>
                <input type="text" class="form-control" id="middle_name" name="middle_name" value="<?php echo e((string) ($learner['middle_name'] ?? '')); ?>">
              </div>
              <div class="col-md-4">
                <label class="form-label" for="last_name">Last name</label>
                <input type="text" class="form-control" id="last_name" name="last_name" value="<?php echo e((string) ($learner['last_name'] ?? '')); ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="email">Email</label>
                <input type="email" class="form-control" id="email" name="email" value="<?php echo e((string) ($learner['email'] ?? '')); ?>" required>
              </div>
              <div class="col-md-6">
                <label class="form-label" for="phone">Contact number</label>
                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo e((string) ($learner['phone'] ?? '')); ?>">
              </div>
            </div>
            <button type="submit" class="btn btn-primary mt-4">
              <i class="fa-solid fa-floppy-disk me-2"></i>Save Profile
            </button>
          </form>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260713-learner-profile"></script>
</body>
</html>
