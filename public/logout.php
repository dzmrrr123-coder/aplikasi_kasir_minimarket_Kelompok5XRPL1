<?php
// public/logout.php
// Proses logout: reset state object user, hancurkan session, balik ke login.
session_start();
require_once __DIR__ . '/../bootstrap/autoload.php';

if (!empty($_SESSION['user_id'])) {
    // Panggil logout() lewat object sesuai role yang tersimpan di session.
    $user = ($_SESSION['role'] ?? '') === 'admin' ? new Admin() : new Kasir();
    $user->logout();
}

session_destroy();
header('Location: ' . SessionGuard::baseUrl() . '/login.php');
exit;
