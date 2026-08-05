<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Strategi pembayaran tunai.
 *
 * Aturan: jumlah yang dibayar harus menutupi total transaksi, dan
 * kembalian dihitung sebagai selisih jumlah bayar terhadap total.
 */
class PembayaranTunai extends Pembayaran
{
    protected function getJenis(): string
    {
        return 'tunai';
    }

    public function prosesBayar(float $total, float $jumlahBayar): bool
    {
        return $jumlahBayar >= $total;
    }

    public function getNamaMetode(): string
    {
        return 'Tunai';
    }

    public function hitungKembalian(float $total, float $jumlahBayar): float
    {
        return max(0.0, $jumlahBayar - $total);
    }
}
