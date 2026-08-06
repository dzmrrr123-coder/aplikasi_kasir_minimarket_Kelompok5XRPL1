<?php

declare(strict_types=1);

/**
 * Entry point aplikasi kasir minimarket.
 *
 * Menginisialisasi skema database (migrasi idempotent) lalu mengarahkan
 * pengguna: sudah login -> halaman sesuai role; belum -> halaman login.
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Jalankan skema & migrasi (aman dijalankan berulang kali).
Database::runSchema();

// Sudah login -> arahkan sesuai role.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: transaksi.php');
    }
    exit;
}

header('Location: login.php');
exit;
