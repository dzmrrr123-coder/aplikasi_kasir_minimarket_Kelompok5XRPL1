<?php

declare(strict_types=1);

/**
 * public/logout.php
 * Proses logout: reset state object user, hancurkan session karyawan, balik ke login.
 */

require __DIR__ . '/../src/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        $user = new \App\Models\Admin();
    } else {
        $user = new \App\Models\Kasir();
    }
    $user->logout();
}

logoutKaryawan();

// Sesi dihancurkan bila tidak ada sesi member lain yang aktif
if (empty($_SESSION['member_id'])) {
    session_destroy();
}

header('Location: login.php');
exit;
