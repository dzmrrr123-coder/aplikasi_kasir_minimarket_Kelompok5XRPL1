<?php

declare(strict_types=1);

/**
 * Halaman laporan laba/rugi (admin only).
 *
 * Filter rentang tanggal, kartu ringkasan (omzet, HPP, laba, margin),
 * grafik omzet-vs-laba per hari, dan tabel laba per transaksi.
 * Semua data diambil via api.php (Controller → Laba).
 */

require __DIR__ . '/../src/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Wajib login; hanya admin yang boleh membuka halaman ini.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'logout') {
    require_csrf();
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

// Periode default: bulan berjalan.
$tanggalMulai = $_GET['tanggal_mulai'] ?? date('Y-m-01');
$tanggalAkhir = $_GET['tanggal_akhir'] ?? date('Y-m-d');

try {
    $dMulai = new DateTimeImmutable($tanggalMulai);
    $dAkhir = new DateTimeImmutable($tanggalAkhir);
} catch (Throwable $e) {
    $dMulai = new DateTimeImmutable(date('Y-m-01'));
    $dAkhir = new DateTimeImmutable(date('Y-m-d'));
}

// Kalau rentang terbalik (mulai > akhir), swap otomatis + peringatan.
$peringatanTanggal = '';

if ($dMulai > $dAkhir) {
    [$dMulai, $dAkhir] = [$dAkhir, $dMulai];
    $peringatanTanggal = 'Rentang tanggal dibalik (mulai > akhir) — otomatis ditukar.';
}

$aktif = 'laba';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Laba - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .nilai-besar { font-size: 1.4rem; font-weight: 700; }
    </style>
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Laporan Laba &amp; Rugi</h1>
        <span class="text-muted small">Omzet, HPP, laba kotor, dan margin per periode</span>
    </div>

    <?php if ($peringatanTanggal !== ''): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($peringatanTanggal) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- Filter periode -->
    <div class="card pos-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label for="tanggal-mulai" class="form-label">Dari</label>
                    <input type="date" id="tanggal-mulai" name="tanggal_mulai" class="form-control" value="<?= $dMulai->format('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <label for="tanggal-akhir" class="form-label">Sampai</label>
                    <input type="date" id="tanggal-akhir" name="tanggal_akhir" class="form-control" value="<?= $dAkhir->format('Y-m-d') ?>">
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Kartu ringkasan (diisi via api.php?aksi=laba.ringkasan) -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-primary"><i class="bi bi-cash-stack"></i></span>
                        <span class="text-muted small">Omzet</span>
                    </div>
                    <div class="nilai-besar font-num" id="nilai-omzet">—</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-warning"><i class="bi bi-truck"></i></span>
                        <span class="text-muted small">HPP (harga beli)</span>
                    </div>
                    <div class="nilai-besar font-num" id="nilai-hpp">—</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-success"><i class="bi bi-graph-up-arrow"></i></span>
                        <span class="text-muted small">Laba Kotor</span>
                    </div>
                    <div class="nilai-besar font-num" id="nilai-laba">—</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card pos-card h-100">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge text-bg-info"><i class="bi bi-percent"></i></span>
                        <span class="text-muted small">Margin</span>
                    </div>
                    <div class="nilai-besar font-num" id="nilai-margin">—</div>
                    <div class="small text-muted" id="nilai-jumlah">—</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Grafik omzet vs laba -->
        <div class="col-lg-5">
            <div class="card pos-card">
                <div class="card-header bg-white"><i class="bi bi-bar-chart-line me-1"></i>Omzet vs Laba per Hari</div>
                <div class="card-body">
                    <canvas id="grafik-laba" height="280"></canvas>
                </div>
            </div>
        </div>

        <!-- Tabel laba per transaksi -->
        <div class="col-lg-7">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Detail per Transaksi</span>
                    <span class="text-muted small">DataTables</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-laba">
                            <thead class="table-light">
                                <tr>
                                    <th>No</th>
                                    <th>Tanggal</th>
                                    <th>Kasir</th>
                                    <th class="text-end">Omzet</th>
                                    <th class="text-end">HPP</th>
                                    <th class="text-end">Laba</th>
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
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    (function () {
        'use strict';

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        var tanggalMulai = document.getElementById('tanggal-mulai').value;
        var tanggalAkhir = document.getElementById('tanggal-akhir').value;
        var qs = 'tanggal_mulai=' + tanggalMulai + '&tanggal_akhir=' + tanggalAkhir;

        // Ringkasan
        fetch('api.php?aksi=laba.ringkasan&' + qs)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                document.getElementById('nilai-omzet').textContent = rupiah(d.omzet);
                document.getElementById('nilai-hpp').textContent = rupiah(d.hpp);
                document.getElementById('nilai-laba').textContent = rupiah(d.laba);
                document.getElementById('nilai-margin').textContent = Number(d.margin).toFixed(1) + '%';
                document.getElementById('nilai-jumlah').textContent = d.jumlah_transaksi + ' transaksi';
            });

        // Grafik
        fetch('api.php?aksi=laba.grafik&' + qs)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var kanvas = document.getElementById('grafik-laba');
                if (!kanvas || !window.Chart) return;
                new Chart(kanvas, {
                    type: 'bar',
                    data: {
                        labels: d.labels,
                        datasets: [
                            {
                                label: 'Omzet',
                                data: d.series.omzet,
                                backgroundColor: 'rgba(13, 148, 136, 0.55)',
                                borderColor: '#0d9488',
                                borderWidth: 1
                            },
                            {
                                label: 'Laba',
                                data: d.series.laba,
                                backgroundColor: 'rgba(22, 163, 74, 0.55)',
                                borderColor: '#16a34a',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + rupiah(c.parsed.y); } } }
                        },
                        scales: {
                            y: {
                                ticks: { callback: function (v) { return 'Rp ' + Number(v).toLocaleString('id-ID'); } }
                            }
                        }
                    }
                });
            });

        // Tabel laba per transaksi (DataTables server-side)
        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-laba').DataTable({
                serverSide: true,
                ajax: {
                    url: 'api.php?aksi=laba.tabel',
                    data: function (d) {
                        d.draw = d.draw || 0;
                        d.tanggal_mulai = tanggalMulai;
                        d.tanggal_akhir = tanggalAkhir;
                    }
                },
                pageLength: 10,
                lengthChange: false,
                order: [],
                columns: [
                    { data: 'id' },
                    { data: 'tanggal' },
                    { data: 'kasir_nama' },
                    { data: 'omzet', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    { data: 'hpp', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    {
                        data: 'laba',
                        className: 'text-end font-num',
                        render: function (d) {
                            var cls = d < 0 ? 'text-danger' : 'text-success';
                            return '<span class="' + cls + '">' + rupiah(d) + '</span>';
                        }
                    }
                ],
                language: {
                    url: 'assets/vendor/datatables/id.json'
                }
            });
        }
    })();
</script>
</body>
</html>
