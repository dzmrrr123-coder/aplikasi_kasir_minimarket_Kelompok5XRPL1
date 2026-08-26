<?php
require __DIR__ . '/src/autoload.php';

use App\Database\Database;
use App\Models\Dashboard;
use App\Models\LaporanPenjualan;

$pdo = Database::connect();

echo "PHP TZ: " . date_default_timezone_get() . "\n";
echo "PHP Now: " . date('Y-m-d H:i:s') . "\n";

// MySQL timezone
$row = $pdo->query("SELECT @@session.time_zone as session_tz, @@global.time_zone as global_tz, NOW() as mysql_now, UTC_TIMESTAMP() as mysql_utc")->fetch();
echo "MySQL session_tz: " . $row['session_tz'] . "\n";
echo "MySQL global_tz: " . $row['global_tz'] . "\n";
echo "MySQL NOW(): " . $row['mysql_now'] . "\n";
echo "MySQL UTC_TIMESTAMP(): " . $row['mysql_utc'] . "\n";

// Check transactions
$count = $pdo->query("SELECT COUNT(*) FROM transaksi")->fetchColumn();
echo "Total transaksi: " . $count . "\n";

if ($count > 0) {
    $rows = $pdo->query("SELECT id, tanggal, total, kasir_id FROM transaksi ORDER BY id DESC LIMIT 5")->fetchAll();
    foreach ($rows as $r) {
        echo "  Transaksi #{$r['id']}: tanggal={$r['tanggal']} total={$r['total']}\n";
    }
    
    $today = date('Y-m-d');
    echo "\nToday PHP: " . $today . "\n";
    $todayCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$today 00:00:00' AND tanggal < '$today 23:59:59'")->fetchColumn();
    echo "Transaksi today: " . $todayCount . "\n";
}

echo "\n=== ringkasanHariIni ===\n";
var_dump(Dashboard::ringkasanHariIni());

echo "\n=== penjualan7Hari (last entry) ===\n";
$penj = Dashboard::penjahan7Hari();
echo "count: " . count($penj) . "\n";
foreach ($penj as $p) {
    echo "  {$p['tanggal']}: total={$p['total']} jumlah={$p['jumlah']}\n";
}

echo "\n=== produkTerlaris ===\n";
$terlaris = Dashboard::produkTerlaris(5);
echo "count: " . count($terlaris) . "\n";
foreach ($terlaris as $t) {
    echo "  {$t['nama']}: qty={$t['qty']} total={$t['total']}\n";
}

echo "\n=== transaksiTerbaru ===\n";
$terbaru = Dashboard::transaksiTerbaru(5);
echo "count: " . count($terbaru) . "\n";
foreach ($terbaru as $t) {
    echo "  #{$t['id']}: tanggal={$t['tanggal']} total={$t['total']}\n";
}

echo "\n=== grafikPenjualan (LaporanPenjualan::getAgregasiGrafik) ===\n";
$lp = new LaporanPenjualan();
$grafik = $lp->getAgregasiGrafik([
    'tanggal_mulai' => date('Y-m-d', strtotime('-6 days')),
    'tanggal_akhir' => date('Y-m-d'),
]);
echo "labels: " . json_encode($grafik['labels']) . "\n";
echo "data: " . json_encode($grafik['series']['data']) . "\n";

echo "\n=== stokKategori (Produk::getAgregasiGrafik) ===\n";
$produk = new App\Models\Produk();
$stok = $produk->getAgregasiGrafik(['limit' => 8]);
echo "labels: " . json_encode($stok['labels']) . "\n";
echo "data: " . json_encode($stok['series']['data']) . "\n";
