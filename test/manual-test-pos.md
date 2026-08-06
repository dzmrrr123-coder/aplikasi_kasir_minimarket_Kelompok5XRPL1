# Manual Test Checklist — Halaman Transaksi (POS)

Checklist ini wajib dijalankan **setiap kali mengubah `public/transaksi.php`**
(atau komponen terkait: `ShiftKasir`, `Keranjang`, `theme.css` bagian kiosk).
Bug state/tampilan POS (mis. layar putih, panel kanan tidak muncul) tidak
tertangkap oleh `test/e2e.php` yang sifatnya backend-only — hanya browser
sungguhan yang bisa memverifikasinya.

**Kredensial demo:** kasir `kasir/kasir123` · admin `admin/admin123`

---

## A. State kritis: Buka Kas / Shift

### A1. Kasir BELUM buka kas (shift kosong)
1. Login sebagai **kasir** di browser bersih (atau logout dulu).
2. Buka halaman Transaksi.
3. **Harus terlihat:** card kuning "Buka Kas" dengan input modal awal + tombol hijau.
4. **Harus terlihat:** kolom kiri (pencarian produk, keranjang) TETAP tampil — bukan layar putih.
5. Panel kanan **tersembunyi** (wajar — belum ada shift).

### A2. Buka kas
6. Isi modal awal (mis. `100000`), klik **"Buka Kas & Mulai Transaksi"**.
7. **Harus terlihat:** banner hijau "Kas dibuka. Selamat bertugas!".
8. **Card "Buka Kas" HILANG** (tidak boleh masih ada).
9. **Panel kanan MUNCUL**: nama kasir, tombol "Tutup Kas", ringkasan total (Rp 0), metode pembayaran.
10. Tidak ada error di console browser (F12).

### A3. Kasir SUDAH buka kas (halaman di-refresh)
11. Refresh halaman (F5).
12. **Harus terlihat:** panel kanan langsung tampil, card buka kas TIDAK muncul.
13. Tombol "Tutup Kas" ada di panel kanan.

### A4. Tutup kas (rekonsiliasi)
14. Klik **"Tutup Kas"** → isi kas fisik + catatan → simpan.
15. **Harus terlihat:** card buka kas muncul lagi, panel kanan tersembunyi.
16. Refresh → state tetap sama (belum buka kas).

### A5. Kasir ganti akun saat shift terbuka
17. Buka kas sebagai kasir A, logout, login kasir B.
18. Kasir B **tidak boleh** melihat shift kasir A (harus buka kas sendiri).

---

## B. Alur transaksi normal

1. (Setelah buka kas) Scan barcode produk → item masuk keranjang.
2. Cari produk via nama → Enter → item masuk.
3. Ubah qty, void item (butuh PIN supervisor `1234`).
4. Total & subtotal di panel kanan sesuai hitungan manual.
5. Terapkan kode diskon (`DISC10`) → potongan tampil.
6. Pilih metode **Tunai** → **numpad/kalkulator MUNCUL**.
7. Pilih **QRIS/EDC** → **numpad HILANG**.
8. Bayar tunai → struk muncul, bisa dicetak, stok berkurang.
9. Transaksi member (scan telepon) → poin bertambah, nama member di struk.

---

## C. Regresi umum (setiap ubah transaksi.php)

1. Login kasir & admin → kedua role masuk dengan benar.
2. Halaman Transaksi tidak layar putih di resolusi desktop & tablet (<1024px).
3. Dark mode & light mode tidak merusak layout POS.
4. Semua tombol di panel kanan berfungsi (buka/tutup kas, logout, hubungkan perangkat).
5. `php test/e2e.php` hijau (semua PASS).

---

## Cara catat hasil

Untuk tiap item: centang `[x]` = lolos, `[ ]` = gagal (tulis gejala di catatan).

| Item | Hasil | Catatan |
|------|-------|---------|
| A1-5 | | |
| A2-8 | | |
| ...   | | |

> Simpan hasil checklist ini (mis. salin ke `test/manual-run-<tanggal>.md`)
> supaya ada jejak verifikasi tiap perubahan.
