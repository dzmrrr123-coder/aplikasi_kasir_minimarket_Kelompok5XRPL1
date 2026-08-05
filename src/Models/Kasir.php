<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Kasir: extends User (Inheritance) dengan override getHakAkses().
 * Kasir hanya punya izin transaksi dan cetak struk.
 */
class Kasir extends User
{
    public function prosesTransaksi(Transaksi $transaksi): void
    {
        // Alur proses transaksi ada di Transaksi: tambah item -> hitung total ->
        // terapkan diskon -> prosesPembayaran() (yang memvalidasi stok & menyimpan).
        // Method ini ada untuk memenuhi kontrak class diagram (Kasir -> Transaksi).
    }

    public function cetakStruk(Transaksi $transaksi): Struk
    {
        return new Struk($transaksi);
    }

    /**
     * Overriding getHakAkses(): kasir sangat terbatas — hanya transaksi
     * dan cetak struk. Tidak punya akses manajemen apa pun.
     *
     * @return string[]
     */
    public function getHakAkses(): array
    {
        return [
            'transaksi',
            'cetak_struk',
        ];
    }
}
