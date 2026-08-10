<?php
// public/index.php
// Entry point: belum login -> login.php; sudah login -> dashboard sesuai role.
session_start();
require_once __DIR__ . '/../bootstrap/autoload.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

header('Location: ' . SessionGuard::dashboardUrl($_SESSION['role'] ?? ''));
exit;
