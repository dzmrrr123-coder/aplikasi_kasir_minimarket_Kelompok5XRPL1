<?php
// bootstrap/autoload.php
// Autoloader sederhana (PHP native, tanpa Composer): otomatis me-load class
// dari folder /src berdasarkan namespace (App\...) atau nama file class.

spl_autoload_register(function (string $class): void {
    $baseDir = __DIR__ . '/../src/';

    // Hilangkan prefix namespace root "App\" bila dipakai.
    $class = ltrim(str_replace('App\\', '', $class), '\\');

    // Ubah separator namespace menjadi separator direktori.
    $path = $baseDir . str_replace('\\', '/', $class) . '.php';

    // Fallback: cari berdasarkan nama file class di seluruh subfolder /src
    // (untuk class tanpa namespace, mis. "Database" -> src/Database/Database.php).
    if (!file_exists($path)) {
        $className = basename(str_replace('\\', '/', $class)) . '.php';
        $iterator  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === $className) {
                $path = $file->getPathname();
                break;
            }
        }
    }

    if (file_exists($path)) {
        require_once $path;
    }
});
