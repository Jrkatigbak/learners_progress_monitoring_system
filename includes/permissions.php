<?php

function kiwiPermissionDefinitions(): array
{
    return [
        'dashboard.view' => ['label' => 'Dashboard', 'group' => 'Dashboard', 'action' => 'view'],
        'classes.view' => ['label' => 'Classes', 'group' => 'Classes', 'action' => 'view'],
        'classes.add' => ['label' => 'Classes', 'group' => 'Classes', 'action' => 'add'],
        'classes.edit' => ['label' => 'Classes', 'group' => 'Classes', 'action' => 'edit'],
        'classes.delete' => ['label' => 'Classes', 'group' => 'Classes', 'action' => 'delete'],
        'grades.view' => ['label' => 'Grades', 'group' => 'Grades', 'action' => 'view'],
        'grades.add' => ['label' => 'Grades', 'group' => 'Grades', 'action' => 'add'],
        'grades.edit' => ['label' => 'Grades', 'group' => 'Grades', 'action' => 'edit'],
        'grades.delete' => ['label' => 'Grades', 'group' => 'Grades', 'action' => 'delete'],
        'users.view' => ['label' => 'User Management', 'group' => 'Users', 'action' => 'view'],
        'users.add' => ['label' => 'User Management', 'group' => 'Users', 'action' => 'add'],
        'users.edit' => ['label' => 'User Management', 'group' => 'Users', 'action' => 'edit'],
        'users.delete' => ['label' => 'User Management', 'group' => 'Users', 'action' => 'delete'],
        'settings.view' => ['label' => 'System Settings', 'group' => 'Settings', 'action' => 'view'],
        'settings.edit' => ['label' => 'System Settings', 'group' => 'Settings', 'action' => 'edit'],
    ];
}

function kiwiAdminSideRoles(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT role_key
         FROM roles
         WHERE role_key NOT IN ("teacher", "learner")
           AND deleted_at IS NULL'
    );

    return array_map(static fn (array $role): string => (string) $role['role_key'], $statement->fetchAll());
}

function kiwiUserPermissions(PDO $pdo, ?array $user = null): array
{
    static $cache = [];

    $user = $user ?? ($_SESSION['user'] ?? null);
    $role = (string) ($user['role'] ?? '');

    if ($role === '') {
        return [];
    }

    if ($role === 'admin') {
        return array_keys(kiwiPermissionDefinitions());
    }

    if (isset($cache[$role])) {
        return $cache[$role];
    }

    $statement = $pdo->prepare(
        'SELECT role_permissions.permission_key
         FROM roles
         INNER JOIN role_permissions ON role_permissions.role_id = roles.id
         WHERE roles.role_key = :role_key
           AND roles.deleted_at IS NULL'
    );
    $statement->execute(['role_key' => $role]);

    $cache[$role] = array_map(static fn (array $permission): string => (string) $permission['permission_key'], $statement->fetchAll());

    return $cache[$role];
}

function kiwiCan(PDO $pdo, string $permission, ?array $user = null): bool
{
    $user = $user ?? ($_SESSION['user'] ?? null);

    if (($user['role'] ?? '') === 'admin') {
        return true;
    }

    $permissions = kiwiUserPermissions($pdo, $user);

    if (in_array($permission, $permissions, true)) {
        return true;
    }

    $aliases = [
        'classes.manage' => ['classes.view', 'classes.add', 'classes.edit', 'classes.delete'],
        'grades.manage' => ['grades.view', 'grades.add', 'grades.edit', 'grades.delete'],
        'users.manage' => ['users.view', 'users.add', 'users.edit', 'users.delete'],
        'settings.manage' => ['settings.view', 'settings.edit'],
    ];

    foreach ($aliases[$permission] ?? [] as $aliasPermission) {
        if (in_array($aliasPermission, $permissions, true)) {
            return true;
        }
    }

    return false;
}

function kiwiRequirePermission(PDO $pdo, string $permission): void
{
    if (!kiwiCan($pdo, $permission)) {
        header('Location: dashboard.php');
        exit;
    }
}
