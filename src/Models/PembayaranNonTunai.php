<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Strategi pembayaran non-tunai (QRIS / EDC / e-wallet).
 *
 * Aturan: jumlah yang dibayar harus positif dan menutupi total transaksi.
 * Metode ini tidak punya konsep kembalian (dibayar pas), jadi kembalian 0.
 */
class PembayaranNonTunai extends Pembayaran
{
    protected function getJenis(): string
    {
        return 'non_tunai';
    }

    public function prosesBayar(float $total, float $jumlahBayar): bool
    {
        return $jumlahBayar > 0 && $jumlahBayar >= $total;
    }

    public function getNamaMetode(): string
    {
        return 'Non-tunai';
    }

    public function hitungKembalian(float $total, float $jumlahBayar): float
    {
        return 0.0;
    }
}
