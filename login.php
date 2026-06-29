<?php

require_once __DIR__ . '/includes/bootstrap.php';

if ($auth->check()) {
    header('Location: ' . $auth->redirectPath());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($auth->attempt($email, $password)) {
        header('Location: ' . $auth->redirectPath());
        exit;
    }

    $error = 'Invalid email or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Kiwi Digital | Login</title>
  <link rel="icon" type="image/png" href="images/kiwi-logo.png">
  <link rel="apple-touch-icon" href="images/kiwi-logo.png">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-grade-status-remarks" rel="stylesheet">
</head>
<body class="auth-page">
  <button class="theme-toggle auth-theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
    <i class="fa-solid fa-moon"></i>
    <span>Dark</span>
  </button>
  <main class="auth-shell">
    <section class="auth-brand">
      <img src="images/kiwi-logo.png" alt="Kiwi Digital Tech Inc." class="brand-logo brand-logo-lg">
      <!-- Show the official system name prominently on the login screen. -->
      <p class="eyebrow">Learners Progress Monitoring System</p>
      <h1>Welcome to Learners Progress Monitoring System</h1>
      <p class="brand-copy">Track learner progress, student activity, and monitoring updates from one secure Kiwi Digital dashboard.</p>
    </section>

    <section class="auth-card">
      <div class="card border-0 shadow-lg">
        <div class="card-body p-4 p-md-5">
          <div class="mb-4">
            <span class="section-kicker">Sign in</span>
            <h2 class="h3 fw-bold mb-1">Access your dashboard</h2>
            <p class="text-secondary mb-0">Use your account credentials to continue.</p>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>

          <form method="post" id="loginForm" novalidate>
            <div class="mb-3">
              <label class="form-label" for="email">Email address</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text"><i class="fa-regular fa-envelope"></i></span>
                <!-- Keep the login form empty so every user manually enters their own email. -->
                <input type="email" class="form-control" id="email" name="email" required autocomplete="email">
              </div>
            </div>
            <div class="mb-4">
              <label class="form-label" for="password">Password</label>
              <div class="input-group input-group-lg">
                <span class="input-group-text"><i class="fa-solid fa-lock"></i></span>
                <!-- Keep the password blank by default instead of exposing seeded credentials. -->
                <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100" id="loginButton">
              <span class="btn-label">Login</span>
              <span class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
            </button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260629-grade-status-remarks"></script>
</body>
</html>
