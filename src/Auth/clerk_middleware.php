<?php

declare(strict_types=1);

/**
 * Clerk Authentication Middleware.
 *
 * Dipanggil di awal setiap halaman yang dilindungi.
 * Bila Clerk dikonfigurasi, verifikasi JWT dari cookie.
 * Bila tidak, fallback ke session-based auth (login lokal).
 *
 * Penggunaan di halaman:
 *   require __DIR__ . '/../src/autoload.php';
 *   require __DIR__ . '/../src/Auth/clerk_middleware.php';
 *
 * Variabel yang tersedia setelah middleware:
 *   $authUser  — array user info atau null
 *   $authMode  — 'clerk' | 'local' | null
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$authUser = null;
$authMode = null;

// Cek apakah sudah login via session (baik Clerk maupun lokal)
if (isset($_SESSION['user_id'])) {
    // Sudah login via session — tidak perlu verifikasi ulang
    $authMode = $_SESSION['auth_provider'] ?? 'local';
    $authUser = [
        'user_id' => $_SESSION['user_id'],
        'nama'    => $_SESSION['nama'] ?? '',
        'role'    => $_SESSION['role'] ?? 'kasir',
    ];

    // Tambah info Clerk bila ada
    if ($authMode === 'clerk') {
        $authUser['email'] = $_SESSION['clerk_email'] ?? '';
        $authUser['clerk_id'] = $_SESSION['clerk_user_id'] ?? '';
    }
} else {
    // Belum login via session — coba Clerk
    $clerk = \App\Auth\ClerkAuth::getInstance();

    if ($clerk->isConfigured()) {
        $result = $clerk->attempt();

        if ($result['authenticated']) {
            $authMode = 'clerk';
            $authUser = [
                'user_id' => $_SESSION['user_id'],
                'nama'    => $_SESSION['nama'] ?? '',
                'role'    => $_SESSION['role'] ?? 'admin',
                'email'   => $result['email'] ?? '',
                'clerk_id' => $result['user_id'] ?? '',
            ];
        }
    }

    // Redirect ke login bila belum authenticated
    if ($authUser === null) {
        $loginPage = ($clerk->isConfigured()) ? 'clerk-login.php' : 'login.php';
        header('Location: ' . $loginPage);
        exit;
    }
}
