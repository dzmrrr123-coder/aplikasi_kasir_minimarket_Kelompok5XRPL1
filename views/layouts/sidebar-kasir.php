<?php
// views/layouts/sidebar-kasir.php
// Navigasi samping untuk role kasir (peta halaman spec bagian 2).
// Variabel $baseUrl tersedia dari header.php yang di-include sebelumnya.
// File halaman tujuan dibuat di langkah-langkah berikutnya.
?>
<ul class="nav flex-column nav-pills p-3 bg-light rounded">
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/kasir/transaksi.php">Transaksi (POS)</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="<?= $baseUrl ?>/kasir/riwayat.php">Riwayat Transaksi</a>
    </li>
</ul>
