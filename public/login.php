<?php

declare(strict_types=1);

/**
 * Halaman login kasir minimarket.
 *
 * Alur: cek kredensial lewat User::login() (Admin/Kasir), simpan
 * user_id, role, dan nama ke $_SESSION, lalu redirect sesuai role:
 *   - kasir -> transaksi.php
 *   - admin -> admin.php
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Admin;
use App\Models\Kasir;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function redirectSesuaiRole(string $role): never
{
    if ($role === 'admin') {
        header('Location: admin.php');
    } else {
        header('Location: transaksi.php');
    }
    exit;
}

// Sudah login: langsung arahkan sesuai role.
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    redirectSesuaiRole($_SESSION['role']);
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    // Coba login sebagai kasir dulu; kalau role-nya admin, User::login()
    // tetap memvalidasi password dan mengisi data yang benar.
    $user = new Kasir();
    $berhasil = $user->login($username, $password);

    if ($berhasil) {
        // Ambil role dari database (bukan dari input).
        $pdo = \App\Database\Database::connect();
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $user->getId()]);
        $row = $stmt->fetch();

        $role = $row['role'] ?? 'kasir';

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user->getId();
        $_SESSION['nama']    = $user->getNama();
        $_SESSION['role']    = $role;

        redirectSesuaiRole($role);
    }

    $error = 'Username atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Kasir Minimarket</title>
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
