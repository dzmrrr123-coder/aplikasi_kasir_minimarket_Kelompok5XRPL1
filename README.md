# Aplikasi Kasir Minimarket (PBO PHP Native)

> **Tugas Pemrograman Berorientasi Objek (PBO)**  
> **Kelompok 5 — X RPL 1**

Aplikasi sistem kasir (*Point of Sale / POS*) minimarket berbasis web modern menggunakan **PHP Native Berorientasi Objek (OOP)**, **MySQL / MariaDB**, serta **Bootstrap 5 & Vanilla JS**.

---

## 🌟 Fitur Utama

### 1. 🛒 Point of Sale (POS) & Transaksi
- **Pencarian Cepat & Scan Barcode:** Pencarian instan berdasarkan nama produk atau barcode scanner USB/kamera.
- **Dukungan Produk Satuan & Curah (Gram):** Menangani produk satuan (*pcs*) serta produk timbangan (*gram*) dengan harga per gram.
- **Diskon Fleksibel:** Diskon persentase maupun nominal dengan kode promo.
- **Pajak Toko (PPN):** Pengaturan persentase PPN toko otomatis terhitung pada total belanja.
- **Pembayaran Multi-Metode:** Tunai (dilengkapi kalkulator uang & hitung kembalian cepat) serta Non-Tunai (QRIS / Kartu Debit / EDC).
- **Struk Termal Siap Cetak:** Format struk siap cetak thermal (*58mm / 80mm*) dengan `@media print` dan tombol `window.print()`.

### 2. ⏱️ Shift Kasir & Rekonsiliasi Kas
- **Buka Kasir:** Kasir wajib memasukkan modal awal kas sebelum memulai transaksi.
- **Tutup Kas & Rekonsiliasi:** Pencatatan kas fisik akhir shift dengan perhitungan otomatis selisih kas (*lebih / pas / kurang*).
- **Audit Log & Void:** Pencatatan log aktivitas void item kasir dengan otorisasi PIN supervisor.

### 3. 👥 Keanggotaan Member & Loyalitas Poin
- **Poin Belanja Otomatis:** Perolehan 1 poin per kelipatan belanja Rp 1.000.
- **Katalog Hadiah:** Penukaran poin member dengan hadiah fisik/voucher.
- **Area Member:** Portal cek saldo poin dan riwayat transaksi member.

### 4. 📈 Laporan Penjualan & Analisis Laba (HPP)
- **Laporan Penjualan:** Filter rentang tanggal dengan ekspor format **PDF** dan **CSV/Excel**.
- **Laba & HPP Historis:** Perhitungan omzet, Harga Pokok Penjualan (HPP historis per transaksi), laba kotor, dan margin laba.

### 5. 📦 Manajemen Master Data (Admin Panel)
- **Produk:** CRUD produk, pengaturan stok minimum, peringatan stok menipis, upload gambar.
- **Kategori:** Pengelompokan kategori produk.
- **Supplier & Pembelian:** Stok masuk (*pembelian*) dan integrasi supplier.
- **Retur Barang:** Pengembalian barang rusak/kadaluarsa ke supplier asal dengan validasi stok otomatis.
- **Manajemen User:** Kelola kasir (tambah, edit, nonaktifkan/soft-delete, reset password).
- **Pengaturan Toko:** Nama toko, alamat, telepon, footer struk, pajak, dan integrasi WhatsApp.

### 6. 📱 Notifikasi WhatsApp Otomatis (n8n Webhook)
- **Transactional Outbox Pattern:** Setiap transaksi selesai langsung masuk ke antrean notifikasi DB dan dikirimkan ke webhook n8n secara asinkron/background dengan mekanisme retry.

---

## 🏛️ Penerapan Konsep PBO & Design Pattern

Aplikasi ini dibangun dengan mengimplementasikan prinsip-prinsip Pemrograman Berorientasi Objek:

