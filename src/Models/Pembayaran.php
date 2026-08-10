<?php
// src/Models/Pembayaran.php
// Abstract class Pembayaran: dasar polymorphic untuk PembayaranTunai &
// PembayaranNonTunai (relasi Transaksi -> Pembayaran).

abstract class Pembayaran
{
    protected float $jumlah;

    public function __construct(float $jumlah)
    {
        $this->jumlah = $jumlah;
    }

    // Memproses pembayaran; true jika berhasil, false jika gagal.
    abstract public function proses(): bool;

    public function getJumlah(): float
    {
        return $this->jumlah;
    }
}
