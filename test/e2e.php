<?php

declare(strict_types=1);

/**
 * Uji end-to-end aplikasi kasir minimarket.
 * Setiap fitur diuji dengan jalur sukses DAN minimal $kasirId skenario gagal,
 * sesuai percabangan error yang didesain di sequence diagram:
 *  - transaksi: stok kurang -> item ditolak
 *  - produk: kategori invalid -> simpan ditolak
 *  - laporan: periode tanpa data -> pesan "tidak ada data"
 *  - retur: stok produk kurang / supplier invalid -> proses dibatalkan
 *  - pembayaran: jumlah tidak valid -> transaksi tidak jadi tersimpan
 *
 * Semua data uji dibersihkan di akhir.
 * Jalankan: php test/e2e.php
 */

require __DIR__ . '/../src/autoload.php';

use App\Database\Database;
use App\Models\Admin;
use App\Models\Dashboard;
use App\Models\DataReporter;
use App\Models\Diskon;
use App\Models\Kasir;
use App\Models\Kategori;
use App\Models\ItemTransaksi;
use App\Models\Laba;
use App\Models\LaporanPenjualan;
use App\Models\Member;
use App\Models\Pembelian;
use App\Models\PembayaranNonTunai;
use App\Models\PembayaranTunai;
use App\Models\PaymentMethod;
use App\Models\Pengaturan;
use App\Models\Produk;
use App\Models\ReturBarang;
use App\Models\ShiftKasir;
use App\Models\AuditLog;
use App\Models\Struk;
use App\Models\Supplier;
use App\Models\Transaksi;
use App\Models\User;

$lulus = 0;
$gagal = 0;

function assertTrue(bool $kondisi, string $nama): void
{
    global $lulus, $gagal;

    if ($kondisi) {
        $lulus++;
        echo "  [PASS] $nama\n";
    } else {
        $gagal++;
        echo "  [FAIL] $nama\n";
    }
}

function assertThrows(callable $fn, string $nama): void
{
    global $lulus, $gagal;

    try {
        $fn();
    } catch (Throwable $e) {
        $lulus++;
        echo "  [PASS] $nama (error: {$e->getMessage()})\n";

        return;
    }

    $gagal++;
    echo "  [FAIL] $nama (tidak melempar exception)\n";
}

try {
    Database::runSchema();
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal menyiapkan skema: ' . $e->getMessage() . "\n");
    exit($kasirId);
}

$pdo = Database::connect();