```
src/
├── Auth/
│   └── SessionGuard.php           # Autentikasi, Role Guard & Proteksi CSRF
├── Database/
│   └── Database.php               # Singleton DB Connection & Migrasi Skema
├── Models/
│   ├── User.php                   # Abstract Base Class User
│   ├── Admin.php                  # Inheritance dari User (Izin Penuh)
│   ├── Kasir.php                  # Inheritance dari User (Izin Kasir)
│   ├── Subject.php                # Interface Subject (Observer Pattern)
│   ├── Observer.php               # Interface Observer (Observer Pattern)
│   ├── Transaksi.php              # Subject Transaksi Penjualan
│   ├── Struk.php                  # Observer Pembuat Struk
│   ├── LaporanPenjualan.php       # Observer Pencatat Rekap Penjualan
│   ├── NotifikasiWhatsApp.php     # Observer Antrean Notifikasi WA
│   ├── PaymentMethod.php          # Interface Strategy Pattern Pembayaran
│   ├── Pembayaran.php             # Abstract Class Pembayaran
│   ├── PembayaranTunai.php        # Strategy Pembayaran Tunai
│   ├── PembayaranNonTunai.php     # Strategy Pembayaran Non-Tunai
│   ├── Produk.php                 # Encapsulation Data Produk & Stok
│   ├── Kategori.php               # Data Kategori Produk
│   ├── Diskon.php                 # Model Diskon & Kode Promo
│   ├── Member.php                 # Manajemen Member & Poin
│   ├── ShiftKasir.php             # Shift & Rekonsiliasi Kas
│   ├── Supplier.php               # Data Supplier
│   ├── ReturBarang.php            # Logika Retur Barang
│   ├── StokTidakCukupException.php # Custom Exception Stok
│   └── SupplierTidakValidException.php # Custom Exception Supplier
```

1. **Inheritance & Polymorphism:**
   - `User` sebagai kelas abstrak diturunkan ke `Admin` dan `Kasir` dengan method `getHakAkses()` yang di-*override*.
   - `User::loginPolimorfik()` mengembalikan objek `Admin` atau `Kasir` secara dinamis sesuai role di database.
2. **Observer Pattern:**
   - `Transaksi` bertindak sebagai `Subject`. Saat transaksi berhasil diproses (`notify()`), tiga observer secara otomatis merespons:
     - `Struk`: Menyiapkan format struk cetak.
     - `LaporanPenjualan`: Mencatat entri ke tabel `rekap_penjualan`.
     - `NotifikasiWhatsApp`: Memasukkan payload ke antrean `notifikasi_queue`.
3. **Strategy Pattern:**
   - `PaymentMethod` mendefinisikan kontrak pemrosesan pembayaran yang diimplementasikan oleh `PembayaranTunai` dan `PembayaranNonTunai`.
4. **Encapsulation & Data Integrity:**
   - Seluruh properti model bersifat `private` / `protected` dengan *getter* dan *setter* yang memvalidasi data.
   - Pengurangan stok produk dilakukan secara atomik di dalam database transaction (`BEGIN ... COMMIT / ROLLBACK`).
5. **Custom Exception Handling:**
   - `StokTidakCukupException` dan `SupplierTidakValidException` untuk menangani error bisnis secara spesifik tanpa menampilkan pesan error SQL mentah ke pengguna.

---

## 🚀 Panduan Instalasi & Menjalankan

### Persyaratan
- **PHP:** Versi 8.1 atau lebih baru (dengan ekstensi `pdo_mysql`, `mbstring`, `curl`)
- **Web Server & Database:** Apache / Nginx & MySQL / MariaDB (misalnya via **Laragon** atau **XAMPP**)

### Langkah Instalasi
1. Clone atau letakkan folder proyek di direktori web server (misalnya `C:\laragon\www\kasir-minimarket`).
2. Pastikan service MySQL dan Apache aktif.
3. Buat database baru di MySQL dengan nama `kasir_minimarket`:
   ```sql
   CREATE DATABASE kasir_minimarket;
   ```
4. Sesuaikan konfigurasi database jika diperlukan di `config/database.php` (default: host `127.0.0.1`, user `root`, password kosong).
5. Jalankan seed data awal untuk mengisi akun demo dan data produk:
   ```bash
   php database/seed.php
   ```
6. Buka aplikasi di browser:
   - **Via Laragon / Apache:** `http://localhost/kasir-minimarket/public/`
   - **Via PHP Built-in Server:**
     ```bash
     php -S 127.0.0.1:8000 -t public
     ```
     Lalu buka `http://127.0.0.1:8000` di browser.

---

## 🔑 Kredensial Akun Demo

| Role | Username | Password | Keterangan |
|---|---|---|---|
| **Admin** | `admin` | `admin123` | Akses penuh: master data, laporan, laba, user, pengaturan |
| **Kasir** | `kasir` | `kasir123` | Akses kasir POS, buka/tutup kasir, riwayat transaksi |
| **Member Demo** | `08123456789` | `member123` | Login area member, cek saldo poin |

---

## 🧪 Menjalankan Automated Test (E2E)

Proyek ini dilengkapi dengan 225 pengujian otomatis end-to-end yang menguji seluruh model, relasi, kalkulasi keuangan, transaksi database, dan keamanan:

```bash
php test/e2e.php
```

Hasil pengujian yang diharapkan:
```
== RINGKASAN ==
Lulus: 225
Gagal: 0
```

---

## 👥 Anggota Kelompok (Kelompok 5 — X RPL 1)
- Aplikasi dikembangkan untuk memenuhi tugas mata pelajaran **Pemrograman Berorientasi Objek (PBO)**.
