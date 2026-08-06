<?php

declare(strict_types=1);

/**
 * Partial: navbar admin bersama (dipakai semua halaman admin).
 * Menghilangkan duplikasi navbar yang dulu di-copy-paste di tiap file.
 *
 * Menu utama tampil langsung; menu sekunder digabung ke dropdown "Lainnya"
 * supaya navbar tetap satu baris rapi di semua ukuran layar.
 *
 * Variabel yang diharapkan dari halaman pemanggil:
 *   $nama    (string) nama pengguna yang login
 *   $aktif   (string) kunci menu aktif: dashboard|admin|transaksi|laporan|pembelian|
 *                    supplier|retur|diskon|member|pengaturan|user
 */

$nama = $nama ?? 'Admin';
$aktif = $aktif ?? '';

// Jumlah produk stok menipis (untuk badge notifikasi di navbar).
$jumlahStokMenipis = 0;

try {
    $jumlahStokMenipis = count(\App\Models\Produk::cariStokMenipis());
} catch (\Throwable $e) {
    $jumlahStokMenipis = 0;
}
?>

<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container-fluid px-3 px-xl-4">
        <a class="navbar-brand flex-shrink-0" href="dashboard.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-admin"
                aria-controls="nav-admin" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-admin">
            <ul class="navbar-nav me-auto align-items-lg-center flex-lg-nowrap">
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i><span>Dashboard</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'transaksi' ? 'active' : '' ?>" href="transaksi.php"><i class="bi bi-cash-register"></i><span>Kasir</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'admin' ? 'active' : '' ?>" href="admin.php"><i class="bi bi-box-seam"></i><span>Produk</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'laporan' ? 'active' : '' ?>" href="laporan.php"><i class="bi bi-bar-chart-line"></i><span>Laporan</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'laba' ? 'active' : '' ?>" href="laba.php"><i class="bi bi-piggy-bank"></i><span>Laba</span></a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'pembelian' ? 'active' : '' ?>" href="pembelian.php"><i class="bi bi-box-arrow-in-down"></i><span>Stok Masuk</span></a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($aktif, ['supplier', 'retur', 'diskon', 'member', 'pengaturan', 'user', 'shift', 'audit'], true) ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-grid-1x2"></i><span>Lainnya</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item <?= $aktif === 'supplier' ? 'active' : '' ?>" href="supplier.php"><i class="bi bi-truck me-2"></i>Supplier</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'retur' ? 'active' : '' ?>" href="retur.php"><i class="bi bi-arrow-counterclockwise me-2"></i>Retur</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'diskon' ? 'active' : '' ?>" href="diskon.php"><i class="bi bi-tags me-2"></i>Diskon</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'member' ? 'active' : '' ?>" href="member.php"><i class="bi bi-person-badge me-2"></i>Member</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'shift' ? 'active' : '' ?>" href="shift.php"><i class="bi bi-clock-history me-2"></i>Shift Kasir</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'audit' ? 'active' : '' ?>" href="audit.php"><i class="bi bi-journal-text me-2"></i>Audit Log</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'pengaturan' ? 'active' : '' ?>" href="pengaturan.php"><i class="bi bi-gear me-2"></i>Pengaturan</a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'user' ? 'active' : '' ?>" href="user.php"><i class="bi bi-people me-2"></i>Kelola Kasir</a>
                        </li>
                    </ul>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <?php if ($jumlahStokMenipis > 0): ?>
                    <a href="admin.php" class="btn btn-sm btn-outline-warning position-relative" title="Produk stok menipis">
                        <i class="bi bi-exclamation-triangle"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger">
                            <?= $jumlahStokMenipis ?>
                        </span>
                    </a>
                <?php endif; ?>
                <button type="button" class="theme-toggle" id="toggle-theme" title="Ganti mode terang/gelap">
                    <i class="bi bi-circle-half"></i>
                </button>
                <span class="navbar-text text-white small me-2 d-none d-xl-inline">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?>
                </span>
                <form method="post" class="d-inline">
                    <input type="hidden" name="aksi" value="logout">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-box-arrow-right me-1"></i>Logout
                    </button>
                </form>
            </div>
        </div>
    </div>
</nav>
