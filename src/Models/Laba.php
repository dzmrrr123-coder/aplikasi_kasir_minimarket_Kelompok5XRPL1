<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use App\Database\Database;

/**
 * Laporan laba/rugi per periode.
 *
 * Laba kotor = total penjualan - HPP (harga beli produk saat ini x qty terjual).
 * Catatan: harga beli yang dipakai adalah harga beli TERAKHIR produk
 * (tidak ada histori harga beli per item transaksi).
 */
class Laba implements DataReporter
{
    /**
     * Ringkasan laba per periode (tanggal_mulai & tanggal_akhir di params).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> omzet, hpp, laba, margin, jumlah transaksi
     */
    public function ringkasan(array $params = []): array
    {
        $mulai = new DateTimeImmutable((string) ($params['tanggal_mulai'] ?? date('Y-m-01')));
        $akhir = new DateTimeImmutable((string) ($params['tanggal_akhir'] ?? date('Y-m-d')));

        $stmt = Database::connect()->prepare(
            'SELECT COALESCE(SUM(t.total), 0) AS omzet,
                    COALESCE(SUM(it.harga_beli_satuan * it.qty), 0) AS hpp
             FROM transaksi t
             JOIN item_transaksi it ON it.transaksi_id = t.id
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir'
        );
        $stmt->execute([
            ':mulai' => $mulai->format('Y-m-d 00:00:00'),
            ':akhir' => $akhir->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);
        $row = $stmt->fetch();

        $omzet = (float) $row['omzet'];
        $hpp = (float) $row['hpp'];
        $laba = $omzet - $hpp;

        return [
            'omzet'            => $omzet,
            'hpp'              => $hpp,
            'laba'             => $laba,
            'margin'           => $omzet > 0 ? ($laba / $omzet) * 100 : 0.0,
            'jumlah_transaksi' => (int) $this->jumlahTransaksi($mulai, $akhir),
        ];
    }

    /** Jumlah transaksi dalam periode. */
    private function jumlahTransaksi(DateTimeImmutable $mulai, DateTimeImmutable $akhir): int
    {
        $stmt = Database::connect()->prepare(
            'SELECT COUNT(*) FROM transaksi
             WHERE tanggal >= :mulai AND tanggal < :akhir'
        );
        $stmt->execute([
            ':mulai' => $mulai->format('Y-m-d 00:00:00'),
            ':akhir' => $akhir->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);

        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik laba: omzet & laba per hari dalam periode.
     *
     * @param array<string, mixed> $params tanggal_mulai & tanggal_akhir
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $mulai = new DateTimeImmutable((string) ($params['tanggal_mulai'] ?? date('Y-m-d', strtotime('-6 days'))));
        $akhir = new DateTimeImmutable((string) ($params['tanggal_akhir'] ?? date('Y-m-d')));

        $labels = [];
        $omzetData = [];
        $labaData = [];

        $t = $mulai;
        $batasAkhir = $akhir->modify('+1 day');
        $hariIterasi = 0;

        while ($t < $batasAkhir && $hariIterasi < 62) {
            $hariMulai = $t->format('Y-m-d 00:00:00');
            $hariAkhir = $t->format('Y-m-d 23:59:59');

            $stmt = Database::connect()->prepare(
                'SELECT COALESCE(SUM(t.total), 0) AS omzet,
                        COALESCE(SUM(it.harga_beli_satuan * it.qty), 0) AS hpp
                 FROM transaksi t
                 JOIN item_transaksi it ON it.transaksi_id = t.id
                 WHERE t.tanggal >= :mulai AND t.tanggal <= :akhir'
            );
            $stmt->execute([':mulai' => $hariMulai, ':akhir' => $hariAkhir]);
            $row = $stmt->fetch();

            $omzet = (float) ($row['omzet'] ?? 0);
            $hpp = (float) ($row['hpp'] ?? 0);

            $labels[] = $t->format('d M');
            $omzetData[] = $omzet;
            $labaData[] = $omzet - $hpp;
            $t = $t->modify('+1 day');
            $hariIterasi++;
        }

        return [
            'labels' => $labels,
            'series' => [
                'omzet' => $omzetData,
                'laba'  => $labaData,
            ],
        ];
    }

    /**
     * Data tabel laba per transaksi: tanggal, no, kasir, omzet, hpp, laba.
     *
     * @param array<string, mixed> $params search/start/length/tanggal
     */
    public function getDataTabel(array $params = []): array
    {
        $mulai = new DateTimeImmutable((string) ($params['tanggal_mulai'] ?? date('Y-m-01')));
        $akhir = new DateTimeImmutable((string) ($params['tanggal_akhir'] ?? date('Y-m-d')));

        $cari = trim((string) ($params['search'] ?? ''));
        $start = max(0, (int) ($params['start'] ?? 0));
        $length = max(1, (int) ($params['length'] ?? 10));

        $where = 'WHERE t.tanggal >= :mulai AND t.tanggal < :akhir';
        $bind = [
            ':mulai' => $mulai->format('Y-m-d 00:00:00'),
            ':akhir' => $akhir->modify('+1 day')->format('Y-m-d 00:00:00'),
        ];

        if ($cari !== '') {
            $where .= ' AND (CAST(t.id AS CHAR) LIKE :cari OR u.nama LIKE :cari)';
            $bind[':cari'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $total = (int) $pdo->query(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             WHERE t.tanggal >= ' . $pdo->quote($bind[':mulai']) . '
               AND t.tanggal < ' . $pdo->quote($bind[':akhir'])
        )->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT t.id, t.tanggal, t.total, u.nama AS kasir_nama,
                    COALESCE(SUM(it.harga_beli_satuan * it.qty), 0) AS hpp
             FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             LEFT JOIN item_transaksi it ON it.transaksi_id = t.id ' . $where . '
             GROUP BY t.id, t.tanggal, t.total, u.nama
             ORDER BY t.tanggal DESC, t.id DESC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bind as $kunci => $nilai) {
            $stmt->bindValue($kunci, $nilai);
        }
        $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            $omzet = (float) $r['total'];
            $hpp = (float) $r['hpp'];
            $laba = $omzet - $hpp;

            return [
                'id'         => $r['id'],
                'tanggal'    => (new DateTimeImmutable($r['tanggal']))->format('d-m-Y H:i'),
                'kasir_nama' => $r['kasir_nama'],
                'omzet'      => $omzet,
                'hpp'        => $hpp,
                'laba'       => $laba,
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
