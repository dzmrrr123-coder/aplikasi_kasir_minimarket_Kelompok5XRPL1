<?php
// src/Models/Kasir.php
// Class Kasir: pengguna yang mengoperasikan transaksi penjualan harian.

class Kasir extends User
{
    // Menjalankan satu transaksi penuh. Item (dan diskon bila ada) sudah diisi
    // pemanggil lewat tambahItem()/terapkanDiskon() sebelumnya — method ini
    // hanya menghitung total lalu memproses pembayaran yang diberikan.
    // Throw Exception jika pembayaran gagal.
    public function prosesTransaksi(Transaksi $t, Pembayaran $pembayaran): void
    {
        $t->hitungTotal();

        if (!$t->prosesPembayaran($pembayaran)) {
            throw new Exception('Pembayaran gagal, transaksi tidak dapat diproses.');
        }
    }

    // Mengambil struk dari transaksi yang sudah selesai dibayar (struk dibuat
    // otomatis di prosesPembayaran()). Throw Exception jika belum ada.
    public function cetakStruk(Transaksi $t): Struk
    {
        $struk = $t->getStruk();

        if ($struk === null) {
            throw new Exception('Transaksi belum selesai dibayar, struk belum tersedia.');
        }

        return $struk;
    }
}
