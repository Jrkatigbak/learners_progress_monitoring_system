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
    'class_learners' => ['module' => 'Class Workspace', 'label' => 'Manage Learners', 'view' => 'class_learners.view', 'add' => 'class_learners.add', 'edit' => 'class_learners.edit', 'delete' => 'class_learners.delete'],
    'class_teachers' => ['module' => 'Class Workspace', 'label' => 'Manage Teachers', 'view' => 'class_teachers.view', 'add' => 'class_teachers.add', 'edit' => 'class_teachers.edit', 'delete' => 'class_teachers.delete'],
    'class_materials' => ['module' => 'Class Workspace', 'label' => 'Class Materials', 'view' => 'class_materials.view', 'add' => 'class_materials.add', 'edit' => 'class_materials.edit', 'delete' => 'class_materials.delete'],
    'class_quizzes' => ['module' => 'Class Workspace', 'label' => 'Class Quizzes', 'view' => 'class_quizzes.view', 'add' => 'class_quizzes.add', 'edit' => 'class_quizzes.edit', 'delete' => 'class_quizzes.delete'],
    'class_assignments' => ['module' => 'Class Workspace', 'label' => 'Class Assignments', 'view' => 'class_assignments.view', 'add' => 'class_assignments.add', 'edit' => 'class_assignments.edit', 'delete' => 'class_assignments.delete'],
    'class_grades' => ['module' => 'Class Workspace', 'label' => 'Class Grades', 'view' => 'class_grades.view', 'add' => 'class_grades.add', 'edit' => 'class_grades.edit', 'delete' => 'class_grades.delete'],
    'class_certificates' => ['module' => 'Class Workspace', 'label' => 'Class Certificates', 'view' => 'class_certificates.view', 'add' => 'class_certificates.add', 'edit' => 'class_certificates.edit', 'delete' => 'class_certificates.delete'],
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
         WHERE role_key <> "learner"
           AND deleted_at IS NULL
         ORDER BY FIELD(role_key, "admin", "staff", "teacher"), role_name'
    )->fetchAll();
}

function adminSideRoleUserCounts(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT role, COUNT(*) AS total
         FROM users
         WHERE deleted_at IS NULL
         GROUP BY role'
    );
    $counts = [];

    foreach ($statement->fetchAll() as $row) {
        $counts[(string) $row['role']] = (int) $row['total'];
    }

    return $counts;
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
    $roleStatement = $pdo->prepare('SELECT * FROM roles WHERE id = :id AND role_key <> "learner" AND deleted_at IS NULL LIMIT 1');
    $roleStatement->execute(['id' => $editRoleId]);
    $formRole = $roleStatement->fetch() ?: $formRole;
    $formRolePermissions = $rolePermissions[(string) ($formRole['role_key'] ?? '')] ?? [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $roleName = trim((string) ($_POST['role_name'] ?? ''));
        $postedRoleKey = trim((string) ($_POST['role_key'] ?? ''));
        $roleKey = slugifyRoleKey($postedRoleKey !== '' ? $postedRoleKey : $roleName);
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($roleName === '') {
            $errors[] = 'Role name is required.';
        }

        if ($roleKey === 'learner') {
            $errors[] = 'Learner is a portal role and cannot be used here.';
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

            header('Location: roles_permissions.php?success=role_saved&edit_role=' . $roleId);
            exit;
        }
    }

    if ($action === 'save_permissions') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $selectedPermissions = array_values(array_intersect(array_keys($permissionDefinitions), (array) ($_POST['permissions'] ?? [])));
        $roleStatement = $pdo->prepare('SELECT * FROM roles WHERE id = :id AND role_key <> "learner" AND deleted_at IS NULL LIMIT 1');
        $roleStatement->execute(['id' => $roleId]);
        $role = $roleStatement->fetch() ?: null;

        if (!$role) {
            $errors[] = 'Select an active role before saving permissions.';
        }

        if (!$errors) {
            // Replace only the selected role permission set from the dedicated permission matrix.
            $permissionDelete = $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id');
            $permissionDelete->execute(['role_id' => $roleId]);
            $permissionInsert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_key) VALUES (:role_id, :permission_key)');

            foreach ($selectedPermissions as $permissionKey) {
                $permissionInsert->execute([
                    'role_id' => $roleId,
                    'permission_key' => $permissionKey,
                ]);
            }

            header('Location: roles_permissions.php?success=permissions_saved&edit_role=' . $roleId . '#assign-permissions');
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
$roleUserCounts = adminSideRoleUserCounts($pdo);
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
    'role_saved' => 'Role saved successfully.',
    'permissions_saved' => 'Permissions saved successfully.',
    'role_deleted' => 'Role deleted successfully.',
];

