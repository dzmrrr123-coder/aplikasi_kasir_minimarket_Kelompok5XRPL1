<?php

declare(strict_types=1);

namespace App\Models;

/**
 * DataReporter: kontrak model pelapor data (Polimorfisme).
 *
 * Model yang mengimplementasikan interface ini sanggup menyuplai:
 *  - data agregasi untuk grafik (Chart.js) via getAgregasiGrafik()
 *  - data tabular untuk DataTables via getDataTabel()
 *
 * Controller PBO memanggil method ini dan membungkus hasilnya menjadi
 * JSON murni; View hanya mengonsumsi JSON tersebut (tidak menyentuh
 * database / query langsung).
 */
interface DataReporter
{
    /**
     * Data agregasi untuk grafik (Chart.js).
     *
     * @param array<string, mixed> $params filter (tanggal_mulai/akhir, limit, dst.)
     *
     * @return array{labels: string[], series: array{label: string, data: array<int, int|float>}}
     */
    public function getAgregasiGrafik(array $params = []): array;

    /**
     * Data tabular untuk DataTables (server-side).
     *
     * @param array<string, mixed> $params filter (search, start, length, order, dst.)
     *
     * @return array{total: int, filtered: int, rows: array<int, array<string, mixed>>}
     */
    public function getDataTabel(array $params = []): array;
}
