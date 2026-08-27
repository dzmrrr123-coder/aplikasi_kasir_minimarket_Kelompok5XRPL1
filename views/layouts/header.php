<?php
// views/layouts/header.php
// Header premium admin — sticky header teal + shell layout sidebar/content.
// Variabel diharapkan: $pageTitle (string), $aktif (string), $nama (string)

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../bootstrap/autoload.php';

$baseUrl = SessionGuard::baseUrl();
$flash   = SessionGuard::getFlash();

$nama  = $_SESSION['nama'] ?? 'Admin';
$aktif = $aktif ?? '';

// Avatar inisial
$namaArr = explode(' ', trim($nama));
$inisial = mb_strtoupper(mb_substr($namaArr[0], 0, 1));
if (isset($namaArr[1])) {
    $inisial .= mb_strtoupper(mb_substr($namaArr[1], 0, 1));
}

// Page title breadcrumb label
$pageLabel = $pageTitle ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle ?? 'Admin') ?> — Kasir Minimarket</title>
    <meta name="description" content="Panel Admin Kasir Minimarket">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Admin Design System -->
    <link rel="stylesheet" href="<?= $baseUrl ?>/assets/css/style.css">
    <style>
        /* Inline: restore dark-mode class on <html> before first paint to avoid flash */
        (function(){
            try {
                if(localStorage.getItem('adminTheme')==='dark'){
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme','dark');
                }
            } catch(e){}
        })();
    </style>
    <script>
        // Run before render to avoid FOUC
        (function(){
            try {
                if(localStorage.getItem('adminTheme')==='dark'){
                    document.documentElement.classList.add('dark-mode');
                    document.documentElement.setAttribute('data-theme','dark');
                }
            } catch(e){}
        })();
    </script>
</head>
<body>

<!-- ====== STICKY HEADER ====== -->
<header class="admin-header" id="adminHeader">

    <!-- Mobile sidebar toggle -->
    <button class="admin-sidebar-toggle d-lg-none" id="sidebarToggleBtn" aria-label="Buka sidebar">
        <i class="bi bi-list"></i>
    </button>

    <!-- Brand -->
    <a class="admin-header-brand" href="<?= $baseUrl ?>/admin/dashboard.php">
        <i class="bi bi-shop-window"></i>
        <span class="d-none d-sm-inline">Kasir Minimarket</span>
    </a>

    <div class="admin-header-divider d-none d-sm-block"></div>

    <!-- Breadcrumb / current page -->
    <div class="admin-header-breadcrumb d-none d-sm-block">
        <span>Admin</span>
        <i class="bi bi-chevron-right mx-1" style="font-size:0.6rem;opacity:.5"></i>
        <span class="page-title-text"><?= htmlspecialchars($pageLabel) ?></span>
    </div>

    <!-- Right actions -->
    <div class="admin-header-actions">
        <!-- Dark mode toggle -->
        <button class="admin-theme-toggle" id="adminThemeToggle" title="Ganti mode terang/gelap">
            <i class="bi bi-circle-half"></i>
        </button>

        <!-- User avatar dropdown -->
        <div class="dropdown">
            <button class="admin-header-avatar" data-bs-toggle="dropdown" data-bs-offset="0,8"
                    aria-expanded="false" title="Akun: <?= htmlspecialchars($nama) ?>">
                <?= htmlspecialchars($inisial) ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end admin-user-dropdown">
                <li class="admin-user-header">
                    <div class="admin-user-avatar-lg"><?= htmlspecialchars($inisial) ?></div>
                    <div>
                        <div class="admin-user-name"><?= htmlspecialchars($nama) ?></div>
                        <div class="admin-user-role">
                            <i class="bi bi-shield-check" style="font-size:0.65rem"></i> Admin
                        </div>
                    </div>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2" href="<?= $baseUrl ?>/admin/user.php">
                        <span class="dropdown-icon-wrap bg-teal" style="width:1.75rem;height:1.75rem;font-size:0.8rem">
                            <i class="bi bi-people"></i>
                        </span>
                        <span>Kelola Kasir</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider my-1"></li>
                <li>
                    <a class="dropdown-item d-flex align-items-center gap-2 text-danger" href="<?= $baseUrl ?>/logout.php">
                        <span class="dropdown-icon-wrap bg-rose" style="width:1.75rem;height:1.75rem;font-size:0.8rem">
                            <i class="bi bi-box-arrow-right"></i>
                        </span>
                        <span>Logout</span>
                    </a>
                </li>
            </ul>
        </div>
    </div>
</header>

<!-- Mobile sidebar backdrop -->
<div class="admin-sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ====== SHELL: sidebar + content ====== -->
<div class="admin-shell">
<?php // sidebar-admin.php di-include setelah ini dari setiap halaman ?>
<?php // konten halaman dibungkus di dalam <main class="admin-content"> ?>
<?php // Flash message dirender di sini agar tersedia sebelum konten ?>
<?php ob_start(); // buffer flash ke awal konten ?>
<?php if ($flash): ?>
    <?php
    $flashIcon  = $flash['type'] === 'success' ? 'bi-check-circle-fill' : ($flash['type'] === 'warning' ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill');
    $flashClass = 'flash-' . ($flash['type'] === 'success' ? 'success' : ($flash['type'] === 'warning' ? 'warning' : 'error'));
    ?>
    <div class="flash-message <?= $flashClass ?>" role="alert">
        <i class="bi <?= $flashIcon ?> flash-icon"></i>
        <span><?= htmlspecialchars($flash['message']) ?></span>
        <button class="flash-close" onclick="this.closest('.flash-message').style.opacity='0';setTimeout(()=>this.closest('.flash-message').remove(),200)" aria-label="Tutup">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
<?php endif; ?>
<?php $flashHtml = ob_get_clean(); ?>
