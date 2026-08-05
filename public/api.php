<?php

declare(strict_types=1);

/**
 * Front-controller AJAX (admin only).
 *
 * Satu-satunya endpoint JSON untuk Chart.js & DataTables:
 *   api.php?aksi=dashboard.grafik
 *   api.php?aksi=dashboard.ringkasan
 *   api.php?aksi=dashboard.terlaris
 *   api.php?aksi=dashboard.transaksi
 *   api.php?aksi=dashboard.stok_kategori
 *   api.php?aksi=laporan.grafik
 *   api.php?aksi=laporan.tabel
 *   api.php?aksi=retur.tabel
 *   api.php?aksi=retur.grafik
 *
 * Tidak ada query SQL / akses DB di sini — semua data dari Controller
 * yang memanggil objek DataReporter. Output JSON murni.
 */

require __DIR__ . '/../src/autoload.php';

use App\Controllers\DashboardController;
use App\Controllers\InventarisController;
use App\Controllers\LaporanController;
use App\Controllers\ReturController;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Guard: wajib login admin.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// Baca params dari request (GET utk data, POST utk filter berat).
$params = array_merge($_GET, $_POST);
$aksi = (string) ($params['aksi'] ?? '');
unset($params['aksi']);

$dashboard = new DashboardController();
$laporan = new LaporanController();
$retur = new ReturController();
$inventaris = new InventarisController();

$hasil = null;

switch ($aksi) {
    case 'produk.tabel':
        $hasil = $inventaris->dataTabelProduk($params);
        break;

    case 'supplier.tabel':
        $hasil = $inventaris->dataTabelSupplier($params);
        break;

    case 'dashboard.grafik':
        $hasil = $dashboard->grafikPenjualan($params);
        break;

    case 'dashboard.ringkasan':
        $hasil = $dashboard->ringkasan($params);
        break;

    case 'dashboard.terlaris':
        $hasil = $dashboard->dataTabelTerlaris($params);
        break;

    case 'dashboard.transaksi':
        $hasil = $dashboard->dataTabelTransaksi($params);
        break;

    case 'dashboard.stok_kategori':
        $hasil = $dashboard->grafikStokKategori($params);
        break;

    case 'laporan.grafik':
        $hasil = $laporan->grafikPeriode($params);
        break;

    case 'laporan.tabel':
        $hasil = $laporan->dataTabelTransaksi($params);
        break;

    case 'retur.tabel':
        $hasil = $retur->dataTabelRiwayat($params);
        break;

    case 'retur.grafik':
        $hasil = $retur->grafikBulanan($params);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'aksi tidak dikenal']);
        exit;
}

// Aksi DataTables membungkus ke format server-side DataTables.
if (in_array($aksi, ['produk.tabel', 'supplier.tabel', 'dashboard.terlaris', 'dashboard.transaksi', 'laporan.tabel', 'retur.tabel'], true)) {
    echo json_encode([
        'draw'            => (int) ($params['draw'] ?? 0),
        'recordsTotal'    => $hasil['total'],
        'recordsFiltered' => $hasil['filtered'],
        'data'            => $hasil['rows'],
    ]);
    exit;
}

echo json_encode($hasil);
