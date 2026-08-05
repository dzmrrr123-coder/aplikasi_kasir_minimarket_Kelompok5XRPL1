<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\LaporanPenjualan;

/**
 * Controller Laporan: orkestrasi data untuk halaman laporan (admin).
 * Data dari DataReporter (LaporanPenjualan) → array JSON murni.
 */
class LaporanController
{
    /**
     * Data grafik penjualan per periode (Chart.js).
     *
     * @param array<string, mixed> $params tanggal_mulai/akhir
     */
    public function grafikPeriode(array $params = []): array
    {
        $reporter = new LaporanPenjualan();

        return $reporter->getAgregasiGrafik([
            'tanggal_mulai' => $params['tanggal_mulai'] ?? date('Y-m-01'),
            'tanggal_akhir' => $params['tanggal_akhir'] ?? date('Y-m-d'),
        ]);
    }

    /**
     * Data tabel transaksi per periode (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelTransaksi(array $params = []): array
    {
        $reporter = new LaporanPenjualan();

        return $reporter->getDataTabel([
            'tanggal_mulai' => $params['tanggal_mulai'] ?? date('Y-m-01'),
            'tanggal_akhir' => $params['tanggal_akhir'] ?? date('Y-m-d'),
            'search'        => $params['search'] ?? '',
            'start'         => $params['start'] ?? 0,
            'length'        => $params['length'] ?? 10,
        ]);
    }
}
