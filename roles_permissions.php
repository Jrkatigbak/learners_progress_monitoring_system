<?php

require_once __DIR__ . '/includes/admin_guard.php';

kiwiRequirePermission($pdo, 'users.manage');

$currentUser = $auth->user();
$errors = [];
$success = $_GET['success'] ?? '';
$editRoleId = (int) ($_GET['edit_role'] ?? 0);
$permissionDefinitions = kiwiPermissionDefinitions();
$permissionRows = [
    'dashboard' => ['label' => 'Dashboard', 'view' => 'dashboard.view'],
    'classes' => ['label' => 'Classes', 'view' => 'classes.view', 'add' => 'classes.add', 'edit' => 'classes.edit', 'delete' => 'classes.delete'],
    'grades' => ['label' => 'Grades', 'view' => 'grades.view', 'add' => 'grades.add', 'edit' => 'grades.edit', 'delete' => 'grades.delete'],
    'users' => ['label' => 'User Management', 'view' => 'users.view', 'add' => 'users.add', 'edit' => 'users.edit', 'delete' => 'users.delete'],
    'settings' => ['label' => 'System Settings', 'view' => 'settings.view', 'edit' => 'settings.edit'],
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function slugifyRoleKey(string $value): string
{
    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $value), '_'));

    return $slug !== '' ? $slug : 'custom_role';
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

$formRole = [
    'id' => 0,
    'role_name' => '',
    'role_key' => '',
    'description' => '',
    'is_system' => 0,
];
$formRolePermissions = [];

$rolePermissionRows = $pdo->query(
    'SELECT roles.role_key, role_permissions.permission_key
     FROM roles
     LEFT JOIN role_permissions ON role_permissions.role_id = roles.id
     WHERE roles.deleted_at IS NULL'
)->fetchAll();
$rolePermissions = [];

foreach ($rolePermissionRows as $row) {
    $rolePermissions[(string) $row['role_key']][] = (string) $row['permission_key'];
}

if ($editRoleId > 0) {
    $roleStatement = $pdo->prepare('SELECT * FROM roles WHERE id = :id AND role_key NOT IN ("teacher", "learner") AND deleted_at IS NULL LIMIT 1');
    $roleStatement->execute(['id' => $editRoleId]);
    $formRole = $roleStatement->fetch() ?: $formRole;
    $formRolePermissions = $rolePermissions[(string) ($formRole['role_key'] ?? '')] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $roleName = trim((string) ($_POST['role_name'] ?? ''));
        $roleKey = slugifyRoleKey((string) ($_POST['role_key'] ?? $roleName));
        $description = trim((string) ($_POST['description'] ?? ''));
        $selectedPermissions = array_values(array_intersect(array_keys($permissionDefinitions), (array) ($_POST['permissions'] ?? [])));

        if ($roleName === '') {
            $errors[] = 'Role name is required.';
        }

        if (in_array($roleKey, ['teacher', 'learner'], true)) {
            $errors[] = 'Teacher and learner are portal roles and cannot be used here.';
        }

        $existingRole = null;

        if ($roleId > 0) {
            $roleStatement = $pdo->prepare('SELECT * FROM roles WHERE id = :id AND deleted_at IS NULL LIMIT 1');
            $roleStatement->execute(['id' => $roleId]);
            $existingRole = $roleStatement->fetch() ?: null;

            if (!$existingRole) {
                $errors[] = 'Select an active role.';
            }

            if ($existingRole && (int) $existingRole['is_system'] === 1) {
                $roleKey = (string) $existingRole['role_key'];
            }
        }

        $duplicateStatement = $pdo->prepare('SELECT id FROM roles WHERE role_key = :role_key AND id <> :id AND deleted_at IS NULL LIMIT 1');
        $duplicateStatement->execute([
            'role_key' => $roleKey,
            'id' => $roleId,
        ]);

        if ($duplicateStatement->fetch()) {
            $errors[] = 'Role key already exists.';
        }

        if (!$errors) {
            if ($roleId > 0) {
                $roleSave = $pdo->prepare('UPDATE roles SET role_name = :role_name, role_key = :role_key, description = :description WHERE id = :id');
                $roleSave->execute([
                    'role_name' => $roleName,
                    'role_key' => $roleKey,
                    'description' => $description !== '' ? $description : null,
                    'id' => $roleId,
                ]);
            } else {
                $roleSave = $pdo->prepare('INSERT INTO roles (role_name, role_key, description) VALUES (:role_name, :role_key, :description)');
                $roleSave->execute([
                    'role_name' => $roleName,
                    'role_key' => $roleKey,
                    'description' => $description !== '' ? $description : null,
                ]);
                $roleId = (int) $pdo->lastInsertId();
            }

            // Replace the permission set so each role has one clear access profile.
            $permissionDelete = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $permissionDelete->execute(['role_id' => $roleId]);
            $permissionInsert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (:role_id, :permission_key)');

            foreach ($selectedPermissions as $permissionKey) {
                $permissionInsert->execute([
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey,
                ]);
            }

            header('Location: roles_permissions.php?success=role_saved');
            exit;
        }
    }

    if ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $roleStatement = $pdo->prepare('SELECT * FROM roles WHERE id = :id AND deleted_at IS NULL LIMIT 1');
        $roleStatement->execute(['id' => $roleId]);
        $role = $roleStatement->fetch() ?: null;

        if (!$role || in_array($role['role_key'], ['admin', 'staff', 'teacher', 'learner'], true) || (int) $role['is_system'] === 1) {
            $errors[] = 'System roles cannot be deleted.';
        } else {
            $userCountStatement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role = :role AND deleted_at IS NULL');
            $userCountStatement->execute(['role' => $role['role_key']]);

            if ((int) $userCountStatement->fetchColumn() > 0) {
                $errors[] = 'Move users to another role before deleting this role.';
            }
        }

        if (!$errors) {
            $deleteRole = $pdo->prepare('UPDATE roles SET deleted_at = NOW() WHERE id = :id');
            $deleteRole->execute(['id' => $roleId]);
            header('Location: roles_permissions.php?success=role_deleted');
            exit;
        }
    }
}

