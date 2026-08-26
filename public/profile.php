<?php

declare(strict_types=1);

/**
 * Halaman profil akun kasir (kasir-only).
 *
 * - Lihat & edit nama kasir.
 * - Kelola device pairing (printer / timbangan Web Serial) via dropdown sederhana.
 *   Device yang dipilih disimpan per-kasir di tabel user_devices dan otomatis
 *   tersedia tiap login (auto-connect di POS lewat api.php?aksi=device.list).
 *
 * Device pairing di sini, bukan di card POS — card POS hanya menampilkan status
 * koneksi ringkas supaya UI kasir minimalis & penuh space ruang kosong.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\User;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login & role kasir (bukan admin-only seperti user.php).
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$user = User::cariBerdasarkanId($userId);

if ($user === null) {
    logoutKaryawan();
    header('Location: login.php');
    exit;
}

$namaUser = (string) ($_SESSION['nama'] ?? $user->getNama());
$nama     = $namaUser; // pakai di navbar-kasir
$aktif = 'profile';

// Flash message dari aksi POST.
$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);
$pesanTipe = $_SESSION['pesan_tipe'] ?? 'info';
unset($_SESSION['pesan_tipe']);

// ---- Handle POST: simpan profil / device pairing ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $aksi = (string) ($_POST['aksi'] ?? '');

    try {
        if ($aksi === 'simpan_nama') {
            $namaBaru = trim((string) ($_POST['nama'] ?? ''));
            if ($namaBaru === '') {
                throw new \RuntimeException('Nama tidak boleh kosong.');
            }
            $pdo = \App\Database\Database::connect();
            $stmt = $pdo->prepare('UPDATE users SET nama = :nama WHERE id = :id');
            $stmt->execute([':nama' => $namaBaru, ':id' => $userId]);
            $_SESSION['nama'] = $namaBaru;
            $_SESSION['pesan'] = 'Nama profil berhasil diperbarui.';
            $_SESSION['pesan_tipe'] = 'success';
        }

        header('Location: profile.php');
        exit;
    } catch (\Throwable $e) {
        $_SESSION['pesan'] = pesanErrorRamah($e);
        $_SESSION['pesan_tipe'] = 'danger';
        header('Location: profile.php');
        exit;
    }
}

// Device pairing dikelola via modal di halaman Kasir (POS), bukan di sini.
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <title>Profil Kasir - <?= htmlspecialchars($namaUser) ?></title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar-kasir.php'; ?>

<div class="container-fluid px-3 px-xl-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-person-circle me-2"></i>Profil Saya</h1>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-<?= $pesanTipe === 'danger' ? 'danger' : ($pesanTipe === 'success' ? 'success' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
    <!-- Kolom kiri: profil akun -->
    <div class="col-lg-4">
        <div class="card pos-card h-100">
            <div class="card-header bg-white py-2">
                <i class="bi bi-info-circle me-1"></i>Akun Kasir
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">Nama yang tampil di navbar kasir.</p>
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aksi" value="simpan_nama">
                    <div class="mb-3">
                        <label class="form-label" for="nama">Nama</label>
                        <input type="text" id="nama" name="nama" class="form-control form-control-sm"
                               value="<?= htmlspecialchars($user->getNama()) ?>" required>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-save me-1"></i>Simpan
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Kolom kanan: petunjuk perangkat (diurus lewat modal di POS) -->
    <div class="col-lg-6">
        <div class="card pos-card h-100">
            <div class="card-header bg-white py-2">
                <i class="bi bi-usb-plug me-1"></i>Perangkat (Printer / Timbangan)
            </div>
            <div class="card-body">
                <p class="text-muted small mb-2">
                    Hubungkan-perangkat dikelola langsung di halaman Kasir (POS) lewat modal — tidak perlu keluar ke halaman lain.
                </p>
                <ul class="small text-muted mb-3">
                    <li class="mb-1"><i class="bi bi-info-lg me-1"></i>Di userbar POS (sebelah nama kasir) ada ikon
                        <i class="bi bi-printer"></i> <strong>Perangkat</strong> — klik untuk buka modal.</li>
                    <li class="mb-1"><i class="bi bi-info-lg me-1"></i>Pilih / ketik label printer &amp; timbangan di
                        combobox, lalu klik ikon <i class="bi bi-plug"></i> untuk hubungkan lewat Web Serial.</li>
                    <li class="mb-1"><i class="bi bi-info-lg me-1"></i>Pilihan disimpan per-akun, otomatis tersedia tiap login.</li>
                    <li><i class="bi bi-info-lg me-1"></i>Printer terhubung akan otomatis cetak struk setelah bayar berhasil.</li>
                </ul>
                <div class="d-grid">
                    <a href="transaksi.php" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-left me-1"></i>Buka Kasir (POS)
                    </a>
                </div>
            </div>
        </div>
    </div>
    </div>
</div>

<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
