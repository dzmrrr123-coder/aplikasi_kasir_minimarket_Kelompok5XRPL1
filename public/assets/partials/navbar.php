<?php

declare(strict_types=1);

/**
 * Partial: sidebar admin bersama (dipakai semua halaman admin).
 * Menggantikan navbar horizontal lama dengan sidebar kiri 250px.
 *
 * Semua menu tampil vertikal dan dikelompokkan (Utama / Lainnya) supaya
 * tetap mudah dipindai tanpa dropdown. Di layar kecil sidebar menjadi
 * off-canvas: disembunyikan dan dibuka lewat tombol hamburger.
 *
 * Variabel yang diharapkan dari halaman pemanggil:
 *   $nama    (string) nama pengguna yang login
 *   $aktif   (string) kunci menu aktif: dashboard|admin|transaksi|laporan|pembelian|
 *                    supplier|retur|diskon|member|pengaturan|user
 */

$nama = $nama ?? 'Admin';
$aktif = $aktif ?? '';

// Jumlah produk stok menipis (untuk badge notifikasi di sidebar).
$jumlahStokMenipis = 0;

try {
    $jumlahStokMenipis = count(\App\Models\Produk::cariStokMenipis());
} catch (\Throwable $e) {
    $jumlahStokMenipis = 0;
}

/**
 * Helper kecil untuk menandai item menu aktif.
 */
$navClass = static fn (bool $on): string => 'pos-sidebar-link' . ($on ? ' active' : '');
?>

<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<!-- Tombol hamburger (hanya tampil di layar kecil) -->
<button type="button" class="pos-sidebar-toggle" id="pos-sidebar-toggle" aria-label="Buka menu" aria-controls="pos-sidebar" aria-expanded="false">
    <i class="bi bi-list"></i>
</button>

<!-- Backdrop gelap saat sidebar terbuka di layar kecil -->
<div class="pos-sidebar-backdrop" id="pos-sidebar-backdrop" hidden></div>

<aside class="pos-sidebar" id="pos-sidebar">
    <div class="pos-sidebar-brand">
        <a href="dashboard.php"><i class="bi bi-shop"></i><span>Kasir Minimarket</span></a>
        <button type="button" class="pos-sidebar-close" id="pos-sidebar-close" aria-label="Tutup menu">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    <nav class="pos-sidebar-nav" aria-label="Navigasi utama">
        <p class="pos-sidebar-section">Utama</p>
        <a class="<?= $navClass($aktif === 'dashboard') ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i><span>Dashboard</span>
        </a>
        <a class="<?= $navClass($aktif === 'transaksi') ?>" href="transaksi.php">
            <i class="bi bi-cash-register"></i><span>Kasir</span>
        </a>
        <a class="<?= $navClass($aktif === 'admin') ?>" href="admin.php">
            <i class="bi bi-box-seam"></i><span>Produk</span>
            <?php if ($jumlahStokMenipis > 0): ?>
                <span class="pos-sidebar-badge" title="Produk stok menipis"><?= $jumlahStokMenipis ?></span>
            <?php endif; ?>
        </a>
        <a class="<?= $navClass($aktif === 'laporan') ?>" href="laporan.php">
            <i class="bi bi-bar-chart-line"></i><span>Laporan</span>
        </a>
        <a class="<?= $navClass($aktif === 'laba') ?>" href="laba.php">
            <i class="bi bi-piggy-bank"></i><span>Laba</span>
        </a>
        <a class="<?= $navClass($aktif === 'pembelian') ?>" href="pembelian.php">
            <i class="bi bi-box-arrow-in-down"></i><span>Stok Masuk</span>
        </a>

        <p class="pos-sidebar-section">Lainnya</p>
        <a class="<?= $navClass($aktif === 'supplier') ?>" href="supplier.php">
            <i class="bi bi-truck"></i><span>Supplier</span>
        </a>
        <a class="<?= $navClass($aktif === 'retur') ?>" href="retur.php">
            <i class="bi bi-arrow-counterclockwise"></i><span>Retur</span>
        </a>
        <a class="<?= $navClass($aktif === 'diskon') ?>" href="diskon.php">
            <i class="bi bi-tags"></i><span>Diskon</span>
        </a>
        <a class="<?= $navClass($aktif === 'member') ?>" href="member.php">
            <i class="bi bi-person-badge"></i><span>Member</span>
        </a>
        <a class="<?= $navClass($aktif === 'shift') ?>" href="shift.php">
            <i class="bi bi-clock-history"></i><span>Shift Kasir</span>
        </a>
        <a class="<?= $navClass($aktif === 'audit') ?>" href="audit.php">
            <i class="bi bi-journal-text"></i><span>Audit Log</span>
        </a>
        <a class="<?= $navClass($aktif === 'pengaturan') ?>" href="pengaturan.php">
            <i class="bi bi-gear"></i><span>Pengaturan</span>
        </a>
        <a class="<?= $navClass($aktif === 'user') ?>" href="user.php">
            <i class="bi bi-people"></i><span>Kelola Kasir</span>
        </a>
    </nav>

    <div class="pos-sidebar-footer">
        <div class="pos-sidebar-user">
            <i class="bi bi-person-circle"></i>
            <span class="pos-sidebar-user-name"><?= htmlspecialchars($nama) ?></span>
            <button type="button" class="theme-toggle" id="toggle-theme" title="Ganti mode terang/gelap">
                <i class="bi bi-circle-half"></i>
            </button>
        </div>
        <form method="post">
            <input type="hidden" name="aksi" value="logout">
            <button type="submit" class="pos-sidebar-logout">
                <i class="bi bi-box-arrow-right"></i><span>Logout</span>
            </button>
        </form>
    </div>
</aside>
