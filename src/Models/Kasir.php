<?php

declare(strict_types=1);

namespace App\Models;

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
}