$selectedPermissionRole = !empty($formRole['id']) ? $formRole : ($roles[0] ?? null);
$selectedRolePermissions = $selectedPermissionRole ? ($rolePermissions[(string) $selectedPermissionRole['role_key']] ?? []) : [];
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
  <link href="css/style.css?v=20260713-learner-sidebar-teacher-email" rel="stylesheet">
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

        <div class="role-permission-layout">
          <div class="role-editor-card">
            <div class="role-panel-heading">
              <span class="role-heading-icon"><i class="fa-solid fa-id-badge"></i></span>
              <div>
                <span class="section-kicker">Role</span>
                <h2>Create or Update Role</h2>
              </div>
            </div>
            <form method="post" class="module-form role-editor-form">
                <input type="hidden" name="action" value="save_role">
                <input type="hidden" name="role_id" value="<?php echo (int) ($formRole['id'] ?? 0); ?>">
                <div class="mb-3">
                  <label class="form-label" for="role_name">Name <span class="text-danger">*</span></label>
                  <input type="text" class="form-control form-control-lg" id="role_name" name="role_name" value="<?php echo e((string) ($formRole['role_name'] ?? '')); ?>" placeholder="Example: Accountant" required>
                </div>
                <input type="hidden" id="role_key" name="role_key" value="<?php echo e((string) ($formRole['role_key'] ?? '')); ?>">
                <textarea class="d-none" id="description" name="description"><?php echo e((string) ($formRole['description'] ?? '')); ?></textarea>
                <button type="submit" class="btn btn-success btn-lg w-100 role-save-button">
                  <i class="fa-regular fa-circle-check me-2"></i>Save Role
                </button>
                <a href="roles_permissions.php" class="btn btn-outline-success btn-lg w-100 role-clear-button">Clear Form</a>
            </form>
          </div>

          <div class="role-list-card">
            <div class="role-panel-heading">
              <span class="role-heading-icon"><i class="fa-solid fa-list-check"></i></span>
              <div>
                <span class="section-kicker">Role List</span>
                <h2>Available System Roles</h2>
              </div>
            </div>
            <input type="search" class="form-control form-control-lg role-search-input" id="roleSearchInput" placeholder="Search roles...">
            <div class="role-list-table-wrap">
              <table class="table align-middle role-list-table">
                  <thead>
                    <tr>
                      <th>Role</th>
                      <th class="text-end">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($roles as $role): ?>
                      <?php
                        $roleKey = (string) $role['role_key'];
                        $assignedUsers = $roleUserCounts[$roleKey] ?? 0;
                      ?>
                      <tr class="role-list-row" data-search="<?php echo e(strtolower((string) $role['role_name'] . ' ' . $roleKey)); ?>">
                        <td>
                          <strong><?php echo e((string) $role['role_name']); ?></strong>
                          <span><?php echo $assignedUsers > 0 ? $assignedUsers . ' assigned user(s)' : 'No assigned users'; ?></span>
                        </td>
                        <td class="text-end">
                          <div class="role-list-actions">
                            <a class="role-action-button" href="roles_permissions.php?edit_role=<?php echo (int) $role['id']; ?>#assign-permissions" aria-label="Assign permissions" title="Assign permissions"><i class="fa-solid fa-tags"></i></a>
                            <a class="role-action-button" href="roles_permissions.php?edit_role=<?php echo (int) $role['id']; ?>" aria-label="Edit role" title="Edit role"><i class="fa-solid fa-pen"></i></a>
                            <?php if ((int) $role['is_system'] !== 1): ?>
                              <form method="post" onsubmit="return confirm('Delete this role?');">
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_id" value="<?php echo (int) $role['id']; ?>">
                                <button type="submit" class="role-action-button is-danger" aria-label="Delete role" title="Delete role"><i class="fa-solid fa-xmark"></i></button>
                              </form>
                            <?php else: ?>
                              <button type="button" class="role-action-button is-danger" disabled aria-label="System role cannot be deleted" title="System role cannot be deleted"><i class="fa-solid fa-xmark"></i></button>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
                <p class="role-list-count mb-0">Showing 1 to <?php echo count($roles); ?> of <?php echo count($roles); ?> entries</p>
            </div>
          </div>
        </div>

        <div class="role-permission-assign-card" id="assign-permissions">
          <div class="role-permission-assign-head">
            <div class="role-panel-heading">
              <span class="role-heading-icon"><i class="fa-solid fa-shield-halved"></i></span>
              <div>
                <span class="section-kicker">Assign Permission</span>
                <h2>Assign Permission<?php echo $selectedPermissionRole ? ' (' . e((string) $selectedPermissionRole['role_name']) . ')' : ''; ?></h2>
              </div>
            </div>
          </div>

          <?php if (!$selectedPermissionRole): ?>
            <div class="empty-state">
              <i class="fa-solid fa-shield-halved"></i>
              <p>Create a role before assigning permissions.</p>
            </div>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="action" value="save_permissions">
              <input type="hidden" name="role_id" value="<?php echo (int) $selectedPermissionRole['id']; ?>">
              <div class="role-permission-scroll table-responsive">
                <table class="table align-middle permission-matrix-table role-assign-table">
                  <thead>
                    <tr>
                      <th>Module</th>
                      <th>Feature</th>
                      <th class="text-center">View</th>
                      <th class="text-center">Add</th>
                      <th class="text-center">Edit</th>
                      <th class="text-center">Delete</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($permissionRows as $moduleKey => $row): ?>
                      <tr>
                        <td><strong><?php echo e($row['module'] ?? ucfirst($moduleKey === 'users' ? 'Administration' : $row['label'])); ?></strong></td>
                        <td><?php echo e($row['label']); ?></td>
                        <?php foreach (['view', 'add', 'edit', 'delete'] as $actionName): ?>
                          <td class="text-center">
                            <?php if (!empty($row[$actionName])): ?>
                              <input class="permission-matrix-check" type="checkbox" name="permissions[]" value="<?php echo e($row[$actionName]); ?>" <?php echo in_array($row[$actionName], $selectedRolePermissions, true) ? 'checked' : ''; ?> aria-label="<?php echo e($row['label'] . ' ' . $actionName); ?>">
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
              <div class="role-permission-save-row">
                <button type="submit" class="btn btn-success btn-lg role-permission-save-button">
                  <i class="fa-regular fa-square-check me-2"></i>Save Permissions
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </section>
    </main>
  </div>

  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    (function () {
      var roleSearch = document.getElementById('roleSearchInput');

      if (!roleSearch) {
        return;
      }

      roleSearch.addEventListener('input', function () {
        var query = roleSearch.value.trim().toLowerCase();

        document.querySelectorAll('.role-list-row').forEach(function (row) {
          row.classList.toggle('d-none', query !== '' && !row.dataset.search.includes(query));
        });
      });
    })();
  </script>
  <script src="js/app.js?v=20260713-learner-sidebar-teacher-email"></script>
</body>
</html>
