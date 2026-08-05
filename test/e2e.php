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
use App\Models\Diskon;
use App\Models\Kasir;
use App\Models\Kategori;
use App\Models\LaporanPenjualan;
use App\Models\PaymentMethod;
use App\Models\PembayaranNonTunai;
use App\Models\PembayaranTunai;
use App\Models\Produk;
use App\Models\ReturBarang;
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
    'item_transaksi', 'rekap_penjualan', 'transaksi', 'pembayaran', 'diskon',
    'retur_barang', 'produk', 'kategori', 'supplier', 'users',
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
$transaksiBatal->tambahItem($produkSetelah, 2);
$transaksiBatal->hitungTotal();
$transaksiBatal->prosesPembayaran(new PembayaranTunai(['jumlah' => 10000]));
$idBatal = $transaksiBatal->getId();

// Jalur sukses: batalkan -> stok dikembalikan.
$transaksiBatal->batalkan();
$produkSetelahBatal = Produk::cari($produkId);
assertTrue(
    $produkSetelahBatal->getStok() === $produkSetelah->getStok(),
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
assertTrue(str_contains($laporan->eksporPDF(), 'No. Transaksi'), 'ekspor laporan menghasilkan CSV');

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

$supplier = new Supplier(['nama' => 'PT Sumber Jaya', 'kontak' => '0812-3456', 'alamat' => 'Jakarta']);
$supplierId = $supplier->simpan();
assertTrue($supplierId > 0, 'simpan supplier sukses');

// Jalur sukses: retur valid -> stok berkurang & retur tercatat.
$produkRetur = Produk::cari($produkId);
$stokSebelumRetur = $produkRetur->getStok();

$retur = new ReturBarang([
    'produk_id'   => $produkId,
    'supplier_id' => $supplierId,
    'qty'         => 2,
    'alasan'      => 'Rusak',
]);
assertTrue($retur->prosesRetur(), 'retur sukses');

$produkSetelahRetur = Produk::cari($produkId);
assertTrue(
    $produkSetelahRetur->getStok() === $stokSebelumRetur - 2,
    'stok berkurang 2 setelah retur'
);

// Jalur gagal 1: stok produk kurang dari qty retur -> dibatalkan.
$returGagalStok = new ReturBarang([
    'produk_id'   => $produkId,
    'supplier_id' => $supplierId,
    'qty'         => 9999,
    'alasan'      => 'Uji',
]);
assertThrows(fn () => $returGagalStok->prosesRetur(), 'retur dengan stok kurang ditolak');

// Jalur gagal 2: supplier invalid -> dibatalkan.
$returGagalSupplier = new ReturBarang([
    'produk_id'   => $produkId,
    'supplier_id' => 999999,
    'qty'         => 1,
    'alasan'      => 'Uji',
]);
assertThrows(fn () => $returGagalSupplier->prosesRetur(), 'retur dengan supplier invalid ditolak');

// Pastikan stok tidak berubah dari 2 skenario gagal di atas.
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
assertThrows(
    fn () => $supplier->hapus(),
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

// Jalur gagal: password salah.
$adminSalah = new Admin();
assertTrue(!$adminSalah->login('admin_uji', 'salah'), 'login dengan password salah ditolak');

$admin->logout();
assertTrue($admin->getId() === '', 'logout mengosongkan sesi');

echo "\n== RINGKASAN ==\n";
echo "Lulus: $lulus\n";
echo "Gagal: $gagal\n";

// Bersihkan semua data uji.
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ([
    'item_transaksi', 'rekap_penjualan', 'transaksi', 'pembayaran', 'diskon',
    'retur_barang', 'produk', 'kategori', 'supplier', 'users',
] as $tabel) {
    $pdo->exec("TRUNCATE TABLE $tabel");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

exit($gagal === 0 ? 0 : 1);
