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

// Ekspor CSV: unduh laporan sesuai periode yang sedang difilter.
if (isset($_GET['ekspor']) && $_GET['ekspor'] === '1') {
    $csv = $laporan->eksporPDF();
    $namaFile = 'laporan-penjualan-' . date('Ymd-His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    // BOM UTF-8 supaya terbuka benar di Excel.
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .total-besar { font-size: 1.5rem; font-weight: 700; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="admin.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-laporan"
                aria-controls="nav-laporan" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-laporan">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php"><i class="bi bi-box-seam"></i> Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transaksi.php"><i class="bi bi-cash-register"></i> Kasir</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" href="laporan.php"><i class="bi bi-bar-chart-line"></i> Laporan</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="supplier.php"><i class="bi bi-truck"></i> Supplier</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="retur.php"><i class="bi bi-arrow-counterclockwise"></i> Retur</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="diskon.php"><i class="bi bi-tags"></i> Diskon</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="user.php"><i class="bi bi-people"></i> Kelola Kasir</a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <span class="navbar-text text-white small me-2 d-none d-lg-inline">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="aksi" value="logout">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Laporan Penjualan</h1>
        <span class="text-muted small">Admin: <?= htmlspecialchars($nama) ?></span>
    </div>

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
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
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
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Transaksi</span>
                    <a
                        href="?tanggal_mulai=<?= htmlspecialchars($laporan->getTanggalMulai()->format('Y-m-d')) ?>&amp;tanggal_akhir=<?= htmlspecialchars($laporan->getTanggalAkhir()->format('Y-m-d')) ?>&amp;ekspor=1"
                        class="btn btn-sm btn-outline-success"
                        title="Unduh laporan periode ini sebagai CSV"
                    >
                        <i class="bi bi-download me-1"></i>Ekspor CSV
                    </a>
                </div>
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
                                            <td><?= htmlspecialchars($t->getKasirNama()) ?></td>
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
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
