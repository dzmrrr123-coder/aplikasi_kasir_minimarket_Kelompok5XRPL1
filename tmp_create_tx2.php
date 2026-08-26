<?php
require __DIR__ . '/src/autoload.php';

use App\Database\Database;
use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\PembayaranTunai;

$pdo = Database::connect();

// Bersihkan transaksi lama
$pdo->exec('DELETE FROM item_transaksi');
$pdo->exec('DELETE FROM rekap_penjualan');
$pdo->exec('DELETE FROM pembayaran');
$pdo->exec('DELETE FROM transaksi');

$produk = Produk::cari(1); // Indomie Goreng
echo "Produk: {$produk->getNama()} harga={$produk->getHarga()} stok={$produk->getStok()}\n";

// Buat transaksi
$tx = new Transaksi(['kasir_id' => 2]);
$tx->tambahItem($produk, 3);
$tx->hitungTotal();
echo "Total: " . $tx->getTotal() . "\n";
$ok = $tx->prosesPembayaran(new PembayaranTunai(['jumlah' => 15000]));
echo "Proses pembayaran: " . ($ok ? 'OK' : 'GAGAL') . "\n";
echo "Transaksi ID: " . $tx->getId() . " tanggal=" . $tx->getTanggal()->format('Y-m-d H:i:s') . "\n";

// Verify DB
$rows = $pdo->query("SELECT id, tanggal, total FROM transaksi ORDER BY id")->fetchAll();
foreach ($rows as $r) {
    echo "  DB: id={$r['id']} tanggal={$r['tanggal']} total={$r['total']}\n";
}

$today = date('Y-m-d');
echo "\nPHP hari ini: $today\n";
echo "PHP time: " . date('Y-m-d H:i:s') . "\n";

$todayCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$today 00:00:00' AND tanggal < '$today 23:59:59'")->fetchColumn();
echo "Transaksi hari ini (PHP range): $todayCount\n";

$mysqlNow = $pdo->query("SELECT NOW(), UTC_TIMESTAMP(), CURDATE()")->fetch();
echo "MySQL NOW(): {$mysqlNow[0]}\n";
echo "MySQL UTC_TIMESTAMP(): {$mysqlNow[1]}\n";
echo "MySQL CURDATE(): {$mysqlNow[2]}\n";

echo "\n=== Dashboard::ringkasanHariIni() ===\n";
var_dump(\App\Models\Dashboard::ringkasanHariIni());

echo "\n=== Dashboard::produkTerlaris(5) ===\n";
$terlaris = \App\Models\Dashboard::produkTerlaris(5);
echo "count: " . count($terlaris) . "\n";
foreach ($terlaris as $t) echo "  {$t['nama']}: qty={$t['qty']} total={$t['total']}\n";

echo "\n=== Dashboard::transaksiTerbaru(5) ===\n";
$terbaru = \App\Models\Dashboard::transaksiTerbaru(5);
echo "count: " . count($terbaru) . "\n";
foreach ($terbaru as $t) echo "  #{$t['id']}: {$t['tanggal']} Rp{$t['total']}\n";

echo "\n=== Dashboard::penjualan7Hari ===\n";
$p7 = \App\Models\Dashboard::penjualan7Hari();
echo "count: " . count($p7) . "\n";
foreach ($p7 as $p) echo "  {$p['tanggal']}: total={$p['total']} jumlah={$p['jumlah']}\n";

echo "\n=== LaporanPenjualan::getAgregasiGrafik ===\n";
$lp = new \App\Models\LaporanPenjualan();
$g = $lp->getAgregasiGrafik(['tanggal_mulai' => date('Y-m-d', strtotime('-6 days')), 'tanggal_akhir' => date('Y-m-d')]);
echo "labels: " . json_encode($grafik_labels ?? $g['labels']) . "\n";
echo "data: " . json_encode($g['series']['data']) . "\n";

echo "\n=== Produk::getAgregasiGrafik ===\n";
$pr = new \App\Models\Produk();
$s = $pr->getAgregasiGrafik(['limit' => 8]);
echo "labels: " . json_encode($s['labels']) . "\n";
echo "data: " . json_encode($s['series']['data']) . "\n";

echo "\n=== Dashboard::metodePembayaran ===\n";
var_dump(\App\Models\Dashboard::metodePembayaran());