// Bersihkan data uji lama (urutan penting karena FK).
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'audit_log', 'shift_kasir', 'item_pembelian', 'pembelian', 'item_transaksi',
    'rekap_penjualan', 'transaksi', 'pembayaran', 'diskon', 'retur_barang', 'produk',
    'kategori', 'supplier', 'users', 'member', 'pengaturan', 'katalog_penukaran',
] as $tabel) {
    $pdo->exec("TRUNCATE TABLE $tabel");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

echo "== 1. Manajemen produk & kategori ==\n";

// User kasir dipakai sebagai kasir_id pada transaksi (FK ke tabel users).
$stmt = $pdo->prepare(
    'INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :password, :role)'
);
$stmt->execute([
    ':nama'     => 'Kasir Uji',
    ':username' => 'kasir_uji',
    ':password' => password_hash('rahasia', PASSWORD_DEFAULT),
    ':role'     => 'kasir',
]);
$kasirId = (int) $pdo->lastInsertId();

$kategori = new Kategori(['nama' => 'Makanan']);
$kategoriId = $kategori->simpan();
assertTrue($kategoriId > 0, 'simpan kategori sukses');

// Jalur gagal: kategori tidak valid (id kosong) -> simpan produk ditolak.
$produkInvalid = new Produk(['nama' => 'Snack A', 'harga' => 5000, 'stok' => 20]);
assertThrows(fn () => $produkInvalid->simpan(), 'produk dengan kategori invalid ditolak');

$produk = new Produk(['nama' => 'Snack A', 'harga' => 5000, 'stok' => 20, 'kategori_id' => $kategoriId]);
$produkId = $produk->simpan();
assertTrue($produkId > 0, 'simpan produk sukses');

$produkBaru = Produk::cari($produkId);
assertTrue($produkBaru !== null && $produkBaru->getNama() === 'Snack A', 'produk bisa dicari kembali');
assertTrue($produkBaru->getKategori()->getNama() === 'Makanan', 'kategori produk terisi');

echo "\n== 1b. CRUD kategori & produk (admin) ==\n";

// ---- Kategori: edit ----
$kategori->setNama('Makanan Ringan');
$kategori->perbarui();
$kategoriSetelahEdit = Kategori::cari($kategoriId);
assertTrue(
    $kategoriSetelahEdit !== null && $kategoriSetelahEdit->getNama() === 'Makanan Ringan',
    'kategori bisa diperbarui'
);

// ---- Kategori: hapus ditolak karena masih dipakai produk ----
$kategoriBaru = new Kategori(['nama' => 'Minuman']);
$kategoriMinumanId = $kategoriBaru->simpan();

// Produk pindah ke kategori Minuman dulu supaya kategori lama bisa dihapus.
$produkBaru->setKategori($kategoriBaru);
$produkBaru->perbarui();

// Hapus kategori lama (Makanan Ringan) yang sudah tidak dipakai -> sukses.
$kategori->hapus();
assertTrue(Kategori::cari($kategoriId) === null, 'kategori yang tidak dipakai bisa dihapus');

// ---- Kategori: hapus kategori yang masih dipakai produk -> ditolak ----
$produkGagalHapus = new Produk(['nama' => 'Air Mineral', 'harga' => 3000, 'stok' => 12, 'kategori_id' => $kategoriMinumanId]);
$produkGagalHapusId = $produkGagalHapus->simpan();
assertThrows(
    fn () => $kategoriBaru->hapus(),
    'hapus kategori yang masih dipakai produk ditolak'
);

// ---- Produk: edit (nama, harga, stok via setStok, kategori) ----
$produkGagalHapus->setNama('Air Mineral 600ml');
$produkGagalHapus->setHarga(3500);
$produkGagalHapus->setStok(8);
$produkGagalHapus->perbarui();

$produkSetelahEdit = Produk::cari($produkGagalHapusId);
assertTrue($produkSetelahEdit->getNama() === 'Air Mineral 600ml', 'produk bisa diperbarui namanya');
assertTrue($produkSetelahEdit->getHarga() === 3500.0, 'produk bisa diperbarui harganya');
assertTrue($produkSetelahEdit->getStok() === 8, 'produk bisa diperbarui stoknya (setStok)');

// ---- Produk: cekStokMenipis ----
assertTrue($produkSetelahEdit->cekStokMenipis(), 'produk stok 8 terdeteksi menipis');
$produkSetelahEdit->setStok(25);
$produkSetelahEdit->perbarui();
assertTrue(
    Produk::cari($produkGagalHapusId)->cekStokMenipis() === false,
    'produk stok 25 tidak terdeteksi menipis'
);

// ---- Produk: validasi gagal saat update ----
$produkInvalidUpdate = Produk::cari($produkGagalHapusId);
$produkInvalidUpdate->setNama('   ');
assertThrows(
    fn () => $produkInvalidUpdate->perbarui(),
    'update produk dengan nama kosong ditolak'
);

// ---- Produk: hapus ----
$produkSetelahEdit->hapus();
assertTrue(Produk::cari($produkGagalHapusId) === null, 'produk bisa dihapus');

// ---- Kategori: hapus kategori yang sekarang tidak dipakai ----
// Pindahkan Snack A ($produkBaru) ke kategori baru dulu, karena ia masih
// menunjuk ke kategori Minuman; kalau tidak, hapus kategori akan ditolak FK.
$kategoriPengganti = new Kategori(['nama' => 'Camilan']);
$kategoriPenggantiId = $kategoriPengganti->simpan();
$produkBaru->setKategori($kategoriPengganti);
$produkBaru->perbarui();

$kategoriBaru->hapus();
assertTrue(Kategori::cari($kategoriMinumanId) === null, 'kategori bisa dihapus setelah produknya pindah');

echo "\n== 2. Transaksi penjualan ==\n";

$kasir = new Kasir(['id' => $kasirId, 'nama' => 'Kasir Uji']);

// Jalur sukses: buat transaksi -> tambah item -> hitung total -> proses pembayaran.
$transaksi = new Transaksi(['kasir_id' => $kasirId]);
$transaksi->tambahItem($produkBaru, 3);
$total = $transaksi->hitungTotal();
assertTrue($total === 15000.0, 'total transaksi = 3 x 5000 = 15000');

$diskon = new Diskon(['jenis' => 'persen', 'nilai' => 10]);
$transaksi->terapkanDiskon($diskon);
assertTrue($transaksi->hitungTotal() === 13500.0, 'total setelah diskon 10% = 13500');

$pembayaran = new PembayaranTunai(['jumlah' => 20000]);
assertTrue($transaksi->prosesPembayaran($pembayaran), 'pembayaran tunai sukses');

assertTrue($transaksi->isSelesai(), 'transaksi ditandai selesai');
assertTrue($transaksi->getId() !== '', 'transaksi tersimpan dengan id');

// Cek stok berkurang setelah transaksi.
$produkSetelah = Produk::cari($produkId);
assertTrue($produkSetelah->getStok() === 17, 'stok berkurang dari 20 menjadi 17');

$struk = $kasir->cetakStruk($transaksi);
assertTrue(str_contains($struk->cetak(), 'Snack A'), 'struk memuat nama produk');
assertTrue(str_contains($struk->cetak(), 'Rp 13.500'), 'struk memuat total');

// Jalur gagal: stok kurang -> item ditolak.
$transaksiGagal = new Transaksi(['kasir_id' => $kasirId]);
assertThrows(
    fn () => $transaksiGagal->tambahItem($produkSetelah, 999),
    'tambah item dengan stok kurang ditolak'
);
assertTrue(count($transaksiGagal->getItems()) === 0, 'item gagal tidak masuk daftar transaksi');

// Jalur gagal: jumlah pembayaran non-tunai 0 -> transaksi tidak tersimpan.
$transaksiGagal2 = new Transaksi(['kasir_id' => $kasirId]);
$transaksiGagal2->tambahItem($produkSetelah, 1);
$transaksiGagal2->hitungTotal();
assertTrue(
    $transaksiGagal2->prosesPembayaran(new PembayaranNonTunai(['jumlah' => 0])) === false,
    'pembayaran non-tunai jumlah 0 ditolak'
);
assertTrue(!$transaksiGagal2->isSelesai(), 'transaksi tidak selesai saat pembayaran ditolak');

// Jalur sukses: pembayaran non-tunai dengan jumlah valid -> transaksi tersimpan.
$transaksiNonTunai = new Transaksi(['kasir_id' => $kasirId]);
$transaksiNonTunai->tambahItem($produkSetelah, 1);
$transaksiNonTunai->hitungTotal();
assertTrue(
    $transaksiNonTunai->prosesPembayaran(new PembayaranNonTunai(['jumlah' => 5000])) === true,
    'pembayaran non-tunai dengan jumlah valid berhasil'
);
assertTrue($transaksiNonTunai->isSelesai(), 'transaksi non-tunai ditandai selesai');
assertTrue($transaksiNonTunai->getId() !== '', 'transaksi non-tunai tersimpan dengan id');

// Jalur gagal: jumlah dibayar kurang dari total -> ditolak (bug fix).
$transaksiKurang = new Transaksi(['kasir_id' => $kasirId]);
$transaksiKurang->tambahItem($produkSetelah, 1);
$transaksiKurang->hitungTotal();
assertTrue(
    $transaksiKurang->prosesPembayaran(new PembayaranTunai(['jumlah' => 100])) === false,
    'pembayaran tunai kurang dari total ditolak'
);
assertTrue(!$transaksiKurang->isSelesai(), 'transaksi tidak selesai saat pembayaran kurang');

echo "\n== 2b. Strategy Pattern pembayaran ==\n";

// Polimorfisme: tiap strategi punya aturan prosesBayar sendiri.
$tunai = new PembayaranTunai(['jumlah' => 10000]);
$nonTunai = new PembayaranNonTunai(['jumlah' => 10000]);
assertTrue($tunai->prosesBayar(5000, 10000), 'strategi tunai: bayar >= total diterima');
assertTrue(!$tunai->prosesBayar(10000, 5000), 'strategi tunai: bayar < total ditolak');
assertTrue($nonTunai->prosesBayar(5000, 10000), 'strategi non-tunai: bayar >= total diterima');
assertTrue(!$nonTunai->prosesBayar(5000, 0), 'strategi non-tunai: jumlah 0 ditolak');

// Kembalian dihitung polimorfik: tunai selisih, non-tunai 0.
assertTrue($tunai->hitungKembalian(5000, 10000) === 5000.0, 'kembalian tunai = selisih');
assertTrue($nonTunai->hitungKembalian(5000, 10000) === 0.0, 'non-tunai tanpa kembalian');

// DI via setter: set strategi dulu, lalu prosesPembayaran() tanpa argumen.
$transaksiSetter = new Transaksi(['kasir_id' => $kasirId]);
$transaksiSetter->tambahItem($produkSetelah, 1);
$transaksiSetter->hitungTotal();
$transaksiSetter->setMetodePembayaran(new PembayaranTunai(['jumlah' => 100000]));
assertTrue($transaksiSetter->prosesPembayaran(), 'proses pembayaran via setter (DI) berhasil');
assertTrue(
    $transaksiSetter->getPembayaran() instanceof PaymentMethod,
    'strategi pembayaran terpasang (instanceof PaymentMethod)'
);

// Transaksi tanpa strategi pembayaran -> ditolak dengan pesan jelas.
$transaksiTanpaMetode = new Transaksi(['kasir_id' => $kasirId]);
$transaksiTanpaMetode->tambahItem($produkSetelah, 1);
$transaksiTanpaMetode->hitungTotal();
assertThrows(
    fn () => $transaksiTanpaMetode->prosesPembayaran(),
    'proses pembayaran tanpa strategi ditolak'
);

echo "\n== 2c. Observer Pattern pasca-transaksi ==\n";

// Observer Struk menyiapkan JSON setelah notify().
$transaksiObserver = new Transaksi(['kasir_id' => $kasirId]);
$transaksiObserver->tambahItem($produkSetelah, 2);
$transaksiObserver->hitungTotal();

$strukObserver = new Struk($transaksiObserver);
$laporanObserver = new LaporanPenjualan();
$transaksiObserver->attach($strukObserver);
$transaksiObserver->attach($laporanObserver);

// Sebelum diproses, JSON belum tersedia.
assertTrue($strukObserver->getJsonOutput() === null, 'struk observer belum punya JSON sebelum notify');

assertTrue(
    $transaksiObserver->prosesPembayaran(new PembayaranTunai(['jumlah' => 50000])),
    'transaksi dengan observer diproses sukses'
);

// notify() dipanggil otomatis -> JSON struk tersedia.
$json = $strukObserver->getJsonOutput();
assertTrue(is_array($json), 'notify() membuat JSON struk tersedia');
assertTrue(
    isset($json['kembalian']) && (float) $json['kembalian'] > 0,
    'JSON struk memuat kembalian'
);
assertTrue(
    isset($json['total']) && (float) $json['total'] > 0,
    'JSON struk memuat total'
);

// Observer LaporanPenjualan mencatat rekap ke database.
$rekap = Database::connect()->prepare(
    'SELECT COUNT(*) FROM rekap_penjualan WHERE transaksi_id = :id'
);
$rekap->execute([':id' => $transaksiObserver->getId()]);
assertTrue((int) $rekap->fetchColumn() === 1, 'laporan observer mencatat rekap ke database');

// detach: transaksi berikutnya tanpa observer Laporan tidak mencatat rekap.
$transaksiDetach = new Transaksi(['kasir_id' => $kasirId]);
$transaksiDetach->tambahItem($produkSetelah, 1);
$transaksiDetach->hitungTotal();
$strukDetach = new Struk($transaksiDetach);
$laporanDetach = new LaporanPenjualan();
$transaksiDetach->attach($strukDetach);
$transaksiDetach->attach($laporanDetach);
$transaksiDetach->detach($laporanDetach);

assertTrue(
    $transaksiDetach->prosesPembayaran(new PembayaranNonTunai(['jumlah' => 5000])),
    'transaksi dengan observer di-detach diproses sukses'
);

$rekap2 = Database::connect()->prepare(
    'SELECT COUNT(*) FROM rekap_penjualan WHERE transaksi_id = :id'
);
$rekap2->execute([':id' => $transaksiDetach->getId()]);
assertTrue((int) $rekap2->fetchColumn() === 0, 'observer yang di-detach tidak mencatat rekap');

echo "\n== 3. Batalkan transaksi ==\n";

$transaksiBatal = new Transaksi(['kasir_id' => $kasirId]);
$stokSebelumJualBatal = Produk::cari($produkId)->getStok();
$transaksiBatal->tambahItem($produkSetelah, 2);
$transaksiBatal->hitungTotal();
$transaksiBatal->prosesPembayaran(new PembayaranTunai(['jumlah' => 10000]));
$idBatal = $transaksiBatal->getId();

// Jalur sukses: batalkan -> stok dikembalikan ke nilai sebelum transaksi batal.
$transaksiBatal->batalkan();
$produkSetelahBatal = Produk::cari($produkId);
assertTrue(
    $produkSetelahBatal->getStok() === $stokSebelumJualBatal,
    'stok dikembalikan setelah transaksi dibatalkan'
);
assertTrue(Transaksi::cari((int) $idBatal) === null, 'transaksi terhapus dari database');

// Jalur gagal: batalkan transaksi yang belum tersimpan.
$transaksiBelumSimpan = new Transaksi(['kasir_id' => $kasirId]);
assertThrows(fn () => $transaksiBelumSimpan->batalkan(), 'batalkan transaksi belum tersimpan ditolak');

echo "\n== 4. Laporan penjualan ==\n";

// Jalur sukses: ada data di periode.
$laporan = new LaporanPenjualan();
$laporan->setPeriode(
    new DateTimeImmutable('2020-01-01'),
    new DateTimeImmutable('2099-12-31')
);
$hasil = $laporan->generate();
assertTrue($hasil['jumlah'] >= 1, 'laporan berisi minimal 1 transaksi');
assertTrue($hasil['total'] > 0, 'total laporan > 0');
$csvEkspor = $laporan->eksporPDF();
assertTrue(str_contains($csvEkspor, 'No. Transaksi'), 'ekspor laporan menghasilkan CSV');
assertTrue(
    str_contains($csvEkspor, $hasil['transaksi'][0]->getKasirNama()),
    'CSV laporan memuat nama kasir'
);

// Ringkasan akurat: jumlah & total laporan sesuai transaksi yang tersimpan.
$stored = Database::connect()->query(
    'SELECT COUNT(*) AS jumlah, COALESCE(SUM(total), 0) AS total FROM transaksi'
)->fetch();
assertTrue(
    (int) $hasil['jumlah'] === (int) $stored['jumlah'],
    'jumlah transaksi di laporan sesuai data tersimpan'
);
assertTrue(
    abs((float) $hasil['total'] - (float) $stored['total']) < 0.01,
    'total laporan sesuai total tersimpan di database'
);

// Isi daftar transaksi: setiap baris memuat id & total.
$transaksiLaporan = $hasil['transaksi'];
assertTrue(
    count($transaksiLaporan) === (int) $hasil['jumlah'],
    'daftar transaksi di laporan lengkap'
);
assertTrue(
    $transaksiLaporan[0]->getTotal() > 0 && $transaksiLaporan[0]->getId() !== '',
    'baris transaksi laporan memuat id dan total'
);
assertTrue(
    $transaksiLaporan[0]->getKasirNama() !== '',
    'laporan menampilkan nama kasir (bukan id)'
);

// Jalur gagal: periode tanpa data -> pesan "tidak ada data".
$laporanKosong = new LaporanPenjualan();
$laporanKosong->setPeriode(
    new DateTimeImmutable('2010-01-01'),
    new DateTimeImmutable('2010-01-02')
);
$hasilKosong = $laporanKosong->generate();
assertTrue($hasilKosong['jumlah'] === 0, 'laporan periode kosong berjumlah 0');
assertTrue(
    str_contains($hasilKosong['pesan'], 'Tidak ada data'),
    'pesan "tidak ada data" muncul saat periode kosong'
);

echo "\n== 4b. Dashboard analytics ==\n";

$ringkasan = Dashboard::ringkasanHariIni();
assertTrue((int) $ringkasan['jumlah'] >= 1, 'dashboard: jumlah transaksi hari ini >= 1');
assertTrue((float) $ringkasan['total'] > 0, 'dashboard: total penjualan hari ini > 0');
assertTrue((int) $ringkasan['item'] >= 1, 'dashboard: item terjual hari ini >= 1');

$penjualan7 = Dashboard::penjualan7Hari();
assertTrue(count($penjualan7) === 7, 'dashboard: penjualan 7 hari berisi 7 hari');
assertTrue(
    (float) $penjualan7[6]['total'] > 0,
    'dashboard: hari terakhir (hari ini) punya total penjualan'
);

$terlaris = Dashboard::produkTerlaris();
assertTrue(
    isset($terlaris[0]['qty']) && (int) $terlaris[0]['qty'] > 0,
    'dashboard: produk terlaris punya qty > 0'
);

$metode = Dashboard::metodePembayaran();
assertTrue(
    count($metode) >= 1 && isset($metode[0]['jenis']),
    'dashboard: metode pembayaran hari ini terisi'
);

echo "\n== 5. Supplier & retur barang ==\n";

// ---- Siapkan stok masuk (pembelian) sebagai asal retur ----
$supplier = new Supplier(['nama' => 'PT Sumber Jaya', 'kontak' => '0812-3456', 'alamat' => 'Jakarta']);
$supplierId = $supplier->simpan();
assertTrue($supplierId > 0, 'simpan supplier sukses');

// Produk yang mau diretur harus punya riwayat stok masuk dulu.
$supplier2 = new Supplier(['nama' => 'PT Sumber Lain', 'kontak' => '0812-0000', 'alamat' => 'Bandung']);
$supplier2Id = $supplier2->simpan();
$pembelianRetur = new Pembelian(['supplier_id' => $supplier2Id, 'keterangan' => 'Stok masuk utk retur']);
$pembelianReturId = $pembelianRetur->simpan([
    ['produk_id' => $produkId, 'qty' => 5, 'harga_beli' => 3000],
]);

// Jalur sukses: retur valid -> stok berkurang & retur tercatat.
$produkRetur = Produk::cari($produkId);
$stokSebelumRetur = $produkRetur->getStok();

$retur = new ReturBarang([
    'produk_id'    => $produkId,
    'supplier_id'  => $supplier2Id,
    'pembelian_id' => $pembelianReturId,
    'qty'          => 2,
    'alasan'       => 'Rusak',
]);
assertTrue($retur->prosesRetur(), 'retur sukses');

$produkSetelahRetur = Produk::cari($produkId);
assertTrue(
    $produkSetelahRetur->getStok() === $stokSebelumRetur - 2,
    'stok berkurang 2 setelah retur'
);

// Retur tersimpan merujuk pembelian asal.
$returTersimpan = ReturBarang::cari((int) $retur->getId());
assertTrue(
    $returTersimpan !== null && $returTersimpan->getPembelianId() === $pembelianReturId,
    'retur tercatat dengan pembelian asal (pembelian_id)'
);

// Jalur gagal 1: produk belum pernah ada stok masuk -> tidak bisa diretur.
$produkTanpaPembelian = new Produk([
    'nama'        => 'Produk Tanpa Beli',
    'harga'       => 1000,
    'stok'        => 10,
    'kategori_id' => (int) $kategoriPenggantiId,
]);
$produkTanpaPembelianId = $produkTanpaPembelian->simpan();
$returTanpaBeli = new ReturBarang([
    'produk_id'   => $produkTanpaPembelianId,
    'supplier_id' => $supplier2Id,
    'qty'         => 1,
    'alasan'      => 'Uji',
]);
assertThrows(
    fn () => $returTanpaBeli->prosesRetur(),
    'retur produk yang belum pernah ada stok masuk ditolak'
);

// Jalur gagal 2: supplier bukan asal pembelian -> ditolak.
$returSupplierSalah = new ReturBarang([
    'produk_id'    => $produkId,
    'supplier_id'  => $supplierId, // supplier lain, bukan asal pembelian
    'pembelian_id' => $pembelianReturId,
    'qty'          => 1,
    'alasan'       => 'Uji',
]);
assertThrows(
    fn () => $returSupplierSalah->prosesRetur(),
    'retur ke supplier yang bukan asal pembelian ditolak'
);

// Jalur gagal 3: stok produk kurang dari qty retur -> dibatalkan.
$returGagalStok = new ReturBarang([
    'produk_id'    => $produkId,
    'supplier_id'  => $supplier2Id,
    'pembelian_id' => $pembelianReturId,
    'qty'          => 9999,
    'alasan'       => 'Uji',
]);
assertThrows(fn () => $returGagalStok->prosesRetur(), 'retur dengan stok kurang ditolak');

// Pastikan stok tidak berubah dari skenario gagal di atas.
$produkAkhir = Produk::cari($produkId);
assertTrue(
    $produkAkhir->getStok() === $produkSetelahRetur->getStok(),
    'stok tidak berubah oleh retur yang gagal'
);

// ---- CRUD supplier ----
$supplier->setNama('PT Sumber Jaya Sejahtera');
$supplier->setKontak('0812-9999');
$supplier->setAlamat('Bekasi');
$supplier->perbarui();
$supplierSetelahEdit = Supplier::cari($supplierId);
assertTrue(
    $supplierSetelahEdit !== null
        && $supplierSetelahEdit->getNama() === 'PT Sumber Jaya Sejahtera'
        && $supplierSetelahEdit->getKontak() === '0812-9999'
        && $supplierSetelahEdit->getAlamat() === 'Bekasi',
    'supplier bisa diperbarui (nama, kontak, alamat)'
);

// Hapus supplier yang masih dipakai retur -> ditolak (FK RESTRICT).
$supplierAsalRetur = Supplier::cari($supplier2Id);
assertThrows(
    fn () => $supplierAsalRetur->hapus(),
    'hapus supplier yang masih dipakai retur ditolak'
);

echo "\n== 6. Kelola akun kasir ==\n";

// ---- Tambah kasir sukses ----
$kasirBaruId = User::simpanKasir([
    'nama'     => 'Kasir Tambahan',
    'username' => 'kasir_tambah',
    'password' => 'rahasia1',
]);
assertTrue($kasirBaruId > 0, 'tambah kasir sukses');

$kasirBaru = User::cariKasir($kasirBaruId);
assertTrue(
    $kasirBaru !== null
        && $kasirBaru->getNama() === 'Kasir Tambahan'
        && $kasirBaru->getUsername() === 'kasir_tambah',
    'kasir baru tersimpan dan bisa dicari'
);

// ---- Validasi: username duplikat ditolak ----
assertThrows(
    fn () => User::simpanKasir([
        'nama'     => 'Duplikat',
        'username' => 'kasir_tambah',
        'password' => 'rahasia1',
    ]),
    'tambah kasir dengan username duplikat ditolak'
);

// ---- Validasi: password < 6 karakter ditolak ----
assertThrows(
    fn () => User::simpanKasir([
        'nama'     => 'Password Pendek',
        'username' => 'kasir_pendek',
        'password' => '123',
    ]),
    'tambah kasir dengan password < 6 karakter ditolak'
);

// ---- Edit kasir (nama & username) ----
User::perbaruiKasir($kasirBaruId, [
    'nama'     => 'Kasir Tambahan Baru',
    'username' => 'kasir_tambah2',
]);
$kasirSetelahEdit = User::cariKasir($kasirBaruId);
assertTrue(
    $kasirSetelahEdit !== null
        && $kasirSetelahEdit->getNama() === 'Kasir Tambahan Baru'
        && $kasirSetelahEdit->getUsername() === 'kasir_tambah2',
    'kasir bisa diperbarui (nama, username)'
);

// ---- Hapus kasir tanpa transaksi: sukses ----
User::hapusKasir($kasirBaruId);
assertTrue(User::cariKasir($kasirBaruId) === null, 'hapus kasir tanpa transaksi sukses');

// ---- Hapus kasir yang masih punya transaksi: ditolak ----
assertThrows(
    fn () => User::hapusKasir($kasirId),
    'hapus kasir yang masih punya transaksi ditolak'
);
assertTrue(User::cariKasir($kasirId) !== null, 'kasir dengan transaksi tetap ada setelah hapus ditolak');

// ---- Reset password kasir ----
User::resetPasswordKasir($kasirId, 'password_baru');
$kasirLoginUlang = new Kasir();
assertTrue(
    $kasirLoginUlang->login('kasir_uji', 'password_baru'),
    'reset password: kasir bisa login dengan password baru'
);

echo "\n== 6b. Diskon dengan kode ==\n";

// Diskon dengan kode bermakna.
$diskonKode = new Diskon(['kode' => 'DISC10', 'jenis' => 'persen', 'nilai' => 10]);
$diskonKodeId = $diskonKode->simpan();
assertTrue($diskonKodeId > 0, 'simpan diskon dengan kode sukses');

$ditemukan = Diskon::cariBerdasarkanKode('disc10'); // case-insensitive
assertTrue(
    $ditemukan !== null && $ditemukan->getId() === (string) $diskonKodeId,
    'diskon bisa dicari berdasarkan kode (case-insensitive)'
);

assertTrue(Diskon::cariBerdasarkanKode('TIDAK_ADA') === null, 'kode diskon tidak dikenal ditolak');

$diskonKode2 = new Diskon(['kode' => 'HEMAT2K', 'jenis' => 'nominal', 'nilai' => 2000]);
$diskonKode2->simpan();
assertTrue(
    Diskon::cariBerdasarkanKode('HEMAT2K') !== null,
    'diskon nominal bisa dicari berdasarkan kode'
);

echo "\n== 6c. CRUD diskon ==\n";

// Tambah diskon sukses.
$diskonBaru = new Diskon(['kode' => 'MURAH50', 'jenis' => 'persen', 'nilai' => 50]);
$diskonBaruId = $diskonBaru->simpan();
assertTrue($diskonBaruId > 0, 'tambah diskon sukses');

// Edit diskon.
$diskonBaru->setNilai(25);
$diskonBaru->perbarui();
$diskonSetelahEdit = Diskon::cari($diskonBaruId);
assertTrue(
    $diskonSetelahEdit !== null && $diskonSetelahEdit->getNilai() === 25.0,
    'diskon bisa diperbarui (nilai)'
);

// Validasi: kode kosong ditolak.
assertThrows(
    fn () => (new Diskon(['kode' => '', 'jenis' => 'persen', 'nilai' => 10]))->simpan(),
    'diskon dengan kode kosong ditolak'
);

// Validasi: nilai <= 0 ditolak.
assertThrows(
    fn () => (new Diskon(['kode' => 'NOL', 'jenis' => 'nominal', 'nilai' => 0]))->simpan(),
    'diskon dengan nilai 0 ditolak'
);

// Validasi: persen > 100 ditolak.
assertThrows(
    fn () => (new Diskon(['kode' => 'BESAR', 'jenis' => 'persen', 'nilai' => 150]))->simpan(),
    'diskon persen > 100 ditolak'
);

// Hapus diskon sukses.
$diskonBaru->hapus();
assertTrue(Diskon::cari($diskonBaruId) === null, 'diskon bisa dihapus');

echo "\n== 7. Login & logout ==\n";

$stmt = $pdo->prepare('INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :password, :role)');
$stmt->execute([
    ':nama'     => 'Admin Uji',
    ':username' => 'admin_uji',
    ':password' => password_hash('rahasia', PASSWORD_DEFAULT),
    ':role'     => 'admin',
]);

$admin = new Admin();
assertTrue($admin->login('admin_uji', 'rahasia'), 'login dengan password benar sukses');
assertTrue($admin->getNama() === 'Admin Uji', 'data user terisi setelah login');

// ---- Inheritance & Overriding: getHakAkses ----
$hakAdmin = $admin->getHakAkses();
assertTrue(in_array('kelola_diskon', $hakAdmin, true), 'admin punya izin kelola diskon');
assertTrue(in_array('retur', $hakAdmin, true), 'admin punya izin retur');
assertTrue(in_array('kelola_user', $hakAdmin, true), 'admin punya izin kelola user');
assertTrue(in_array('transaksi', $hakAdmin, true), 'admin punya izin transaksi');

// ---- Login polimorfik: admin -> objek Admin spesifik ----
$userAdmin = User::loginPolimorfik('admin_uji', 'rahasia');
assertTrue($userAdmin instanceof Admin, 'login polimorfik admin mengembalikan objek Admin');
assertTrue(!$userAdmin instanceof Kasir, 'login admin bukan objek Kasir');

// ---- Login polimorfik: kasir -> objek Kasir spesifik ----
$userKasir = User::loginPolimorfik('kasir_uji', 'password_baru');
assertTrue($userKasir instanceof Kasir, 'login polimorfik kasir mengembalikan objek Kasir');
assertTrue(!$userKasir instanceof Admin, 'login kasir bukan objek Admin');
$hakKasir = $userKasir->getHakAkses();
assertTrue(in_array('transaksi', $hakKasir, true), 'kasir punya izin transaksi');
assertTrue(!in_array('kelola_diskon', $hakKasir, true), 'kasir TIDAK punya izin kelola diskon');
assertTrue(!in_array('retur', $hakKasir, true), 'kasir TIDAK punya izin retur');

// Login polimorfik gagal -> null.
assertTrue(User::loginPolimorfik('admin_uji', 'salah') === null, 'login polimorfik password salah -> null');

// Jalur gagal: password salah.
$adminSalah = new Admin();
assertTrue(!$adminSalah->login('admin_uji', 'salah'), 'login dengan password salah ditolak');

$admin->logout();
assertTrue($admin->getId() === '', 'logout mengosongkan sesi');

echo "\n== 8. DataReporter & Controller PBO ==\n";

// ---- Polimorfisme: model implement DataReporter ----
$laporanReporter = new LaporanPenjualan();
assertTrue($laporanReporter instanceof DataReporter, 'LaporanPenjualan implements DataReporter');
assertTrue((new Produk()) instanceof DataReporter, 'Produk implements DataReporter');
assertTrue((new ReturBarang()) instanceof DataReporter, 'ReturBarang implements DataReporter');
assertTrue((new Supplier()) instanceof DataReporter, 'Supplier implements DataReporter');

// ---- getAgregasiGrafik: data grafik lengkap ----
$grafik = $laporanReporter->getAgregasiGrafik([
    'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')),
    'tanggal_akhir' => date('Y-m-d'),
]);
assertTrue(is_array($grafik['labels'] ?? null), 'grafik punya labels');
assertTrue(is_array($grafik['series']['data'] ?? null), 'grafik punya series.data');
assertTrue(
    isset($grafik['series']['data']) && array_sum($grafik['series']['data']) > 0,
    'grafik penjualan berisi total > 0'
);

