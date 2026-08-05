<?php

declare(strict_types=1);

namespace App\Models;

class Admin extends User
{
    public function kelolaProduk(): void
    {
        // Logika CRUD produk akan diimplementasikan di langkah 3 (Kategori, Produk).
    }

    public function lihatLaporan(): LaporanPenjualan
    {
        // LaporanPenjualan diimplementasikan di langkah 6.
        return new LaporanPenjualan();
    }

    public function kelolaUser(): void
    {
        // Logika manajemen akun kasir akan diimplementasikan menyusul.
    }
}
