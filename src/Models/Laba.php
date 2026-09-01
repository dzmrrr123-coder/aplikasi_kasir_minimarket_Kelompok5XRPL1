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
    private DateTimeImmutable $mulai;
    private DateTimeImmutable $akhir;

    public function __construct()
    {
        $this->mulai = new DateTimeImmutable(date('Y-m-01'));
        $this->akhir = new DateTimeImmutable(date('Y-m-d'));
    }

    public function setPeriode(DateTimeImmutable $mulai, DateTimeImmutable $akhir): void
    {
        $this->mulai = $mulai;
        $this->akhir = $akhir;
    }

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
            $where .= ' AND (CAST(t.id AS CHAR) LIKE :cari_id OR u.nama LIKE :cari_nama)';
            $bind[':cari_id'] = '%' . $cari . '%';
            $bind[':cari_nama'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmtTotal = $pdo->prepare(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             WHERE t.tanggal >= :mulai
               AND t.tanggal < :akhir'
        );
        $stmtTotal->execute([
            ':mulai' => $bind[':mulai'],
            ':akhir' => $bind[':akhir'],
        ]);
        $total = (int) $stmtTotal->fetchColumn();

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

    /**
     * Ekspor laporan laba sebagai PDF (via Dompdf).
     */
    public function eksporPDF(): string
    {
        $ringkasan = $this->ringkasan([
            'tanggal_mulai' => $this->mulai->format('Y-m-d'),
            'tanggal_akhir' => $this->akhir->format('Y-m-d'),
        ]);

        $tabel = $this->getDataTabel([
            'tanggal_mulai' => $this->mulai->format('Y-m-d'),
            'tanggal_akhir' => $this->akhir->format('Y-m-d'),
            'start' => 0,
            'length' => 1000,
        ]);

        $barisTabel = '';
        foreach ($tabel['rows'] as $row) {
            $labaClass = $row['laba'] >= 0 ? 'color:green' : 'color:red';
            $barisTabel .= sprintf(
                '<tr><td>%s</td><td>%s</td><td style="text-align:right">%s</td><td style="text-align:right">%s</td><td style="text-align:right;%s">%s</td></tr>',
                htmlspecialchars((string) $row['id']),
                htmlspecialchars($row['tanggal']),
                htmlspecialchars($row['kasir_nama']),
                'Rp ' . number_format($row['omzet'], 0, ',', '.'),
                $labaClass,
                'Rp ' . number_format($row['laba'], 0, ',', '.')
            );
        }

        $namaToko = Pengaturan::get('nama_toko', 'Minimarket');
        $periode = $this->mulai->format('d-m-Y') . ' s/d ' . $this->akhir->format('d-m-Y');
        $labaClass = $ringkasan['laba'] >= 0 ? 'color:green' : 'color:red';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
            h1 { font-size: 18px; margin: 0 0 2px; }
            .periode { color: #666; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
            th { background: #f0f0f0; }
            .ringkasan { margin-top: 16px; }
            .ringkasan td { border: none; padding: 2px 8px; }
            .ringkasan .label { font-weight: bold; }
        </style></head><body>
            <h1>' . htmlspecialchars($namaToko) . '</h1>
            <div class="periode">Laporan Laba & Rugi — ' . htmlspecialchars($periode) . '</div>
            <table class="ringkasan">
                <tr><td class="label">Omzet</td><td>Rp ' . number_format($ringkasan['omzet'], 0, ',', '.') . '</td></tr>
                <tr><td class="label">HPP</td><td>Rp ' . number_format($ringkasan['hpp'], 0, ',', '.') . '</td></tr>
                <tr><td class="label">Laba</td><td style="' . $labaClass . ';font-weight:bold">Rp ' . number_format($ringkasan['laba'], 0, ',', '.') . '</td></tr>
                <tr><td class="label">Margin</td><td>' . number_format($ringkasan['margin'], 1) . '%</td></tr>
                <tr><td class="label">Jumlah Transaksi</td><td>' . $ringkasan['jumlah_transaksi'] . '</td></tr>
            </table>
            <table>
                <thead><tr><th>No.</th><th>Tanggal</th><th>Kasir</th><th style="text-align:right">Omzet</th><th style="text-align:right">Laba</th></tr></thead>
                <tbody>' . $barisTabel . '</tbody>
            </table>
        </body></html>';

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Ekspor laporan laba sebagai CSV.
     */
    public function keCsv(): string
    {
        $ringkasan = $this->ringkasan([
            'tanggal_mulai' => $this->mulai->format('Y-m-d'),
            'tanggal_akhir' => $this->akhir->format('Y-m-d'),
        ]);

        $tabel = $this->getDataTabel([
            'tanggal_mulai' => $this->mulai->format('Y-m-d'),
            'tanggal_akhir' => $this->akhir->format('Y-m-d'),
            'start' => 0,
            'length' => 1000,
        ]);

        $periode = $this->mulai->format('d-m-Y') . ' s/d ' . $this->akhir->format('d-m-Y');
        $baris = [
            ['Periode', $periode],
            [],
            ['Omzet', $ringkasan['omzet']],
            ['HPP', $ringkasan['hpp']],
            ['Laba', $ringkasan['laba']],
            ['Margin', number_format($ringkasan['margin'], 1) . '%'],
            ['Jumlah Transaksi', $ringkasan['jumlah_transaksi']],
            [],
            ['No.', 'Tanggal', 'Kasir', 'Omzet', 'HPP', 'Laba'],
        ];

        foreach ($tabel['rows'] as $row) {
            $baris[] = [
                $row['id'],
                $row['tanggal'],
                $row['kasir_nama'],
                $row['omzet'],
                $row['hpp'],
                $row['laba'],
            ];
        }

        return self::keCsvDariBaris($baris);
    }

    private static function keCsvDariBaris(array $baris): string
    {
        $out = '';
        foreach ($baris as $kolom) {
            $escaped = array_map(
                static fn ($nilai): string => self::escapeCsv((string) $nilai),
                $kolom
            );
            $out .= implode(',', $escaped) . "\r\n";
        }
        return $out;
    }

    private static function escapeCsv(string $nilai): string
    {
        if (str_contains($nilai, ',') || str_contains($nilai, '"') || str_contains($nilai, "\n") || str_contains($nilai, "\r")) {
            return '"' . str_replace('"', '""', $nilai) . '"';
        }
        return $nilai;
    }
}
