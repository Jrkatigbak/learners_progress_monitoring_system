<?php

require_once __DIR__ . '/bootstrap.php';

if (!$auth->check()) {
    header('Location: login.php');
    exit;
}

$currentUser = $auth->user();
