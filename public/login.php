<?php
// public/login.php
// Halaman + proses login untuk kasir & admin (form Bootstrap sederhana).
session_start();
require_once __DIR__ . '/../bootstrap/autoload.php';

// Sudah login -> langsung ke dashboard sesuai role, form tidak ditampilkan.
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . SessionGuard::dashboardUrl($_SESSION['role'] ?? ''));
    exit;
}

// Proses submit form login.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SessionGuard::validateCsrfToken($_POST['csrf_token'] ?? null)) {
        SessionGuard::setFlash('error', 'Sesi tidak valid, silakan coba lagi.');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Cek role dulu dari tabel users untuk menentukan class yang dipakai
        // (Kasir atau Admin), baru panggil login().
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT role FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $role = $stmt->fetchColumn();

        $user = null;
        if ($role === 'kasir') {
            $user = new Kasir();
        } elseif ($role === 'admin') {
            $user = new Admin();
        }

        if ($user !== null && $user->login($username, $password)) {
            // Login sukses: isi session lalu redirect ke dashboard sesuai role.
            $_SESSION['user_id'] = $user->getId();
            $_SESSION['nama']    = $user->getNama();
            $_SESSION['role']    = $role;
            header('Location: ' . SessionGuard::dashboardUrl($role));
            exit;
        }

        // Pesan GENERIK: jangan bocorkan username atau password yang salah.
        SessionGuard::setFlash('error', 'Username atau password salah');
    }

    // Pola PRG (Post-Redirect-Get): refresh halaman tidak submit ulang form.
    header('Location: ' . SessionGuard::baseUrl() . '/login.php');
    exit;
}

$csrfToken = SessionGuard::generateCsrfToken();
$flash     = SessionGuard::getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= SessionGuard::baseUrl() ?>/assets/css/style.css">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center mt-5">
        <div class="col-md-4">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h1 class="h4 text-center mb-4">Login Kasir Minimarket</h1>
                    <?php if ($flash): ?>
                    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?>">
                        <?= htmlspecialchars($flash['message']) ?>
                    </div>
                    <?php endif; ?>
                    <form method="post" action="<?= SessionGuard::baseUrl() ?>/login.php">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control" id="username" name="username" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="password" class="form-label">Password</label>
                            <input type="password" class="form-control" id="password" name="password" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
