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
use App\Models\Member;
use App\Models\Pembelian;
use App\Models\Pengaturan;
use App\Models\Produk;
use App\Models\Supplier;

Database::runSchema();
$pdo = Database::connect();

// Bersihkan data lama (urutan penting karena FK).
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'audit_log', 'shift_kasir', 'item_pembelian', 'pembelian', 'item_transaksi',
    'rekap_penjualan', 'transaksi', 'pembayaran', 'diskon', 'retur_barang', 'produk',
    'kategori', 'supplier', 'users', 'member', 'pengaturan', 'katalog_penukaran',
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
    'Makanan'    => ['Indomie Goreng', 3500, 48, '8991002101201', 3000, 20],
    'Minuman'    => ['Teh Botol', 5000, 30, '8991002101202', 4200, 15],
    'Snack'      => ['Keripik Kentang', 12000, 8, '8991002101203', 9500, 10],   // stok menipis
    'Rokok'      => ['Rokok Filter', 25000, 0, '8991002101204', 21000, 10],    // stok habis
    'Alat Tulis' => ['Pensil 2B', 3000, 15, '8991002101205', 1800, 5],
];

$produkList = [];
$produkIds = [];

foreach ($kategoriData as $namaKategori => [$namaProduk, $harga, $stok, $barcode, $hargaBeli, $stokMin]) {
    $kategori = new Kategori(['nama' => $namaKategori]);
    $kategoriId = $kategori->simpan();

    $produk = new Produk([
        'nama'         => $namaProduk,
        'harga'        => $harga,
        'stok'         => $stok,
        'kategori_id'  => $kategoriId,
        'barcode'      => $barcode,
        'harga_beli'   => $hargaBeli,
        'stok_minimum' => $stokMin,
    ]);
    $produk->simpan();
    $produkList[] = $namaProduk;
    $produkIds[$namaProduk] = (int) $produk->getId();
}

// Produk curah (satuan gram) — dibeli per berat via timbangan digital.
$kategoriBuah = new Kategori(['nama' => 'Buah']);
$kategoriBuahId = $kategoriBuah->simpan();

$produkGram = new Produk([
    'nama'           => 'Jeruk Peras',
    'harga'          => 0,
    'stok'           => 50000,   // 50 kg = 50.000 gram
    'kategori_id'    => $kategoriBuahId,
    'satuan'         => 'gram',
    'harga_per_gram' => 25,      // Rp 25/gram = Rp 25.000/kg
    'barcode'        => '8991002101206',
    'harga_beli'     => 15,      // Rp 15/gram
    'stok_minimum'   => 10000,   // 10 kg
]);
$produkGram->simpan();
$produkList[] = 'Jeruk Peras (per gram)';
$produkIds['Jeruk Peras (per gram)'] = (int) $produkGram->getId();

// ---- Supplier ----
$supplierData = [
    ['PT Sumber Jaya', '0812-3456-7890', 'Jl. Raya Industri No. 1, Jakarta'],
    ['CV Maju Bersama', '0813-2222-3333', 'Jl. Merdeka No. 45, Bandung'],
    ['UD Berkah Abadi', '0857-1111-2222', 'Kawasan Berdagang Blok C, Surabaya'],
];

$supplierIds = [];

foreach ($supplierData as [$nama, $kontak, $alamat]) {
    $supplier = new Supplier(['nama' => $nama, 'kontak' => $kontak, 'alamat' => $alamat]);
    $supplier->simpan();
    $supplierIds[] = (int) $supplier->getId();
}

// ---- Diskon (kode bermakna, unik) ----
(new Diskon(['kode' => 'DISC10', 'jenis' => 'persen', 'nilai' => 10]))->simpan();   // 10%
(new Diskon(['kode' => 'HEMAT2K', 'jenis' => 'nominal', 'nilai' => 2000]))->simpan(); // Rp 2.000

// ---- Pengaturan toko ----
\App\Models\Pengaturan::simpan([
    'nama_toko'    => 'Minimarket Plaza',
    'alamat'       => 'Jl. Sudirman No. 1, Jakarta',
    'telepon'      => '021-5551234',
    'footer_struk' => 'Terima kasih sudah berbelanja!',
    'pajak'        => '11',
    'pin_supervisor' => '1234',
]);

// ---- Member demo ----
$member = new \App\Models\Member([
    'nama'     => 'Siti Rahma',
    'telepon'  => '081298765432',
    'poin'     => 150,
    'password' => 'member123',
]);
$member->simpan();

// ---- Katalog penukaran poin (badge/hadiah member) ----
$pdo->prepare(
    'INSERT INTO katalog_penukaran (nama, poin, deskripsi) VALUES
     (:nama1, :poin1, :desk1),
     (:nama2, :poin2, :desk2),
     (:nama3, :poin3, :desk3)'
)->execute([
    ':nama1'  => 'Voucher Rp 10.000', ':poin1' => 100, ':desk1' => 'Voucher belanja senilai Rp 10.000',
    ':nama2'  => 'Voucher Rp 25.000', ':poin2' => 250, ':desk2' => 'Voucher belanja senilai Rp 25.000',
    ':nama3'  => 'Badge Member Gold', ':poin3' => 500, ':desk3' => 'Badge eksklusif member gold',
]);

// ---- Pembelian / stok masuk demo ----
$pembelian = new \App\Models\Pembelian([
    'supplier_id' => $supplierIds[0],
    'keterangan'  => 'Stok awal periode demo',
]);
$pembelian->simpan([
    ['produk_id' => $produkIds['Indomie Goreng'], 'qty' => 20, 'harga_beli' => 2900],
    ['produk_id' => $produkIds['Teh Botol'], 'qty' => 15, 'harga_beli' => 4100],
]);

echo "Seed selesai.\n";
echo "  - User kasir : kasir / kasir123\n";
echo "  - User admin : admin / admin123\n";
echo '  - Kategori  : ' . count($kategoriData) . " (+ Buah)\n";
echo '  - Produk    : ' . count($produkList) . ' (' . implode(', ', $produkList) . ")\n";
echo '  - Supplier  : ' . count($supplierData) . "\n";
echo "  - Diskon    : 2 (DISC10 = 10%, HEMAT2K = Rp 2.000)\n";
echo '  - Member    : 1 (' . $member->getNama() . ' / ' . $member->getNomorMember() . ' / password: member123)' . "\n";
echo "  - Katalog   : 3 hadiah tukar poin\n";
echo "  - Pembelian : 1 riwayat stok masuk (PT Sumber Jaya)\n";
echo "  - Pengaturan: nama toko 'Minimarket Plaza', pajak 11%\n";
