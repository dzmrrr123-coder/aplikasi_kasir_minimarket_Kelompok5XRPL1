<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ReturBarang;

/**
 * Controller Retur: orkestrasi data untuk halaman retur (admin).
 * Data dari DataReporter (ReturBarang) → array JSON murni.
 */
class ReturController
{
    /**
     * Data tabel riwayat retur (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelRiwayat(array $params = []): array
    {
        $reporter = new ReturBarang();

        return $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }

    /**
     * Data grafik retur per bulan (Chart.js).
     *
     * @param array<string, mixed> $params
     */
    public function grafikBulanan(array $params = []): array
    {
        $reporter = new ReturBarang();

        return $reporter->getAgregasiGrafik([
            'bulan' => $params['bulan'] ?? 6,
        ]);
    }
}