// ---- getDataTabel: tabel transaksi ----
$tabel = $laporanReporter->getDataTabel([
    'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')),
    'tanggal_akhir' => date('Y-m-d'),
    'start'  => 0,
    'length' => 10,
]);
assertTrue(isset($tabel['total']) && $tabel['total'] >= 1, 'tabel transaksi punya total >= 1');
assertTrue(count($tabel['rows']) >= 1, 'tabel transaksi punya baris');
assertTrue(
    isset($tabel['rows'][0]['kasir_nama']) && $tabel['rows'][0]['kasir_nama'] !== '',
    'baris tabel memuat nama kasir'
);

// ---- getDataTabel Produk (inventaris) ----
$tabelProduk = (new Produk())->getDataTabel(['search' => '', 'start' => 0, 'length' => 10]);
assertTrue($tabelProduk['total'] >= 1, 'tabel produk punya total >= 1');
assertTrue(isset($tabelProduk['rows'][0]['kategori']), 'baris produk memuat kategori');

// ---- getDataTabel Retur (search) ----
$tabelRetur = (new ReturBarang())->getDataTabel(['search' => '', 'start' => 0, 'length' => 10]);
assertTrue($tabelRetur['total'] >= 1, 'tabel retur punya total >= 1');

// ---- getDataTabel Supplier ----
$tabelSupplier = (new Supplier())->getDataTabel(['search' => '', 'start' => 0, 'length' => 10]);
assertTrue($tabelSupplier['total'] >= 1, 'tabel supplier punya total >= 1');
assertTrue(isset($tabelSupplier['rows'][0]['nama']), 'baris supplier memuat nama');
$grafikSupplier = (new Supplier())->getAgregasiGrafik();
assertTrue($grafikSupplier['labels'] === ['Supplier'], 'grafik supplier punya label Supplier');

