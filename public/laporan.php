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
    logoutKaryawan();
    header('Location: login.php');
    exit;
}

// Periode default: bulan berjalan.
$tanggalMulai = $_GET['tanggal_mulai'] ?? date('Y-m-01');
$tanggalAkhir = $_GET['tanggal_akhir'] ?? date('Y-m-d');

// Validasi tanggal: kalau tidak valid, fallback ke bulan berjalan.
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

// Ekspor laporan: PDF (sungguhan via Dompdf) atau CSV.
// Dipanggil server-side via model, dikirim sebagai download.
$ekspor = (string) ($_GET['ekspor'] ?? '');

if ($ekspor === 'pdf' || $ekspor === 'csv') {
    $laporan = new LaporanPenjualan();
    $laporan->setPeriode($dMulai, $dAkhir);

    if ($ekspor === 'pdf') {
        $konten = $laporan->eksporPDF();
        $namaFile = 'laporan-penjualan-' . date('Ymd-His') . '.pdf';
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $namaFile . '"');
        echo $konten;
        exit;
    }

    $csv = $laporan->keCsv();
    $namaFile = 'laporan-penjualan-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    // BOM UTF-8 supaya terbuka benar di Excel.
    echo "\xEF\xBB\xBF" . $csv;
    exit;
}

// Tabel & grafik periode diambil via api.php (Controller → DataReporter),
// bukan di-render di view. View murni konsumen JSON.
$aktif = 'laporan';
$breadcrumb = ['Dashboard' => 'dashboard.php', 'Laporan Penjualan' => ''];

