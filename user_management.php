<?php

require_once __DIR__ . '/includes/admin_guard.php';

kiwiRequirePermission($pdo, 'users.manage');

$currentUser = $auth->user();
$errors = [];
$success = $_GET['success'] ?? '';
$search = trim((string) ($_GET['search'] ?? ''));
$editUserId = (int) ($_GET['edit_user'] ?? 0);

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function adminUserCount(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND deleted_at IS NULL")->fetchColumn();
}

function adminSideRoleRows(PDO $pdo): array
{
    return $pdo->query(
        'SELECT *
         FROM roles
         WHERE role_key NOT IN ("teacher", "learner")
           AND deleted_at IS NULL
         ORDER BY FIELD(role_key, "admin", "staff"), role_name'
    )->fetchAll();
}

$roles = adminSideRoleRows($pdo);
$roleKeys = array_map(static fn (array $role): string => (string) $role['role_key'], $roles);
$formUser = [
    'id' => 0,
    'name' => '',
    'email' => '',
    'role' => 'staff',
];

if ($editUserId > 0) {
    $editStatement = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $editStatement->execute(['id' => $editUserId]);
    $formUser = $editStatement->fetch() ?: $formUser;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $role = (string) ($_POST['role'] ?? 'staff');
        $password = (string) ($_POST['password'] ?? '');

        if ($name === '') {
            $errors[] = 'Full name is required.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        }

        if (!in_array($role, $roleKeys, true)) {
            $errors[] = 'Select an active role.';
        }

        if ($userId <= 0 && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($userId > 0 && $password !== '' && strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }

        if ($userId > 0) {
            $targetStatement = $pdo->prepare('SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $targetStatement->execute(['id' => $userId]);
            $targetUser = $targetStatement->fetch() ?: null;

            if (!$targetUser || !in_array((string) $targetUser['role'], $roleKeys, true)) {
                $errors[] = 'Select an active admin-side user.';
            } elseif ($targetUser['role'] === 'admin' && $role !== 'admin' && adminUserCount($pdo) <= 1) {
                $errors[] = 'Keep at least one active admin account.';
            }
        }

        $duplicateStatement = $pdo->prepare('SELECT id FROM users WHERE email = :email AND role = :role AND id <> :id AND deleted_at IS NULL LIMIT 1');
        $duplicateStatement->execute([
            'email' => $email,
            'role' => $role,
            'id' => $userId,
        ]);

        if ($duplicateStatement->fetch()) {
            $errors[] = 'An active account already uses this email and role.';
        }

        if (!$errors) {
            if ($userId > 0) {
                $fields = 'name = :name, email = :email, role = :role';
                $params = [
                    'name' => $name,
                    'email' => $email,
                    'role' => $role,
                    'id' => $userId,
                ];

                if ($password !== '') {
                    // Password resets are optional while editing, so existing access stays unchanged by default.
                    $fields .= ', password_hash = :password_hash';
                    $params['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }

                $update = $pdo->prepare("UPDATE users SET {$fields} WHERE id = :id AND deleted_at IS NULL");
                $update->execute($params);
                header('Location: user_management.php?success=user_updated');
                exit;
            }

            $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (:name, :email, :password_hash, :role)');
            $insert->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
            ]);
            header('Location: user_management.php?success=user_created');
            exit;
        }
    }

    if ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === (int) $currentUser['id']) {
            $errors[] = 'You cannot delete your own account.';
        }

        $targetStatement = $pdo->prepare('SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $targetStatement->execute(['id' => $userId]);
        $targetUser = $targetStatement->fetch() ?: null;

        if (!$targetUser || !in_array((string) $targetUser['role'], $roleKeys, true)) {
            $errors[] = 'Select an active admin-side user.';
        } elseif ($targetUser['role'] === 'admin' && adminUserCount($pdo) <= 1) {
            $errors[] = 'Keep at least one active admin account.';
        }

        if (!$errors) {
            // Soft delete removes login access without losing account history.
            $delete = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL');
            $delete->execute(['id' => $userId]);
            header('Location: user_management.php?success=user_deleted');
            exit;
        }
    }
}

$roles = adminSideRoleRows($pdo);
$roleKeys = array_map(static fn (array $role): string => (string) $role['role_key'], $roles);
$where = 'WHERE users.deleted_at IS NULL AND users.role IN (' . implode(',', array_map([$pdo, 'quote'], $roleKeys)) . ')';
$params = [];

if ($search !== '') {
    $where .= ' AND (users.name LIKE :search OR users.email LIKE :search OR users.role LIKE :search)';
    $params['search'] = '%' . $search . '%';
}

$userStatement = $pdo->prepare("SELECT users.id, users.name, users.email, users.role, users.created_at, roles.role_name FROM users LEFT JOIN roles ON roles.role_key COLLATE utf8mb4_general_ci = users.role {$where} ORDER BY FIELD(users.role, 'admin', 'staff'), users.name ASC");
$userStatement->execute($params);
$users = $userStatement->fetchAll();

$successMessages = [
    'user_created' => 'User account added successfully.',
    'user_updated' => 'User account updated successfully.',
    'user_deleted' => 'User account removed successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | User Management</title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css?v=20260713-learner-sidebar-teacher-email" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'user_management'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Users Management</p>
          <h1 class="h3 mb-0">User Management</h1>
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
        <?php if ($success && isset($successMessages[$success])): ?>
          <div class="alert alert-success" role="alert"><?php echo e($successMessages[$success]); ?></div>
        <?php endif; ?>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <?php foreach ($errors as $error): ?><div><?php echo e($error); ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <div class="row g-4">
          <div class="col-xl-4">
            <div class="panel-card">
              <span class="section-kicker"><?php echo !empty($formUser['id']) ? 'Edit User' : 'Add User'; ?></span>
              <h2 class="h5 mb-4">User account</h2>
              <form method="post" class="module-form">
                <input type="hidden" name="action" value="save_user">
                <input type="hidden" name="user_id" value="<?php echo (int) ($formUser['id'] ?? 0); ?>">
                <div class="mb-3">
                  <label class="form-label" for="name">Full name</label>
                  <input type="text" class="form-control" id="name" name="name" value="<?php echo e((string) ($formUser['name'] ?? '')); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="email">Email</label>
                  <input type="email" class="form-control" id="email" name="email" value="<?php echo e((string) ($formUser['email'] ?? '')); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="role">Role</label>
                  <select class="form-select" id="role" name="role" required>
                    <?php foreach ($roles as $role): ?>
                      <option value="<?php echo e((string) $role['role_key']); ?>" <?php echo ($formUser['role'] ?? '') === $role['role_key'] ? 'selected' : ''; ?>><?php echo e((string) $role['role_name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-4">
                  <label class="form-label" for="password"><?php echo !empty($formUser['id']) ? 'New password' : 'Password'; ?></label>
                  <input type="password" class="form-control" id="password" name="password" <?php echo empty($formUser['id']) ? 'required' : ''; ?> minlength="8" autocomplete="new-password">
                  <?php if (!empty($formUser['id'])): ?><small class="text-secondary">Leave blank to keep the current password.</small><?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i><?php echo !empty($formUser['id']) ? 'Save User' : 'Add User'; ?></button>
                  <?php if (!empty($formUser['id'])): ?><a href="user_management.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <div class="col-xl-8">
            <div class="panel-card">
              <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
                <div>
                  <span class="section-kicker">Directory</span>
                  <h2 class="h5 mb-0">User accounts</h2>
                </div>
                <form method="get" class="d-flex gap-2 admin-staff-search">
                  <input type="search" class="form-control" name="search" value="<?php echo e($search); ?>" placeholder="Search name, email, or role">
                  <button type="submit" class="btn btn-outline-primary">Search</button>
                  <?php if ($search !== ''): ?><a href="user_management.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
              </div>
              <div class="table-responsive">
                <table class="table align-middle">
                  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th class="text-end">Actions</th></tr></thead>
                  <tbody>
                    <?php if (!$users): ?><tr><td colspan="5" class="text-center text-secondary py-5">No users found.</td></tr><?php endif; ?>
                    <?php foreach ($users as $user): ?>
                      <tr>
                        <td><strong><?php echo e((string) $user['name']); ?></strong></td>
                        <td><?php echo e((string) $user['email']); ?></td>
                        <td><span class="badge <?php echo $user['role'] === 'admin' ? 'text-bg-primary' : 'text-bg-secondary'; ?>"><?php echo e((string) ($user['role_name'] ?: ucfirst((string) $user['role']))); ?></span></td>
                        <td><?php echo e(date('M d, Y', strtotime((string) $user['created_at']))); ?></td>
                        <td class="text-end">
                          <div class="d-inline-flex gap-2">
                            <a class="learner-icon-button" href="user_management.php?edit_user=<?php echo (int) $user['id']; ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>" aria-label="Edit user"><i class="fa-solid fa-pen"></i></a>
                            <form method="post" onsubmit="return confirm('Remove this user account?');">
                              <input type="hidden" name="action" value="delete_user">
                              <input type="hidden" name="user_id" value="<?php echo (int) $user['id']; ?>">
                              <button class="learner-icon-button is-danger" type="submit" aria-label="Delete user" <?php echo (int) $user['id'] === (int) $currentUser['id'] ? 'disabled' : ''; ?>><i class="fa-solid fa-trash"></i></button>
                            </form>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="js/app.js?v=20260713-learner-sidebar-teacher-email"></script>
</body>
</html>