// ---- Controller PBO: dataTabelTransaksi mereturn array utk JSON ----
$laporanCtrl = new \App\Controllers\LaporanController();
$ctrlData = $laporanCtrl->dataTabelTransaksi([
    'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')),
    'tanggal_akhir' => date('Y-m-d'),
    'search' => '',
    'start'  => 0,
    'length' => 10,
]);
assertTrue(isset($ctrlData['rows']) && is_array($ctrlData['rows']), 'controller punya rows');
assertTrue((int) ($ctrlData['total'] ?? 0) >= 1, 'controller total >= 1');

// ---- api.php (front-controller) mereturn JSON valid ----
// Dipanggil via subprocess agar perilaku exit/header tidak menghentikan e2e.
$cmd = 'php -r ' . escapeshellarg(
    "session_start(); \$_SESSION['user_id']=1; \$_SESSION['role']='admin'; " .
    "\$_SERVER['REQUEST_METHOD']='GET'; \$_GET['aksi']='laporan.tabel'; " .
    "\$_GET['tanggal_mulai']='" . date('Y-m-d', strtotime('-30 days')) . "'; " .
    "\$_GET['tanggal_akhir']='" . date('Y-m-d') . "'; \$_GET['draw']=1; " .
    "require 'C:/laragon/www/kasir-minimarket/public/api.php';"
);
$apiOut = shell_exec($cmd);
$apiJson = json_decode((string) $apiOut, true);
assertTrue(is_array($apiJson), 'api.php mereturn JSON valid');
assertTrue(isset($apiJson['data']) && is_array($apiJson['data']), 'api.php tabel punya data array');
assertTrue((int) ($apiJson['recordsTotal'] ?? 0) >= 1, 'api.php recordsTotal >= 1');

