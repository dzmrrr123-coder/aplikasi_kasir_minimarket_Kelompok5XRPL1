<?php
/**
 * Partial: navbar khusus kasir (minimalis).
 * Hanya tampilkan menu yang dibutuhkan kasir:
 *   - Brand
 *   - Kasir (POS)  -> transaksi.php
 *   - Dropdown akun -> Profil Saya / Logout
 *
 * Variabel yang diharapkan dari halaman pemanggil:
 *   $nama  (string) nama kasir yang login
 *   $aktif (string) kunci menu aktif: kasir|profile
 */
$nama  = $nama ?? 'Kasir';
$aktif = $aktif ?? '';
?>

<meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">

<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container-fluid px-3 px-xl-4">
        <a class="navbar-brand flex-shrink-0" href="transaksi.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <div class="collapse navbar-collapse" id="nav-kasir">
            <ul class="navbar-nav me-auto align-items-lg-center flex-lg-nowrap">
                <li class="nav-item">
                    <a class="nav-link <?= $aktif === 'kasir' ? 'active' : '' ?>" href="transaksi.php">
                        <i class="bi bi-cash-register"></i><span>Kasir (POS)</span>
                    </a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <span class="navbar-text text-white small me-2">
                    <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($nama) ?>
                </span>
                <div class="dropdown">
                    <a href="#" class="d-flex align-items-center text-white text-decoration-none"
                       data-bs-toggle="dropdown" aria-expanded="false" title="Akun">
                        <i class="bi bi-chevron-down"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <a class="dropdown-item active <?= $aktif === 'profile' ? 'active' : '' ?>" href="profile.php">
                                <i class="bi bi-gear me-2"></i>Profil Saya
                            </a>
                        </li>
                        <li><hr class="dropdown-divider m-0"></li>
                        <li>
                            <form method="post" action="logout.php" class="d-inline">
                                <button type="submit" class="dropdown-item text-danger">
                                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                                </button>
                            </form>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
