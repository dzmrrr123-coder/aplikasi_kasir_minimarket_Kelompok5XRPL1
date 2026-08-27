<?php

declare(strict_types=1);

/**
 * Halaman admin: laporan penjualan.
 *
 * Fitur:
 * - Form filter periode (tanggal mulai & akhir), default bulan berjalan.
 * - Tampilkan ringkasan (jumlah transaksi, total pendapatan, rata-rata transaksi).
 * - Tabel detail transaksi per baris.
 * - Tombol ekspor PDF dan CSV.
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Models\LaporanPenjualan;

SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

$nama      = $_SESSION['nama'] ?? 'Admin';
$pageTitle = 'Laporan Penjualan';
$aktif     = 'laporan';

// Default periode: bulan berjalan.
$bulanIni     = date('Y-m');
$mulaiDefault = $bulanIni . '-01';
$akhirDefault = date('Y-m-t'); // hari terakhir bulan ini

$mulai = trim((string) ($_GET['mulai'] ?? $mulaiDefault));
$akhir = trim((string) ($_GET['akhir'] ?? $akhirDefault));

// Ekspor PDF / CSV (download).
$ekspor = (string) ($_GET['ekspor'] ?? '');
if ($ekspor !== '' && in_array($ekspor, ['pdf', 'csv'], true)) {
    $laporan = new LaporanPenjualan();
    try {
        $mulaiDt = new DateTimeImmutable($mulai);
        $akhirDt = new DateTimeImmutable($akhir);
    } catch (Throwable) {
        $mulaiDt = new DateTimeImmutable($mulaiDefault);
        $akhirDt = new DateTimeImmutable($akhirDefault);
    }
    $laporan->setPeriode($mulaiDt, $akhirDt);

    if ($ekspor === 'pdf') {
        try {
            $pdf = $laporan->eksporPDF();
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="laporan-' . $mulai . '-sd-' . $akhir . '.pdf"');
            echo $pdf;
        } catch (Throwable $e) {
            // Dompdf mungkin belum ada — fallback ke pesan error HTML.
            http_response_code(500);
            echo '<p>Ekspor PDF gagal: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    } elseif ($ekspor === 'csv') {
        $csv = $laporan->keCsv();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="laporan-' . $mulai . '-sd-' . $akhir . '.csv"');
        echo "\xEF\xBB\xBF"; // BOM untuk Excel
        echo $csv;
    }
    exit;
}

// Ambil data laporan.
$laporan = new LaporanPenjualan();
try {
    $mulaiDt = new DateTimeImmutable($mulai);
    $akhirDt = new DateTimeImmutable($akhir);
} catch (Throwable) {
    $mulaiDt = new DateTimeImmutable($mulaiDefault);
    $akhirDt = new DateTimeImmutable($akhirDefault);
}
$laporan->setPeriode($mulaiDt, $akhirDt);
$hasil = $laporan->generate();

$rataRata = $hasil['jumlah'] > 0 ? (int) round($hasil['total'] / $hasil['jumlah']) : 0;

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-bar-chart-line me-2 text-primary"></i>Laporan Penjualan</h1>
        <p class="page-subtitle">Rekapitulasi penjualan dan performa pendapatan toko</p>
    </div>
    <?php if ($hasil['jumlah'] > 0): ?>
    <div class="d-flex gap-2">
        <a href="?mulai=<?= urlencode($mulai) ?>&akhir=<?= urlencode($akhir) ?>&ekspor=pdf"
           class="btn btn-outline-danger" title="Unduh laporan dalam format PDF">
            <i class="bi bi-file-earmark-pdf me-1"></i>Ekspor PDF
        </a>
        <a href="?mulai=<?= urlencode($mulai) ?>&akhir=<?= urlencode($akhir) ?>&ekspor=csv"
           class="btn btn-outline-success" title="Unduh data dalam format CSV Excel">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i>Ekspor CSV
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Form Filter Periode -->
<div class="admin-card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-sm-4 col-md-3">
                <label class="form-label">Tanggal Mulai</label>
                <input type="date" name="mulai" class="form-control" value="<?= htmlspecialchars($mulai) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <label class="form-label">Tanggal Akhir</label>
                <input type="date" name="akhir" class="form-control" value="<?= htmlspecialchars($akhir) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-filter me-1"></i>Terapkan Filter
                </button>
            </div>
            <div class="col-md-3 d-flex gap-1">
                <a href="?mulai=<?= date('Y-m-d') ?>&akhir=<?= date('Y-m-d') ?>" class="btn btn-sm btn-outline-secondary flex-fill text-nowrap">
                    Hari Ini
                </a>
                <a href="?mulai=<?= date('Y-m-01') ?>&akhir=<?= date('Y-m-t') ?>" class="btn btn-sm btn-outline-secondary flex-fill text-nowrap">
                    Bulan Ini
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Stat Ringkasan -->
<?php if ($hasil['jumlah'] > 0): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-teal">
                <i class="bi bi-receipt"></i>
            </div>
            <div>
                <div class="stat-mini-value font-num"><?= number_format($hasil['jumlah'], 0, ',', '.') ?></div>
                <div class="stat-mini-label">Total Transaksi</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-emerald">
                <i class="bi bi-currency-dollar"></i>
            </div>
            <div>
                <div class="stat-mini-value font-num text-success">Rp <?= number_format($hasil['total'], 0, ',', '.') ?></div>
                <div class="stat-mini-label">Total Pendapatan</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-indigo">
                <i class="bi bi-calculator"></i>
            </div>
            <div>
                <div class="stat-mini-value font-num">Rp <?= number_format($rataRata, 0, ',', '.') ?></div>
                <div class="stat-mini-label">Rata-rata / Transaksi</div>
            </div>
        </div>
    </div>
</div>

<!-- Tabel Detail Transaksi -->
<div class="admin-table-wrap animate-fade-in">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center bg-surface-2">
        <span class="fw-bold"><i class="bi bi-list-check me-2"></i>Rincian Transaksi</span>
        <span class="text-muted small">
            Periode: <?= htmlspecialchars($mulaiDt->format('d M Y')) ?> &mdash; <?= htmlspecialchars($akhirDt->format('d M Y')) ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="admin-table">
            <thead>
                <tr>
                    <th style="width:140px">ID Transaksi</th>
                    <th>Waktu Transaksi</th>
                    <th>Nama Kasir</th>
                    <th style="width:180px" class="text-end">Nominal Belanja</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($hasil['transaksi'] as $tx): ?>
                <tr>
                    <td class="font-num text-muted">
                        <span class="status-badge" style="background:var(--surface-2);color:var(--text);font-family:var(--font-num)">
                            #<?= htmlspecialchars((string) $tx->getId()) ?>
                        </span>
                    </td>
                    <td>
                        <i class="bi bi-clock me-1 text-muted small"></i>
                        <?= htmlspecialchars($tx->getTanggal()->format('d/m/Y H:i')) ?>
                    </td>
                    <td>
                        <i class="bi bi-person me-1 text-primary small"></i>
                        <?= htmlspecialchars($tx->getKasirNama()) ?>
                    </td>
                    <td class="text-end font-num fw-bold">
                        Rp <?= number_format($tx->getTotal(), 0, ',', '.') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--surface-2);font-weight:700">
                    <td colspan="3" class="text-end text-muted">TOTAL KESELURUHAN:</td>
                    <td class="text-end font-num text-success fs-6">
                        Rp <?= number_format($hasil['total'], 0, ',', '.') ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php else: ?>
<div class="admin-table-wrap">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-inbox" style="font-size:3rem;opacity:.3"></i>
        <h5 class="mt-3 mb-1 fw-bold">Tidak Ada Data Penjualan</h5>
        <p class="mb-0 text-muted small"><?= htmlspecialchars($hasil['pesan']) ?></p>
    </div>
</div>
<?php endif; ?>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