echo "\n== 9. Produk curah (gram) & qty float ==\n";

// Produk gram: wajib harga_per_gram > 0.
$katGram = new Kategori(['nama' => 'Buah Uji']);
$katGramId = $katGram->simpan();

assertThrows(
    fn () => (new Produk(['nama' => 'Gram Tanpa Harga', 'harga' => 0, 'stok' => 1000, 'kategori_id' => $katGramId, 'satuan' => 'gram', 'harga_per_gram' => 0]))->simpan(),
    'produk gram tanpa harga_per_gram ditolak'
);

$produkGram = new Produk([
    'nama'           => 'Apel Uji',
    'harga'          => 0,
    'stok'           => 20000,
    'kategori_id'    => $katGramId,
    'satuan'         => 'gram',
    'harga_per_gram' => 100,
]);
$produkGramId = $produkGram->simpan();
assertTrue($produkGramId > 0, 'simpan produk gram sukses');

$produkGramCari = Produk::cari($produkGramId);
assertTrue($produkGramCari->getSatuan() === 'gram', 'produk gram terbaca satuan gram');
assertTrue($produkGramCari->getHargaEfektif() === 100.0, 'getHargaEfektif = harga_per_gram utk gram');

// Transaksi qty float (gram) -> subtotal = harga_per_gram x qty.
$transaksiGram = new Transaksi(['kasir_id' => $kasirId]);
$transaksiGram->tambahItem($produkGramCari, 1250.5); // 1.250,5 gram
$totalGram = $transaksiGram->hitungTotal();
assertTrue(abs($totalGram - (100 * 1250.5)) < 0.01, 'total transaksi gram = harga_per_gram x qty float');

