<?php

declare(strict_types=1);

/**
 * Entry point aplikasi kasir minimarket.
 * Halaman ini menampilkan status koneksi database dan daftar model
 * yang tersedia (logika bisnis diuji via test/e2e.php).
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kasir Minimarket</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/theme.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg pos-navbar mb-4 sticky-top">
    <div class="container">
        <a class="navbar-brand" href="index.php"><i class="bi bi-shop"></i> Kasir Minimarket</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav-index"
                aria-controls="nav-index" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav-index">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link active" href="index.php"><i class="bi bi-house-door"></i> Beranda</a>
                </li>
            </ul>
            <div class="d-flex align-items-center gap-2">
                <a href="login.php" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Masuk
                </a>
            </div>
        </div>
    </div>
</nav>
<div class="container py-4">

    <div class="mb-4">
        <h1 class="h3 mb-1">Kasir Minimarket</h1>
        <span class="text-muted small">Halaman status aplikasi & database</span>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white"><strong>Status Database</strong></div>
                <div class="card-body">
                    <?php if ($status === 'OK'): ?>
                        <div class="alert alert-success py-2 mb-0" role="alert">
                            <i class="bi bi-check-circle me-1"></i> Koneksi database OK.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger py-2" role="alert">
                            <i class="bi bi-x-circle me-1"></i> Koneksi database GAGAL.
                        </div>
                        <pre class="mb-0"><?= htmlspecialchars($error) ?></pre>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card pos-card mb-4">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <span>Model Tersedia</span>
                    <span class="text-muted small"><?= count($namaModel) ?> model</span>
                </div>
                <div class="card-body p-0">
                    <?php if ($namaModel === []): ?>
                        <div class="p-4 text-center text-muted">Belum ada model.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($namaModel as $nama): ?>
                                <li class="list-group-item d-flex align-items-center gap-2">
                                    <i class="bi bi-box-seam text-brand"></i>
                                    <span><?= htmlspecialchars($nama) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <p class="text-muted small">
        <i class="bi bi-terminal me-1"></i> Jalankan uji end-to-end via CLI:
        <code>php test/e2e.php</code>
    </p>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
