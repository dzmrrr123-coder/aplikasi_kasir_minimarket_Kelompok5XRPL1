<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Dashboard;
use App\Models\DataReporter;
use App\Models\LaporanPenjualan;
use App\Models\Produk;

/**
 * Controller Dashboard: orkestrasi data untuk halaman dashboard (admin).
 *
 * Tidak melakukan query database langsung — semua data diambil dari
 * objek DataReporter (polimorfisme) dan dibungkus sebagai array JSON
 * murni. View hanya mengonsumsi JSON ini via fetch()/DataTables ajax.
 */
class DashboardController
{
    /**
     * Ringkasan kartu statistik (fetch JSON).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, int|float>
     */
    public function ringkasan(array $params = []): array
    {
        return Dashboard::ringkasanHariIni();
    }

    /**
     * Data grafik penjualan 7 hari (Chart.js).
     *
     * @param array<string, mixed> $params
     */
    public function grafikPenjualan(array $params = []): array
    {
        $reporter = new LaporanPenjualan();

        return $reporter->getAgregasiGrafik([
            'tanggal_mulai' => date('Y-m-d', strtotime('-6 days')),
            'tanggal_akhir' => date('Y-m-d'),
        ]);
    }

    /**
     * Data tabel produk terlaris (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelTerlaris(array $params = []): array
    {
        // Produk memakai DataReporter; terlaris = ambil semua lalu urut qty.
        $reporter = new Produk();
        $data = $reporter->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);

        // Terlaris berbasis penjualan: ambil agregasi qty via Dashboard.
        $terlaris = Dashboard::produkTerlaris((int) ($params['length'] ?? 10));

        $rows = array_map(static function (array $t): array {
            return [
                'nama'  => $t['nama'],
                'qty'   => (int) $t['qty'],
                'total' => (float) $t['total'],
            ];
        }, $terlaris);

        return [
            'total'    => count($rows),
            'filtered' => count($rows),
            'rows'     => $rows,
        ];
    }

    /**
     * Data tabel transaksi terbaru (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelTransaksi(array $params = []): array
    {
        $reporter = new LaporanPenjualan();

        return $reporter->getDataTabel([
            'tanggal_mulai' => date('Y-m-d', strtotime('-30 days')),
            'tanggal_akhir' => date('Y-m-d'),
            'search'        => $params['search'] ?? '',
            'start'         => $params['start'] ?? 0,
            'length'        => $params['length'] ?? 10,
        ]);
    }

    /**
     * Data grafik stok per kategori (Chart.js) — memakai Produk.
     *
     * @param array<string, mixed> $params
     */
    public function grafikStokKategori(array $params = []): array
    {
        $reporter = new Produk();

        return $reporter->getAgregasiGrafik([
            'limit' => $params['limit'] ?? 8,
        ]);
    }
}