assertTrue(
    $transaksiGram->prosesPembayaran(new PembayaranTunai(['jumlah' => 200000])),
    'transaksi gram bisa diproses'
);
assertTrue(
    abs(ItemTransaksi::untukTransaksi((int) $transaksiGram->getId())[0]->getQty() - 1250.5) < 0.01,
    'qty float (gram) tersimpan di item_transaksi'
);

echo "\n== 10. Fitur v2 (pengaturan, barcode, pembelian, laba, member, soft-delete) ==\n";

// Kategori valid yang masih ada di titik ini (kategori dari bagian 1 bisa
// sudah dihapus di akhir tes CRUD produk/kategori).
$kategoriValid = Kategori::semua()[0];
$kategoriValidId = (int) $kategoriValid->getId();

// ---- Pengaturan toko ----
Pengaturan::simpan([
    'nama_toko'    => 'Minimarket Plaza Uji',
    'alamat'       => 'Jl. Test No. 1',
    'telepon'      => '021-000',
    'footer_struk' => 'Terima kasih!',
    'pajak'        => '11',
]);
$semuaSet = Pengaturan::semua();
assertTrue(($semuaSet['nama_toko'] ?? '') === 'Minimarket Plaza Uji', 'pengaturan simpan & baca (semua)');
assertTrue(Pengaturan::get('pajak', '0') === '11', 'pengaturan get dengan nilai');
assertTrue(Pengaturan::get('kunci_tidak_ada', 'dflt') === 'dflt', 'pengaturan get fallback default');

// Struk memakai nama toko & footer dari pengaturan.
$strukPengaturan = new Struk($transaksiGram);
$teksStruk = $strukPengaturan->cetak();
assertTrue(str_contains($teksStruk, 'MINIMARKET PLAZA UJI'), 'struk memakai nama toko dari pengaturan');
assertTrue(str_contains($teksStruk, 'Terima kasih!'), 'struk memakai footer dari pengaturan');

// ---- Barcode produk ----
$produkBarcode = new Produk([
    'nama'         => 'Produk Barcode Uji',
    'harga'        => 5000,
    'stok'         => 10,
    'kategori_id'  => $kategoriValidId,
    'barcode'      => '8991002101234',
    'harga_beli'   => 4000,
    'stok_minimum' => 5,
]);
$produkBarcodeId = $produkBarcode->simpan();
$cariBarcode = Produk::cariBerdasarkanBarcode('8991002101234');
assertTrue($cariBarcode !== null && $cariBarcode->getId() === $produkBarcode->getId(), 'cari produk by barcode');
assertTrue($cariBarcode->getHargaBeli() === 4000.0, 'produk menyimpan harga_beli');
assertTrue($cariBarcode->getStokMinimum() === 5, 'produk menyimpan stok_minimum');
assertTrue(Produk::cariBerdasarkanBarcode('000') === null, 'barcode tidak dikenal -> null');

assertThrows(
    fn () => (new Produk(['nama' => 'Dup', 'harga' => 1, 'stok' => 1, 'kategori_id' => $kategoriValidId, 'barcode' => '8991002101234']))->simpan(),
    'barcode duplikat ditolak'
);

// ---- Pembelian / stok masuk ----
$supplierPembelian = new Supplier(['nama' => 'Supplier Uji', 'kontak' => '081', 'alamat' => 'Jl. Uji']);
$supplierPembelianId = $supplierPembelian->simpan();
$stokAwalProdukBarcode = Produk::cari($produkBarcodeId)->getStok();

$pembelian = new Pembelian([
    'supplier_id' => $supplierPembelianId,
    'keterangan'  => 'Restock uji',
]);
$pembelianId = $pembelian->simpan([
    ['produk_id' => $produkBarcodeId, 'qty' => 10, 'harga_beli' => 3000],
]);
assertTrue($pembelianId > 0, 'simpan pembelian sukses');
assertTrue(abs($pembelian->getTotal() - (10 * 3000)) < 0.01, 'total pembelian = qty x harga beli');

$produkSetelahBeli = Produk::cari($produkBarcodeId);
assertTrue($produkSetelahBeli->getStok() === $stokAwalProdukBarcode + 10, 'stok produk bertambah setelah pembelian');
assertTrue($produkSetelahBeli->getHargaBeli() === 3000.0, 'harga_beli produk diperbarui dari pembelian');

assertThrows(
    fn () => (new Pembelian(['supplier_id' => $supplierPembelianId]))->simpan([['produk_id' => $produkBarcodeId, 'qty' => 0, 'harga_beli' => 100]]),
    'pembelian qty 0 ditolak'
);

// ---- Laporan laba ----
// HPP transaksi gram sebelumnya: harga beli produk gram saat itu (default 0), omzet 125.050
$laba = new Laba();
$ringkasan = $laba->ringkasan(['tanggal_mulai' => date('Y-m-01'), 'tanggal_akhir' => date('Y-m-d')]);
assertTrue($ringkasan['omzet'] > 0, 'ringkasan laba: omzet > 0');
assertTrue(isset($ringkasan['hpp'], $ringkasan['laba'], $ringkasan['margin']), 'ringkasan laba punya hpp/laba/margin');

