<?php
// views/layouts/sidebar-kasir.php
// Navigasi samping untuk role kasir (peta halaman spec bagian 2).
// Variabel $baseUrl dan $aktif tersedia dari header.php yang di-include sebelumnya.
// Ikon Bootstrap (bi-*) dan class 'active' mengikuti pola navbar-kasir.php.

$aktif = $aktif ?? '';
?>

<!-- Sidebar kasir -->
<div class="list-group" id="sidebar-kasir">
    <a href="<?= $baseUrl ?>/kasir/transaksi.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'transaksi' ? 'active' : '' ?>">
        <i class="bi bi-cash-register"></i><span>Transaksi (POS)</span>
    </a>
    <a href="<?= $baseUrl ?>/kasir/riwayat.php"
       class="list-group-item list-group-item-action d-flex align-items-center gap-2 <?= $aktif === 'riwayat' ? 'active' : '' ?>">
        <i class="bi bi-clock-history"></i><span>Riwayat Transaksi</span>
    </a>
</div>
