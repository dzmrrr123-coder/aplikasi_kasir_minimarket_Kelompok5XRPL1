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
     * Supplier asal (dari pembelian/stok masuk terakhir) sebuah produk.
     * Dipakai form retur: setelah admin memilih produk, dropdown supplier
     * otomatis diisi supplier yang memasok produk itu.
     *
     * @param array<string, mixed> $params
     */
    public function supplierAsalProduk(array $params = []): array
    {
        $produkId = (int) ($params['produk_id'] ?? 0);
        $asal = ReturBarang::pembelianTerakhirProduk($produkId);

        if ($asal === null) {
            return [
                'ditemukan'   => false,
                'supplier_id' => 0,
                'nama'        => '',
                'pembelian_id' => 0,
                'tanggal_beli' => '',
            ];
        }

        return [
            'ditemukan'    => true,
            'supplier_id'  => (int) $asal['supplier_id'],
            'nama'         => $asal['supplier_nama'],
            'pembelian_id' => (int) $asal['pembelian_id'],
            'tanggal_beli' => $asal['tanggal_beli'],
        ];
    }

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
