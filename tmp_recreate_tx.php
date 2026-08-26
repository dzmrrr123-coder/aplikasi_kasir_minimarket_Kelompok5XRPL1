<?php
require __DIR__ . '/src/autoload.php';
use App\Database\Database;
use App\Models\Produk;
use App\Models\Transaksi;
use App\Models\PembayaranTunai;

$pdo = Database::connect();
// Clear and recreate
$pdo->exec('DELETE FROM item_transaksi');
$pdo->exec('DELETE FROM rekap_penjualan');
$pdo->exec('DELETE FROM pembayaran');
$pdo->exec('DELETE FROM transaksi');

$produk = Produk::cari(1); // Indomie Goreng
$tx = new Transaksi(['kasir_id' => 2]);
$tx->tambahItem($produk, 3);
$tx->hitungTotal();
$tx->prosesPembayaran(new PembayaranTunai(['jumlah' => 15000]));
echo "Created tx #{$tx->getId()} at " . $tx->getTanggal()->format('Y-m-d H:i:s') . "\n";

// Verify
$rows = $pdo->query("SELECT id, tanggal, total FROM transaksi")->fetchAll();
foreach ($rows as $r) echo "DB: id={$r['id']} tgl={$r['tanggal']} total={$r['total']}\n";
echo "Today: " . date('Y-m-d') . "\n";
