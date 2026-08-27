<?php

declare(strict_types=1);

/**
 * Halaman laporan laba/rugi (admin only) - Ultra UX Analytics.
 *
 * Filter rentang tanggal dengan Quick Date Presets (Hari ini, 7 hari, Bulan ini),
 * Kartu ringkasan finansial (Omzet, HPP, Laba, Margin),
 * Grafik omzet-vs-laba interaktif Chart.js, dan DataTables per transaksi.
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
    logoutKaryawan();
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
$breadcrumb = ['Dashboard' => 'dashboard.php', 'Laba & Rugi' => ''];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Laba &amp; Rugi — Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css?v=<?= @filemtime(__DIR__ . '/assets/theme.css') ?: time() ?>" rel="stylesheet">
    <style>
        .nilai-besar { font-size: 1.55rem; font-weight: 800; line-height: 1.2; }
        @media print {
            .navbar, .pos-navbar, .btn-preset-chip, .card-header button, form, .dataTables_filter, .dataTables_paginate { display: none !important; }
            .card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <!-- Page Header & Action Bar -->
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3">
        <div>
            <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-piggy-bank text-primary me-2"></i>Laporan Laba &amp; Rugi</h1>
            <span class="text-muted small">Analisis performa omzet, HPP modal, laba kotor, dan profit margin toko</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Cetak Laporan">
                <i class="bi bi-printer me-1"></i>Cetak
            </button>
            <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
        </div>
    </div>

    <?php if ($peringatanTanggal !== ''): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($peringatanTanggal) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <!-- Filter periode dengan Quick Date Presets -->
    <div class="card pos-card mb-4 border-0 shadow-sm">
        <div class="card-body p-3 p-md-4">
            <form method="get" id="form-filter-laba" class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label for="tanggal-mulai" class="form-label fw-semibold small text-muted">Dari Tanggal</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-calendar3"></i></span>
                        <input type="date" id="tanggal-mulai" name="tanggal_mulai" class="form-control" value="<?= $dMulai->format('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label for="tanggal-akhir" class="form-label fw-semibold small text-muted">Sampai Tanggal</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-calendar-check"></i></span>
                        <input type="date" id="tanggal-akhir" name="tanggal_akhir" class="form-control" value="<?= $dAkhir->format('Y-m-d') ?>">
                    </div>
                </div>
                <div class="col-md-4 col-sm-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-funnel-fill me-1"></i>Tampilkan
                    </button>
                    <a href="laba.php" class="btn btn-outline-secondary btn-sm" title="Reset filter">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                </div>

                <!-- Quick Date Preset Chips -->
                <div class="col-12 pt-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="small text-muted fw-semibold me-1"><i class="bi bi-lightning-charge me-1"></i>Filter Cepat:</span>
                        <div class="date-preset-chips mt-0">
                            <button type="button" class="btn-preset-chip" data-preset="today">Hari Ini</button>
                            <button type="button" class="btn-preset-chip" data-preset="yesterday">Kemarin</button>
                            <button type="button" class="btn-preset-chip" data-preset="7days">7 Hari Terakhir</button>
                            <button type="button" class="btn-preset-chip" data-preset="this_month">Bulan Ini</button>
                            <button type="button" class="btn-preset-chip" data-preset="last_month">Bulan Lalu</button>
                            <button type="button" class="btn-preset-chip" data-preset="this_year">Tahun Ini</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Kartu ringkasan finansial interaktif -->
    <div class="row g-3 mb-4">
        <!-- Omzet -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-elevated p-3 h-100 shadow-sm">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase">Total Omzet</span>
                    <div class="stat-icon-bubble bg-teal text-primary">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
                <div class="nilai-besar font-num text-dark mb-1" id="nilai-omzet">
                    <span class="spinner-border spinner-border-sm text-muted"></span>
                </div>
                <span class="small text-muted">Nilai bruto penjualan</span>
            </div>
        </div>

        <!-- HPP -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-elevated card-warning p-3 h-100 shadow-sm">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase">Total HPP (Modal)</span>
                    <div class="stat-icon-bubble bg-amber text-warning">
                        <i class="bi bi-box-seam"></i>
                    </div>
                </div>
                <div class="nilai-besar font-num text-dark mb-1" id="nilai-hpp">
                    <span class="spinner-border spinner-border-sm text-muted"></span>
                </div>
                <span class="small text-muted">Harga pokok pembelian produk</span>
            </div>
        </div>

        <!-- Laba Kotor -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-elevated card-success p-3 h-100 shadow-sm">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase">Laba Kotor</span>
                    <div class="stat-icon-bubble bg-emerald text-success">
                        <i class="bi bi-graph-up-arrow"></i>
                    </div>
                </div>
                <div class="nilai-besar font-num text-success mb-1" id="nilai-laba">
                    <span class="spinner-border spinner-border-sm text-muted"></span>
                </div>
                <span class="small text-muted">Keuntungan kotor (Omzet - HPP)</span>
            </div>
        </div>

        <!-- Margin -->
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-elevated card-info p-3 h-100 shadow-sm">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="text-muted small fw-semibold text-uppercase">Margin Keuntungan</span>
                    <div class="stat-icon-bubble bg-sky text-info">
                        <i class="bi bi-percent"></i>
                    </div>
                </div>
                <div class="d-flex align-items-baseline gap-2 mb-1">
                    <div class="nilai-besar font-num text-info" id="nilai-margin">
                        <span class="spinner-border spinner-border-sm text-muted"></span>
                    </div>
                </div>
                <div class="small text-muted" id="nilai-jumlah">—</div>
            </div>
        </div>
    </div>

    <!-- Grafik & Tabel Detail -->
    <div class="row g-4">
        <!-- Grafik omzet vs laba -->
        <div class="col-lg-5">
            <div class="card pos-card h-100 border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-bar-chart-fill text-primary me-2"></i>Tren Omzet vs Laba per Hari</span>
                    <span class="badge text-bg-light border small font-num"><?= $dMulai->format('d M') ?> - <?= $dAkhir->format('d M Y') ?></span>
                </div>
                <div class="card-body p-3">
                    <div style="position: relative; height: 300px;">
                        <canvas id="grafik-laba"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel laba per transaksi -->
        <div class="col-lg-7">
            <div class="card pos-card h-100 border-0 shadow-sm">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <span class="fw-bold"><i class="bi bi-receipt-cutoff text-primary me-2"></i>Rincian per Transaksi</span>
                    <span class="badge text-bg-primary" id="badge-total-trx">Data Live</span>
                </div>
                <div class="card-body p-3">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 w-100" id="tabel-laba">
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

        function formatTanggalISO(d) {
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        var inputMulai = document.getElementById('tanggal-mulai');
        var inputAkhir = document.getElementById('tanggal-akhir');
        var formFilter = document.getElementById('form-filter-laba');

        // Quick Date Preset Logic
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-preset-chip');
            if (!btn) return;
            var preset = btn.getAttribute('data-preset');
            var now = new Date();
            var start = new Date();
            var end = new Date();

            if (preset === 'today') {
                // start & end = now
            } else if (preset === 'yesterday') {
                start.setDate(now.getDate() - 1);
                end.setDate(now.getDate() - 1);
            } else if (preset === '7days') {
                start.setDate(now.getDate() - 6);
            } else if (preset === 'this_month') {
                start = new Date(now.getFullYear(), now.getMonth(), 1);
                end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            } else if (preset === 'last_month') {
                start = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                end = new Date(now.getFullYear(), now.getMonth(), 0);
            } else if (preset === 'this_year') {
                start = new Date(now.getFullYear(), 0, 1);
                end = new Date(now.getFullYear(), 11, 31);
            }

            if (inputMulai && inputAkhir && formFilter) {
                inputMulai.value = formatTanggalISO(start);
                inputAkhir.value = formatTanggalISO(end);
                formFilter.submit();
            }
        });

        // Set active chip styling based on current values
        var tMulaiVal = inputMulai ? inputMulai.value : '';
        var tAkhirVal = inputAkhir ? inputAkhir.value : '';
        var nowISO = formatTanggalISO(new Date());
        var firstDayMonthISO = formatTanggalISO(new Date(new Date().getFullYear(), new Date().getMonth(), 1));

        if (tMulaiVal === nowISO && tAkhirVal === nowISO) {
            var bToday = document.querySelector('[data-preset="today"]');
            if (bToday) bToday.classList.add('active');
        } else if (tMulaiVal === firstDayMonthISO && tAkhirVal === nowISO) {
            var bMonth = document.querySelector('[data-preset="this_month"]');
            if (bMonth) bMonth.classList.add('active');
        }

        var qs = 'tanggal_mulai=' + tMulaiVal + '&tanggal_akhir=' + tAkhirVal;

        // Ringkasan Finansial
        fetch('api.php?aksi=laba.ringkasan&' + qs)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                document.getElementById('nilai-omzet').textContent = rupiah(d.omzet);
                document.getElementById('nilai-hpp').textContent = rupiah(d.hpp);
                document.getElementById('nilai-laba').textContent = rupiah(d.laba);
                document.getElementById('nilai-margin').textContent = Number(d.margin).toFixed(1) + '%';
                document.getElementById('nilai-jumlah').textContent = d.jumlah_transaksi + ' Transaksi tercatat';
            })
            .catch(function () {
                document.getElementById('nilai-omzet').textContent = 'Rp 0';
                document.getElementById('nilai-hpp').textContent = 'Rp 0';
                document.getElementById('nilai-laba').textContent = 'Rp 0';
                document.getElementById('nilai-margin').textContent = '0%';
                document.getElementById('nilai-jumlah').textContent = 'Gagal memuat';
            });

        // Grafik Tren Omzet vs Laba Chart.js
        fetch('api.php?aksi=laba.grafik&' + qs)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var kanvas = document.getElementById('grafik-laba');
                if (!kanvas || !window.Chart) return;
                var ctx = kanvas.getContext('2d');

                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: d.labels,
                        datasets: [
                            {
                                label: 'Omzet Penjualan',
                                data: d.series.omzet,
                                backgroundColor: 'rgba(13, 148, 136, 0.75)',
                                borderColor: '#0d9488',
                                borderRadius: 4,
                                borderWidth: 1
                            },
                            {
                                label: 'Laba Bersih Kotor',
                                data: d.series.laba,
                                backgroundColor: 'rgba(16, 185, 129, 0.85)',
                                borderColor: '#10b981',
                                borderRadius: 4,
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    boxWidth: 12,
                                    font: { family: 'Inter, sans-serif', weight: '600' }
                                }
                            },
                            tooltip: {
                                padding: 10,
                                cornerRadius: 6,
                                callbacks: {
                                    label: function (c) {
                                        return c.dataset.label + ': ' + rupiah(c.parsed.y);
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                grid: { display: false }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function (v) { return rupiah(v); }
                                }
                            }
                        }
                    }
                });
            });

        // Tabel laba per transaksi (DataTables)
        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-laba').DataTable({
                serverSide: true,
                ajax: {
                    url: 'api.php?aksi=laba.tabel',
                    data: function (d) {
                        d.draw = d.draw || 0;
                        d.tanggal_mulai = tMulaiVal;
                        d.tanggal_akhir = tAkhirVal;
                    }
                },
                pageLength: 10,
                lengthChange: false,
                order: [],
                columns: [
                    { data: 'id', width: '40px', className: 'text-center' },
                    { data: 'tanggal', className: 'font-num small' },
                    { data: 'kasir_nama', className: 'fw-semibold' },
                    { data: 'omzet', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    { data: 'hpp', className: 'text-end font-num', render: function (d) { return rupiah(d); } },
                    {
                        data: 'laba',
                        className: 'text-end font-num fw-bold',
                        render: function (d) {
                            var cls = d < 0 ? 'text-danger' : 'text-success';
                            return '<span class="' + cls + '">' + rupiah(d) + '</span>';
                        }
                    }
                ],
                language: {
                    search: "Cari transaksi:",
                    searchPlaceholder: "Nomor / kasir...",
                    emptyTable: "Belum ada data transaksi di periode ini",
                    info: "Menampilkan _START_ - _END_ dari _TOTAL_ transaksi",
                    infoEmpty: "0 transaksi",
                    paginate: {
                        first: "«",
                        previous: "‹",
                        next: "›",
                        last: "»"
                    }
                }
            });
        }
    })();
</script>
</body>
</html>
