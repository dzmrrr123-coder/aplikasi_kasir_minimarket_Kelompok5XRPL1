<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/autoload.php';

use App\Database\Database;
use App\Models\Produk;

// Auth: wajib login + role admin.
SessionGuard::requireLogin();
SessionGuard::requireRole('admin');

// Ringkasan singkat: hitung cepat dari database.
$pdo = Database::connect();

$totalProduk    = (int) $pdo->query("SELECT COUNT(*) FROM produk WHERE is_active = 1")->fetchColumn();
$totalKasir     = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'kasir' AND is_active = 1")->fetchColumn();
$totalSupplier  = (int) $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
$stokMenipis    = Produk::cariStokMenipis();

$pageTitle = 'Dashboard';
$aktif     = 'dashboard';

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<h1 class="h3 mb-1">Dashboard</h1>
<p class="text-muted mb-4">Ringkasan performa toko hari ini.</p>

<?php if (!empty($stokMenipis)): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>Stok menipis!</strong>
    <?php
    $daftar = array_map(static fn ($p) => $p->getNama() . ' (' . $p->getStok() . ')', array_slice($stokMenipis, 0, 5));
    ?>
    <?= htmlspecialchars(implode(', ', $daftar)) ?>
    <?php if (count($stokMenipis) > 5): ?>
        <span class="text-muted">+<?= count($stokMenipis) - 5 ?> lagi</span>
    <?php endif; ?>
    <a href="<?= $baseUrl ?>/admin/produk.php" class="alert-link ms-2">Kelola stok</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Tutup"></button>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="bg-primary bg-opacity-10 text-primary rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="bi bi-boxes fs-5"></i>
                </span>
                <div>
                    <div class="fs-4 fw-bold font-num"><?= $totalProduk ?></div>
                    <div class="text-muted small">Total Produk</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="bg-info bg-opacity-10 text-info rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="bi bi-people fs-5"></i>
                </span>
                <div>
                    <div class="fs-4 fw-bold font-num"><?= $totalKasir ?></div>
                    <div class="text-muted small">Kasir Aktif</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="bg-warning bg-opacity-10 text-warning rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="bi bi-truck fs-5"></i>
                </span>
                <div>
                    <div class="fs-4 fw-bold font-num"><?= $totalSupplier ?></div>
                    <div class="text-muted small">Total Supplier</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="bg-danger bg-opacity-10 text-danger rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                    <i class="bi bi-exclamation-triangle fs-5"></i>
                </span>
                <div>
                    <div class="fs-4 fw-bold font-num"><?= count($stokMenipis) ?></div>
                    <div class="text-muted small">Stok Menipis</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Quick links -->
<div class="row g-3">
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/produk.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-primary border-2 text-center p-3">
                <i class="bi bi-box-seam fs-2 text-primary mb-2"></i>
                <div class="fw-medium">Kelola Produk</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/kategori.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-secondary border-2 text-center p-3">
                <i class="bi bi-tag fs-2 text-secondary mb-2"></i>
                <div class="fw-medium">Kelola Kategori</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/laporan.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-success border-2 text-center p-3">
                <i class="bi bi-bar-chart-line fs-2 text-success mb-2"></i>
                <div class="fw-medium">Laporan Penjualan</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/supplier.php" class="text-decoration-none">
            <div class="card h-100 shadow-sm border-warning border-2 text-center p-3">
                <i class="bi bi-truck fs-2 text-warning mb-2"></i>
                <div class="fw-medium">Kelola Supplier</div>
            </div>
        </a>
    </div>
</div>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