// Header toko utk cetak laporan (muncul hanya saat print via @media print).
$namaTokoCetak = \App\Models\Pengaturan::get('nama_toko', 'Minimarket');
$alamatTokoCetak = \App\Models\Pengaturan::get('alamat', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Penjualan - Kasir Minimarket</title>
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/vendor/datatables/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
    <style>
        .total-besar { font-size: 1.5rem; font-weight: 700; }
        .header-toko-cetak { display: none; }
        @media print {
            .header-toko-cetak {
                display: block !important;
                text-align: center;
                margin-bottom: 1rem;
                border-bottom: 2px solid #000;
                padding-bottom: 0.5rem;
            }
            .header-toko-cetak h1 {
                font-size: 1.25rem;
                margin-bottom: 0.1rem;
            }
            .header-toko-cetak p {
                margin-bottom: 0;
                font-size: 0.8rem;
                color: #333;
            }
        }
    </style>
</head>
<body>
<?php require __DIR__ . '/assets/partials/navbar.php'; ?>
<div class="container py-4">

    <div class="header-toko-cetak">
        <h1><?= htmlspecialchars($namaTokoCetak) ?></h1>
        <p>Laporan Penjualan — <?= htmlspecialchars($dMulai->format('d M Y')) ?> s/d <?= htmlspecialchars($dAkhir->format('d M Y')) ?></p>
        <?php if ($alamatTokoCetak !== ''): ?>
            <p><?= htmlspecialchars($alamatTokoCetak) ?></p>
        <?php endif; ?>
    </div>

    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-3 no-print">
        <div>
            <h1 class="h3 mb-1 fw-bold text-dark"><i class="bi bi-bar-chart-line text-primary me-2"></i>Laporan Penjualan</h1>
            <span class="text-muted small">Rekapitulasi transaksi penjualan kasir per periode</span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="laporan.php?tanggal_mulai=<?= urlencode($tanggalMulai) ?>&tanggal_akhir=<?= urlencode($tanggalAkhir) ?>&ekspor=pdf"
               class="btn btn-outline-danger btn-sm" title="Unduh PDF">
                <i class="bi bi-file-earmark-pdf me-1"></i>Unduh PDF
            </a>
            <a href="laporan.php?tanggal_mulai=<?= urlencode($tanggalMulai) ?>&tanggal_akhir=<?= urlencode($tanggalAkhir) ?>&ekspor=csv"
               class="btn btn-outline-success btn-sm" title="Unduh Excel (CSV)">
                <i class="bi bi-file-earmark-excel me-1"></i>Export CSV
            </a>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()" title="Cetak Halaman">
                <i class="bi bi-printer me-1"></i>Cetak
            </button>
        </div>
    </div>

    <?php if ($peringatanTanggal !== ''): ?>
        <div class="alert alert-warning alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($peringatanTanggal) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
        </div>
    <?php endif; ?>

    <div class="card pos-card mb-4 border-0 shadow-sm no-print">
        <div class="card-body p-3 p-md-4">
            <form method="get" id="form-filter-laporan" class="row g-3 align-items-end">
                <div class="col-md-4 col-sm-6">
                    <label for="tanggal-mulai" class="form-label fw-semibold small text-muted">Dari Tanggal</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-calendar3"></i></span>
                        <input
                            type="date"
                            id="tanggal-mulai"
                            name="tanggal_mulai"
                            class="form-control"
                            value="<?= htmlspecialchars($tanggalMulai) ?>"
                            required
                        >
                    </div>
                </div>
                <div class="col-md-4 col-sm-6">
                    <label for="tanggal-akhir" class="form-label fw-semibold small text-muted">Sampai Tanggal</label>
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-light"><i class="bi bi-calendar-check"></i></span>
                        <input
                            type="date"
                            id="tanggal-akhir"
                            name="tanggal_akhir"
                            class="form-control"
                            value="<?= htmlspecialchars($tanggalAkhir) ?>"
                            required
                        >
                    </div>
                </div>
                <div class="col-md-4 col-sm-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm flex-fill">
                        <i class="bi bi-funnel-fill me-1"></i>Tampilkan
                    </button>
                    <a href="laporan.php" class="btn btn-outline-secondary btn-sm" title="Reset filter">
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

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card pos-card">
                <div class="card-header bg-white"><strong>Ringkasan</strong></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-7 text-muted fw-normal">Periode</dt>
                        <dd class="col-5 text-end mb-0">
                            <?= htmlspecialchars($dMulai->format('d M Y')) ?>
                            s/d
                            <?= htmlspecialchars($dAkhir->format('d M Y')) ?>
                        </dd>
                        <dt class="col-7 text-muted fw-normal">Jumlah transaksi</dt>
                        <dd class="col-5 text-end mb-0 font-num" id="ringkasan-jumlah">—</dd>
                        <hr class="my-2">
                        <dt class="col-7 fw-semibold">Total penjualan</dt>
                        <dd class="col-5 text-end total-besar font-num" id="ringkasan-total">—</dd>
                    </dl>
                </div>
            </div>

            <!-- Grafik penjualan periode -->
            <div class="card pos-card mt-4">
                <div class="card-header bg-white"><span>Grafik Penjualan</span></div>
                <div class="card-body">
                    <div class="chart-container" style="height: 240px;">
                        <canvas id="grafik-laporan"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card pos-card">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Daftar Transaksi</span>
                    <span class="d-flex gap-2 no-print">
                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="window.print()" title="Cetak laporan">
                            <i class="bi bi-printer me-1"></i>Cetak
                        </button>
                        <a
                            href="?tanggal_mulai=<?= htmlspecialchars($dMulai->format('Y-m-d')) ?>&amp;tanggal_akhir=<?= htmlspecialchars($dAkhir->format('Y-m-d')) ?>&amp;ekspor=pdf"
                            class="btn btn-sm btn-outline-danger"
                            title="Unduh laporan periode ini sebagai PDF"
                        >
                            <i class="bi bi-file-earmark-pdf me-1"></i>Ekspor PDF
                        </a>
                        <a
                            href="?tanggal_mulai=<?= htmlspecialchars($dMulai->format('Y-m-d')) ?>&amp;tanggal_akhir=<?= htmlspecialchars($dAkhir->format('Y-m-d')) ?>&amp;ekspor=csv"
                            class="btn btn-sm btn-outline-success"
                            title="Unduh laporan periode ini sebagai CSV"
                        >
                            <i class="bi bi-download me-1"></i>Ekspor CSV
                        </a>
                    </span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" id="tabel-transaksi-laporan">
                            <thead class="table-light">
                                <tr>
                                    <th>No. Transaksi</th>
                                    <th>Tanggal</th>
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
<script src="assets/vendor/jquery/jquery.min.js"></script>
<script src="assets/vendor/datatables/dataTables.min.js"></script>
<script src="assets/vendor/datatables/dataTables.bootstrap5.min.js"></script>
<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="assets/theme.js"></script>
<script>
    // View murni: ringkasan, grafik, dan tabel dari api.php (Controller → DataReporter).
    (function () {
        'use strict';

        var mulai = <?= json_encode($dMulai->format('Y-m-d')) ?>;
        var akhir = <?= json_encode($dAkhir->format('Y-m-d')) ?>;
        var params = '&tanggal_mulai=' + mulai + '&tanggal_akhir=' + akhir;

        function formatTanggalISO(d) {
            var y = d.getFullYear();
            var m = String(d.getMonth() + 1).padStart(2, '0');
            var day = String(d.getDate()).padStart(2, '0');
            return y + '-' + m + '-' + day;
        }

        var inputMulai = document.getElementById('tanggal-mulai');
        var inputAkhir = document.getElementById('tanggal-akhir');
        var formFilter = document.getElementById('form-filter-laporan');

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

        function rupiah(n) {
            return 'Rp ' + Number(n).toLocaleString('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        }

        // Ringkasan: ambil total & jumlah dari tabel (recordsTotal) + hitung total.
        // Memakai endpoint grafik utk total periode.
        fetch('api.php?aksi=laporan.grafik' + params)
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var total = (d.series.data || []).reduce(function (a, b) { return a + b; }, 0);
                document.getElementById('ringkasan-total').textContent = rupiah(total);

                var el = document.getElementById('grafik-laporan');
                if (el && typeof Chart !== 'undefined') {
                    new Chart(el, {
                        type: 'line',
                        data: {
                            labels: d.labels || [],
                            datasets: [{
                                label: 'Penjualan',
                                data: d.series.data || [],
                                borderColor: 'rgba(13, 148, 136, 0.9)',
                                backgroundColor: 'rgba(13, 148, 136, 0.15)',
                                fill: true,
                                tension: 0.3,
                                pointRadius: 4
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
                }
            })
            .catch(function () { /* diam */ });

        // DataTables server-side transaksi periode.
        if (window.jQuery && window.DataTable) {
            jQuery('#tabel-transaksi-laporan').DataTable({
                serverSide: true,
                ajax: {
                    url: 'api.php?aksi=laporan.tabel' + params,
                    data: function (d) { d.draw = d.draw || 0; }
                },
                pageLength: 10,
                lengthChange: false,
                order: [],
                columns: [
                    { data: 'id', render: function (d) { return '#' + d; } },
                    { data: 'tanggal' },
                    { data: 'kasir_nama' },
                    { data: 'total', className: 'text-end font-num', render: function (d) { return rupiah(d); } }
                ],
                language: {
                    url: 'assets/vendor/datatables/id.json'
                }
            }).on('xhr.dt', function (e, settings, json) {
                if (json && typeof json.recordsTotal !== 'undefined') {
                    document.getElementById('ringkasan-jumlah').textContent = json.recordsTotal;
                }
            });
        }
    })();
</script>
</body>
</html>
