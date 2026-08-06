<?php

declare(strict_types=1);

/**
 * Halaman login kasir minimarket.
 *
 * Alur: validasi kredensial lewat User::loginPolimorfik() yang mengembalikan
 * objek Admin atau Kasir secara polimorfik (berdasarkan role di database),
 * simpan user_id, role, dan nama ke $_SESSION, lalu redirect sesuai role:
 *   - admin -> dashboard.php
 *   - kasir -> transaksi.php
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\User;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function redirectSesuaiRole(string $role): never
{
    if ($role === 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: transaksi.php');
    }
    exit;
}

// Sudah login: validasi sesi masih valid (user masih ada & aktif di DB).
// Kalau user dihapus/nonaktif, hancurkan sesi supaya tidak redirect-loop.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $userSesi = User::cariBerdasarkanId((int) $_SESSION['user_id']);

    if ($userSesi === null || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'kasir')) {
        session_unset();
        session_destroy();
        // Lanjut ke form login di bawah (tidak redirect).
    } else {
        redirectSesuaiRole((string) $_SESSION['role']);
    }
}

$error = '';
$username = '';

// Rate-limit login sederhana: maksimal 5 percobaan gagal per 5 menit.
// Disimpan per-IP di file temp (tidak bergantung sesi yang belum ada).
$fileGagal = sys_get_temp_dir() . '/kasir-login-' . md5($_SERVER['REMOTE_ADDR'] ?? 'local') . '.txt';
$percobaan = 0;
$terkunciSampai = 0;

if (is_file($fileGagal)) {
    $data = @unserialize((string) file_get_contents($fileGagal));
    if (is_array($data)) {
        $percobaan = (int) ($data['percobaan'] ?? 0);
        $terkunciSampai = (int) ($data['terkunci_sampai'] ?? 0);
    }
    // Reset percobaan kalau sudah lewat jendela 5 menit.
    if ($terkunciSampai === 0 && time() - (int) ($data['waktu'] ?? 0) > 300) {
        $percobaan = 0;
    }
}

$terkunci = $terkunciSampai > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($terkunci) {
        $sisa = (int) ceil(($terkunciSampai - time()) / 60);
        $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . max(1, $sisa) . ' menit.';
    } else {
        // Login polimorfik: dapat objek Admin atau Kasir spesifik dari role DB.
        $user = User::loginPolimorfik($username, $password);

        if ($user !== null) {
            // Sukses: reset counter & bersihkan file rate-limit.
            @unlink($fileGagal);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user->getId();
            $_SESSION['nama']    = $user->getNama();
            $_SESSION['role']    = $user instanceof \App\Models\Admin ? 'admin' : 'kasir';

            redirectSesuaiRole($_SESSION['role']);
        }

        $percobaan++;
        $terkunciSampai = $percobaan >= 5 ? time() + 300 : 0;

        @file_put_contents($fileGagal, serialize([
            'percobaan'       => $percobaan,
            'terkunci_sampai' => $terkunciSampai,
            'waktu'           => time(),
        ]));

        $error = 'Username atau password salah.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Kasir Minimarket</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .login-card {
            max-width: 420px;
            border: 0;
        }
        .login-brand-icon {
            width: 3.5rem;
            height: 3.5rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--brand-primary) 0%, var(--brand-primary-darker) 100%);
            color: #fff;
            border-radius: 1rem;
            font-size: 1.75rem;
            box-shadow: 0 0.25rem 0.75rem rgba(13, 148, 136, 0.35);
        }
    </style>
</head>
<body class="d-flex align-items-center" style="min-height: 100vh;">
    <div class="container">
        <div class="card pos-card login-card mx-auto">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <span class="login-brand-icon"><i class="bi bi-shop"></i></span>
                    <h1 class="h4 mt-3 mb-1">Kasir Minimarket</h1>
                    <p class="text-muted mb-0">Masuk untuk mulai bertransaksi.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger py-2" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            value="<?= htmlspecialchars($username) ?>"
                            autocomplete="username"
                            required
                            autofocus
                        >
                    </div>
                    <div class="mb-4">
                        <label for="password" class="form-label">Password</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            autocomplete="current-password"
                            required
                        >
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Masuk
                    </button>
                </form>

                <p class="text-muted small mt-4 mb-0 text-center">
                    <i class="bi bi-shield-lock me-1"></i> Akun dibuat langsung di tabel <code>users</code>.
                </p>
            </div>
        </div>
    </div>
</body>
</html>
