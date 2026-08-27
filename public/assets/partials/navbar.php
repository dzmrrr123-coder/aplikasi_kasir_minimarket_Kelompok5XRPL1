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

$nama  = $nama ?? 'Admin';
$aktif = $aktif ?? '';

// Breadcrumb opsional: array asosiatif ['Label' => 'url']. URL kosong = halaman
// ini (tidak bisa diklik). Contoh: ['Dashboard' => 'dashboard.php', 'Produk' => 'admin.php']
$breadcrumb = $breadcrumb ?? [];

// Inisial nama untuk avatar
$namaArr  = explode(' ', trim($nama));
$inisial  = mb_strtoupper(mb_substr($namaArr[0], 0, 1));
if (isset($namaArr[1])) {
    $inisial .= mb_strtoupper(mb_substr($namaArr[1], 0, 1));
}

// Jumlah produk stok menipis (untuk badge notifikasi di navbar).
$jumlahStokMenipis = 0;

try {
    $jumlahStokMenipis = count(\App\Models\Produk::cariStokMenipis());
} catch (\Throwable $e) {
    $jumlahStokMenipis = 0;
}

// Jam server untuk greeting
$jam = (int) date('H');
$greeting = match(true) {
    $jam >= 4  && $jam < 11 => 'Selamat pagi',
    $jam >= 11 && $jam < 15 => 'Selamat siang',
    $jam >= 15 && $jam < 18 => 'Selamat sore',
    default                  => 'Selamat malam',
};
?>

