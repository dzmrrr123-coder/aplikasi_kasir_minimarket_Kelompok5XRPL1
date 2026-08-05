<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Produk;

/**
 * Controller Inventaris: orkestrasi data produk/kategori (admin).
 * Data dari DataReporter (Produk) → array JSON murni.
 */
class InventarisController
{
    /**
     * Data tabel produk (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelProduk(array $params = []): array
    {
        $reporter = new Produk();

        return $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }
}
