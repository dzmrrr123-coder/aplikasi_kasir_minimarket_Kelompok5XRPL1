<?php
require __DIR__ . '/src/autoload.php';
session_start();
// reuse existing admin session in browser by reading nothing; just call models
$g = App\Models\Dashboard::penjualan7Hari();
$s = (new App\Models\LaporanPenjualan())->getAgregasiGrafik([
    'tanggal_mulai' => date('Y-m-d', strtotime('-6 days')),
    'tanggal_akhir' => date('Y-m-d'),
]);
echo "penjualan7Hari:\n"; var_export($g); echo "\n\nlaporan grafik:\n"; var_export($s); echo PHP_EOL;