$tabelLaba = $laba->getDataTabel(['tanggal_mulai' => date('Y-m-01'), 'tanggal_akhir' => date('Y-m-d')]);
assertTrue(count($tabelLaba['rows']) > 0, 'tabel laba per transaksi terisi');

// ---- Member & poin ----
$member = new Member(['nama' => 'Budi Uji', 'telepon' => '081234567890', 'poin' => 50, 'password' => 'rahasia123']);
$memberId = $member->simpan();
assertTrue($memberId > 0, 'simpan member sukses');

// Nomor member dibuat otomatis (format MEM-XXXXXX) & unik.
assertTrue(str_starts_with($member->getNomorMember(), 'MEM-'), 'nomor member otomatis format MEM-XXXXXX');
assertTrue(Member::cariBerdasarkanNomor($member->getNomorMember()) !== null, 'cari member by nomor member');
assertTrue(Member::cariBerdasarkanTelepon('081234567890') !== null, 'cari member by telepon');

// Login member: pakai nomor member atau telepon + password.
assertTrue(Member::login($member->getNomorMember(), 'rahasia123') !== null, 'login member pakai nomor member sukses');
assertTrue(Member::login('081234567890', 'rahasia123') !== null, 'login member pakai telepon sukses');
assertTrue(Member::login($member->getNomorMember(), 'salah') === null, 'login member password salah ditolak');
assertTrue(Member::login('TIDAK_ADA', 'rahasia123') === null, 'login member identitas tidak dikenal ditolak');

// Reset password member via setPassword.
$memberSet = Member::cari($memberId);
$memberSet->setPassword('baru456');
assertTrue(Member::login('081234567890', 'baru456') !== null, 'member bisa login dengan password baru');
assertTrue(Member::login('081234567890', 'rahasia123') === null, 'password lama member tidak berlaku');
$memberSet->setPassword('rahasia123');

assertThrows(
    fn () => (new Member(['nama' => 'X', 'telepon' => '081234567890']))->simpan(),
    'telepon member duplikat ditolak'
);

// Transaksi member -> poin bertambah (1 poin / Rp 1.000).
$transaksiMember = new Transaksi(['kasir_id' => $kasirId]);
$transaksiMember->setMemberId($memberId);
$transaksiMember->tambahItem($produkSetelahBeli, 2); // harga 5000 x 2 = 10.000
$transaksiMember->prosesPembayaran(new PembayaranTunai(['jumlah' => 20000]));
$memberSetelah = Member::cari($memberId);
assertTrue($memberSetelah->getPoin() >= 60, 'poin member bertambah setelah transaksi member');
assertTrue($transaksiMember->getMemberNama() === 'Budi Uji', 'nama member terbaca dari transaksi');

$strukMember = new Struk($transaksiMember);
assertTrue(str_contains($strukMember->cetak(), 'Budi Uji'), 'struk menampilkan nama member');

// ---- Katalog penukaran poin ----
$pdo = Database::connect();
$pdo->prepare('INSERT INTO katalog_penukaran (nama, poin) VALUES (:nama, :poin)')
    ->execute([':nama' => 'Voucher Uji', ':poin' => 100]);
$hadiahId = (int) $pdo->lastInsertId();

assertTrue(count(Member::katalogHadiah()) >= 1, 'katalog hadiah terisi');

// Pastikan saldo cukup utk hadiah 100 poin (poin member saat ini 60).
$memberCukup = Member::cari($memberId);
$memberCukup->setPoin(150);
$memberCukup->perbarui();

// Tukar poin sukses: poin berkurang sesuai biaya hadiah.
$poinSebelumTukar = Member::cari($memberId)->getPoin();
Member::tukarPoin($memberId, $hadiahId);
assertTrue(
    Member::cari($memberId)->getPoin() === $poinSebelumTukar - 100,
    'poin berkurang 100 setelah tukar hadiah'
);

// Tukar poin gagal: poin tidak cukup (sisa 50 < 100).
assertThrows(
    fn () => Member::tukarPoin($memberId, $hadiahId),
    'tukar poin dengan saldo kurang ditolak'
);

// Tukar poin gagal: hadiah tidak ditemukan.
assertThrows(
    fn () => Member::tukarPoin($memberId, 999999),
    'tukar poin hadiah tidak dikenal ditolak'
);

// ---- Soft-delete user ----
$kasirSoft = User::simpanKasir(['nama' => 'Kasir Soft', 'username' => 'kasirsoft', 'password' => 'rahasia1']);
assertTrue(User::loginPolimorfik('kasirsoft', 'rahasia1') !== null, 'kasir baru bisa login');
User::setStatusAktifKasir($kasirSoft, false);
assertTrue(User::loginPolimorfik('kasirsoft', 'rahasia1') === null, 'kasir nonaktif ditolak login');
User::setStatusAktifKasir($kasirSoft, true);
assertTrue(User::loginPolimorfik('kasirsoft', 'rahasia1') !== null, 'kasir diaktifkan lagi bisa login');
User::hapusKasir($kasirSoft);

// ---- Stok menipis dengan stok_minimum ----
$produkMin = new Produk(['nama' => 'Produk Min Uji', 'harga' => 1000, 'stok' => 20, 'kategori_id' => $kategoriValidId, 'stok_minimum' => 25]);
$produkMinId = $produkMin->simpan();
$namaMenipis = array_map(static fn ($p) => $p->getNama(), Produk::cariStokMenipis());
assertTrue(in_array('Produk Min Uji', $namaMenipis, true), 'produk di bawah stok_minimum terdeteksi menipis');

echo "\n== 11. Debug QA: akurasi uang, pajak, poin, HPP, CSRF ==\n";

// ---- Diskon tidak boleh dobel ----
Pengaturan::simpan(['pajak' => '0']);
$produkDis = new Produk(['nama' => 'Produk Diskon', 'harga' => 100000, 'stok' => 100, 'kategori_id' => $kategoriValidId, 'harga_beli' => 60000]);
$produkDisId = $produkDis->simpan();
$diskon10 = new Diskon(['kode' => 'DIS10UJI', 'jenis' => 'persen', 'nilai' => 10]);
$diskon10Id = $diskon10->simpan();

$tDis = new Transaksi(['kasir_id' => $kasirId]);
$tDis->tambahItem(Produk::cari((int) $produkDisId), 1);
$tDis->terapkanDiskon(Diskon::cari((int) $diskon10Id));
$tDis->hitungTotal();
$tDis->hitungTotal(); // panggil 2x — harus tetap 90.000 (bukan 81.000)
assertTrue(abs($tDis->getTotal() - 90000.0) < 0.01, 'diskon TIDAK dobel saat hitungTotal dipanggil 2x');
$tDis->prosesPembayaran(new PembayaranTunai(['jumlah' => 100000]));
assertTrue(abs(Transaksi::cari((int) $tDis->getId())->getTotal() - 90000.0) < 0.01, 'total tersimpan = 1x diskon (90.000)');

// ---- Pajak masuk total & validasi ----
Pengaturan::simpan(['pajak' => '11']);
$tPajak = new Transaksi(['kasir_id' => $kasirId]);
$tPajak->tambahItem(Produk::cari((int) $produkDisId), 1); // 100.000
$tPajak->hitungTotal();
assertTrue(abs($tPajak->getTotal() - 111000.0) < 0.01, 'total termasuk PPN 11% (111.000)');
assertTrue(abs($tPajak->getPajak() - 11000.0) < 0.01, 'nilai pajak 11.000');
assertTrue(!$tPajak->prosesPembayaran(new PembayaranTunai(['jumlah' => 100000])), 'bayar kurang dari total + pajak DITOLAK');
$tPajak->prosesPembayaran(new PembayaranTunai(['jumlah' => 120000]));
assertTrue(abs(Transaksi::cari((int) $tPajak->getId())->getTotal() - 111000.0) < 0.01, 'total tersimpan termasuk pajak');
Pengaturan::simpan(['pajak' => '0']);

