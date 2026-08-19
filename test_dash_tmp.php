<?php
require __DIR__ . '/src/autoload.php';
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role'] = 'admin';
$_SESSION['csrf_token'] = bin2hex(random_bytes(8));
$_SESSION['nama'] = 'Admin';
$_SESSION['keranjang'] = [];

$out = [];
$out['stok_kategori'] = @(new App\Models\Produk())->getAgregasiGrafik(['limit' => 8]);
$out['produkTerlaris'] = App\Models\Dashboard::produkTerlaris(5);
$out['transaksiTerbaru'] = App\Models\Dashboard::transaksiTerbaru(5);
$out['metode'] = App\Models\Dashboard::metodePembayaran();
echo json_encode($out, JSON_PRETTY_PRINT);
echo PHP_EOL;
