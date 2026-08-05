<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Produk;
use App\Models\Supplier;

/**
 * Controller Inventaris: orkestrasi data produk/kategori/supplier (admin).
 * Data dari DataReporter → array JSON murni.
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

    /**
     * Data tabel supplier (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelSupplier(array $params = []): array
    {
        $reporter = new Supplier();

        return $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }
}
