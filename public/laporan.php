<?php

declare(strict_types=1);

/**
 * Halaman laporan penjualan (admin only).
 *
 * Filter berdasarkan rentang tanggal, daftar transaksi per periode, dan
 * ringkasan total penjualan. Memakai LaporanPenjualan::generate().
 * Kalau tidak ada data di rentang tanggal, tampilkan pesan yang jelas
 * (bukan tabel kosong).
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\LaporanPenjualan;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login; hanya admin yang boleh membuka halaman ini.
// Kalau sudah login tapi bukan admin (mis. kasir), tampilkan pesan
// akses ditolak alih-alih mengarahkan ke login.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    $nama = $_SESSION['nama'] ?? 'Pengguna';
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Akses Ditolak - Kasir Minimarket</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #f4f6f8; }
            .pos-card { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .375rem rgba(16,24,40,.06); }
        </style>
    </head>
    <body class="bg-light">
    <div class="container py-4">
        <div class="card pos-card mx-auto" style="max-width: 480px;">
            <div class="card-body text-center p-4">
                <h1 class="h4 mb-3">Akses Ditolak</h1>
                <p class="mb-4">Anda tidak memiliki akses ke halaman ini.</p>
                <a href="transaksi.php" class="btn btn-primary">Kembali ke Kasir</a>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$nama = $_SESSION['nama'] ?? 'Admin';

// Logout.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'logout') {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Periode default: bulan berjalan.
$tanggalMulai = $_GET['tanggal_mulai'] ?? date('Y-m-01');
$tanggalAkhir = $_GET['tanggal_akhir'] ?? date('Y-m-d');

$laporan = new LaporanPenjualan();

try {
    $laporan->setPeriode(
        new DateTimeImmutable($tanggalMulai),
        new DateTimeImmutable($tanggalAkhir)
    );
    $hasil = $laporan->generate();
} catch (Throwable $e) {
    // Tanggal tidak valid -> fallback ke bulan berjalan.
    $laporan->setPeriode(
        new DateTimeImmutable(date('Y-m-01')),
        new DateTimeImmutable(date('Y-m-d'))
    );
    $hasil = $laporan->generate();
}

$transaksi = $hasil['transaksi']; // Transaksi[]
$jumlah = (int) $hasil['jumlah'];
$total = (float) $hasil['total'];
$pesan = (string) $hasil['pesan'];

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Penjualan - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f4f6f8; }
        .pos-card { border: 0; border-radius: .75rem; box-shadow: 0 .125rem .375rem rgba(16,24,40,.06); }
        .total-besar { font-size: 1.5rem; font-weight: 700; }
    </style>
</head>
<body class="bg-light">
<div class="container py-4">

    <header class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div>
            <h1 class="h3 mb-0">Laporan Penjualan</h1>
            <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
        </div>
        <div class="d-flex gap-2">
            <a href="admin.php" class="btn btn-outline-secondary btn-sm">Kembali ke Admin</a>
            <form method="post" class="d-inline">
                <input type="hidden" name="aksi" value="logout">
                <button type="submit" class="btn btn-outline-danger btn-sm">Logout</button>
            </form>
        </div>
    </header>

    <div class="card pos-card mb-4">
        <div class="card-header bg-white"><strong>Filter Periode</strong></div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label for="tanggal-mulai" class="form-label">Dari tanggal</label>
                    <input
                        type="date"
                        id="tanggal-mulai"
                        name="tanggal_mulai"
                        class="form-control"
                        value="<?= htmlspecialchars($tanggalMulai) ?>"
                        required
                    >
                </div>
                <div class="col-md-3">
                    <label for="tanggal-akhir" class="form-label">Sampai tanggal</label>
                    <input
                        type="date"
                        id="tanggal-akhir"
                        name="tanggal_akhir"
                        class="form-control"
                        value="<?= htmlspecialchars($tanggalAkhir) ?>"
                        required
                    >
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-primary">Tampilkan</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card pos-card">
                <div class="card-header bg-white"><strong>Ringkasan</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-muted fw-normal">Periode</dt>
                        <dd class="col-5 text-end mb-0">
                            <?= htmlspecialchars($laporan->getTanggalMulai()->format('d M Y')) ?>
                            s/d
                            <?= htmlspecialchars($laporan->getTanggalAkhir()->format('d M Y')) ?>
                        </dd>
                        <dt class="col-7 text-muted fw-normal">Jumlah transaksi</dt>
                        <dd class="col-5 text-end mb-0"><?= $jumlah ?></dd>
                        <hr class="my-2">
                        <dt class="col-7 fw-semibold">Total penjualan</dt>
                        <dd class="col-5 text-end total-besar"><?= formatRupiah($total) ?></dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white">Daftar Transaksi</div>
                <div class="card-body p-0">
                    <?php if ($jumlah === 0): ?>
                        <div class="p-4 text-center">
                            <div class="text-muted mb-1">Tidak ada data penjualan pada periode tersebut.</div>
                            <div class="small text-muted">
                                Coba ubah rentang tanggal, atau buat transaksi baru di
                                <a href="transaksi.php">halaman kasir</a>.
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>No. Transaksi</th>
                                        <th>Tanggal</th>
                                        <th>Kasir</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($transaksi as $t): ?>
                                        <tr>
                                            <td>#<?= $t->getId() ?></td>
                                            <td><?= $t->getTanggal()->format('d-m-Y H:i') ?></td>
                                            <td><?= $t->getKasirId() ?></td>
                                            <td class="text-end"><?= formatRupiah($t->getTotal()) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

</div>
</body>
</html>
