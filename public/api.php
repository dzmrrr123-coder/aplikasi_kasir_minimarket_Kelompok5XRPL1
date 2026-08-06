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
use App\Controllers\LabaController;
use App\Controllers\LaporanController;
use App\Controllers\ReturController;
use App\Controllers\ShiftController;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Guard: wajib login. Aksi hardware.config boleh diakses kasir (POS),
// sisanya khusus admin.
if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$aksi = (string) ($_GET['aksi'] ?? ($_POST['aksi'] ?? ''));

// Aksi yang boleh diakses kasir (data milik kasir sendiri / konfigurasi
// hardware POS yang tidak sensitif). Sisanya khusus admin.
$aksiKasir = ['hardware.config', 'shift.ringkasan'];

if ($_SESSION['role'] !== 'admin' && !in_array($aksi, $aksiKasir, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// Baca params dari request (GET utk data, POST utk filter berat).
$params = array_merge($_GET, $_POST);
$aksi = (string) ($params['aksi'] ?? '');
unset($params['aksi']);

// Normalisasi parameter DataTables server-side:
// - search[value] dikirim sebagai array -> ambil string-nya
// - start/length/draw dijamin int
if (isset($params['search']) && is_array($params['search'])) {
    $params['search'] = (string) ($params['search']['value'] ?? '');
}
if (isset($params['start'])) {
    $params['start'] = (int) $params['start'];
}
if (isset($params['length'])) {
    $params['length'] = (int) $params['length'];
}
if (isset($params['draw'])) {
    $params['draw'] = (int) $params['draw'];
}

$dashboard = new DashboardController();
$laporan = new LaporanController();
$retur = new ReturController();
$inventaris = new InventarisController();
$laba = new LabaController();
$shift = new ShiftController();

$hasil = null;

switch ($aksi) {
    case 'hardware.config':
        // Konfigurasi Web Serial (timbangan & printer) untuk frontend.
        $hardware = require __DIR__ . '/../config/hardware.php';
        $hasil = is_array($hardware) ? $hardware : ['timbangan' => [], 'printer' => []];
        break;

    case 'produk.tabel':
        $hasil = $inventaris->dataTabelProduk($params);
        break;

    case 'supplier.tabel':
        $hasil = $inventaris->dataTabelSupplier($params);
        break;

    case 'pembelian.tabel':
        $hasil = $inventaris->dataTabelPembelian($params);
        break;

    case 'member.tabel':
        $hasil = $inventaris->dataTabelMember($params);
        break;

    case 'member.cari':
        $hasil = $inventaris->cariMember($params);
        break;

    case 'produk.harga_beli':
        $hasil = $inventaris->hargaBeliTerakhir($params);
        break;

    case 'shift.tabel':
        $hasil = $shift->dataTabelShift($params);
        break;

    case 'shift.ringkasan':
        // Ringkasan shift aktif untuk modal tutup kas (riwayat transaksi).
        $hasil = $shift->ringkasanShift($params);
        break;

    case 'audit.tabel':
        $hasil = $shift->dataTabelAudit($params);
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

    case 'laba.ringkasan':
        $hasil = $laba->ringkasan($params);
        break;

    case 'laba.grafik':
        $hasil = $laba->grafik($params);
        break;

    case 'laba.tabel':
        $hasil = $laba->dataTabel($params);
        break;

    case 'retur.tabel':
        $hasil = $retur->dataTabelRiwayat($params);
        break;

    case 'retur.supplier_asal':
        // Supplier asal produk (dari pembelian/stok masuk terakhir).
        $hasil = $retur->supplierAsalProduk($params);
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
if (in_array($aksi, ['produk.tabel', 'supplier.tabel', 'pembelian.tabel', 'member.tabel', 'dashboard.terlaris', 'dashboard.transaksi', 'laporan.tabel', 'laba.tabel', 'retur.tabel', 'shift.tabel', 'audit.tabel'], true)) {
    echo json_encode([
        'draw'            => (int) ($params['draw'] ?? 0),
        'recordsTotal'    => $hasil['total'],
        'recordsFiltered' => $hasil['filtered'],
        'data'            => $hasil['rows'],
    ]);
    exit;
}

echo json_encode($hasil);
