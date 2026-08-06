<?php

declare(strict_types=1);

/**
 * Dashboard analytics (admin only).
 *
 * VIEW MURNI: tidak ada query SQL / akses database / logika data di sini.
 * Semua data diambil via fetch() ke public/api.php (Controller PBO →
 * DataReporter) dan dirender oleh JavaScript (Chart.js + DataTables).
 */

require __DIR__ . '/../src/autoload.php';

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
        <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
        <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
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
    require_csrf();
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Notifikasi stok menipis (server-side, pakai stok_minimum per produk).
use App\Models\Produk;
$stokMenipis = Produk::cariStokMenipis();
$aktif = 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
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

    <?php if ($stokMenipis !== []): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <div class="d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill mt-1"></i>
                <div>
                    <strong>Stok menipis:</strong>
                    <?php
                    $namaStokMenipis = array_map(
                        static fn ($p) => $p->getNama() . ' (' . $p->getStok() . ')',
                        array_slice($stokMenipis, 0, 5)
                    );
                    ?>
                    <?= htmlspecialchars(implode(', ', $namaStokMenipis)) ?>
                    <?php if (count($stokMenipis) > 5): ?>
                        <span class="text-muted">+<?= count($stokMenipis) - 5 ?> lainnya</span>
                    <?php endif; ?>
                    <a href="admin.php" class="alert-link ms-1">Kelola stok</a>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- Stat cards (diisi via AJAX) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <span class="stat-icon bg-brand-soft text-brand"><i class="bi bi-cash-stack"></i></span>
                    <div>
                        <div class="stat-nilai font-num" id="stat-total">—</div>
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
                        <div class="stat-nilai font-num" id="stat-jumlah">—</div>
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
                        <div class="stat-nilai font-num" id="stat-item">—</div>
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
                        <div class="stat-nilai font-num" id="stat-rata">—</div>
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

        <!-- Grafik stok per kategori -->
        <div class="col-lg-4">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><span>Stok per Kategori</span></div>
                <div class="card-body">
                    <div class="chart-container" style="height: 300px;">
                        <canvas id="grafik-stok"></canvas>
                    </div>
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
                    <table class="table table-hover align-middle mb-0" id="tabel-terlaris">
                        <thead class="table-light">
                            <tr>
                                <th class="text-center">#</th>
                                <th>Produk</th>
                                <th class="text-center">Qty</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>

        <!-- Transaksi terbaru -->
        <div class="col-lg-7">
            <div class="card pos-card h-100">
                <div class="card-header bg-white"><span>Transaksi Terbaru</span></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-transaksi">
                            <thead class="table-light">
                                <tr>
                                    <th>No.</th>
                                    <th>Waktu</th>
                                    <th>Kasir</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<script src="assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    // View murni: semua data dari api.php (Controller → DataReporter).
    (function () {
        'use strict';

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        // Stat cards.
        fetch('api.php?aksi=dashboard.ringkasan')
            .then(function (r) {
                if (r.status === 401) { window.location.href = 'login.php'; throw new Error('Sesi habis'); }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (d) {
                document.getElementById('stat-total').textContent = rupiah(d.total || 0);
                document.getElementById('stat-jumlah').textContent = d.jumlah || 0;
                document.getElementById('stat-item').textContent = d.item || 0;
                document.getElementById('stat-rata').textContent = rupiah(d.rata_rata || 0);
            })
            .catch(function (err) {
                if (err.message === 'Sesi habis') return;
                var el = document.getElementById('stat-total');
                if (el && el.textContent === '—') {
                    el.closest('.card').querySelector('.stat-label').textContent = 'Gagal memuat data';
                    el.textContent = '!';
                }
            });

        // Grafik penjualan 7 hari.
        fetch('api.php?aksi=dashboard.grafik')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var el = document.getElementById('grafik-penjualan');
                if (!el || typeof Chart === 'undefined') return;
                new Chart(el, {
                    type: 'bar',
                    data: {
                        labels: d.labels || [],
                        datasets: [{
                            label: d.series.label || 'Penjualan (Rp)',
                            data: d.series.data || [],
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
                            tooltip: { callbacks: { label: function (c) { return rupiah(c.raw); } } }
                        },
                        scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return rupiah(v); } } } }
                    }
                });
            })
            .catch(function () { /* diam */ });

        // Grafik stok per kategori.
        fetch('api.php?aksi=dashboard.stok_kategori')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var el = document.getElementById('grafik-stok');
                if (!el || typeof Chart === 'undefined') return;
                new Chart(el, {
                    type: 'doughnut',
                    data: {
                        labels: d.labels || [],
                        datasets: [{
                            data: d.series.data || [],
                            backgroundColor: ['#0d9488', '#f59e0b', '#0891b2', '#16a34a', '#8b5cf6', '#dc2626', '#64748b', '#d97706']
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'bottom' } }
                    }
                });
            })
            .catch(function () { /* diam */ });

        // DataTables server-side: produk terlaris.
        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-terlaris').DataTable({
                serverSide: true,
                ajax: { url: 'api.php?aksi=dashboard.terlaris', data: function (d) { d.draw = d.draw || 0; } },
                pageLength: 5,
                lengthChange: false,
                searching: false,
                order: [],
                columns: [
                    { data: null, orderable: false, render: function (d, t, row, meta) { return meta.row + 1; } },
                    { data: 'nama' },
                    { data: 'qty', className: 'text-center' },
                    { data: 'total', className: 'text-end font-num', render: function (d) { return rupiah(d); } }
                ]
            });

            jQuery('#tabel-transaksi').DataTable({
                serverSide: true,
                ajax: { url: 'api.php?aksi=dashboard.transaksi', data: function (d) { d.draw = d.draw || 0; } },
                pageLength: 5,
                lengthChange: false,
                searching: false,
                order: [],
                columns: [
                    { data: 'id', render: function (d) { return '#' + d; } },
                    { data: 'tanggal' },
                    { data: 'kasir_nama' },
                    { data: 'total', className: 'text-end font-num', render: function (d) { return rupiah(d); } }
                ]
            });
        }
    })();
</script>
</body>
</html>
