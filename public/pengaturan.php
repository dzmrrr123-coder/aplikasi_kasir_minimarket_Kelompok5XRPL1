<?php

declare(strict_types=1);

/**
 * Halaman admin: pengaturan toko.
 *
 * Menyimpan nama toko, alamat, telepon, footer struk, dan pajak (PPN).
 * Dipakai oleh struk (header toko, footer) dan laporan laba (pajak).
 * Data disimpan sebagai pasangan kunci-nilai di tabel `pengaturan`.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Pengaturan;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login sebagai admin.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama403 = $_SESSION['nama'] ?? 'Pengguna';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses Ditolak - Kasir Minimarket</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
        <link href="assets/theme.css" rel="stylesheet">
    </head>
    <body class="d-flex align-items-center" style="min-height: 100vh;">
    <div class="container">
        <div class="card pos-card mx-auto" style="max-width: 480px;">
            <div class="card-body text-center p-4">
                <span class="badge text-bg-danger mb-3"><i class="bi bi-shield-exclamation me-1"></i>403</span>
                <h1 class="h4 mb-3">Akses Ditolak</h1>
                <p class="mb-4">Anda tidak memiliki akses ke halaman ini.</p>
                <a href="transaksi.php" class="btn btn-primary"><i class="bi bi-cash-register me-1"></i>Kembali ke Kasir</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$nama = $_SESSION['nama'] ?? 'Admin';

$pesan = $_SESSION['pesan'] ?? '';
unset($_SESSION['pesan']);

// ---- Routing aksi (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $aksi = $_POST['aksi'] ?? '';

    switch ($aksi) {
        case 'logout':
            session_unset();
            session_destroy();
            header('Location: login.php');
            exit;

        case 'simpan':
            $data = [
                'nama_toko'   => trim((string) ($_POST['nama_toko'] ?? '')),
                'alamat'      => trim((string) ($_POST['alamat'] ?? '')),
                'telepon'     => trim((string) ($_POST['telepon'] ?? '')),
                'footer_struk'=> trim((string) ($_POST['footer_struk'] ?? '')),
                'pajak'       => trim((string) ($_POST['pajak'] ?? '0')),
                'pin_supervisor' => trim((string) ($_POST['pin_supervisor'] ?? '0000')),
            ];

            if ($data['nama_toko'] === '') {
                $pesan = 'Nama toko tidak boleh kosong.';
                break;
            }

            $pajak = (float) $data['pajak'];

            if ($pajak < 0 || $pajak > 100) {
                $pesan = 'Pajak harus antara 0 dan 100 persen.';
                break;
            }

            if (strlen($data['pin_supervisor']) < 4) {
                $pesan = 'PIN supervisor minimal 4 digit.';
                break;
            }

            $data['pajak'] = (string) $pajak;
            Pengaturan::simpan($data);
            $pesan = 'Pengaturan toko disimpan.';
            break;
    }
}

// ---- Data untuk tampilan ----
$pengaturan = Pengaturan::semua();
$namaToko = $pengaturan['nama_toko'] ?? '';
$alamat = $pengaturan['alamat'] ?? '';
$telepon = $pengaturan['telepon'] ?? '';
$footer = $pengaturan['footer_struk'] ?? '';
$pajak = $pengaturan['pajak'] ?? '0';
$pinSupervisor = $pengaturan['pin_supervisor'] ?? '0000';

$aktif = 'pengaturan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengaturan Toko - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Pengaturan Toko</h1>
        <span class="text-muted small">Identitas toko yang tampil di struk & laporan</span>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($pesan) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card pos-card">
                <div class="card-header bg-white">
                    <i class="bi bi-shop me-1"></i>Identitas Toko
                </div>
                <div class="card-body">
                    <form method="post" class="row g-3">
                        <input type="hidden" name="aksi" value="simpan">
                        <div class="col-12">
                            <label for="nama-toko" class="form-label">Nama toko</label>
                            <input
                                type="text"
                                id="nama-toko"
                                name="nama_toko"
                                class="form-control"
                                value="<?= htmlspecialchars($namaToko) ?>"
                                placeholder="cth: Minimarket Plaza"
                                required
                            >
                        </div>
                        <div class="col-12">
                            <label for="alamat" class="form-label">Alamat</label>
                            <textarea
                                id="alamat"
                                name="alamat"
                                class="form-control"
                                rows="2"
                                placeholder="cth: Jl. Sudirman No. 1, Jakarta"
                            ><?= htmlspecialchars($alamat) ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label for="telepon" class="form-label">Telepon</label>
                            <input
                                type="text"
                                id="telepon"
                                name="telepon"
                                class="form-control"
                                value="<?= htmlspecialchars($telepon) ?>"
                                placeholder="cth: 021-5551234"
                            >
                        </div>
                        <div class="col-md-6">
                            <label for="pajak" class="form-label">Pajak (PPN) %</label>
                            <input
                                type="number"
                                id="pajak"
                                name="pajak"
                                class="form-control"
                                value="<?= htmlspecialchars($pajak) ?>"
                                min="0"
                                max="100"
                                step="0.01"
                            >
                            <div class="form-text">0 berarti tanpa pajak.</div>
                        </div>
                        <div class="col-md-6">
                            <label for="pin-supervisor" class="form-label">PIN Supervisor</label>
                            <input
                                type="password"
                                id="pin-supervisor"
                                name="pin_supervisor"
                                class="form-control"
                                value="<?= htmlspecialchars($pinSupervisor) ?>"
                                maxlength="10"
                                autocomplete="off"
                            >
                            <div class="form-text">Dipakai untuk void item di layar kasir.</div>
                        </div>
                        <div class="col-12">
                            <label for="footer-struk" class="form-label">Pesan footer struk</label>
                            <input
                                type="text"
                                id="footer-struk"
                                name="footer_struk"
                                class="form-control"
                                value="<?= htmlspecialchars($footer) ?>"
                                placeholder="cth: Terima kasih atas kunjungan Anda!"
                            >
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i>Simpan Pengaturan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card pos-card">
                <div class="card-header bg-white">
                    <i class="bi bi-info-circle me-1"></i>Pratinjau Struk
                </div>
                <div class="card-body">
                    <div class="p-3 rounded" style="background: var(--surface-2);">
                        <pre class="font-num mb-0" style="white-space: pre-wrap;">==================================
<?= htmlspecialchars(strtoupper($namaToko !== '' ? $namaToko : 'KASIR MINIMARKET')) ?>
<?= $alamat !== '' ? htmlspecialchars($alamat) : '' ?>
<?= $telepon !== '' ? 'Telp: ' . htmlspecialchars($telepon) : '' ?>
==================================
No. Transaksi : 25
Tanggal       : 01-08-2026 10:30
Kasir         : Kasir Demo
----------------------------------
1. Indomie Goreng
    2 x Rp 3.500
    Subtotal  : Rp 7.000
----------------------------------
Subtotal      : Rp 7.000
TOTAL         : Rp 7.000
Metode Bayar  : Tunai
Dibayar       : Rp 10.000
Kembalian     : Rp 3.000
==================================
<?= htmlspecialchars($footer !== '' ? $footer : 'Terima kasih atas kunjungan Anda!') ?></pre>
                    </div>
                    <div class="form-text mt-2">
                        <i class="bi bi-lightbulb me-1"></i>Pengaturan ini otomatis dipakai struk saat transaksi & preview struk.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/theme.js"></script>
</body>
</html>
