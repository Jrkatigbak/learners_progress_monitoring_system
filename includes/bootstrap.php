<?php

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
require_once __DIR__ . '/system_settings.php';
require_once __DIR__ . '/permissions.php';

$database = new Database();
$pdo = $database->connect();
$auth = new Auth($pdo);