<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<style>
/* Jaminan styling Navbar Kasir Minimarket */
.pos-navbar .navbar-avatar-btn,
button.navbar-avatar-btn {
    background: rgba(255, 255, 255, 0.12) !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.5rem !important;
    padding: 0.25rem 0.65rem 0.25rem 0.35rem !important;
    border-radius: 2rem !important;
    transition: all 0.15s ease !important;
    color: #ffffff !important;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1) !important;
}
.pos-navbar .navbar-avatar-btn:hover,
button.navbar-avatar-btn:hover {
    background: rgba(255, 255, 255, 0.22) !important;
    border-color: rgba(255, 255, 255, 0.4) !important;
    transform: translateY(-1px) !important;
}
.navbar-avatar {
    width: 2rem !important;
    height: 2rem !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #a7f3d0 0%, #2dd4bf 100%) !important;
    color: #0f766e !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 0.75rem !important;
    font-weight: 800 !important;
    border: 2px solid rgba(255, 255, 255, 0.3) !important;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15) !important;
    flex-shrink: 0 !important;
}
.navbar-user-dropdown {
    min-width: 250px !important;
    padding: 0.6rem !important;
    border-radius: 0.85rem !important;
    border: 1px solid var(--border, #e2e8f0) !important;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.15) !important;
    background: var(--surface, #ffffff) !important;
}
.navbar-user-header {
    display: flex !important;
    align-items: center !important;
    gap: 0.75rem !important;
    padding: 0.85rem !important;
    background: linear-gradient(135deg, rgba(13, 148, 136, 0.1) 0%, rgba(20, 184, 166, 0.05) 100%) !important;
    border: 1px solid rgba(13, 148, 136, 0.18) !important;
    border-radius: 0.65rem !important;
    margin-bottom: 0.35rem !important;
}
.dark-mode .navbar-user-header {
    background: rgba(13, 148, 136, 0.18) !important;
    border-color: rgba(45, 212, 191, 0.25) !important;
}
.navbar-user-avatar-lg {
    width: 2.75rem !important;
    height: 2.75rem !important;
    border-radius: 50% !important;
    background: linear-gradient(135deg, #a7f3d0 0%, #2dd4bf 100%) !important;
    color: #0f766e !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    font-size: 1rem !important;
    font-weight: 800 !important;
    box-shadow: 0 3px 8px rgba(13, 148, 136, 0.25) !important;
    flex-shrink: 0 !important;
}
.navbar-user-info {
    display: flex !important;
    flex-direction: column !important;
    min-width: 0 !important;
    flex: 1 !important;
    text-align: left !important;
}
.navbar-user-name {
    font-size: 0.95rem !important;
    font-weight: 700 !important;
    color: var(--text, #1e293b) !important;
    line-height: 1.2 !important;
    margin: 0 !important;
}
.navbar-user-greeting {
    font-size: 0.75rem !important;
    color: var(--text-muted, #64748b) !important;
    margin-top: 0.15rem !important;
}
.navbar-user-role-badge {
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.25rem !important;
    font-size: 0.68rem !important;
    font-weight: 700 !important;
    background: #ccfbf1 !important;
    color: #0f766e !important;
    padding: 0.15rem 0.55rem !important;
    border-radius: 1rem !important;
    margin-top: 0.35rem !important;
    width: fit-content !important;
}
.dark-mode .navbar-user-role-badge {
    background: rgba(13, 148, 136, 0.3) !important;
    color: #2dd4bf !important;
}
.pos-navbar .navbar-stok-btn,
a.navbar-stok-btn {
    background: rgba(245, 158, 11, 0.2) !important;
    border: 1px solid rgba(245, 158, 11, 0.5) !important;
    color: #fde047 !important;
    font-size: 0.8rem !important;
    padding: 0.3rem 0.75rem !important;
    border-radius: 0.5rem !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 0.4rem !important;
    text-decoration: none !important;
    font-weight: 700 !important;
    transition: all 0.15s ease !important;
}
.pos-navbar .navbar-stok-btn:hover,
a.navbar-stok-btn:hover {
    background: rgba(245, 158, 11, 0.32) !important;
    border-color: rgba(245, 158, 11, 0.7) !important;
    color: #ffffff !important;
    transform: translateY(-1px) !important;
}
.pos-navbar .navbar-stok-btn i {
    color: #fde047 !important;
}

/* Navbar Breadcrumb */
.navbar-breadcrumb {
    color: rgba(255, 255, 255, 0.75) !important;
    font-size: 0.82rem !important;
    margin-left: 0.25rem !important;
}
.navbar-breadcrumb-divider {
    color: rgba(255, 255, 255, 0.4) !important;
    font-size: 0.75rem !important;
    margin: 0 0.15rem !important;
}
.navbar-breadcrumb-link {
    color: rgba(255, 255, 255, 0.8) !important;
    text-decoration: none !important;
    font-weight: 500 !important;
    transition: color 0.15s ease !important;
}
.navbar-breadcrumb-link:hover {
    color: #ffffff !important;
    text-decoration: underline !important;
}
.navbar-breadcrumb-active {
    color: #ffffff !important;
    font-weight: 700 !important;
    background: rgba(255, 255, 255, 0.18) !important;
    padding: 0.15rem 0.55rem !important;
    border-radius: 1rem !important;
    border: 1px solid rgba(255, 255, 255, 0.25) !important;
}
</style>

<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top" id="mainNavbar">
    <div class="container-fluid px-3 px-xl-4">

        <!-- Brand -->
        <a class="navbar-brand flex-shrink-0" href="dashboard.php">
            <i class="bi bi-shop-window"></i>
            <span>Kasir Minimarket</span>
        </a>

        <!-- Breadcrumb (desktop; menunjukkan lokasi halaman) -->
        <?php if (!empty($breadcrumb)): ?>
            <nav class="d-none d-lg-flex align-items-center gap-1 small mt-lg-0 px-2 navbar-breadcrumb" aria-label="breadcrumb">
                <span class="navbar-breadcrumb-divider">/</span>
                <?php $totalBc = count($breadcrumb); $idxBc = 0; ?>
                <?php foreach ($breadcrumb as $label => $url): ?>
                    <?php $idxBc++; $trimmed = trim((string) $url); ?>
                    <?php if ($trimmed === '' || $idxBc === $totalBc): ?>
                        <span class="navbar-breadcrumb-active">
                            <?= htmlspecialchars((string) $label) ?>
                        </span>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars($trimmed) ?>"
                           class="navbar-breadcrumb-link"><?= htmlspecialchars((string) $label) ?></a>
                        <span class="navbar-breadcrumb-divider">/</span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>

        <!-- Mobile: stok badge + toggler -->
        <div class="d-flex align-items-center gap-2 d-lg-none ms-auto">
            <?php if ($jumlahStokMenipis > 0): ?>
                <a href="admin.php" class="btn btn-sm position-relative navbar-stok-badge" title="<?= $jumlahStokMenipis ?> produk stok menipis">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span class="badge-stok-pill"><?= $jumlahStokMenipis ?></span>
                </a>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-admin"
                    aria-controls="nav-admin" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
        </div>

        <div class="collapse navbar-collapse" id="nav-admin">
            <!-- Menu utama kiri -->
            <ul class="navbar-nav me-auto align-items-lg-center flex-lg-nowrap gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
                        <i class="bi bi-speedometer2"></i><span>Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link nav-link-kasir <?= $aktif === 'transaksi' ? 'active' : '' ?>" href="transaksi.php" title="Buka Kasir (Alt+K)">
                        <i class="bi bi-cash-register"></i><span>Kasir</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'admin' ? 'active' : '' ?>" href="admin.php">
                        <i class="bi bi-box-seam"></i><span>Produk</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'laporan' ? 'active' : '' ?>" href="laporan.php">
                        <i class="bi bi-bar-chart-line"></i><span>Laporan</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'laba' ? 'active' : '' ?>" href="laba.php">
                        <i class="bi bi-piggy-bank"></i><span>Laba</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'pembelian' ? 'active' : '' ?>" href="pembelian.php">
                        <i class="bi bi-box-arrow-in-down"></i><span>Stok Masuk</span>
                    </a>
                </li>

                <!-- Dropdown Lainnya -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?= in_array($aktif, ['supplier', 'retur', 'diskon', 'member', 'pengaturan', 'user', 'shift', 'audit'], true) ? 'active' : '' ?>"
                       href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-grid-3x3-gap"></i><span>Lainnya</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end navbar-dropdown-rich">
                        <li class="dropdown-section-title">Operasional</li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'supplier' ? 'active' : '' ?>" href="supplier.php">
                                <span class="dropdown-icon-wrap bg-sky"><i class="bi bi-truck"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Supplier</span>
                                    <span class="dropdown-item-sub">Kelola data pemasok</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'retur' ? 'active' : '' ?>" href="retur.php">
                                <span class="dropdown-icon-wrap bg-amber"><i class="bi bi-arrow-counterclockwise"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Retur</span>
                                    <span class="dropdown-item-sub">Pengembalian barang</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'diskon' ? 'active' : '' ?>" href="diskon.php">
                                <span class="dropdown-icon-wrap bg-rose"><i class="bi bi-tags"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Diskon</span>
                                    <span class="dropdown-item-sub">Promo & potongan harga</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'member' ? 'active' : '' ?>" href="member.php">
                                <span class="dropdown-icon-wrap bg-indigo"><i class="bi bi-person-badge"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Member</span>
                                    <span class="dropdown-item-sub">Program loyalitas pelanggan</span>
                                </span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider my-1"></li>
                        <li class="dropdown-section-title">Manajemen</li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'shift' ? 'active' : '' ?>" href="shift.php">
                                <span class="dropdown-icon-wrap bg-teal"><i class="bi bi-clock-history"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Shift Kasir</span>
                                    <span class="dropdown-item-sub">Jadwal & rekap shift</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'audit' ? 'active' : '' ?>" href="audit.php">
                                <span class="dropdown-icon-wrap bg-purple"><i class="bi bi-journal-text"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Audit Log</span>
                                    <span class="dropdown-item-sub">Riwayat aktivitas sistem</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'pengaturan' ? 'active' : '' ?>" href="pengaturan.php">
                                <span class="dropdown-icon-wrap bg-slate"><i class="bi bi-gear"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Pengaturan</span>
                                    <span class="dropdown-item-sub">Konfigurasi toko</span>
                                </span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?= $aktif === 'user' ? 'active' : '' ?>" href="user.php">
                                <span class="dropdown-icon-wrap bg-emerald"><i class="bi bi-people"></i></span>
                                <span class="dropdown-item-text">
                                    <span class="dropdown-item-label">Kelola Kasir</span>
                                    <span class="dropdown-item-sub">Akun & hak akses</span>
                                </span>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>

            <!-- Kanan: stok badge + theme toggle + user dropdown -->
            <div class="d-flex align-items-center gap-2 flex-shrink-0 ms-lg-3">

                <!-- Stok menipis badge (desktop) -->
                <?php if ($jumlahStokMenipis > 0): ?>
                    <a href="admin.php" class="d-none d-lg-flex btn btn-sm navbar-stok-btn position-relative" title="<?= $jumlahStokMenipis ?> produk stok menipis">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <span class="d-none d-xl-inline"><?= $jumlahStokMenipis ?> Stok Menipis</span>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem">
                            <?= $jumlahStokMenipis ?>
                        </span>
                    </a>
                <?php endif; ?>

                <!-- Dark mode toggle -->
                <button type="button" class="theme-toggle" id="toggle-theme" title="Ganti mode terang/gelap">
                    <i class="bi bi-circle-half"></i>
                </button>

                <!-- User avatar + dropdown -->
                <div class="dropdown">
                    <button type="button" class="navbar-avatar-btn" data-bs-toggle="dropdown" data-bs-offset="0,8"
                            aria-expanded="false" title="Akun: <?= htmlspecialchars($nama) ?>">
                        <span class="navbar-avatar" aria-hidden="true"><?= htmlspecialchars($inisial) ?></span>
                        <span class="d-none d-xl-inline text-white fw-semibold small ms-1"><?= htmlspecialchars($namaArr[0]) ?></span>
                        <i class="bi bi-chevron-down text-white-50 ms-1" style="font-size:0.7rem"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end navbar-user-dropdown">
                        <!-- Header dropdown -->
                        <li class="navbar-user-header">
                            <div class="navbar-user-avatar-lg"><?= htmlspecialchars($inisial) ?></div>
                            <div class="navbar-user-info">
                                <div class="navbar-user-name"><?= htmlspecialchars($nama) ?></div>
                                <div class="navbar-user-greeting"><?= $greeting ?> 👋</div>
                                <span class="navbar-user-role-badge">
                                    <i class="bi bi-shield-check"></i> Admin
                                </span>
                            </div>
                        </li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2" href="profile.php">
                                <span class="dropdown-icon-wrap bg-teal" style="width:1.75rem;height:1.75rem;font-size:0.85rem">
                                    <i class="bi bi-person-circle"></i>
                                </span>
                                <span>Profil Saya</span>
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center gap-2" href="pengaturan.php">
                                <span class="dropdown-icon-wrap bg-slate" style="width:1.75rem;height:1.75rem;font-size:0.85rem">
                                    <i class="bi bi-gear"></i>
                                </span>
                                <span>Pengaturan Toko</span>
                            </a>
                        </li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li>
                            <form method="post" action="logout.php" class="d-block">
                                <button type="submit" class="dropdown-item d-flex align-items-center gap-2 text-danger">
                                    <span class="dropdown-icon-wrap bg-rose" style="width:1.75rem;height:1.75rem;font-size:0.85rem">
                                        <i class="bi bi-box-arrow-right"></i>
                                    </span>
                                    <span>Logout</span>
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
