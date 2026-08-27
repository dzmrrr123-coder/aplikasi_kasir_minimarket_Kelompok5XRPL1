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
$totalKategori  = (int) $pdo->query("SELECT COUNT(*) FROM kategori")->fetchColumn();
$stokMenipis    = Produk::cariStokMenipis();

$pageTitle = 'Dashboard';
$aktif     = 'dashboard';

require __DIR__ . '/../../views/layouts/header.php';
require __DIR__ . '/../../views/layouts/sidebar-admin.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div class="page-header-left">
        <h1><i class="bi bi-speedometer2 me-2 text-teal"></i>Dashboard Admin</h1>
        <p class="page-subtitle">Ringkasan cepat status data master toko dan navigasi cepat</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $baseUrl ?>/transaksi.php" class="btn btn-success">
            <i class="bi bi-cash-register me-1"></i>Buka Kasir POS
        </a>
    </div>
</div>

<?php if (!empty($stokMenipis)): ?>
<div class="alert alert-warning alert-dismissible fade show" role="alert">
    <div class="d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5 text-warning"></i>
        <div>
            <strong>Peringatan: <?= count($stokMenipis) ?> produk dengan stok menipis!</strong>
            <span class="d-none d-md-inline ms-1">Segera lakukan pembelian stok atau cek detail di menu Produk.</span>
        </div>
        <a href="<?= $baseUrl ?>/admin/produk.php" class="btn btn-sm btn-warning text-dark ms-auto fw-semibold">
            Lihat Produk
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-indigo">
                <i class="bi bi-box-seam"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalProduk ?></div>
                <div class="stat-mini-label">Total Produk</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-emerald">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalKasir ?></div>
                <div class="stat-mini-label">Kasir Aktif</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-sky">
                <i class="bi bi-truck"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalSupplier ?></div>
                <div class="stat-mini-label">Supplier</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-mini">
            <div class="stat-mini-icon icon-amber">
                <i class="bi bi-tag"></i>
            </div>
            <div>
                <div class="stat-mini-value"><?= $totalKategori ?></div>
                <div class="stat-mini-label">Kategori</div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Navigation Menu -->
<h2 class="h5 fw-bold mb-3"><i class="bi bi-grid me-2 text-primary"></i>Pintas Navigasi Admin</h2>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/produk.php" class="text-decoration-none">
            <div class="admin-card h-100 p-3 text-center">
                <span class="sidebar-icon icon-indigo mx-auto mb-2" style="width:3rem;height:3rem;font-size:1.4rem">
                    <i class="bi bi-box-seam"></i>
                </span>
                <div class="fw-bold text-dark">Kelola Produk</div>
                <div class="text-muted small mt-1">Data master produk & stok</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/kategori.php" class="text-decoration-none">
            <div class="admin-card h-100 p-3 text-center">
                <span class="sidebar-icon icon-amber mx-auto mb-2" style="width:3rem;height:3rem;font-size:1.4rem">
                    <i class="bi bi-tag"></i>
                </span>
                <div class="fw-bold text-dark">Kelola Kategori</div>
                <div class="text-muted small mt-1">Struktur & jenis produk</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/user.php" class="text-decoration-none">
            <div class="admin-card h-100 p-3 text-center">
                <span class="sidebar-icon icon-emerald mx-auto mb-2" style="width:3rem;height:3rem;font-size:1.4rem">
                    <i class="bi bi-people"></i>
                </span>
                <div class="fw-bold text-dark">Kelola Kasir</div>
                <div class="text-muted small mt-1">Akun kasir & hak akses</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-lg-3">
        <a href="<?= $baseUrl ?>/admin/laporan.php" class="text-decoration-none">
            <div class="admin-card h-100 p-3 text-center">
                <span class="sidebar-icon icon-sky mx-auto mb-2" style="width:3rem;height:3rem;font-size:1.4rem">
                    <i class="bi bi-bar-chart-line"></i>
                </span>
                <div class="fw-bold text-dark">Laporan Penjualan</div>
                <div class="text-muted small mt-1">Rekap transaksi & ekspor PDF</div>
            </div>
        </a>
    </div>
</div>

<?php
require __DIR__ . '/../../views/layouts/footer.php';
