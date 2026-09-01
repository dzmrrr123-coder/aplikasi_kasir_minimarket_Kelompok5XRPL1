<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class Analitik
{
    /**
     * Tren penjualan harian dalam satu bulan.
     *
     * @return array<int, array{tanggal:string, jumlah:int, total:float}>
     */
    public static function trenHarian(int $tahun, int $bulan): array
    {
        $mulai = sprintf('%04d-%02d-01', $tahun, $bulan);
        $akhir = (new \DateTimeImmutable($mulai))->modify('+1 month')->format('Y-m-d');

        $stmt = Database::connect()->prepare(
            'SELECT DATE(tanggal) AS tanggal,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(total), 0) AS total
             FROM transaksi
             WHERE tanggal >= :mulai AND tanggal < :akhir
             GROUP BY DATE(tanggal)
             ORDER BY tanggal'
        );
        $stmt->execute([':mulai' => $mulai, ':akhir' => $akhir]);

        $hasil = [];
        foreach ($stmt->fetchAll() as $row) {
            $hasil[] = [
                'tanggal' => $row['tanggal'],
                'jumlah'  => (int) $row['jumlah'],
                'total'   => (float) $row['total'],
            ];
        }

        return $hasil;
    }

    /**
     * Tren penjualan bulanan dalam satu tahun.
     *
     * @return array<int, array{bulan:string, nama_bulan:string, jumlah:int, total:float}>
     */
    public static function trenBulanan(int $tahun): array
    {
        $mulai = $tahun . '-01-01';
        $akhir = ($tahun + 1) . '-01-01';

        $stmt = Database::connect()->prepare(
            'SELECT DATE_FORMAT(tanggal, \'%Y-%m\') AS bulan,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(total), 0) AS total
             FROM transaksi
             WHERE tanggal >= :mulai AND tanggal < :akhir
             GROUP BY DATE_FORMAT(tanggal, \'%Y-%m\')
             ORDER BY bulan'
        );
        $stmt->execute([':mulai' => $mulai, ':akhir' => $akhir]);

        $namaBulan = [
            'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
            'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
        ];

        // Isi semua 12 bulan (termasuk yang 0 transaksi)
        $mapBulan = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapBulan[$row['bulan']] = [
                'jumlah' => (int) $row['jumlah'],
                'total'  => (float) $row['total'],
            ];
        }

        $hasil = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%04d-%02d', $tahun, $m);
            $hasil[] = [
                'bulan'      => $key,
                'nama_bulan' => $namaBulan[$m - 1],
                'jumlah'     => $mapBulan[$key]['jumlah'] ?? 0,
                'total'      => $mapBulan[$key]['total'] ?? 0.0,
            ];
        }

        return $hasil;
    }

    /**
     * Tren penjualan tahunan (5 tahun terakhir).
     *
     * @return array<int, array{tahun:int, jumlah:int, total:float}>
     */
    public static function trenTahunan(): array
    {
        $tahunSekarang = (int) date('Y');
        $tahunAwal = $tahunSekarang - 4;

        $stmt = Database::connect()->prepare(
            'SELECT YEAR(tanggal) AS tahun,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(total), 0) AS total
             FROM transaksi
             WHERE tanggal >= :mulai
             GROUP BY YEAR(tanggal)
             ORDER BY tahun'
        );
        $stmt->execute([':mulai' => $tahunAwal . '-01-01']);

        $mapTahun = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapTahun[(int) $row['tahun']] = [
                'jumlah' => (int) $row['jumlah'],
                'total'  => (float) $row['total'],
            ];
        }

        $hasil = [];
        for ($y = $tahunAwal; $y <= $tahunSekarang; $y++) {
            $hasil[] = [
                'tahun'  => $y,
                'jumlah' => $mapTahun[$y]['jumlah'] ?? 0,
                'total'  => $mapTahun[$y]['total'] ?? 0.0,
            ];
        }

        return $hasil;
    }

    /**
     * Hari tersibuk dalam seminggu (berdasarkan data transaksi).
     *
     * @return array<int, array{hari:string, nama_hari:string, jumlah:int, total:float}>
     */
    public static function hariTersibuk(string $tanggalMulai, string $tanggalAkhir): array
    {
        $akhir = (new \DateTimeImmutable($tanggalAkhir))->modify('+1 day')->format('Y-m-d');

        $stmt = Database::connect()->prepare(
            'SELECT DAYOFWEEK(tanggal) AS hari,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(total), 0) AS total
             FROM transaksi
             WHERE tanggal >= :mulai AND tanggal < :akhir
             GROUP BY DAYOFWEEK(tanggal)
             ORDER BY hari'
        );
        $stmt->execute([':mulai' => $tanggalMulai, ':akhir' => $akhir]);

        $namaHari = [
            1 => 'Minggu', 2 => 'Senin', 3 => 'Selasa', 4 => 'Rabu',
            5 => 'Kamis', 6 => 'Jumat', 7 => 'Sabtu',
        ];

        $mapHari = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapHari[(int) $row['hari']] = [
                'jumlah' => (int) $row['jumlah'],
                'total'  => (float) $row['total'],
            ];
        }

        $hasil = [];
        for ($h = 1; $h <= 7; $h++) {
            $hasil[] = [
                'hari'      => (string) $h,
                'nama_hari' => $namaHari[$h],
                'jumlah'    => $mapHari[$h]['jumlah'] ?? 0,
                'total'     => $mapHari[$h]['total'] ?? 0.0,
            ];
        }

        return $hasil;
    }

    /**
     * Analisis metode pembayaran dalam periode.
     *
     * @return array<int, array{metode:string, jumlah:int, total:float}>
     */
    public static function metodePembayaran(string $tanggalMulai, string $tanggalAkhir): array
    {
        $akhir = (new \DateTimeImmutable($tanggalAkhir))->modify('+1 day')->format('Y-m-d');

        $stmt = Database::connect()->prepare(
            'SELECT COALESCE(p.jenis, \'tunai\') AS metode,
                    COUNT(*) AS jumlah,
                    COALESCE(SUM(t.total), 0) AS total
             FROM transaksi t
             LEFT JOIN pembayaran p ON p.id = t.pembayaran_id
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir
             GROUP BY metode
             ORDER BY total DESC'
        );
        $stmt->execute([':mulai' => $tanggalMulai, ':akhir' => $akhir]);

        $hasil = [];
        foreach ($stmt->fetchAll() as $row) {
            $hasil[] = [
                'metode' => $row['metode'] === 'non_tunai' ? 'Non-tunai (QRIS/EDC)' : 'Tunai',
                'jumlah' => (int) $row['jumlah'],
                'total'  => (float) $row['total'],
            ];
        }

        return $hasil;
    }
}
