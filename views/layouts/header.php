<?php
// views/layouts/header.php
// Pembuka semua halaman terproteksi: session_start paling atas (sebelum ada
// output), <head> + Bootstrap CDN, navbar (nama user + tombol logout), dan
// render flash message sekali tampil.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../bootstrap/autoload.php';

$baseUrl = SessionGuard::baseUrl(); // dipakai juga oleh sidebar yang di-include setelah ini
$flash   = SessionGuard::getFlash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Kasir Minimarket') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
</head>
<body>
<nav class="navbar navbar-dark bg-primary">
    <div class="container-fluid">
        <span class="navbar-brand mb-0">Kasir Minimarket</span>
        <?php if (!empty($_SESSION['nama'])): ?>
        <div class="d-flex align-items-center text-white gap-3">
            <span><?= htmlspecialchars($_SESSION['nama']) ?> (<?= htmlspecialchars($_SESSION['role'] ?? '') ?>)</span>
            <a href="<?= $baseUrl ?>/logout.php" class="btn btn-outline-light btn-sm">Logout</a>
        </div>
        <?php endif; ?>
    </div>
</nav>
<div class="container-fluid py-3">
    <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show">
        <?= htmlspecialchars($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
