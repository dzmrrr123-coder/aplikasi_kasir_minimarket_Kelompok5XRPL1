<?php
// views/layouts/sidebar-admin.php
// Navigasi samping untuk role admin (peta halaman spec bagian 2).
// Variabel $baseUrl dan $aktif tersedia dari header.php yang di-include sebelumnya.
// Ikon Bootstrap (bi-*) dan class 'active' mengikuti pola navbar.php.

$aktif = $aktif ?? '';
?>

<!-- Sidebar admin -->
<div class="list-group" id="sidebar-admin">
    <a href="<?= $baseUrl ?>/admin/dashboard.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'dashboard' ? 'active' : '' ?>">
        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
    </a>
    <a href="<?= $baseUrl ?>/admin/produk.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'produk' ? 'active' : '' ?>">
        <i class="bi bi-box-seam"></i><span>Produk</span>
    </a>
    <a href="<?= $baseUrl ?>/admin/kategori.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'kategori' ? 'active' : '' ?>">
        <i class="bi bi-tag"></i><span>Kategori</span>
    </a>
    <a href="<?= $baseUrl ?>/admin/laporan.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'laporan' ? 'active' : '' ?>">
        <i class="bi bi-bar-chart-line"></i><span>Laporan Penjualan</span>
    </a>
    <a href="<?= $baseUrl ?>/admin/user.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'user' ? 'active' : '' ?>">
        <i class="bi bi-people"></i><span>User / Kasir</span>
    </a>
    <hr class="m-2">
    <a href="<?= $baseUrl ?>/admin/supplier.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'supplier' ? 'active' : '' ?>">
        <i class="bi bi-truck"></i><span>Supplier</span>
    </a>
    <a href="<?= $baseUrl ?>/admin/retur.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'retur' ? 'active' : '' ?>">
        <i class="bi bi-arrow-counterclockwise"></i><span>Retur Barang</span>
    </a>
</div>
