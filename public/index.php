<?php

declare(strict_types=1);

/**
 * Entry point aplikasi kasir minimarket.
 * Halaman ini hanya menampilkan status koneksi database dan daftar model
 * yang tersedia (belum ada antarmuka CRUD; logika bisnis diuji via test/e2e.php).
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;

$status = 'OK';
$error = '';

try {
    $pdo = Database::connect();
    $pdo->query('SELECT 1');
    Database::runSchema();
} catch (Throwable $e) {
    $status = 'GAGAL';
    $error = $e->getMessage();
}

$models = glob(__DIR__ . '/../src/Models/*.php');
$namaModel = $models === false
    ? []
    : array_map(static fn (string $file): string => basename($file, '.php'), $models);
sort($namaModel);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Kasir Minimarket</title>
    <style>
        body { font-family: sans-serif; max-width: 720px; margin: 40px auto; padding: 0 16px; }
        .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-weight: bold; }
        .badge.ok { background: #dcfce7; color: #166534; }
        .badge.gagal { background: #fee2e2; color: #991b1b; }
        pre { background: #f4f4f5; padding: 12px; border-radius: 8px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Kasir Minimarket</h1>
    <p>
        Status database:
        <span class="badge <?= $status === 'OK' ? 'ok' : 'gagal' ?>"><?= htmlspecialchars($status) ?></span>
    </p>

    <?php if ($error !== ''): ?>
        <pre><?= htmlspecialchars($error) ?></pre>
    <?php endif; ?>

    <h2>Model tersedia (<?= count($namaModel) ?>)</h2>
    <ul>
        <?php foreach ($namaModel as $nama): ?>
            <li><?= htmlspecialchars($nama) ?></li>
        <?php endforeach; ?>
    </ul>

    <p>Jalankan uji end-to-end via CLI: <code>php test/e2e.php</code></p>
</body>
</html>
