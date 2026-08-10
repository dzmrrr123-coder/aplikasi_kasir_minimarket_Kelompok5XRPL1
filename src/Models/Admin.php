<?php
// src/Models/Admin.php
// Class Admin/Manajer: kelola produk, stok, promo, laporan, user, supplier, retur.

class Admin extends User
{
    // Mengelola data produk (tambah/edit/hapus).
    // Method ini penanda bahwa Admin adalah aktor yang berwenang mengelola
    // produk — operasi konkretnya sudah dimiliki class Produk (tambahStok/
    // kurangiStok/findById) dan helper tambahProdukBaru() di bawah.
    public function kelolaProduk(): void
    {
        // Tidak ada operasi default; gunakan tambahProdukBaru() atau method
        // Produk yang sesuai.
    }

    // Menambah produk baru sesuai alur spec bagian 5: cek kategori valid dulu,
    // lalu validasi data produk, baru simpan. Tiap titik gagal melempar
    // Exception dengan pesan spesifik.
    public function tambahProdukBaru(string $nama, float $harga, int $stok, Kategori $kategori): Produk
    {
        // 1. Cek kategori valid (masih ada di database).
        if (Kategori::findById($kategori->getId()) === null) {
            throw new InvalidArgumentException("Kategori #{$kategori->getId()} tidak ditemukan.");
        }

        // 2. Validasi data produk.
        if (trim($nama) === '') {
            throw new InvalidArgumentException('Nama produk tidak boleh kosong.');
        }
        if ($harga <= 0) {
            throw new InvalidArgumentException('Harga produk harus lebih dari 0.');
        }
        if ($stok < 0) {
            throw new InvalidArgumentException('Stok awal produk tidak boleh negatif.');
        }

        // 3. Simpan ke database.
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO produk (nama, harga, stok, kategori_id) VALUES (:nama, :harga, :stok, :kategori_id)'
        );
        $stmt->execute([
            'nama'        => $nama,
            'harga'       => $harga,
            'stok'        => $stok,
            'kategori_id' => (int) $kategori->getId(),
        ]);

        return new Produk((int) $pdo->lastInsertId(), $nama, $harga, $stok, $kategori);
    }

    // Melihat laporan penjualan per periode. Parameter opsional: jika tidak
    // diisi, dipakai rentang tanggal 1 s/d hari terakhir bulan berjalan.
    // Hanya membuat object LaporanPenjualan — pemanggil yang menjalankan
    // ->generate() sesuai kebutuhan.
    public function lihatLaporan(?DateTime $mulai = null, ?DateTime $akhir = null): LaporanPenjualan
    {
        $mulai ??= new DateTime('first day of this month');
        $akhir ??= new DateTime('last day of this month');

        return new LaporanPenjualan($mulai, $akhir);
    }

    // Mengelola akun pengguna (kasir/admin).
    // Method ini penanda bahwa Admin adalah aktor yang berwenang mengelola
    // user — operasi konkretnya ada di helper tambahKasirBaru() di bawah.
    public function kelolaUser(): void
    {
        // Tidak ada operasi default; gunakan tambahKasirBaru() atau query
        // users yang sesuai.
    }

    // Membuat akun kasir baru: password di-hash (password_hash), disimpan ke
    // tabel users dengan role 'kasir'. Throw Exception jika data tidak valid.
    public function tambahKasirBaru(string $nama, string $username, string $password): Kasir
    {
        if (trim($nama) === '') {
            throw new InvalidArgumentException('Nama kasir tidak boleh kosong.');
        }
        if (trim($username) === '') {
            throw new InvalidArgumentException('Username tidak boleh kosong.');
        }
        if ($password === '') {
            throw new InvalidArgumentException('Password tidak boleh kosong.');
        }

        $pdo = Database::getInstance()->getConnection();

        // Username harus unik — cek dulu supaya pesan errornya jelas (bukan
        // sekadar PDOException dari constraint UNIQUE).
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $stmt->execute(['username' => $username]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException("Username '{$username}' sudah dipakai.");
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            "INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :password, 'kasir')"
        );
        $stmt->execute(['nama' => $nama, 'username' => $username, 'password' => $hash]);

        return new Kasir((int) $pdo->lastInsertId(), $nama, $username, $hash);
    }
}
