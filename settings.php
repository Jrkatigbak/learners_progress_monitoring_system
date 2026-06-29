<?php

require_once __DIR__ . '/includes/admin_guard.php';

kiwiRequirePermission($pdo, 'settings.manage');

$currentUser = $auth->user();
$settings = kiwiSystemSettings();
$defaults = kiwiDefaultSystemSettings();
$errors = [];
$success = $_GET['success'] ?? '';
$settingsUploadDirectory = __DIR__ . '/uploads/settings';
$settingsUploadPrefix = 'uploads/settings/';

if (!is_dir($settingsUploadDirectory)) {
    mkdir($settingsUploadDirectory, 0777, true);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $updatedSettings = $settings;

    if ($action === 'save_branding') {
        $brandName = trim((string) ($_POST['brand_name'] ?? ''));
        $systemName = trim((string) ($_POST['system_name'] ?? ''));
        $logoPath = (string) ($settings['logo_path'] ?? $defaults['logo_path']);

        if ($brandName === '') {
            $errors[] = 'Brand name is required.';
        }

        if ($systemName === '') {
            $errors[] = 'System name is required.';
        }

        if (!empty($_FILES['logo']['name'])) {
            $logo = $_FILES['logo'];
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];
            $mimeType = mime_content_type($logo['tmp_name']);

            if (!isset($allowedTypes[$mimeType])) {
                $errors[] = 'Logo must be JPG, PNG, WEBP, or GIF.';
            } elseif ((int) $logo['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Logo must be 5MB or smaller.';
            } else {
                $filename = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
                $destination = $settingsUploadDirectory . '/' . $filename;

                if (!move_uploaded_file($logo['tmp_name'], $destination)) {
                    $errors[] = 'Logo upload failed.';
                } else {
                    $logoPath = $settingsUploadPrefix . $filename;
                }
            }
        }

        $updatedSettings = array_replace_recursive($settings, [
            'brand_name' => $brandName,
            'system_name' => $systemName,
            'logo_path' => $logoPath,
        ]);

        if (!$errors) {
            // Branding can be updated without touching the saved theme colors.
            kiwiSaveSystemSettings($updatedSettings);
            header('Location: settings.php?success=branding_updated');
            exit;
        }
    }

    if ($action === 'save_theme') {
        $colors = [];
        foreach ($defaults['colors'] as $key => $fallback) {
            $colors[$key] = kiwiSanitizeHexColor((string) ($_POST['colors'][$key] ?? ''), (string) $fallback);
        }

        $updatedSettings = array_replace_recursive($settings, [
            'colors' => $colors,
        ]);

        if (!$errors) {
            // Theme colors can be updated without touching the saved name or logo.
            kiwiSaveSystemSettings($updatedSettings);
            header('Location: settings.php?success=theme_updated');
            exit;
        }
    }

    if (!in_array($action, ['save_branding', 'save_theme'], true)) {
        $errors[] = 'Select a valid settings section to update.';
    }

    $settings = $updatedSettings;
}

$colorFields = [
    'primary' => 'Primary buttons',
    'primary_dark' => 'Primary hover',
    'success' => 'Success buttons',
    'success_dark' => 'Success hover',
    'warning' => 'Warning buttons',
    'danger' => 'Delete buttons',
    'text' => 'Main text',
    'muted_text' => 'Secondary text',
    'soft_background' => 'Soft background',
    'border' => 'Borders',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | System Settings</title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260629-class-role-permissions" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'settings'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1"><?php echo e(kiwiSystemName()); ?></p>
          <h1 class="h3 mb-0">System Settings</h1>
        </div>
        <button class="theme-toggle ms-auto" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
          <i class="fa-solid fa-moon"></i>
          <span class="d-none d-sm-inline">Dark</span>
        </button>
        <div class="dropdown">
          <button class="btn user-menu dropdown-toggle" data-bs-toggle="dropdown" type="button">
            <span class="avatar"><?php echo e(strtoupper(substr((string) $currentUser['name'], 0, 1))); ?></span>
            <span class="d-none d-sm-inline"><?php echo e((string) $currentUser['name']); ?></span>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow border-0">
            <li><span class="dropdown-item-text text-secondary small"><?php echo e((string) $currentUser['email']); ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-arrow-right-from-bracket me-2"></i>Logout</a></li>
          </ul>
        </div>
      </header>

      <section class="content-wrap">
        <?php if ($success === 'branding_updated'): ?>
          <div class="alert alert-success" role="alert">System branding updated successfully.</div>
        <?php elseif ($success === 'theme_updated'): ?>
          <div class="alert alert-success" role="alert">Theme colors and buttons updated successfully.</div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?>
              <div><?php echo e($error); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-xl-4">
            <form method="post" enctype="multipart/form-data" class="module-form">
              <input type="hidden" name="action" value="save_branding">
              <div class="panel-card h-100">
                <span class="section-kicker">Branding</span>
                <h2 class="h5 mb-4">System identity</h2>
                <div class="settings-logo-preview mb-4">
                  <img src="<?php echo e((string) $settings['logo_path']); ?>" alt="Current system logo">
                </div>
                <div class="mb-3">
                  <label class="form-label" for="brand_name">Brand name</label>
                  <input type="text" class="form-control" id="brand_name" name="brand_name" value="<?php echo e((string) $settings['brand_name']); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="system_name">System name</label>
                  <input type="text" class="form-control" id="system_name" name="system_name" value="<?php echo e((string) $settings['system_name']); ?>" required>
                </div>
                <div class="mb-0">
                  <label class="form-label" for="logo">Logo</label>
                  <input type="file" class="form-control" id="logo" name="logo" accept="image/png,image/jpeg,image/webp,image/gif">
                  <small class="text-secondary">Recommended square PNG, at least 256 x 256.</small>
                </div>
                <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                  <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Update Branding
                  </button>
                </div>
              </div>
            </form>
          </div>

          <div class="col-xl-8">
            <form method="post" class="module-form">
              <input type="hidden" name="action" value="save_theme">
              <div class="panel-card h-100">
                <span class="section-kicker">Theme</span>
                <h2 class="h5 mb-4">Colors and buttons</h2>
                <div class="settings-color-grid">
                  <?php foreach ($colorFields as $key => $label): ?>
                    <label class="settings-color-field" for="color_<?php echo e($key); ?>">
                      <span><?php echo e($label); ?></span>
                      <input type="color" id="color_<?php echo e($key); ?>" name="colors[<?php echo e($key); ?>]" value="<?php echo e((string) $settings['colors'][$key]); ?>">
                    </label>
                  <?php endforeach; ?>
                </div>

                <div class="settings-button-preview mt-4">
                  <button type="button" class="btn btn-primary">Primary</button>
                  <button type="button" class="btn btn-success">Success</button>
                  <button type="button" class="btn btn-warning">Warning</button>
                  <button type="button" class="btn btn-danger">Delete</button>
                  <span class="text-secondary">Text preview</span>
                </div>

                <div class="d-flex flex-wrap justify-content-end gap-2 mt-4">
                  <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                  <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-palette me-2"></i>Update Theme
                  </button>
                </div>
              </div>
            </form>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260629-class-role-permissions"></script>
</body>
</html>
