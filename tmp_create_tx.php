<?php
require __DIR__ . '/src/autoload.php';

use App\Database\Database;
use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\PembayaranTunai;
use App\Models\Kasir;

$pdo = Database::connect();

// Bersihkan transaksi lama, buat yang baru
$pdo->exec('DELETE FROM item_transaksi');
$pdo->exec('DELETE FROM rekap_penjualan');
$pdo->exec('DELETE FROM pembayaran');
$pdo->exec('DELETE FROM transaksi');

$produk = Produk::cari(1); // Indomie Goreng
$kasir = new Kasir(['id' => 2]); // kasir

$tx = new Transaksi(['kasir_id' => 2]);
$tx->tambahItem($produk, 3);
$tx->prosesPembayaran(new PembayaranTunai(['jumlah' => 10500]));
$tx->simpan();

echo "Created transaksi #{$tx->getId()} at " . $tx->getTanggal()->format('Y-m-d H:i:s') . "\n";

// Query langsung ke DB untuk verifikasi
$rows = $pdo->query("SELECT id, tanggal, total FROM transaksi ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "  DB: id={$r['id']} tanggal={$r['tanggal']} total={$r['total']}\n";
}

$today = date('Y-m-d');
echo "\nPHP hari ini: $today\n";
$todayCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$today 00:00:00' AND tanggal < '$today 23:59:59'")->fetchColumn();
echo "Transaksi hari ini (PHP date): $todayCount\n";

$mysqlToday = $pdo->query("SELECT CURDATE()")->fetchColumn();
echo "MySQL CURDATE(): $mysqlToday\n";
$mysqlTodayCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE DATE(tanggal) = CURDATE()")->fetchColumn();
echo "Transaksi hari ini (MySQL CURDATE): $mysqlTodayCount\n";

// Now test dashboard methods
echo "\n=== Dashboard::ringkasanHariIni() ===\n";
var_dump(Dashboard::ringkasanHariIni());

echo "\n=== Dashboard::produkTerlaris(5) ===\n";
var_dump(Dashboard::produkTerlaris(5));

echo "\n=== Dashboard::transaksiTerbaru(5) ===\n";
var_dump(Dashboard::transaksiTerbaru(5));

echo "\n=== LaporanPenjualan::getAgregasiGrafik ===\n";
$lp = new LaporanPenjualan();
$g = $lp->getAgregasiGrafik(['tanggal_mulai' => date('Y-m-d', strtotime('-6 days')), 'tanggal_akhir' => date('Y-m-d')]);
var_dump($g);

echo "\n=== Produk::getAgregasiGrafik ===\n";
$pr = new Produk();
$s = $pr->getAgregasiGrafik(['limit' => 8]);
var_dump($s);

echo "\n=== Dashboard::penjualan7Hari ===\n";
$p7 = Dashboard::penjualan7Hari();
var_dump($p7);

echo "\n=== Dashboard::metodePembayaran ===\n";
var_dump(Dashboard::metodePembayaran());
