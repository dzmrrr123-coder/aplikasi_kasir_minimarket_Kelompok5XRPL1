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
        foreach (['user_id', 'role', 'nama'] as $kunci) {
            unset($_SESSION[$kunci]);
        }

        // Regenerasi id sesi supaya sesi lama (yang sudah punya data
        // karyawan) tidak bisa disadap/dipakai ulang.
        session_regenerate_id(true);
    }
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

        if ($e instanceof \PDOException && (int) $e->getCode() === 23000) {
            return 'Data sudah dipakai atau duplikat. Periksa kembali input Anda.';
        }

        return 'Terjadi kesalahan. Silakan coba lagi.';
    }
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
