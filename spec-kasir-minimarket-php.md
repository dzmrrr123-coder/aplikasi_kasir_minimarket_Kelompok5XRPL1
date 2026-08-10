# Spesifikasi Project — Aplikasi Kasir Minimarket (PHP OOP)

Prompt/spec ini adalah hasil tahap desain (use case, class diagram, sequence diagram). Tolong implementasikan persis sesuai struktur class, atribut, method, dan relasi di bawah — jangan bikin struktur/arsitektur sendiri yang berbeda.

**Stack**: PHP native (OOP), koneksi database pakai PDO + MySQL.

## 1. Aktor
- **Kasir** — operasiin transaksi penjualan harian
- **Admin / Manajer** — kelola produk, stok, promo, laporan, user, supplier, retur

## 2. Use Case per Modul
- **Transaksi penjualan**: input/scan barang, hitung total & diskon, proses pembayaran (tunai/non-tunai), cetak struk, batalkan transaksi
- **Manajemen produk & stok**: tambah/edit/hapus produk, kelola kategori, update stok, cek stok menipis
- **Laporan & manajemen user**: laporan penjualan per periode, kelola akun kasir
- **Manajemen supplier & retur**: catat retur barang ke supplier, kelola data supplier

## 3. Struktur Class

```
abstract class User {
    private string $id;
    private string $nama;
    private string $username;
    private string $password;
    public function login(string $username, string $password): bool
    public function logout(): void
}

class Kasir extends User {
    public function prosesTransaksi(Transaksi $t): void
    public function cetakStruk(Transaksi $t): Struk
}

class Admin extends User {
    public function kelolaProduk(): void
    public function lihatLaporan(): LaporanPenjualan
    public function kelolaUser(): void
}

class Kategori {
    private string $id;
    private string $nama;
}

class Produk {
    private string $id;
    private string $nama;
    private float $harga;
    private int $stok;
    private Kategori $kategori;
    public function updateStok(int $qty): void
    public function kurangiStok(int $qty): void
    public function cekStokMenipis(): bool
}

class Transaksi {
    private string $id;
    private DateTime $tanggal;
    private float $total;
    private array $items = []; // ItemTransaksi[]
    private ?Diskon $diskon = null;
    private ?Pembayaran $pembayaran = null;
    public function tambahItem(Produk $produk, int $qty): void
    public function hitungTotal(): float
    public function terapkanDiskon(Diskon $diskon): void
    public function batalkan(): void
}

class ItemTransaksi {
    private Produk $produk;
    private int $qty;
    private float $subtotal;
}

abstract class Pembayaran {
    protected float $jumlah;
    abstract public function proses(): bool
}

class PembayaranTunai extends Pembayaran {
    public function proses(): bool
}

class PembayaranNonTunai extends Pembayaran {
    public function proses(): bool
}

class Struk {
    private string $transaksiId;
    private DateTime $tanggalCetak;
    public function cetak(): string
}

class Diskon {
    private string $id;
    private string $jenis; // persen | nominal
    private float $nilai;
    public function terapkan(float $total): float
}

class LaporanPenjualan {
    private DateTime $tanggalMulai;
    private DateTime $tanggalAkhir;
    public function generate(): array
    public function eksporPDF(): string
}

class Supplier {
    private string $id;
    private string $nama;
    private string $kontak;
    private string $alamat;
}

class ReturBarang {
    private string $id;
    private DateTime $tanggal;
    private Produk $produk;
    private Supplier $supplier;
    private int $qty;
    private string $alasan;
    public function prosesRetur(): bool
}
```

## 4. Relasi Antar Class
- `Kasir` dan `Admin` **extends** `User` (inheritance)
- `Transaksi` **composition** dengan `ItemTransaksi` (kalau Transaksi dihapus, item ikut terhapus)
- `ItemTransaksi` → `Produk`
- `Produk` → `Kategori`
- `Transaksi` → `Pembayaran` (abstract, polymorphic lewat `PembayaranTunai`/`PembayaranNonTunai`)
- `Transaksi` → `Struk`, `Transaksi` → `Diskon`
- `Admin` → `LaporanPenjualan`
- `ReturBarang` → `Produk` dan `Supplier`, di-trigger oleh `Admin`

## 5. Alur Proses Utama (ikuti urutan ini persis di logic-nya)

**Transaksi penjualan**: buat transaksi → loop tambah item (cek stok tiap item, kalau kurang tolak item itu) → hitung total → (opsional) terapkan diskon → proses pembayaran → kalau berhasil, update stok produk & cetak struk.

**Tambah produk baru**: cek kategori valid dulu → kalau valid, validasi data produk → kalau valid, simpan. Kalau kategori atau data invalid, kembalikan pesan error spesifik.

**Generate laporan penjualan**: ambil semua transaksi di rentang tanggal → kalau kosong, kasih pesan "tidak ada data" → kalau ada, hitung subtotal tiap transaksi → generate laporan → (opsional) ekspor PDF.

**Retur barang**: cek stok produk cukup untuk diretur → cek data supplier valid → kalau keduanya valid, kurangi stok produk & catat retur. Kalau salah satu invalid, batalkan proses dan kasih pesan error sesuai titik gagalnya (jangan sampai stok terlanjur berkurang tapi retur gagal tercatat).

## 6. Saran Skema Tabel Database

```sql
users(id, nama, username, password, role) -- role: 'kasir' / 'admin'
kategori(id, nama)
produk(id, nama, harga, stok, kategori_id)
transaksi(id, tanggal, total, kasir_id, diskon_id, pembayaran_id)
item_transaksi(id, transaksi_id, produk_id, qty, subtotal)
pembayaran(id, jenis, jumlah) -- jenis: 'tunai' / 'non_tunai'
diskon(id, jenis, nilai)
supplier(id, nama, kontak, alamat)
retur_barang(id, tanggal, produk_id, supplier_id, qty, alasan)
```

## 7. Saran Struktur Folder

```
/kasir-minimarket
  /src
    /Models      -> semua class di atas, 1 file per class
    /Database    -> Database.php (koneksi PDO)
  /public
    index.php
  /config
    config.php   -> kredensial database
```

## 8. Urutan Implementasi (ikuti biar dependency-nya aman)
1. Setup koneksi PDO + jalankan skema tabel
2. `User`, `Kasir`, `Admin`
3. `Kategori`, `Produk`
4. `Transaksi`, `ItemTransaksi`, `Pembayaran` + subclass-nya
5. `Struk`, `Diskon`
6. `LaporanPenjualan`
7. `Supplier`, `ReturBarang`

---

**Instruksi ke AI**: Implementasikan project ini pakai PHP native OOP sesuai struktur class, atribut, method, relasi, dan alur proses di atas. Mulai dari langkah 1 di "Urutan Implementasi", satu langkah per prompt biar gampang dicek. Sertakan validasi/error handling sesuai yang dijelaskan di bagian "Alur Proses Utama".
