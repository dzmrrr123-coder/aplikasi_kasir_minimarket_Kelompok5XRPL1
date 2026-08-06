<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Laba;

/**
 * Controller Laba: orkestrasi data laporan laba/rugi (admin).
 * Data dari model Laba → array JSON murni.
 */
class LabaController
{
    /**
     * Ringkasan laba per periode.
     *
     * @param array<string, mixed> $params
     */
    public function ringkasan(array $params = []): array
    {
        return (new Laba())->ringkasan([
            'tanggal_mulai' => $params['tanggal_mulai'] ?? '',
            'tanggal_akhir' => $params['tanggal_akhir'] ?? '',
        ]);
    }

    /**
     * Data grafik laba (omzet & laba per hari).
     *
     * @param array<string, mixed> $params
     */
    public function grafik(array $params = []): array
    {
        return (new Laba())->getAgregasiGrafik([
            'tanggal_mulai' => $params['tanggal_mulai'] ?? '',
            'tanggal_akhir' => $params['tanggal_akhir'] ?? '',
        ]);
    }

    /**
     * Data tabel laba per transaksi (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabel(array $params = []): array
    {
        return (new Laba())->getDataTabel([
            'search'        => $params['search'] ?? '',
            'start'         => $params['start'] ?? 0,
            'length'        => $params['length'] ?? 10,
            'tanggal_mulai' => $params['tanggal_mulai'] ?? '',
            'tanggal_akhir' => $params['tanggal_akhir'] ?? '',
        ]);
    }
}
