# Spesifikasi Lanjutan — UI/UX, Routing & Infrastruktur (Kasir Minimarket PHP OOP)

Spec ini adalah **lanjutan** dari `spec-kasir-minimarket-php.md`. Fase 1 (semua class model:
`User`, `Kasir`, `Admin`, `Produk`, `Transaksi`, `Struk`, `Diskon`, `LaporanPenjualan`,
`Supplier`, `ReturBarang`, dst) **sudah selesai diimplementasikan dan terverifikasi** —
jangan bongkar atau tulis ulang class-class itu. Spec ini murni menambah lapisan
presentasi (halaman, routing, session) yang memanggil class-class tersebut.

**Stack tambahan**: tetap PHP native (tanpa framework backend). Untuk styling boleh
pakai Bootstrap lewat CDN (cuma file CSS/JS statis, bukan dependency PHP) supaya
tampilan layak tanpa perlu desain custom dari nol — kalau kamu lebih suka CSS polos,
tinggal bilang, tinggal ganti bagian styling saja.

## 1. Aktor & Hak Akses Halaman

| Halaman | Kasir | Admin |
|---|---|---|
| Login | ✅ | ✅ |
| Transaksi (POS) | ✅ | ❌ |
| Riwayat transaksi & cetak struk | ✅ (miliknya sendiri) | ✅ (semua) |
| Kelola produk & kategori | ❌ | ✅ |
| Laporan penjualan | ❌ | ✅ |
| Kelola user/kasir | ❌ | ✅ |
| Kelola supplier & retur | ❌ | ✅ |

## 2. Peta Halaman (Sitemap)

```
/public
  index.php            -> redirect ke login atau dashboard sesuai session
  login.php
  logout.php
  /kasir
    transaksi.php       -> halaman POS: input/scan barang, bayar
    riwayat.php         -> daftar transaksi milik kasir yang login
    struk.php?id=...    -> tampilan struk siap cetak (dipanggil setelah bayar)
  /admin
    dashboard.php        -> ringkasan singkat (opsional, boleh redirect ke produk)
    produk.php            -> list + tambah/edit produk
    kategori.php          -> list + tambah/edit kategori
    laporan.php            -> filter tanggal, tampilkan hasil generate(), tombol ekspor
    user.php                -> list kasir, tambah kasir baru
    supplier.php              -> list + tambah/edit supplier
    retur.php                  -> form retur + riwayat retur
```

## 3. Struktur Folder Tambahan

```
/kasir-minimarket
  /src
    /Models       (sudah ada, jangan diubah)
    /Database     (sudah ada)
    /Controllers  -> BARU: 1 file per grup halaman, isi logic pemroses form/request
    /Auth         -> BARU: SessionGuard.php (cek login, cek role)
  /public
    /kasir/...
    /admin/...
    /assets
      /css style.css   (custom override di atas Bootstrap CDN)
  /views
    /layouts
      header.php
      footer.php
      sidebar-admin.php
      sidebar-kasir.php
    /kasir/*.php        -> partial tampilan, di-include dari /public/kasir/*.php
    /admin/*.php
```

Alasan controller dipisah dari `/public`: file di `/public/*.php` jadi **tipis**
(cuma include controller + view), sementara logic pemrosesan form (validasi input,
panggil class Model, redirect) ada di `/src/Controllers`. Ini bukan MVC framework
penuh, cuma pemisahan supaya file di `/public` gampang dibaca.

## 4. Session & Autentikasi

- `session_start()` dipanggil di awal tiap file publik (lewat `views/layouts/header.php`
  yang di-include paling atas, atau lewat `SessionGuard`).
- Setelah `User::login()` sukses di `login.php`, simpan ke session:
  `$_SESSION['user_id']`, `$_SESSION['nama']`, `$_SESSION['role']` ('kasir'/'admin').
- `src/Auth/SessionGuard.php`: berisi fungsi/method statis dipanggil di **baris
  pertama** tiap halaman terproteksi:
  - `SessionGuard::requireLogin()` — kalau `$_SESSION['user_id']` kosong, redirect ke `login.php`
  - `SessionGuard::requireRole('admin')` — kalau role tidak cocok, redirect balik ke
    dashboard role yang sesuai (bukan error 403 mentah, supaya UX tetap halus)
- `logout.php`: panggil `User::logout()` lalu `session_destroy()`, redirect ke `login.php`.
- **Asumsi**: satu session PHP = satu login aktif. Tidak ada "remember me" atau
  multi-device session di scope ini.

## 5. Wireframe Tekstual per Halaman Kunci

**Login** — form username + password, satu tombol submit. Error ditampilkan di atas
form kalau login gagal (pesan generik "Username atau password salah", jangan bocorkan
mana yang salah).

**Transaksi (POS)** — ini halaman paling sering dipakai, harus cepat dioperasikan:
- Input/scan kode produk di atas → Enter → baris item baru muncul di tabel keranjang
  (nama, qty, subtotal), total otomatis update di bawah
- Baris keranjang bisa diedit qty-nya atau dihapus sebelum bayar
- Field diskon (opsional) → pilih dari `Diskon` yang ada
- Tombol "Bayar" → modal pilih metode (tunai/non-tunai) → konfirmasi → redirect ke
  `struk.php?id=...`
- Kalau stok kurang saat tambah item, tampilkan pesan error di baris itu saja
  (sesuai `StokTidakCukupException` dari Transaksi::tambahItem()), keranjang lain
  tidak terganggu

