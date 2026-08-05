<?php

declare(strict_types=1);

/**
 * Dashboard analytics (admin only).
 *
 * Ringkasan performa toko: penjualan hari ini, jumlah transaksi,
 * item terjual, rata-rata per transaksi, grafik penjualan 7 hari,
 * produk terlaris, stok menipis, dan transaksi terbaru.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\Dashboard;
use App\Models\Produk;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

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

$ringkasan   = Dashboard::ringkasanHariIni();
$penjualan   = Dashboard::penjualan7Hari();
$terlaris    = Dashboard::produkTerlaris();
$terbaru     = Dashboard::transaksiTerbaru();
$metode      = Dashboard::metodePembayaran();
$stokMenipis = Produk::cariStokMenipis();

function formatRupiah(float $jumlah): string
{
    return 'Rp ' . number_format($jumlah, 0, ',', '.');
}

// Data untuk Chart.js.
$chartLabel = array_map(static fn (array $d): string => date('d M', strtotime($d['tanggal'])), $penjualan);
$chartTotal = array_map(static fn (array $d): float => $d['total'], $penjualan);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="dashboard.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-dash"
                aria-controls="nav-dash" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-dash">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="admin.php"><i class="bi bi-box-seam"></i> Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="transaksi.php"><i class="bi bi-cash-register"></i> Kasir</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="laporan.php"><i class="bi bi-bar-chart-line"></i> Laporan</a>
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
                <button type="button" class="theme-toggle" id="toggle-theme" title="Ganti mode terang/gelap">
                    <i class="bi bi-circle-half"></i>
                </button>
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

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
        <div>
            <h1 class="h3 mb-1">Dashboard</h1>
            <span class="text-muted small">Ringkasan performa toko hari ini</span>
        </div>
        <a href="laporan.php" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-bar-chart-line me-1"></i>Buka Laporan
        </a>
    </div>

    <!-- Stat cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-brand-soft text-brand"><i class="bi bi-cash-stack"></i></span>
                    <div>
                        <div class="stat-nilai"><?= formatRupiah((float) $ringkasan['total']) ?></div>
                        <div class="stat-label">Penjualan Hari Ini</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon text-bg-primary"><i class="bi bi-receipt"></i></span>
                    <div>
                        <div class="stat-nilai"><?= (int) $ringkasan['jumlah'] ?></div>
                        <div class="stat-label">Transaksi</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon text-bg-success"><i class="bi bi-bag-check"></i></span>
                    <div>
                        <div class="stat-nilai"><?= (int) $ringkasan['item'] ?></div>
                        <div class="stat-label">Item Terjual</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon text-bg-warning"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <div class="stat-nilai"><?= formatRupiah((float) $ringkasan['rata_rata']) ?></div>
                        <div class="stat-label">Rata-rata / Transaksi</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Grafik penjualan 7 hari -->
        <div class="col-lg-8">
            <div class="card pos-card h-100">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Penjualan 7 Hari Terakhir</span>
                    <span class="text-muted small">per hari</span>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="grafik-penjualan"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Metode pembayaran hari ini -->
        <div class="col-lg-4">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><span>Metode Pembayaran Hari Ini</span></div>
                <div class="card-body">
                    <?php if ($metode === []): ?>
                        <div class="text-muted small">Belum ada transaksi hari ini.</div>
                    <?php else: ?>
                        <?php foreach ($metode as $m): ?>
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <span>
                                    <i class="bi <?= $m['jenis'] === 'tunai' ? 'bi-cash' : 'bi-credit-card' ?> text-brand me-2"></i>
                                    <?= $m['jenis'] === 'tunai' ? 'Tunai' : 'Non-tunai' ?>
                                </span>
                                <span class="fw-semibold"><?= formatRupiah((float) $m['total']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mt-0">
        <!-- Produk terlaris -->
        <div class="col-lg-5">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><span>Produk Terlaris</span></div>
                <div class="card-body p-0">
                    <?php if ($terlaris === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada data penjualan.</div>
                    <?php else: ?>
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Produk</th>
                                    <th class="text-center">Qty</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $no = 1; ?>
                                <?php foreach ($terlaris as $t): ?>
                                    <tr>
                                        <td class="text-center text-muted"><?= $no++ ?></td>
                                        <td><?= htmlspecialchars($t['nama']) ?></td>
                                        <td class="text-center"><span class="badge text-bg-light border"><?= (int) $t['qty'] ?>x</span></td>
                                        <td class="text-end"><?= formatRupiah((float) $t['total']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Transaksi terbaru -->
        <div class="col-lg-7">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><span>Transaksi Terbaru</span></div>
                <div class="card-body p-0">
                    <?php if ($terbaru === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada transaksi.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>No.</th>
                                        <th>Waktu</th>
                                        <th>Kasir</th>
                                        <th class="text-end">Total</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($terbaru as $t): ?>
                                        <tr>
                                            <td>#<?= $t['id'] ?></td>
                                            <td><?= date('d-m-Y H:i', strtotime($t['tanggal'])) ?></td>
                                            <td><?= htmlspecialchars($t['kasir_nama']) ?></td>
                                            <td class="text-end fw-semibold"><?= formatRupiah((float) $t['total']) ?></td>
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

    <?php if ($stokMenipis !== []): ?>
        <div class="card pos-card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span><i class="bi bi-exclamation-triangle text-warning me-1"></i>Stok Menipis</span>
                <a href="admin.php" class="btn btn-sm btn-outline-primary">Kelola Produk</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Produk</th>
                                <th class="text-end">Harga</th>
                                <th class="text-center">Stok</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stokMenipis as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p->getNama()) ?></td>
                                    <td class="text-end"><?= formatRupiah($p->getHarga()) ?></td>
                                    <td class="text-center">
                                        <span class="<?= $p->getStok() <= 0 ? 'stok-habis' : 'stok-menipis' ?>">
                                            <?= $p->getStok() ?>
                                        </span>
                                        <?= $p->getStok() <= 0 ? '(habis)' : '(menipis)' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    (function () {
        var el = document.getElementById('grafik-penjualan');
        if (!el || typeof Chart === 'undefined') return;

        new Chart(el, {
            type: 'bar',
            data: {
                labels: <?= json_encode($chartLabel) ?>,
                datasets: [{
                    label: 'Penjualan (Rp)',
                    data: <?= json_encode($chartTotal) ?>,
                    backgroundColor: 'rgba(13, 148, 136, 0.75)',
                    hoverBackgroundColor: 'rgba(13, 148, 136, 0.95)',
                    borderRadius: 6,
                    maxBarThickness: 48
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return 'Rp ' + Number(ctx.raw).toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function (value) {
                                return 'Rp ' + Number(value).toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        });
    })();
</script>
</body>
</html>
