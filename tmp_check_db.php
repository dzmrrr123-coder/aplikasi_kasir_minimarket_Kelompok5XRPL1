<?php
require __DIR__ . '/src/autoload.php';
use App\Database\Database;
$pdo = Database::connect();
$count = $pdo->query('SELECT COUNT(*) FROM transaksi')->fetchColumn();
echo "Total transactions: $count\n\n";

$rows = $pdo->query('SELECT id, tanggal, total, kasir_id FROM transaksi ORDER BY id DESC LIMIT 5')->fetchAll();
foreach ($rows as $r) {
    echo "  id={$r['id']} tanggal={$r['tanggal']} total={$r['total']}\n";
}

echo "\nToday: " . date('Y-m-d') . "\n";
$today = date('Y-m-d');
$todayCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$today 00:00:00' AND tanggal < '$today 23:59:59'")->fetchColumn();
echo "Transactions today (>= mulai < akhir): $todayCount\n";

// Check the exact query used by Dashboard::ringkasanHariIni()
$mulai = $today . ' 00:00:00';
$akhir = $today . ' 23:59:59';
echo "Query range: $mulai to $akhir\n";

// Check if query uses < :akhir (exclusive) - then binding :akhir to $mulai would be a bug
$testCount = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$mulai' AND tanggal < '$mulai'")->fetchColumn();
echo "BUG TEST (tanggal >= mulai AND tanggal < mulai): $testCount\n";

// Correct query
$testCount2 = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$mulai' AND tanggal <= '$akhir'")->fetchColumn();
echo "CORRECT (tanggal >= mulai AND tanggal <= akhir): $testCount2\n";

// Also test with time range
$testCount3 = $pdo->query("SELECT COUNT(*) FROM transaksi WHERE tanggal >= '$mulai' AND tanggal <= '$akhir'")->fetchColumn();
echo "CORRECT (>= mulai AND <= akhir): $testCount3\n";
