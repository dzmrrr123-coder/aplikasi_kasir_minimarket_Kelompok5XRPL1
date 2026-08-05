<?php

declare(strict_types=1);

/**
 * Seed data demo untuk aplikasi kasir minimarket.
 *
 * Mengisi: akun demo (kasir & admin), kategori, produk (termasuk stok
 * menipis/habis), supplier, dan diskon. Aman dijalankan berulang kali
 * (data lama dibersihkan dulu).
 *
 * Jalankan: php database/seed.php
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Diskon;
use App\Models\Kategori;
use App\Models\Produk;
use App\Models\Supplier;

Database::runSchema();
$pdo = Database::connect();

// Bersihkan data lama (urutan penting karena FK).
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'item_transaksi', 'transaksi', 'pembayaran', 'diskon',
    'retur_barang', 'produk', 'kategori', 'supplier', 'users',
] as $tabel) {
    $pdo->exec("TRUNCATE TABLE $tabel");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// ---- Akun demo ----
$stmt = $pdo->prepare(
    'INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :password, :role)'
);
$stmt->execute([
    ':nama'     => 'Kasir Demo',
    ':username' => 'kasir',
    ':password' => password_hash('kasir123', PASSWORD_DEFAULT),
    ':role'     => 'kasir',
]);
$stmt->execute([
    ':nama'     => 'Admin Demo',
    ':username' => 'admin',
    ':password' => password_hash('admin123', PASSWORD_DEFAULT),
    ':role'     => 'admin',
]);

// ---- Kategori ----
$kategoriData = [
    'Makanan'    => ['Indomie Goreng', 3500, 48],
    'Minuman'    => ['Teh Botol', 5000, 30],
    'Snack'      => ['Keripik Kentang', 12000, 8],   // stok menipis
    'Rokok'      => ['Rokok Filter', 25000, 0],      // stok habis
    'Alat Tulis' => ['Pensil 2B', 3000, 15],
];

$produkList = [];

foreach ($kategoriData as $namaKategori => [$namaProduk, $harga, $stok]) {
    $kategori = new Kategori(['nama' => $namaKategori]);
    $kategoriId = $kategori->simpan();

    $produk = new Produk([
        'nama'        => $namaProduk,
        'harga'       => $harga,
        'stok'        => $stok,
        'kategori_id' => $kategoriId,
    ]);
    $produk->simpan();
    $produkList[] = $namaProduk;
}

// ---- Supplier ----
$supplierData = [
    ['PT Sumber Jaya', '0812-3456-7890', 'Jl. Raya Industri No. 1, Jakarta'],
    ['CV Maju Bersama', '0813-2222-3333', 'Jl. Merdeka No. 45, Bandung'],
    ['UD Berkah Abadi', '0857-1111-2222', 'Kawasan Berdagang Blok C, Surabaya'],
];

foreach ($supplierData as [$nama, $kontak, $alamat]) {
    $supplier = new Supplier(['nama' => $nama, 'kontak' => $kontak, 'alamat' => $alamat]);
    $supplier->simpan();
}

// ---- Diskon (kode bermakna, unik) ----
(new Diskon(['kode' => 'DISC10', 'jenis' => 'persen', 'nilai' => 10]))->simpan();   // 10%
(new Diskon(['kode' => 'HEMAT2K', 'jenis' => 'nominal', 'nilai' => 2000]))->simpan(); // Rp 2.000

echo "Seed selesai.\n";
echo "  - User kasir : kasir / kasir123\n";
echo "  - User admin : admin / admin123\n";
echo '  - Kategori  : ' . count($kategoriData) . "\n";
echo '  - Produk    : ' . count($produkList) . ' (' . implode(', ', $produkList) . ")\n";
echo '  - Supplier  : ' . count($supplierData) . "\n";
echo "  - Diskon    : 2 (DISC10 = 10%, HEMAT2K = Rp 2.000)\n";