$roles = adminSideRoleRows($pdo);
$rolePermissionRows = $pdo->query(
    'SELECT roles.role_key, role_permissions.permission_key
     FROM roles
     LEFT JOIN role_permissions ON role_permissions.role_id = roles.id
     WHERE roles.deleted_at IS NULL'
)->fetchAll();
$rolePermissions = [];

foreach ($rolePermissionRows as $row) {
    $rolePermissions[(string) $row['role_key']][] = (string) $row['permission_key'];
}

$successMessages = [
    'role_saved' => 'Role and permissions saved successfully.',
    'role_deleted' => 'Role deleted successfully.',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo e(kiwiSystemBrandName()); ?> | Roles & Permissions</title>
  <link rel="icon" type="image/png" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(kiwiSystemLogo()); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
  <script>
    document.documentElement.setAttribute('data-theme', localStorage.getItem('kiwi-dashboard-theme') || 'light');
  </script>
  <link href="css/style.css" rel="stylesheet">
  <?php echo kiwiSystemThemeStyle(); ?>
</head>
<body class="dashboard-page">
  <div class="app-layout">
    <?php $activeSidebarItem = 'roles_permissions'; require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="main-panel">
      <header class="topbar">
        <button class="btn btn-light d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle menu">
          <i class="fa-solid fa-bars"></i>
        </button>
        <div>
          <p class="eyebrow mb-1">Access Control</p>
          <h1 class="h3 mb-0">Roles & Permissions</h1>
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
              <span class="section-kicker"><?php echo !empty($formRole['id']) ? 'Edit Role' : 'Add Role'; ?></span>
              <h2 class="h5 mb-4">Role details</h2>
              <form method="post" class="module-form">
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_id" value="<?php echo (int) ($formRole['id'] ?? 0); ?>">
                <div class="mb-3">
                  <label class="form-label" for="role_name">Role name</label>
                  <input type="text" class="form-control" id="role_name" name="role_name" value="<?php echo e((string) ($formRole['role_name'] ?? '')); ?>" required>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="role_key">Role key</label>
                  <input type="text" class="form-control" id="role_key" name="role_key" value="<?php echo e((string) ($formRole['role_key'] ?? '')); ?>" <?php echo !empty($formRole['is_system']) ? 'readonly' : ''; ?>>
                  <small class="text-secondary">Used internally for access checks.</small>
                </div>
                <div class="mb-3">
                  <label class="form-label" for="description">Description</label>
                  <textarea class="form-control" id="description" name="description" rows="3"><?php echo e((string) ($formRole['description'] ?? '')); ?></textarea>
                </div>
                <div class="table-responsive mb-4">
                  <table class="table align-middle permission-matrix-table">
                    <thead>
                      <tr>
                        <th>Module</th>
                        <th class="text-center">View</th>
                        <th class="text-center">Add</th>
                        <th class="text-center">Edit</th>
                        <th class="text-center">Delete</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($permissionRows as $row): ?>
                        <tr>
                          <td><strong><?php echo e($row['label']); ?></strong></td>
                          <?php foreach (['view', 'add', 'edit', 'delete'] as $actionName): ?>
                            <td class="text-center">
                              <?php if (!empty($row[$actionName])): ?>
                                <input class="permission-matrix-check" type="checkbox" name="permissions[]" value="<?php echo e($row[$actionName]); ?>" <?php echo in_array($row[$actionName], $formRolePermissions, true) ? 'checked' : ''; ?> aria-label="<?php echo e($row['label'] . ' ' . $actionName); ?>">
                              <?php else: ?>
                                <span class="text-secondary">-</span>
                              <?php endif; ?>
                            </td>
                          <?php endforeach; ?>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk me-2"></i>Save Role</button>
                  <?php if (!empty($formRole['id'])): ?><a href="roles_permissions.php" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
                </div>
              </form>
            </div>
          </div>

          <div class="col-xl-8">
            <div class="panel-card">
              <span class="section-kicker">Role Library</span>
              <h2 class="h5 mb-4">Saved roles</h2>
              <div class="table-responsive">
                <table class="table align-middle permission-summary-table">
                  <thead>
                    <tr>
                      <th>Role</th>
                      <?php foreach ($permissionRows as $row): ?>
                        <th class="text-center"><?php echo e($row['label']); ?></th>
                      <?php endforeach; ?>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($roles as $role): ?>
                      <?php $savedPermissions = $rolePermissions[(string) $role['role_key']] ?? []; ?>
                      <tr>
                        <td>
                          <strong><?php echo e((string) $role['role_name']); ?></strong><br>
                          <span class="text-secondary small"><?php echo e((string) ($role['description'] ?: $role['role_key'])); ?></span>
                        </td>
                        <?php foreach ($permissionRows as $row): ?>
                          <td>
                            <div class="permission-summary-checks">
                              <?php foreach (['view' => 'V', 'add' => 'A', 'edit' => 'E', 'delete' => 'D'] as $actionName => $label): ?>
                                <?php if (!empty($row[$actionName])): ?>
                                  <span class="<?php echo in_array($row[$actionName], $savedPermissions, true) ? 'is-checked' : ''; ?>"><?php echo e($label); ?></span>
                                <?php endif; ?>
                              <?php endforeach; ?>
                            </div>
                          </td>
                        <?php endforeach; ?>
                        <td class="text-end">
                          <div class="d-inline-flex gap-2">
                            <a class="learner-icon-button" href="roles_permissions.php?edit_role=<?php echo (int) $role['id']; ?>" aria-label="Edit role"><i class="fa-solid fa-pen"></i></a>
                            <?php if ((int) $role['is_system'] !== 1): ?>
                              <form method="post" onsubmit="return confirm('Delete this role?');">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_id" value="<?php echo (int) $role['id']; ?>">
                                <button type="submit" class="learner-icon-button is-danger" aria-label="Delete role"><i class="fa-solid fa-trash"></i></button>
                              </form>
                            <?php endif; ?>
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
  <script src="js/app.js"></script>
</body>
</html>
