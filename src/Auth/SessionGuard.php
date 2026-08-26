<?php
// src/Auth/SessionGuard.php
// Penjaga session: cek login, cek role, token CSRF, dan flash message.
// Dipanggil di baris pertama tiap halaman terproteksi.

class SessionGuard
{
    // Pastikan session aktif sebelum baca/tulis $_SESSION.
    private static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    // Base URL aplikasi = path URL sampai folder /public, dihitung dari posisi
    // nyata folder public terhadap DOCUMENT_ROOT — benar dari kedalaman folder
    // mana pun (mis. /public/kasir/transaksi.php maupun /public/index.php).
    public static function baseUrl(): string
    {
        $docroot   = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
        $publicDir = str_replace('\\', '/', realpath(__DIR__ . '/../../public') ?: '');

        if ($docroot !== '' && str_starts_with($publicDir, $docroot)) {
            return rtrim(substr($publicDir, strlen($docroot)), '/');
        }

        return '';
    }

    // URL dashboard per role: kasir -> transaksi, admin -> produk.
    public static function dashboardUrl(string $role): string
    {
        return self::baseUrl() . ($role === 'admin' ? '/admin/produk.php' : '/kasir/transaksi.php');
    }

    // Wajib login: redirect ke login.php kalau session user belum ada.
    public static function requireLogin(): void
    {
        self::start();

        if (empty($_SESSION['user_id'])) {
            header('Location: ' . self::baseUrl() . '/login.php');
            exit;
        }
    }

    // Wajib role tertentu: kalau role session tidak cocok, redirect halus ke
    // dashboard role yang sedang login (bukan halaman error mentah).
    public static function requireRole(string $role): void
    {
        self::requireLogin();

        if (($_SESSION['role'] ?? '') !== $role) {
            header('Location: ' . self::dashboardUrl($_SESSION['role'] ?? ''));
            exit;
        }
    }

    // Ambil token CSRF (dibuat sekali lalu disimpan di session) untuk hidden
    // input di form manapun.
    public static function generateCsrfToken(): string
    {
        self::start();

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    // Validasi token CSRF dari form POST (hash_equals = aman timing attack).
    public static function validateCsrfToken(?string $token): bool
    {
        self::start();

        return is_string($token)
            && !empty($_SESSION['csrf_token'])
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    // Simpan flash message sekali tampil. type: 'success' | 'error'.
    public static function setFlash(string $type, string $message): void
    {
        self::start();
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    // Ambil flash message lalu HAPUS dari session (reload tidak menampilkan
    // pesan yang sama dua kali).
    public static function getFlash(): ?array
    {
        self::start();

        if (empty($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return $flash;
    }
}
