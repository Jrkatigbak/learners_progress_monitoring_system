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
        'class_learners.view' => ['label' => 'Class Learners', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_learners.add' => ['label' => 'Class Learners', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_learners.edit' => ['label' => 'Class Learners', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_learners.delete' => ['label' => 'Class Learners', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_teachers.view' => ['label' => 'Class Teachers', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_teachers.add' => ['label' => 'Class Teachers', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_teachers.edit' => ['label' => 'Class Teachers', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_teachers.delete' => ['label' => 'Class Teachers', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_materials.view' => ['label' => 'Class Materials', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_materials.add' => ['label' => 'Class Materials', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_materials.edit' => ['label' => 'Class Materials', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_materials.delete' => ['label' => 'Class Materials', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_quizzes.view' => ['label' => 'Class Quizzes', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_quizzes.add' => ['label' => 'Class Quizzes', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_quizzes.edit' => ['label' => 'Class Quizzes', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_quizzes.delete' => ['label' => 'Class Quizzes', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_assignments.view' => ['label' => 'Class Assignments', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_assignments.add' => ['label' => 'Class Assignments', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_assignments.edit' => ['label' => 'Class Assignments', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_assignments.delete' => ['label' => 'Class Assignments', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_grades.view' => ['label' => 'Class Grades', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_grades.add' => ['label' => 'Class Grades', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_grades.edit' => ['label' => 'Class Grades', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_grades.delete' => ['label' => 'Class Grades', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_certificates.view' => ['label' => 'Class Certificates', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_certificates.add' => ['label' => 'Class Certificates', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_certificates.edit' => ['label' => 'Class Certificates', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_certificates.delete' => ['label' => 'Class Certificates', 'group' => 'Class Workspace', 'action' => 'delete'],
        'class_evaluations.view' => ['label' => 'Class Evaluations', 'group' => 'Class Workspace', 'action' => 'view'],
        'class_evaluations.add' => ['label' => 'Class Evaluations', 'group' => 'Class Workspace', 'action' => 'add'],
        'class_evaluations.edit' => ['label' => 'Class Evaluations', 'group' => 'Class Workspace', 'action' => 'edit'],
        'class_evaluations.delete' => ['label' => 'Class Evaluations', 'group' => 'Class Workspace', 'action' => 'delete'],
    ];
}

function kiwiAdminSideRoles(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT role_key
         FROM roles
         WHERE role_key <> "learner"
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
        'class_learners.manage' => ['class_learners.view', 'class_learners.add', 'class_learners.edit', 'class_learners.delete'],
        'class_teachers.manage' => ['class_teachers.view', 'class_teachers.add', 'class_teachers.edit', 'class_teachers.delete'],
        'class_materials.manage' => ['class_materials.view', 'class_materials.add', 'class_materials.edit', 'class_materials.delete'],
        'class_quizzes.manage' => ['class_quizzes.view', 'class_quizzes.add', 'class_quizzes.edit', 'class_quizzes.delete'],
        'class_assignments.manage' => ['class_assignments.view', 'class_assignments.add', 'class_assignments.edit', 'class_assignments.delete'],
        'class_grades.manage' => ['class_grades.view', 'class_grades.add', 'class_grades.edit', 'class_grades.delete'],
        'class_certificates.manage' => ['class_certificates.view', 'class_certificates.add', 'class_certificates.edit', 'class_certificates.delete'],
        'class_evaluations.manage' => ['class_evaluations.view', 'class_evaluations.add', 'class_evaluations.edit', 'class_evaluations.delete'],
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
