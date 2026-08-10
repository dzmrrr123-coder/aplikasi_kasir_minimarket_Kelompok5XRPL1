<?php
// views/layouts/sidebar-admin.php
// Navigasi samping untuk role admin (peta halaman spec bagian 2).
// Variabel $baseUrl tersedia dari header.php yang di-include sebelumnya.
// File halaman tujuan dibuat di langkah-langkah berikutnya.
?>
<ul class="nav flex-column nav-pills p-3 bg-light rounded">
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/dashboard.php">Dashboard</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/produk.php">Produk</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/kategori.php">Kategori</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/laporan.php">Laporan Penjualan</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/user.php">User / Kasir</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/supplier.php">Supplier</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/admin/retur.php">Retur Barang</a>
    </li>
</ul>
