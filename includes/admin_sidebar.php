<?php

$activeSidebarItem = $activeSidebarItem ?? pathinfo((string) ($_SERVER['SCRIPT_NAME'] ?? ''), PATHINFO_FILENAME);
$sidebarUserName = (string) ($currentUser['name'] ?? ($_SESSION['user']['name'] ?? 'Administrator'));

if (!function_exists('kiwiSidebarActive')) {
    function kiwiSidebarActive(string $item, string $activeSidebarItem): string
    {
        return $item === $activeSidebarItem ? ' class="active"' : '';
    }
}
?>
<aside class="sidebar">
  <a class="sidebar-brand" href="dashboard.php">
    <img src="<?php echo htmlspecialchars(kiwiSystemLogo(), ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars(kiwiSystemBrandName(), ENT_QUOTES, 'UTF-8'); ?>" class="brand-logo">
    <span>
      <strong><?php echo htmlspecialchars(kiwiSystemBrandName(), ENT_QUOTES, 'UTF-8'); ?></strong>
      <!-- Shared admin sidebar keeps the main dashboard navigation consistent across modules. -->
      <small><?php echo htmlspecialchars(kiwiSystemName(), ENT_QUOTES, 'UTF-8'); ?></small>
    </span>
  </a>
  <nav class="sidebar-nav">
    <?php if (kiwiCan($pdo, 'dashboard.view')): ?>
      <a<?php echo kiwiSidebarActive('dashboard', $activeSidebarItem); ?> href="dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
    <?php endif; ?>
    <?php if (kiwiCan($pdo, 'classes.manage')): ?>
      <a<?php echo kiwiSidebarActive('classes', $activeSidebarItem); ?> href="classes.php"><i class="fa-solid fa-chalkboard-user"></i> Classes</a>
      <a<?php echo kiwiSidebarActive('teachers', $activeSidebarItem); ?> href="teachers.php"><i class="fa-solid fa-user-tie"></i> Teachers Masterlist</a>
      <a<?php echo kiwiSidebarActive('learners', $activeSidebarItem); ?> href="learners.php"><i class="fa-solid fa-users"></i> Learners Masterlist</a>
    <?php endif; ?>
    <?php if (kiwiCan($pdo, 'users.manage')): ?>
      <a<?php echo kiwiSidebarActive('user_management', $activeSidebarItem); ?> href="user_management.php"><i class="fa-solid fa-users-gear"></i> User Management</a>
      <a<?php echo kiwiSidebarActive('roles_permissions', $activeSidebarItem); ?> href="roles_permissions.php"><i class="fa-solid fa-shield-halved"></i> Roles & Permissions</a>
    <?php endif; ?>
    <?php if (kiwiCan($pdo, 'settings.manage')): ?>
      <a<?php echo kiwiSidebarActive('settings', $activeSidebarItem); ?> href="settings.php"><i class="fa-solid fa-sliders"></i> System Settings</a>
    <?php endif; ?>
    <?php if (kiwiCan($pdo, 'grades.manage')): ?>
      <a<?php echo kiwiSidebarActive('grades', $activeSidebarItem); ?> href="grades.php"><i class="fa-solid fa-star"></i> Grades</a>
    <?php endif; ?>
  </nav>
  <div class="sidebar-footer">
    <p class="mb-1">Logged in as</p>
    <strong><?php echo htmlspecialchars($sidebarUserName, ENT_QUOTES, 'UTF-8'); ?></strong>
  </div>
</aside>
