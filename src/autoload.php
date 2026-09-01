<?php

declare(strict_types=1);

/**
 * Autoloader PSR-4 sederhana untuk namespace App\.
 * Daftar class-nya bisa berubah, jadi pakai pemetaan directory-to-namespace
 * alih-alih daftar class per class.
 */

// Timezone aplikasi: WIB (Asia/Jakarta). Dipakai semua halaman & model
// supaya jam di database (DateTime) konsisten dengan jam lokal.
date_default_timezone_set('Asia/Jakarta');

// Load .env file ke environment variables (getenv).
// Dilakukan sekali supaya semua komponen (Midtrans, Clerk, dll) bisa
// membaca config via getenv() tanpa harus load .env sendiri.
(function () {
    $envFile = dirname(__DIR__) . '/.env';
    if (!is_file($envFile)) {
        return;
    }
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!getenv($key)) {
                putenv("$key=$value");
            }
        }
    }
})();

// Hardening cookie sesi: HttpOnly + SameSite=Lax mencegah akses cookie
// via JavaScript dan memblokir sebagian besar serangan CSRF lintas-situs.
// Tidak dipanggil di CLI (tidak ada cookie & menghindari warning header).
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => '/',
    ]);
}

/**
 * Token CSRF untuk form POST (session).
 * Dibuat sekali per sesi lalu dipakai ulang (aman & tetap valid).
 */
if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}

/** Field hidden CSRF siap tempel di form. */
if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token()) . '">';
    }
}

/** Validasi token CSRF dari POST. */
if (!function_exists('csrf_valid')) {
    function csrf_valid(): bool
    {
        $dikirim = (string) ($_POST['csrf'] ?? '');
        $tersimpan = (string) ($_SESSION['csrf_token'] ?? '');

        return $dikirim !== '' && $tersimpan !== '' && hash_equals($tersimpan, $dikirim);
    }
}

/**
 * Logout karyawan (kasir/admin): hapus key sesi milik karyawan TANPA
 * menghapus sesi member yang mungkin aktif di browser/perangkat sama.
 * Sesi member dipisahkan (member_id, member_nama, member_nomor) supaya
 * kios self-service member tidak terganggu saat kasir ganti akun.
 */
if (!function_exists('logoutKaryawan')) {
    function logoutKaryawan(): void
    {
        foreach (['user_id', 'role', 'nama', 'auth_provider', 'clerk_user_id', 'clerk_email', 'clerk_nama'] as $kunci) {
            unset($_SESSION[$kunci]);
        }

        // Regenerasi id sesi supaya sesi lama (yang sudah punya data
        // karyawan) tidak bisa disadap/dipakai ulang.
        session_regenerate_id(true);
    }
}

/**
 * Ambil ID admin pemilik data dari sesi.
 * - Admin login → ID sendiri (admin IS owner)
 * - Kasir login → admin_id dari baris kasir di tabel users
 * Dipakai semua model untuk filter data per-toko (multi-tenancy).
 *
 * @return int 0 bila sesi belum ada / tidak valid
 */
if (!function_exists('currentAdminId')) {
    function currentAdminId(): int
    {
        return (int) ($_SESSION['admin_id'] ?? 0);
    }
}

/**
 * Registrasi shutdown function untuk mem-persist data sesi (seperti keranjang dan filter laporan)
 * ke database setiap kali eksekusi PHP selesai.
 */
if (PHP_SAPI !== 'cli' && !function_exists('syncSesiKeDB')) {
    function syncSesiKeDB(): void
    {
        if (isset($_SESSION['user_id'])) {
            try {
                $pdo = \App\Database\Database::connect();
                $id = (int) $_SESSION['user_id'];
                
                // Ambil data yang perlu disimpan dari session
                $dataToSave = [
                    'keranjang' => $_SESSION['keranjang'] ?? [],
                    'keranjang_tertunda' => $_SESSION['keranjang_tertunda'] ?? [],
                    'diskon_id' => $_SESSION['diskon_id'] ?? null,
                    'member_id' => $_SESSION['member_id'] ?? null,
                    'laporan_tanggal_mulai' => $_SESSION['laporan_tanggal_mulai'] ?? null,
                    'laporan_tanggal_akhir' => $_SESSION['laporan_tanggal_akhir'] ?? null,
                ];

                $stmt = $pdo->prepare('UPDATE users SET data_sesi = :data WHERE id = :id');
                $stmt->execute([
                    ':data' => json_encode($dataToSave, JSON_UNESCAPED_UNICODE),
                    ':id'   => $id,
                ]);
            } catch (\Throwable $e) {
                // Jangan melempar error di shutdown function, diamkan saja.
            }
        }
    }
    register_shutdown_function('syncSesiKeDB');
}

/**
 * Wajib token CSRF valid untuk semua request POST.
 * Dipanggil di awal blok routing POST tiap halaman. Kalau tidak valid,
 * hentikan dengan 419 (atau redirect ke halaman sendiri untuk UX).
 */
if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid()) {
            http_response_code(419);
            exit('Sesi kedaluwarsa. Muat ulang halaman dan coba lagi.');
        }
    }
}

/**
 * Ubah exception menjadi pesan yang aman ditampilkan ke pengguna.
 * - RuntimeException (validasi) -> pesan aslinya (memang ditujukan utk user)
 * - PDOException kode 23000 (duplikat/FK) -> pesan ramah generik
 * - lainnya -> pesan generik tanpa detail teknis/DB
 */
if (!function_exists('pesanErrorRamah')) {
    function pesanErrorRamah(\Throwable $e): string
    {
        if ($e instanceof \RuntimeException) {
            return $e->getMessage();
        }

        // @phpstan-ignore-next-line instanceof.alwaysFalse (PDOException is built-in)
        if ($e instanceof \PDOException) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'fk_shift_kasir') || str_contains($msg, 'kasir_id')) {
                return 'Sesi akun kasir tidak valid atau database baru saja di-reset. Silakan logout dan login kembali.';
            }
            if ((int) $e->getCode() === 23000 || str_contains($msg, '1062') || str_contains($msg, '1452')) {
                return 'Data sudah dipakai atau relasi data tidak ditemukan. Periksa kembali input Anda.';
            }
        }

        return 'Terjadi kesalahan. Silakan coba lagi.';
    }
}

// Muat autoload Composer bila vendor/ ada — dipakai library eksternal
// seperti Dompdf (ekspor PDF laporan). Aplikasi sendiri tetap pakai
// autoloader App\ di bawah.
$composerAutoload = __DIR__ . '/../vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
