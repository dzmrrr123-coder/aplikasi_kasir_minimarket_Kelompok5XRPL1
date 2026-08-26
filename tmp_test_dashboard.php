<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/src/autoload.php';

use App\Database\Database;
use App\Models\Produk;

try {
    $pdo = Database::connect();
    echo "Connected!\n";

    $totalProduk   = (int) $pdo->query("SELECT COUNT(*) FROM produk WHERE is_active = 1")->fetchColumn();
    $totalKasir    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'kasir' AND is_active = 1")->fetchColumn();
    $totalSupplier = (int) $pdo->query("SELECT COUNT(*) FROM supplier")->fetchColumn();
    $stokMenipis   = Produk::cariStokMenipis();

    echo "Produk: $totalProduk\n";
    echo "Kasir: $totalKasir\n";
    echo "Supplier: $totalSupplier\n";
    echo "Stok menipis: " . count($stokMenipis) . "\n";
    foreach (array_slice($stokMenipis, 0, 5) as $p) {
        echo "  - " . $p->getNama() . " (" . $p->getStok() . ")\n";
    }
    echo "OK\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
