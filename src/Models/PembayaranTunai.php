<?php

declare(strict_types=1);

namespace App\Models;

class PembayaranTunai extends Pembayaran
{
    protected function getJenis(): string
    {
        return 'tunai';
    }

    public function proses(): bool
    {
        // Pembayaran tunai dianggap berhasil bila jumlah yang dibayarkan
        // sudah terisi (>= 0) dan transaksi sudah memastikan jumlah cukup
        // saat menghitung kembalian.
        return $this->jumlah >= 0;
    }
}