// ---- Poin dikembalikan saat batalkan ----
$memberPoin = new Member(['nama' => 'Poin Uji', 'telepon' => '085700000001', 'poin' => 10]);
$memberPoinId = $memberPoin->simpan();
$tPoin = new Transaksi(['kasir_id' => $kasirId]);
$tPoin->setMemberId((int) $memberPoinId);
$tPoin->tambahItem(Produk::cari((int) $produkDisId), 2); // 200.000 -> +200 poin
$tPoin->prosesPembayaran(new PembayaranTunai(['jumlah' => 200000]));
assertTrue(Member::cari((int) $memberPoinId)->getPoin() === 210, 'poin member bertambah setelah transaksi');
$tPoin->batalkan();
assertTrue(Member::cari((int) $memberPoinId)->getPoin() === 10, 'poin member kembali setelah batalkan');

// ---- HPP historis (laba tidak berubah saat harga_beli diubah) ----
$produkHpp = Produk::cari((int) $produkDisId); // harga_beli 60.000
$tHpp = new Transaksi(['kasir_id' => $kasirId]);
$tHpp->tambahItem($produkHpp, 1);
$tHpp->prosesPembayaran(new PembayaranTunai(['jumlah' => 100000]));

// Ubah harga beli produk setelah transaksi.
$produkHpp->setHargaBeli(70000);
$produkHpp->perbarui();

$labaHpp = (new Laba())->ringkasan(['tanggal_mulai' => date('Y-m-01'), 'tanggal_akhir' => date('Y-m-d')]);
// HPP transaksi $tHpp harus pakai snapshot 60.000, bukan 70.000.
$itemHpp = ItemTransaksi::untukTransaksi((int) $tHpp->getId())[0];
assertTrue(abs($itemHpp->getHargaBeliSatuan() - 60000.0) < 0.01, 'item_transaksi menyimpan snapshot harga_beli (HPP historis)');
assertTrue($labaHpp['hpp'] > 0, 'ringkasan laba tetap punya HPP setelah harga beli berubah');

// ---- Struk produk gram pakai harga per gram ----
$produkGramStruk = new Produk(['nama' => 'Gram Struk', 'harga' => 0, 'stok' => 5000, 'kategori_id' => $kategoriValidId, 'satuan' => 'gram', 'harga_per_gram' => 100]);
$produkGramStrukId = $produkGramStruk->simpan();
$tGram = new Transaksi(['kasir_id' => $kasirId]);
$tGram->tambahItem(Produk::cari((int) $produkGramStrukId), 100);
$tGram->prosesPembayaran(new PembayaranTunai(['jumlah' => 20000]));
$strukGram = (new Struk($tGram))->cetak();
assertTrue(str_contains($strukGram, 'x Rp 100'), 'struk gram menampilkan harga per gram (bukan Rp 0)');

// ---- Merge duplikat produk di pembelian ----
$produkMerge = new Produk(['nama' => 'Produk Merge', 'harga' => 5000, 'stok' => 0, 'kategori_id' => $kategoriValidId]);
$produkMergeId = $produkMerge->simpan();
$pembelianMerge = new Pembelian(['supplier_id' => $supplierPembelianId]);
$pembelianMerge->simpan([
    ['produk_id' => $produkMergeId, 'qty' => 10, 'harga_beli' => 3000],
    ['produk_id' => $produkMergeId, 'qty' => 5, 'harga_beli' => 3000],
]);
assertTrue(Produk::cari((int) $produkMergeId)->getStok() === 15, 'pembelian dengan duplikat produk di-merge (10+5=15)');

echo "\n== 12. Shift kasir, void item, audit log ==\n";

// ---- Shift kasir: buka ----
Pengaturan::simpan(['pin_supervisor' => '1234']);
$shiftId = ShiftKasir::buka($kasirId, 100000);
assertTrue($shiftId > 0, 'buka kas sukses');
assertTrue(ShiftKasir::shiftAktif($kasirId) !== null, 'shift aktif terdeteksi setelah buka kas');

assertThrows(
    fn () => ShiftKasir::buka($kasirId, 50000),
    'buka kas kedua ditolak saat shift masih terbuka'
);

// Transaksi selama shift -> total sistem bertambah.
$produkShift = Produk::cari((int) $produkDisId); // harga 100.000
$tShift = new Transaksi(['kasir_id' => $kasirId]);
$tShift->attach(new LaporanPenjualan()); // mencatat rekap_penjualan (basis total shift)
$tShift->tambahItem($produkShift, 1);
$tShift->prosesPembayaran(new PembayaranTunai(['jumlah' => 100000]));

$shift = ShiftKasir::shiftAktif($kasirId);
assertTrue($shift->totalPenjualanShift() > 0, 'total penjualan shift > 0 setelah transaksi');

// ---- Shift kasir: tutup (rekonsiliasi) ----
// Uang di laci = modal 100.000 + penjualan 100.000 = 200.000.
$shift->tutup(200000, 'Shift uji');
assertTrue($shift->getStatus() === 'tutup', 'shift berstatus tutup setelah ditutup');
assertTrue(abs((float) $shift->getTotalSistem() - 200000.0) < 0.01, 'total sistem = modal + penjualan (200.000)');
assertTrue(abs((float) $shift->getSelisih() - 0.0) < 0.01, 'selisih 0 saat kas fisik pas');

// Selisih tidak nol kalau kas fisik beda dari modal + penjualan shift.
$shift2Id = ShiftKasir::buka($kasirId, 50000);
$shift2 = ShiftKasir::cari($shift2Id);
$harusnya = 50000 + $shift2->totalPenjualanShift(); // uang yang seharusnya di laci
$shift2->tutup($harusnya + 10000);                  // kas fisik lebih 10.000
assertTrue(abs((float) $shift2->getSelisih() - 10000.0) < 0.01, 'selisih +10.000 saat kas fisik lebih');
assertThrows(fn () => $shift2->tutup($harusnya + 10000), 'tutup shift dua kali ditolak');

// ---- Audit log ----
AuditLog::catat('void_item', 'item_transaksi', 99, ['produk' => 'Tes', 'kasir' => 'Kasir Uji']);
$auditTabel = (new AuditLog())->getDataTabel([]);
assertTrue($auditTabel['total'] >= 1, 'audit log mencatat entri');
assertTrue($auditTabel['rows'][0]['aksi'] === 'void_item', 'audit log baris terbaru = void_item');

echo "\n== RINGKASAN ==\n";
echo "Lulus: $lulus\n";
echo "Gagal: $gagal\n";

// Bersihkan semua data uji.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'audit_log', 'shift_kasir', 'item_pembelian', 'pembelian', 'item_transaksi',
    'rekap_penjualan', 'transaksi', 'pembayaran', 'diskon', 'retur_barang', 'produk',
    'kategori', 'supplier', 'users', 'member', 'pengaturan', 'katalog_penukaran',
] as $tabel) {
    $pdo->exec("TRUNCATE TABLE $tabel");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// Restore data demo supaya aplikasi tetap bisa dipakai (akun admin/kasir,
// produk, supplier, dll.) setelah pengujian selesai.
require __DIR__ . '/../database/seed.php';

exit($gagal === 0 ? 0 : 1);
