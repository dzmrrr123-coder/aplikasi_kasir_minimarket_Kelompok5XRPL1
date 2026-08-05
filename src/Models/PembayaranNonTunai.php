<?php

declare(strict_types=1);

namespace App\Models;

class PembayaranNonTunai extends Pembayaran
{
    protected function getJenis(): string
    {
        return 'non_tunai';
    }

    public function proses(): bool
    {
        // Pembayaran non-tunai (kartu/QRIS/e-wallet) dianggap berhasil
        // bila jumlah pembayaran sudah terisi.
        return $this->jumlah > 0;
    }
}
