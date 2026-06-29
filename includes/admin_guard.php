<?php

require_once __DIR__ . '/auth_guard.php';

if (!$auth->isAdminSideUser()) {
    // Non-admin accounts stay inside their own dashboards even if they open admin URLs.
    header('Location: ' . $auth->redirectPath());
    exit;
}
