<?php

require_once __DIR__ . '/includes/bootstrap.php';

header('Location: ' . ($auth->check() ? $auth->redirectPath() : 'login.php'));
exit;
