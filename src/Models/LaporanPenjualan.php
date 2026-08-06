<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class LaporanPenjualan implements Observer, DataReporter
{
    private DateTimeImmutable $tanggalMulai;
    private DateTimeImmutable $tanggalAkhir;
    private array $transaksi = []; // Transaksi[]

    public function __construct()
    {
        $this->tanggalMulai = new DateTimeImmutable();
        $this->tanggalAkhir = new DateTimeImmutable();
    }

    /**
     * Observer Pattern: dipanggil Subject (Transaksi) saat notify().
     * Mencatat rekap penjualan transaksi yang baru selesai ke database.
     */
    public function update(Subject $subject): void
    {
        if (!$subject instanceof Transaksi || $subject->getId() === '') {
            return;
        }

        $metode = $subject->getPembayaran()?->getNamaMetode() ?? 'Tunai';

        $stmt = Database::connect()->prepare(
            'INSERT INTO rekap_penjualan (transaksi_id, tanggal, total, kasir_id, metode)
             VALUES (:transaksi_id, :tanggal, :total, :kasir_id, :metode)'
        );
        $stmt->execute([
            ':transaksi_id' => (int) $subject->getId(),
            ':tanggal'      => $subject->getTanggal()->format('Y-m-d H:i:s'),
            ':total'        => $subject->getTotal(),
            ':kasir_id'     => $subject->getKasirId(),
            ':metode'       => $metode,
        ]);
    }

    public function getTanggalMulai(): DateTimeImmutable
    {
        return $this->tanggalMulai;
    }

    public function getTanggalAkhir(): DateTimeImmutable
    {
        return $this->tanggalAkhir;
    }

    public function setPeriode(DateTimeImmutable $tanggalMulai, DateTimeImmutable $tanggalAkhir): void
    {
        $this->tanggalMulai = $tanggalMulai;
        $this->tanggalAkhir = $tanggalAkhir;
    }

    /**
     * Mengambil semua transaksi dalam rentang tanggal (tanggalAkhir inklusif,
     * ditambah 1 hari agar transaksi pada tanggal akhir ikut terhitung).
     * Nama kasir ikut diambil lewat JOIN users.
     *
     * @return Transaksi[]
     */
    public function ambilTransaksiPeriode(): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT t.id, t.tanggal, t.total, t.kasir_id, u.nama AS kasir_nama
             FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir
             ORDER BY t.tanggal ASC'
        );
        $stmt->execute([
            ':mulai' => $this->tanggalMulai->format('Y-m-d 00:00:00'),
            ':akhir' => $this->tanggalAkhir->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);

        return array_map(
            static fn (array $row): Transaksi => new Transaksi($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Generate laporan penjualan sesuai alur pada spesifikasi:
     * ambil transaksi di rentang tanggal -> kalau kosong, "tidak ada data" ->
     * kalau ada, kumpulkan subtotal tiap transaksi lalu hitung ringkasan.
     *
     * @return array<string, mixed> Berisi 'transaksi' dan ringkasan (jumlah, total)
     */
    public function generate(): array
    {
        $this->transaksi = $this->ambilTransaksiPeriode();

        if (count($this->transaksi) === 0) {
            return [
                'pesan'      => 'Tidak ada data penjualan pada periode tersebut.',
                'transaksi'  => [],
                'jumlah'     => 0,
                'total'      => 0.0,
            ];
        }

        $total = 0.0;

        foreach ($this->transaksi as $transaksi) {
            // Subtotal tiap transaksi: total yang sudah termasuk diskon
            // (disimpan di kolom `total` saat transaksi diproses).
            $total += $transaksi->getTotal();
        }

        return [
            'pesan'      => 'Laporan penjualan berhasil dibuat.',
            'transaksi'  => $this->transaksi,
            'jumlah'     => count($this->transaksi),
            'total'      => $total,
        ];
    }

    /**
     * Mengekspor laporan sebagai file PDF sungguhan (via Dompdf).
     * Layout: header periode + tabel transaksi + ringkasan.
     * Mengembalikan konten PDF biner (siap dikirim sebagai download).
     */
    public function eksporPDF(): string
    {
        $laporan = $this->generate();

        $barisTabel = '';
        foreach ($laporan['transaksi'] as $transaksi) {
            $barisTabel .= sprintf(
                '<tr><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right">%s</td></tr>',
                htmlspecialchars($transaksi->getId()),
                htmlspecialchars($transaksi->getTanggal()->format('d-m-Y H:i')),
                htmlspecialchars($transaksi->getKasirNama()),
                'Rp ' . number_format($transaksi->getTotal(), 0, ',', '.')
            );
        }

        $pesanKosong = count($laporan['transaksi']) === 0
            ? '<p style="color:#999">Tidak ada data penjualan pada periode tersebut.</p>'
            : '';

        $html = '
<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    .periode { color: #666; margin-bottom: 16px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
    th { background: #f0f0f0; }
    .ringkasan { margin-top: 16px; }
    .ringkasan td { border: none; padding: 2px 8px; }
    .ringkasan .label { font-weight: bold; }
</style></head>
<body>
    <h1>' . htmlspecialchars(\App\Models\Pengaturan::get('nama_toko', 'Minimarket')) . '</h1>
    <div class="periode">Laporan Penjualan — '
    . htmlspecialchars($this->tanggalMulai->format('d-m-Y')) . ' s/d '
    . htmlspecialchars($this->tanggalAkhir->format('d-m-Y')) . '</div>
    <table>
        <thead><tr><th>No. Transaksi</th><th>Tanggal</th><th>Kasir</th><th style="text-align:right">Total</th></tr></thead>
        <tbody>' . $barisTabel . '</tbody>
    </table>
    ' . $pesanKosong . '
    <table class="ringkasan">
        <tr><td class="label">Jumlah transaksi</td><td>' . (int) $laporan['jumlah'] . '</td></tr>
        <tr><td class="label">Total penjualan</td><td>Rp ' . number_format($laporan['total'], 0, ',', '.') . '</td></tr>
    </table>
</body>
</html>';

        $dompdf = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont'     => 'DejaVu Sans',
        ]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    /**
     * Mengekspor laporan sebagai CSV (alternatif ringan, bisa dibuka
     * di Excel/Spreadsheet).
     */
    public function keCsv(): string
    {
        $laporan = $this->generate();

        $baris = [
            ['Periode', $this->tanggalMulai->format('d-m-Y') . ' s/d ' . $this->tanggalAkhir->format('d-m-Y')],
            [],
            ['No. Transaksi', 'Tanggal', 'Kasir', 'Total'],
        ];

        foreach ($laporan['transaksi'] as $transaksi) {
            $baris[] = [
                $transaksi->getId(),
                $transaksi->getTanggal()->format('d-m-Y H:i'),
                $transaksi->getKasirNama(),
                $transaksi->getTotal(),
            ];
        }

        $baris[] = [];
        $baris[] = ['Jumlah transaksi', $laporan['jumlah']];
        $baris[] = ['Total penjualan', $laporan['total']];

        return self::keCsvDariBaris($baris);
    }

    /**
     * Mengubah array baris menjadi string CSV. Setiap nilai di-escape:
     * dibungkus tanda kutip ganda bila mengandung koma, tanda kutip,
     * atau baris baru; tanda kutip ganda di dalam nilai digandakan.
     *
     * @param array<int, array<int, int|float|string>> $baris
     */
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
        if (
            str_contains($nilai, ',')
            || str_contains($nilai, '"')
            || str_contains($nilai, "\n")
            || str_contains($nilai, "\r")
        ) {
            return '"' . str_replace('"', '""', $nilai) . '"';
        }

        return $nilai;
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik: penjualan per hari dalam periode.
     *
     * @param array<string, mixed> $params berisi tanggal_mulai & tanggal_akhir
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $mulai = new DateTimeImmutable((string) ($params['tanggal_mulai'] ?? date('Y-m-d', strtotime('-6 days'))));
        $akhir = new DateTimeImmutable((string) ($params['tanggal_akhir'] ?? date('Y-m-d')));
        $this->setPeriode($mulai, $akhir);

        $labels = [];
        $data = [];

        // Iterasi per hari dalam rentang, dibatasi maksimal 62 hari supaya
        // periode yang terlalu lebar tidak membuat loop tak berujung.
        $t = $mulai;
        $batasAkhir = $akhir->modify('+1 day');
        $hariIterasi = 0;

        while ($t < $batasAkhir && $hariIterasi < 62) {
            $hariMulai = $t->format('Y-m-d 00:00:00');
            $hariAkhir = $t->format('Y-m-d 23:59:59');

            $stmt = Database::connect()->prepare(
                'SELECT COALESCE(SUM(total), 0) AS total
                 FROM transaksi
                 WHERE tanggal >= :mulai AND tanggal <= :akhir'
            );
            $stmt->execute([':mulai' => $hariMulai, ':akhir' => $hariAkhir]);
            $total = (float) ($stmt->fetch()['total'] ?? 0);

            $labels[] = $t->format('d M');
            $data[] = $total;
            $t = $t->modify('+1 day');
            $hariIterasi++;
        }

        return [
            'labels' => $labels,
            'series' => [
                'label' => 'Penjualan',
                'data'  => $data,
            ],
        ];
    }

    /**
     * Data tabel: daftar transaksi dalam periode dengan dukungan
     * pencarian & pagination (untuk DataTables server-side).
     *
     * @param array<string, mixed> $params search/start/length/tanggal
     */
    public function getDataTabel(array $params = []): array
    {
        $mulai = new DateTimeImmutable((string) ($params['tanggal_mulai'] ?? date('Y-m-01')));
        $akhir = new DateTimeImmutable((string) ($params['tanggal_akhir'] ?? date('Y-m-d')));
        $this->setPeriode($mulai, $akhir);

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

        $total = (int) $pdo->query(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             WHERE t.tanggal >= ' . $pdo->quote($bind[':mulai']) . '
               AND t.tanggal < ' . $pdo->quote($bind[':akhir'])
        )->fetchColumn();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM transaksi t
             JOIN users u ON u.id = t.kasir_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT t.id, t.tanggal, t.total, u.nama AS kasir_nama
             FROM transaksi t
             JOIN users u ON u.id = t.kasir_id ' . $where . '
             ORDER BY t.tanggal DESC, t.id DESC
             LIMIT :limit OFFSET :offset'
        );

        // Bind semua parameter via bindValue (tidak mencampur execute(array)
        // dengan bindValue — bisa memicu HY093 pada emulasi prepare off).
        foreach ($bind as $kunci => $nilai) {
            $stmt->bindValue($kunci, $nilai);
        }
        $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            return [
                'id'         => $r['id'],
                'tanggal'    => (new DateTimeImmutable($r['tanggal']))->format('d-m-Y H:i'),
                'kasir_nama' => $r['kasir_nama'],
                'total'      => (float) $r['total'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
