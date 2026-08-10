<?php
// src/Models/LaporanPenjualan.php
// Class LaporanPenjualan: rekap transaksi penjualan per rentang tanggal
// (dipakai Admin lewat lihatLaporan()).

class LaporanPenjualan
{
    private DateTime $tanggalMulai;
    private DateTime $tanggalAkhir;
    private ?array $lastGenerated = null; // cache hasil generate() untuk eksporPDF()

    public function __construct(DateTime $tanggalMulai, DateTime $tanggalAkhir)
    {
        $this->tanggalMulai = $tanggalMulai;
        $this->tanggalAkhir = $tanggalAkhir;
    }

    // Menghasilkan laporan sesuai alur spec bagian 5:
    // ambil transaksi 'selesai' di rentang tanggal -> kosong? pesan "tidak ada
    // data" : hitung & susun detail tiap transaksi beserta itemnya.
    public function generate(): array
    {
        $pdo = Database::getInstance()->getConnection();

        // Hanya transaksi berstatus 'selesai' ('pending'/'batal' tidak dihitung).
        // Rentang tanggal dianggap inklusif seharian penuh (00:00:00 s/d 23:59:59).
        $stmt = $pdo->prepare(
            "SELECT id, tanggal, total
             FROM transaksi
             WHERE status = 'selesai' AND tanggal BETWEEN :mulai AND :akhir
             ORDER BY tanggal"
        );
        $stmt->execute([
            'mulai' => $this->tanggalMulai->format('Y-m-d 00:00:00'),
            'akhir' => $this->tanggalAkhir->format('Y-m-d 23:59:59'),
        ]);
        $rows = $stmt->fetchAll();

        if (count($rows) === 0) {
            $this->lastGenerated = [
                'status'  => 'kosong',
                'message' => 'Tidak ada data',
                'data'    => [],
            ];

            return $this->lastGenerated;
        }

        // Ambil item tiap transaksi (join item_transaksi + produk).
        $stmtItem = $pdo->prepare(
            'SELECT i.qty, i.subtotal, pr.nama
             FROM item_transaksi i
             JOIN produk pr ON pr.id = i.produk_id
             WHERE i.transaksi_id = :id'
        );

        $data            = [];
        $totalPendapatan = 0.0;
        foreach ($rows as $row) {
            $stmtItem->execute(['id' => (int) $row['id']]);
            $items = [];
            foreach ($stmtItem->fetchAll() as $it) {
                $items[] = [
                    'nama'     => $it['nama'],
                    'qty'      => (int) $it['qty'],
                    'subtotal' => (float) $it['subtotal'],
                ];
            }

            $totalPendapatan += (float) $row['total'];
            $data[] = [
                'id'      => (string) $row['id'],
                'tanggal' => $row['tanggal'],
                'total'   => (float) $row['total'],
                'items'   => $items,
            ];
        }

        $this->lastGenerated = [
            'status'           => 'ok',
            'periode'          => [
                'mulai' => $this->tanggalMulai->format('Y-m-d'),
                'akhir' => $this->tanggalAkhir->format('Y-m-d'),
            ],
            'jumlah_transaksi' => count($data),
            'total_pendapatan' => $totalPendapatan,
            'data'             => $data,
        ];

        return $this->lastGenerated;
    }

    // Mengekspor hasil laporan ke file teks dan mengembalikan path-nya.
    // CATATAN: PDF asli butuh library seperti dompdf/mPDF, di luar scope
    // "PHP native tanpa dependency". Ganti implementasi ini kalau mau ekspor
    // PDF sungguhan.
    public function eksporPDF(): string
    {
        // Pakai hasil generate terakhir; generate dulu kalau belum pernah.
        $hasil = $this->lastGenerated ?? $this->generate();

        $baris   = [];
        $baris[] = 'LAPORAN PENJUALAN';
        $baris[] = str_repeat('=', 50);

        if ($hasil['status'] === 'kosong') {
            $baris[] = 'Periode : ' . $this->tanggalMulai->format('Y-m-d')
                . ' s/d ' . $this->tanggalAkhir->format('Y-m-d');
            $baris[] = $hasil['message'];
        } else {
            $baris[] = 'Periode          : ' . $hasil['periode']['mulai'] . ' s/d ' . $hasil['periode']['akhir'];
            $baris[] = 'Jumlah Transaksi : ' . $hasil['jumlah_transaksi'];
            $baris[] = 'Total Pendapatan : Rp ' . number_format($hasil['total_pendapatan'], 0, ',', '.');
            $baris[] = str_repeat('-', 50);
            $baris[] = 'Daftar Transaksi:';
            foreach ($hasil['data'] as $trx) {
                $baris[] = sprintf(
                    '#%s | %s | Rp %s',
                    $trx['id'],
                    $trx['tanggal'],
                    number_format($trx['total'], 0, ',', '.')
                );
                foreach ($trx['items'] as $item) {
                    $baris[] = sprintf(
                        '    - %s x%d = Rp %s',
                        $item['nama'],
                        $item['qty'],
                        number_format($item['subtotal'], 0, ',', '.')
                    );
                }
            }
        }

        // Simpan ke storage/laporan/ (buat folder kalau belum ada).
        $dir = __DIR__ . '/../../storage/laporan';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/laporan_' . date('Ymd_His') . '.txt';
        file_put_contents($path, implode(PHP_EOL, $baris) . PHP_EOL);

        return $path;
    }

    public function getTanggalMulai(): DateTime
    {
        return $this->tanggalMulai;
    }

    public function getTanggalAkhir(): DateTime
    {
        return $this->tanggalAkhir;
    }
}