**Struk** — render hasil `Struk::cetak()` di dalam `<pre>` atau tabel HTML rapi,
dengan CSS `@media print` supaya saat di-print browser (Ctrl+P / tombol "Cetak")
hasilnya bersih tanpa header/footer browser. Tombol "Cetak" pakai `window.print()`
sederhana (JS inline, tanpa library).

**Kelola Produk** — tabel list produk (nama, kategori, harga, stok, badge merah kalau
`cekStokMenipis()` true) + tombol tambah/edit di atas tabel. Form tambah/edit modal
atau halaman terpisah, bebas dipilih — validasi error dari `Produk`/`Admin::tambahProdukBaru()`
ditampilkan di atas form.

**Laporan Penjualan** — 2 input tanggal (mulai/akhir, default bulan berjalan) + tombol
"Tampilkan". Hasil `generate()` ditampilkan sebagai tabel ringkasan (jumlah transaksi,
total pendapatan) + tabel detail transaksi. Tombol "Ekspor" memanggil `eksporPDF()`
lalu kasih link download file teks-nya (ingat: bukan PDF asli, sudah dicatat sebagai
keterbatasan di spec fase 1).

**Kelola User/Kasir** — list kasir aktif + form tambah kasir baru (nama, username,
password) yang manggil `Admin::tambahKasirBaru()`.

**Retur Barang** — form pilih produk + supplier + qty + alasan → panggil
`ReturBarang::prosesRetur()`. Tampilkan pesan error spesifik sesuai titik gagalnya
(stok kurang vs supplier tidak valid), persis seperti yang sudah didesain di
`StokTidakCukupException` / `SupplierTidakValidException`.

## 6. Alur Proses per Halaman (hubungkan UI → Controller → Model)

**Login**: submit form → `AuthController` panggil `Kasir::login()` atau
`Admin::login()` (coba dua-duanya, atau cek role dari tabel `users` dulu baru
instansiasi class yang sesuai) → sukses: isi session, redirect sesuai role →
gagal: tampilkan error, jangan bocorkan detail.

**Transaksi**: tiap "Enter" di field scan → AJAX (fetch, vanilla JS, tanpa
library) ke `TransaksiController::tambahItem` → panggil
`$transaksi->tambahItem()` → return JSON (sukses + data item, atau error message)
→ JS update tabel keranjang tanpa reload halaman. Tombol "Bayar" → submit form
biasa (reload) ke `TransaksiController::bayar` → panggil
`Kasir::prosesTransaksi($t, $pembayaran)` → redirect ke `struk.php`.

**Kelola Produk**: submit form tambah → `ProdukController::tambah` → panggil
`Admin::tambahProdukBaru()` → sukses: redirect balik ke list dengan pesan sukses
→ gagal: balik ke form dengan pesan error dari Exception.

**Laporan**: submit filter tanggal → `LaporanController::tampilkan` → panggil
`Admin::lihatLaporan($mulai, $akhir)->generate()` → render hasil array ke tabel.

**Retur**: submit form → `ReturController::proses` → panggil
`ReturBarang::prosesRetur()` → tangkap Exception spesifik, tampilkan pesan sesuai
titik gagalnya.

## 7. Hal Teknis Tambahan yang Perlu Diputuskan dari Awal

- **CSRF protection**: tiap form POST sertakan hidden token session sederhana
  (generate random token, simpan di `$_SESSION`, cocokkan saat submit). Native
  PHP, tanpa library.
- **Flash message**: setelah redirect (misal sukses tambah produk), pesan sukses
  disimpan sebentar di `$_SESSION['flash']`, ditampilkan sekali lalu dihapus —
  supaya reload halaman tidak submit ulang form.
- **Format angka**: pakai `number_format()` untuk tampilan rupiah di semua halaman
  (konsisten, misal `Rp 15.000`).
- **Konfirmasi aksi destruktif**: hapus produk/batalkan transaksi pakai konfirmasi
  JS `confirm()` sederhana sebelum submit — cukup untuk scope ini.

## 8. Urutan Implementasi (Fase 2, lanjutan dari Fase 1 yang sudah selesai)

1. `SessionGuard.php` + `login.php` + `logout.php` + layout dasar (header/footer/sidebar)
2. Halaman POS transaksi kasir (`transaksi.php` + `TransaksiController`) — ini yang
   paling kompleks, kerjakan sendirian dulu sebelum lanjut
3. Halaman struk (`struk.php`) + riwayat transaksi kasir (`riwayat.php`)
4. Halaman kelola produk & kategori (admin)
5. Halaman laporan penjualan (admin)
6. Halaman kelola user/kasir (admin)
7. Halaman kelola supplier & retur (admin)

Ikuti pola yang sama seperti fase 1: **satu langkah per prompt**, supaya gampang
direview satu-satu sebelum lanjut ke langkah berikutnya.

---

**Instruksi ke AI**: Implementasikan fase 2 ini di atas class-class model yang sudah
ada di fase 1 (JANGAN diubah kecuali diminta eksplisit). Mulai dari langkah 1 di
bagian 8, satu langkah per prompt. Sertakan validasi/error handling yang konsisten
dengan pola yang sudah dipakai di fase 1 (Exception untuk kegagalan yang perlu
dibedakan titik gagalnya, redirect + flash message untuk hasil biasa).
