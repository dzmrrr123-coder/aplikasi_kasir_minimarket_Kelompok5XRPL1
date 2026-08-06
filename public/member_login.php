<?php

declare(strict_types=1);

/**
 * Halaman login khusus member (pelanggan).
 *
 * Member login pakai NOMOR MEMBER (MEM-XXXXXX) atau nomor telepon + password.
 * Sesi member disimpan terpisah dari sesi admin/kasir (member_id + member_nama),
 * supaya tidak bentrok dengan sesi karyawan.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Member;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Sudah login member -> langsung ke area member.
if (isset($_SESSION['member_id'])) {
    header('Location: member_area.php');
    exit;
}

// Login karyawan yang sudah masuk tidak perlu form login member lagi.
// (opsional; kalau admin/kasir buka halaman ini, biarkan saja.)

$error = '';
$identitas = '';

// Ambil member demo pertama (untuk ditampilkan sebagai petunjuk login).
$demoMember = null;
$semuaMember = \App\Models\Member::semua();
if ($semuaMember !== []) {
    $demoMember = $semuaMember[0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $identitas = trim((string) ($_POST['identitas'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $member = Member::login($identitas, $password);

    if ($member !== null) {
        session_regenerate_id(true);
        $_SESSION['member_id']   = (int) $member->getId();
        $_SESSION['member_nama'] = $member->getNama();
        $_SESSION['member_nomor'] = $member->getNomorMember();

        header('Location: member_area.php');
        exit;
    }

    $error = 'Nomor member / telepon atau password salah.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login Member - Kasir Minimarket</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
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
                    <span class="login-brand-icon"><i class="bi bi-person-badge"></i></span>
                    <h1 class="h4 mt-3 mb-1">Login Member</h1>
                    <p class="text-muted mb-0">Cek poin & tukar badge hasil belanja.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger py-2" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($demoMember !== null): ?>
                    <div class="alert alert-light border small py-2 mb-3">
                        <strong>Akun demo:</strong> <?= htmlspecialchars($demoMember->getNama()) ?><br>
                        Identitas: <code><?= htmlspecialchars($demoMember->getNomorMember()) ?></code>
                        (atau telepon <code><?= htmlspecialchars($demoMember->getTelepon()) ?></code>)<br>
                        Password: <code>member123</code>
                    </div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label for="identitas" class="form-label">Nomor Member / Telepon / Nama</label>
                        <input
                            type="text"
                            id="identitas"
                            name="identitas"
                            class="form-control"
                            value="<?= htmlspecialchars($identitas) ?>"
                            placeholder="cth: MEM-000123 atau 081234567890"
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
                    <i class="bi bi-shop me-1"></i>
                    <a href="login.php" class="text-decoration-none">Login untuk karyawan</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>
