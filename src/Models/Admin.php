<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Admin: extends User (Inheritance) dengan override getHakAkses().
 * Admin punya izin penuh — termasuk kelola diskon dan retur.
 */
class Admin extends User
{
    public function kelolaProduk(): void
    {
        // Logika CRUD produk diimplementasikan di model Produk/Kategori.
    }

    public function lihatLaporan(): LaporanPenjualan
    {
        return new LaporanPenjualan();
    }

    public function kelolaUser(): void
    {
        // Logika manajemen akun kasir diimplementasikan di User (CRUD kasir).
    }

    /**
     * Overriding getHakAkses(): admin memiliki izin penuh atas semua modul,
     * termasuk Kelola Diskon dan Retur.
     *
     * @return string[]
     */
    public function getHakAkses(): array
    {
        return [
            'transaksi',
            'cetak_struk',
            'kelola_produk',
            'kelola_kategori',
            'kelola_user',
            'kelola_supplier',
            'kelola_diskon',
            'retur',
            'laporan',
            'dashboard',
        ];
    }
}
