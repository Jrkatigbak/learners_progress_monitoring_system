<?php

require_once __DIR__ . '/auth_guard.php';

if (!$auth->isTeacher()) {
    // Keep admin and learner accounts inside their own dashboards.
    header('Location: ' . $auth->redirectPath());
    exit;
}
