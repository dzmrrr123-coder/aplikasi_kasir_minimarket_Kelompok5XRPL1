<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Member;
use App\Models\Pembelian;
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

    /**
     * Data tabel pembelian / stok masuk (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelPembelian(array $params = []): array
    {
        $reporter = new Pembelian();

        return $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }

    /**
     * Data tabel member (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelMember(array $params = []): array
    {
        $reporter = new Member();

        return $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }

    /**
     * Harga beli terakhir produk dari supplier (dipakai form produk:
     * saat supplier dipilih, harga beli otomatis terisi).
     *
     * @param array<string, mixed> $params produk_id & supplier_id
     */
    public function hargaBeliTerakhir(array $params = []): array
    {
        $produkId = (int) ($params['produk_id'] ?? 0);
        $supplierId = (int) ($params['supplier_id'] ?? 0);

        if ($produkId <= 0) {
            return ['harga_beli' => 0.0];
        }

        return [
            'harga_beli' => Produk::hargaBeliTerakhir($produkId, $supplierId),
        ];
    }

    /**
     * Cari member berdasarkan nomor telepon (dipakai layar POS).
     *
     * @param array<string, mixed> $params
     */
    public function cariMember(array $params = []): array
    {
        $telepon = trim((string) ($params['telepon'] ?? ''));
        $member = Member::cariBerdasarkanTelepon($telepon);

        if ($member === null) {
            return ['ditemukan' => false];
        }

        return [
            'ditemukan' => true,
            'id'        => (int) $member->getId(),
            'nama'      => $member->getNama(),
            'telepon'   => $member->getTelepon(),
            'poin'      => $member->getPoin(),
        ];
    }
}
