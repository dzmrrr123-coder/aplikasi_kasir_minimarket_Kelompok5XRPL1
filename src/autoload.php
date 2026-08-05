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
