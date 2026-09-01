<?php

declare(strict_types=1);

/**
 * Halaman Autentikasi: Login & Registrasi Toko Baru.
 * Mendukung Resume Sesi Aktif, Ganti Akun, Logout, dan Registrasi Toko Baru.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\User;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function redirectSesuaiRole(string $role): never
{
    if ($role === 'admin') {
        header('Location: dashboard.php');
    } else {
        header('Location: transaksi.php');
    }
    exit;
}

// Handle Logout (POST only untuk keamanan CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    logoutKaryawan();
    header('Location: login.php');
    exit;
}
// Backward-compat: GET logout dengan konfirmasi JS
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logoutKaryawan();
    header('Location: login.php');
    exit;
}

// Cek apakah ada sesi karyawan yang aktif
$userSesiAktif = null;
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $foundUser = User::cariBerdasarkanId((int) $_SESSION['user_id']);
    if ($foundUser !== null && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'kasir')) {
        $userSesiAktif = $foundUser;
    } else {
        logoutKaryawan();
    }
}

$action = (string) ($_POST['action'] ?? ($_GET['tab'] ?? ($_GET['action'] ?? 'login')));
if ($action !== 'register') {
    $action = 'login';
}

$explicitSwitch = isset($_GET['switch']) || (isset($_GET['tab']) && $_GET['tab'] === 'register') || $_SERVER['REQUEST_METHOD'] === 'POST';
$showResumeCard = ($userSesiAktif !== null && !$explicitSwitch);

$error = '';
$success = '';
$username = '';
$nama = '';

$fileGagal = sys_get_temp_dir() . '/kasir-login-' . md5($_SERVER['REMOTE_ADDR'] ?? 'local') . '.txt';
$percobaan = 0;
$terkunciSampai = 0;

if (is_file($fileGagal)) {
    $data = @unserialize((string) file_get_contents($fileGagal));
    if (is_array($data)) {
        $percobaan = (int) ($data['percobaan'] ?? 0);
        $terkunciSampai = (int) ($data['terkunci_sampai'] ?? 0);

        if ($terkunciSampai > 0 && time() > $terkunciSampai) {
            $percobaan = 0;
            $terkunciSampai = 0;
        } elseif ($terkunciSampai === 0 && time() - (int) ($data['waktu'] ?? 0) > 300) {
            $percobaan = 0;
        }
    }
}

$terkunci = $terkunciSampai > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? 'login');

    if ($action === 'register') {
        $nama     = trim((string) ($_POST['nama'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        // Registrasi hanya untuk admin/pemilik toko.
        // Akun kasir dibuat dari panel admin (user.php).
        $role = 'admin';

        \App\Database\Database::runSchema();

        try {
            $user = User::register([
                'nama'     => $nama,
                'username' => $username,
                'password' => $password,
                'role'     => $role,
            ]);

            @unlink($fileGagal);
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user->getId();
            $_SESSION['nama']    = $user->getNama();
            $_SESSION['role']    = $user instanceof \App\Models\Admin ? 'admin' : 'kasir';

            $dataSesi = $user->muatDataSesi();
            if (is_array($dataSesi)) {
                foreach ($dataSesi as $k => $v) {
                    $_SESSION[$k] = $v;
                }
            }

            redirectSesuaiRole($_SESSION['role']);
        } catch (\RuntimeException $e) {
            $error = $e->getMessage();
        }
    } else {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($terkunci) {
            $sisa = (int) ceil(($terkunciSampai - time()) / 60);
            $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam ' . max(1, $sisa) . ' menit.';
        } else {
            \App\Database\Database::runSchema();
            $user = User::loginPolimorfik($username, $password);

            if ($user !== null) {
                @unlink($fileGagal);
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user->getId();
                $_SESSION['nama']    = $user->getNama();
                $_SESSION['role']    = $user instanceof \App\Models\Admin ? 'admin' : 'kasir';

                $dataSesi = $user->muatDataSesi();
                if (is_array($dataSesi)) {
                    foreach ($dataSesi as $k => $v) {
                        $_SESSION[$k] = $v;
                    }
                }

                redirectSesuaiRole($_SESSION['role']);
            }

            $percobaan++;
            $terkunciSampai = $percobaan >= 5 ? time() + 300 : 0;

            @file_put_contents($fileGagal, serialize([
                'percobaan'       => $percobaan,
                'terkunci_sampai' => $terkunciSampai,
                'waktu'           => time(),
            ]));

            $error = 'Username atau password salah.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= $action === 'register' ? 'Daftar Akun Toko' : 'Masuk POS' ?> — DZMS Kasir</title>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token()) ?>">
    <link rel="icon" type="image/svg+xml" href="assets/video/poster.svg">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;500;600;700;800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/auth.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body>
    
    <div class="auth-split">
        <!-- LEFT PANEL -->
        <div class="auth-left">
            <!-- VIDEO BACKGROUND -->
            <div class="auth-video-wrap" id="authVideoWrap">
                <video class="auth-video" autoplay muted loop playsinline preload="auto" poster="assets/video/poster.svg" id="authVideo">
                    <source src="assets/video/hero-bg.mp4" type="video/mp4">
                </video>
                <div class="auth-video-overlay"></div>
            </div>
            
            <a href="landing.php" class="auth-brand">
                <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                <span>DZMS</span>
            </a>
            
            <div class="auth-left-content">
                <h1>Kasir Cepat,<br>Toko Melesat.</h1>
                <p>Satu platform POS elegan untuk mencatat penjualan harian, mengelola stok, dan memantau keuntungan secara real-time dari mana saja.</p>
            </div>
            
            <div class="auth-left-footer">
                &copy; <?= date('Y') ?> DZMS POS. Hak Cipta Dilindungi.
            </div>
        </div>

        <!-- RIGHT PANEL -->
        <main class="auth-right">
            <div class="auth-right-header">
                <a href="landing.php" class="mobile-brand">
                    <span class="brand-mark" aria-hidden="true"><span></span><span></span><span></span></span>
                    <span>DZMS</span>
                </a>
                
                <a href="landing.php" class="btn-auth-back" aria-label="Kembali ke Beranda">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> <span class="d-none d-sm-inline">Ke Beranda</span>
                </a>
            </div>

            <div class="auth-container">
                <?php if ($userSesiAktif !== null): ?>
                    <!-- CARD A: RESUME SESI AKTIF -->
                    <div class="text-center" id="cardSessionResume" style="<?= $showResumeCard ? '' : 'display: none;' ?>">
                        <div class="session-avatar">
                            <?= htmlspecialchars(strtoupper(mb_substr($userSesiAktif->getNama(), 0, 1))) ?>
                        </div>
                        <h2 class="auth-header-text h2 mb-1"><?= htmlspecialchars($userSesiAktif->getNama()) ?></h2>
                        <p class="text-muted mb-2">@<?= htmlspecialchars($userSesiAktif->getUsername()) ?></p>
                        
                        <div class="mb-4">
                            <?php if ($userSesiAktif instanceof \App\Models\Admin): ?>
                                <span class="session-role-pill"><i class="bi bi-shield-lock-fill"></i> Owner / Admin</span>
                            <?php else: ?>
                                <span class="session-role-pill"><i class="bi bi-person-badge-fill"></i> Kasir Toko</span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex flex-column gap-3 mt-4">
                            <?php if ($userSesiAktif instanceof \App\Models\Admin): ?>
                                <a href="dashboard.php" class="btn-auth-submit text-decoration-none">
                                    <i class="bi bi-speedometer2"></i> Masuk Dashboard Admin
                                </a>
                                <a href="transaksi.php" class="btn btn-outline-secondary w-100 py-3 fw-bold rounded-3">
                                    <i class="bi bi-cart3"></i> Buka Layar Kasir
                                </a>
                            <?php else: ?>
                                <a href="transaksi.php" class="btn-auth-submit text-decoration-none">
                                    <i class="bi bi-cart3"></i> Masuk Layar Kasir
                                </a>
                            <?php endif; ?>

                            <button type="button" class="btn btn-link text-decoration-none text-muted fw-semibold mt-2" onclick="showAuthForms()">
                                <i class="bi bi-arrow-left-right me-1"></i> Masuk dengan Akun Lain
                            </button>
                        </div>
                        
                        <div class="mt-5 text-center border-top pt-4">
                            <a href="login.php?action=logout" class="text-decoration-none text-danger fw-bold">
                                <i class="bi bi-box-arrow-right me-1"></i>Keluar (Logout)
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- CARD B: FORM LOGIN / REGISTER -->
                <div id="cardAuthForms" style="<?= ($userSesiAktif !== null && $showResumeCard) ? 'display: none;' : '' ?>">
                    
                    <div class="auth-header-text">
                        <h1>Selamat Datang</h1>
                        <p id="authSubtitle">Sudah punya akun? Masuk di bawah ini.</p>
                    </div>

                    <!-- 2-Tab Switcher -->
                    <div class="auth-nav-tabs">
                        <button type="button" class="auth-tab-btn <?= $action === 'login' ? 'active' : '' ?>" id="tabBtnLogin" onclick="switchAuthTab('login')">
                            Masuk
                        </button>
                        <button type="button" class="auth-tab-btn <?= $action === 'register' ? 'active' : '' ?>" id="tabBtnRegister" onclick="switchAuthTab('register')">
                            Daftar Baru
                        </button>
                    </div>

                    <?php if ($error !== ''): ?>
                        <div class="alert alert-danger py-3 small mb-4 shadow-sm" role="alert" aria-live="assertive">
                            <i class="bi bi-exclamation-triangle-fill me-2 fs-6"></i> <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <!-- FORM 1: LOGIN -->
                    <form method="post" id="formLogin" class="<?= $action === 'login' ? '' : 'd-none' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login">

                        <div class="floating-group">
                            <input
                                type="text"
                                id="loginUsername"
                                name="username"
                                class="input-auth-field"
                                placeholder=" "
                                value="<?= htmlspecialchars($username) ?>"
                                autocomplete="username"
                                required
                            >
                            <label for="loginUsername">Username Akun</label>
                            <i class="bi bi-person-fill input-icon-lead"></i>
                        </div>

                        <div class="floating-group">
                            <input
                                type="password"
                                id="loginPassword"
                                name="password"
                                class="input-auth-field"
                                placeholder=" "
                                autocomplete="current-password"
                                required
                            >
                            <label for="loginPassword">Password Akses</label>
                            <i class="bi bi-lock-fill input-icon-lead"></i>
                            <button type="button" class="btn-pwd-eye" id="btnToggleLoginPwd" aria-label="Lihat password">
                                <i class="bi bi-eye" id="iconLoginEye"></i>
                            </button>
                        </div>

                        <button type="submit" class="btn-auth-submit" id="btnSubmitLogin">
                            Masuk ke Sistem POS <i class="bi bi-arrow-right ms-1"></i>
                        </button>

                        <div class="demo-hint-box">
                            <div class="fw-bold mb-2" style="color:var(--text)">Mode Uji Coba Cepat</div>
                            <div class="d-flex justify-content-center gap-2">
                                <button type="button" class="demo-hint-btn flex-fill" onclick="fillQuick('kasir', 'kasir123')">
                                    <i class="bi bi-person-badge me-1"></i> Kasir
                                </button>
                                <button type="button" class="demo-hint-btn flex-fill" onclick="fillQuick('admin', 'admin123')">
                                    <i class="bi bi-shield-check me-1"></i> Admin
                                </button>
                            </div>
                        </div>

                        <p class="text-center" style="font-size:13px;color:var(--text-muted);margin-top:24px">
                            Belum punya akun? <a href="#" onclick="switchAuthTab('register');return false" style="color:var(--teal);font-weight:600">Daftar disini</a>
                        </p>
                    </form>

                    <!-- FORM 2: REGISTER -->
                    <form method="post" id="formRegister" class="<?= $action === 'register' ? '' : 'd-none' ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register">

                        <input type="hidden" name="role" value="admin">

                        <div class="floating-group">
                            <input
                                type="text"
                                id="regNama"
                                name="nama"
                                class="input-auth-field"
                                placeholder=" "
                                value="<?= htmlspecialchars($nama) ?>"
                                required
                            >
                            <label for="regNama" id="labelRegNama">Nama Pemilik / Admin Toko</label>
                            <i class="bi bi-person-vcard input-icon-lead"></i>
                        </div>

                        <div class="floating-group">
                            <input
                                type="text"
                                id="regUsername"
                                name="username"
                                class="input-auth-field"
                                placeholder=" "
                                value="<?= htmlspecialchars($username) ?>"
                                autocomplete="username"
                                minlength="3"
                                required
                            >
                            <label for="regUsername">Username Baru</label>
                            <i class="bi bi-at input-icon-lead"></i>
                        </div>

                        <div class="floating-group">
                            <input
                                type="password"
                                id="regPassword"
                                name="password"
                                class="input-auth-field"
                                placeholder=" "
                                autocomplete="new-password"
                                minlength="6"
                                required
                            >
                            <label for="regPassword">Password (Min. 6 Karakter)</label>
                            <i class="bi bi-key-fill input-icon-lead"></i>
                            <button type="button" class="btn-pwd-eye" id="btnToggleRegPwd" aria-label="Lihat password">
                                <i class="bi bi-eye" id="iconRegEye"></i>
                            </button>
                        </div>

                        <button type="submit" class="btn-auth-submit" id="btnSubmitRegister">
                            Buat Akun Toko &amp; Mulai <i class="bi bi-arrow-right ms-1"></i>
                        </button>

                        <p class="text-center mt-4" style="font-size:14px;color:var(--text-muted)">
                            Sudah punya akun? <a href="#" onclick="switchAuthTab('login');return false" style="color:var(--teal);font-weight:600">Masuk disini</a>
                        </p>

                        <p class="text-center small" style="font-size:12px;color:var(--text-muted);margin-top:8px">
                            <i class="bi bi-info-circle me-1"></i>Akun kasir dibuat dari panel admin setelah login.
                        </p>
                    </form>

                    <?php if ($userSesiAktif !== null): ?>
                    <div class="text-center mt-4 border-top pt-3">
                        <button type="button" class="btn btn-link p-0 text-decoration-none small fw-bold text-muted" onclick="showResumeCard()">
                            <i class="bi bi-arrow-left me-1"></i>Batal, kembali ke sesi aktif
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
        function showAuthForms() {
            var resumeCard = document.getElementById('cardSessionResume');
            var authCard = document.getElementById('cardAuthForms');
            if (resumeCard) resumeCard.style.display = 'none';
            if (authCard) {
                authCard.style.display = 'block';
                var loginU = document.getElementById('loginUsername');
                if (loginU) loginU.focus();
            }
        }

        function showResumeCard() {
            var resumeCard = document.getElementById('cardSessionResume');
            var authCard = document.getElementById('cardAuthForms');
            if (authCard) authCard.style.display = 'none';
            if (resumeCard) resumeCard.style.display = 'block';
        }

        function switchAuthTab(tab) {
            var formL = document.getElementById('formLogin');
            var formR = document.getElementById('formRegister');
            var btnL = document.getElementById('tabBtnLogin');
            var btnR = document.getElementById('tabBtnRegister');
            var subtitle = document.getElementById('authSubtitle');

            if (tab === 'register') {
                if (formL) formL.classList.add('d-none');
                if (formR) formR.classList.remove('d-none');
                if (btnL) btnL.classList.remove('active');
                if (btnR) btnR.classList.add('active');
                if (subtitle) subtitle.textContent = 'Buat akun toko baru sebagai admin/owner.';
                var regNama = document.getElementById('regNama');
                if (regNama) regNama.focus();
            } else {
                if (formR) formR.classList.add('d-none');
                if (formL) formL.classList.remove('d-none');
                if (btnR) btnR.classList.remove('active');
                if (btnL) btnL.classList.add('active');
                if (subtitle) subtitle.textContent = 'Sudah punya akun? Masuk di bawah ini.';
                var loginU = document.getElementById('loginUsername');
                if (loginU) loginU.focus();
            }
        }

        function fillQuick(u, p) {
            var loginU = document.getElementById('loginUsername');
            var loginP = document.getElementById('loginPassword');
            if (loginU && loginP) {
                loginU.value = u;
                loginP.value = p;
                loginU.focus();
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            // Video fade-in
            var authVideo = document.getElementById('authVideo');
            if (authVideo) {
                function onAuthVideoReady() {
                    authVideo.classList.add('is-ready');
                }
                authVideo.addEventListener('playing', onAuthVideoReady);
                authVideo.addEventListener('canplay', onAuthVideoReady);
                if (!authVideo.paused) onAuthVideoReady();
            }

            // Password toggle
            function setupPwdToggle(btnId, pwdId, iconId) {
                var btn = document.getElementById(btnId);
                var pwd = document.getElementById(pwdId);
                var icon = document.getElementById(iconId);
                if (btn && pwd && icon) {
                    btn.addEventListener('click', function () {
                        var isPwd = pwd.type === 'password';
                        pwd.type = isPwd ? 'text' : 'password';
                        icon.className = isPwd ? 'bi bi-eye-slash' : 'bi bi-eye';
                    });
                }
            }
            setupPwdToggle('btnToggleLoginPwd', 'loginPassword', 'iconLoginEye');
            setupPwdToggle('btnToggleRegPwd', 'regPassword', 'iconRegEye');

            // Check filled inputs for floating labels (Autofill fix)
            function checkFilledInputs() {
                var inputs = document.querySelectorAll('.input-auth-field');
                inputs.forEach(function(input) {
                    if (input.value.trim() !== '') {
                        input.classList.add('is-filled');
                    } else {
                        input.classList.remove('is-filled');
                    }
                });
            }
            
            // Run on load and on input
            checkFilledInputs();
            document.addEventListener('input', checkFilledInputs);
            setTimeout(checkFilledInputs, 500); // Catch delayed autofills
            
            // Submit spinners
            function setupSpinner(formId, btnId, loadingText) {
                var form = document.getElementById(formId);
                var btn = document.getElementById(btnId);
                if (form && btn) {
                    form.addEventListener('submit', function (e) {
                        // Cek native validity
                        if (!form.checkValidity()) {
                            e.preventDefault();
                            return;
                        }
                        btn.disabled = true;
                        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> ' + loadingText;
                    });
                }
            }
            setupSpinner('formLogin', 'btnSubmitLogin', 'Membuka POS...');
            setupSpinner('formRegister', 'btnSubmitRegister', 'Mendaftar...');
        });
    </script>
</body>
</html>
