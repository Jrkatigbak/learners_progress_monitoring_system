<?php

require_once __DIR__ . '/includes/bootstrap.php';

header('Location: ' . ($auth->check() ? 'dashboard.php' : 'login.php'));
exit;
