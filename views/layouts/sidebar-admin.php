<?php
// views/layouts/sidebar-admin.php
// Sidebar premium navigasi admin dengan icon bubble berwarna, active state,
// section titles, hover animasi, dan mobile collapse.
// Variabel $baseUrl dan $aktif tersedia dari header.php.

$aktif   = $aktif ?? '';
$baseUrl = $baseUrl ?? '';

// Jumlah stok menipis untuk badge
$jumlahStokMenipis = 0;
try {
    $jumlahStokMenipis = count(\App\Models\Produk::cariStokMenipis());
} catch (\Throwable $e) {
    $jumlahStokMenipis = 0;
}
?>

<!-- ====== SIDEBAR ====== -->
<aside class="admin-sidebar" id="adminSidebar">

    <!-- Section: Utama -->
    <div class="sidebar-section">
        <div class="sidebar-section-label">Menu Utama</div>
    </div>
    <ul class="sidebar-nav">
        <li>
            <a href="<?= $baseUrl ?>/admin/dashboard.php"
               class="sidebar-nav-link <?= $aktif === 'dashboard' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-teal"><i class="bi bi-speedometer2"></i></span>
                <span class="sidebar-link-label">Dashboard</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/admin/produk.php"
               class="sidebar-nav-link <?= $aktif === 'produk' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-indigo"><i class="bi bi-box-seam"></i></span>
                <span class="sidebar-link-label">Produk</span>
                <?php if ($jumlahStokMenipis > 0 && $aktif !== 'produk'): ?>
                    <span class="badge rounded-pill bg-warning text-dark ms-auto" style="font-size:0.6rem"><?= $jumlahStokMenipis ?></span>
                <?php else: ?>
                    <i class="bi bi-chevron-right sidebar-chevron"></i>
                <?php endif; ?>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/admin/kategori.php"
               class="sidebar-nav-link <?= $aktif === 'kategori' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-amber"><i class="bi bi-tag"></i></span>
                <span class="sidebar-link-label">Kategori</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/admin/laporan.php"
               class="sidebar-nav-link <?= $aktif === 'laporan' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-sky"><i class="bi bi-bar-chart-line"></i></span>
                <span class="sidebar-link-label">Laporan Penjualan</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
    </ul>

    <div class="sidebar-divider"></div>

    <!-- Section: Manajemen -->
    <div class="sidebar-section">
        <div class="sidebar-section-label">Manajemen</div>
    </div>
    <ul class="sidebar-nav">
        <li>
            <a href="<?= $baseUrl ?>/admin/user.php"
               class="sidebar-nav-link <?= $aktif === 'user' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-emerald"><i class="bi bi-people"></i></span>
                <span class="sidebar-link-label">User / Kasir</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/admin/supplier.php"
               class="sidebar-nav-link <?= $aktif === 'supplier' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-sky"><i class="bi bi-truck"></i></span>
                <span class="sidebar-link-label">Supplier</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/admin/retur.php"
               class="sidebar-nav-link <?= $aktif === 'retur' ? 'active' : '' ?>">
                <span class="sidebar-icon icon-rose"><i class="bi bi-arrow-counterclockwise"></i></span>
                <span class="sidebar-link-label">Retur Barang</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
    </ul>

    <div class="sidebar-divider"></div>

    <!-- Section: Akses Cepat -->
    <div class="sidebar-section">
        <div class="sidebar-section-label">Akses Cepat</div>
    </div>
    <ul class="sidebar-nav">
        <li>
            <a href="<?= $baseUrl ?>/transaksi.php"
               class="sidebar-nav-link"
               style="color: var(--brand-primary); font-weight: 600;">
                <span class="sidebar-icon icon-teal"><i class="bi bi-cash-register"></i></span>
                <span class="sidebar-link-label">Buka Kasir POS</span>
                <i class="bi bi-arrow-up-right ms-auto" style="font-size:0.7rem; opacity:.5"></i>
            </a>
        </li>
        <li>
            <a href="<?= $baseUrl ?>/dashboard.php"
               class="sidebar-nav-link">
                <span class="sidebar-icon icon-purple"><i class="bi bi-house"></i></span>
                <span class="sidebar-link-label">Kembali ke Beranda</span>
                <i class="bi bi-chevron-right sidebar-chevron"></i>
            </a>
        </li>
    </ul>

    <!-- Footer sidebar -->
    <div class="sidebar-footer">
        <div class="sidebar-footer-text">
            <i class="bi bi-shield-lock me-1" style="color: var(--brand-primary)"></i>
            Panel Admin
        </div>
    </div>
</aside>

<!-- Konten halaman dimulai di sini -->
<main class="admin-content">
    <!-- Flash message (dirender dari header.php) -->
    <?= $flashHtml ?? '' ?>
