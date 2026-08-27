<?php

declare(strict_types=1);

/**
 * Entry point transaksi kasir (POS).
 *
 * Mengarahkan ke antarmuka POS utama di /public/transaksi.php
 * setelah memvalidasi otentikasi login kasir/admin.
 */

require_once __DIR__ . '/../../bootstrap/autoload.php';

SessionGuard::requireLogin();

header('Location: ' . SessionGuard::baseUrl() . '/transaksi.php');
exit;
