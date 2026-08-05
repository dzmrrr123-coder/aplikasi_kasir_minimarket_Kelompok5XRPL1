<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class LaporanPenjualan
{
    private DateTimeImmutable $tanggalMulai;
    private DateTimeImmutable $tanggalAkhir;
    private array $transaksi = []; // Transaksi[]

    public function __construct()
    {
        $this->tanggalMulai = new DateTimeImmutable();
        $this->tanggalAkhir = new DateTimeImmutable();
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
     *
     * @return Transaksi[]
     */
    public function ambilTransaksiPeriode(): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, tanggal, total, kasir_id
             FROM transaksi
             WHERE tanggal >= :mulai AND tanggal < :akhir
             ORDER BY tanggal ASC'
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
     * Mengekspor laporan sebagai CSV (pengganti ekspor PDF sederhana, tanpa
     * library eksternal). Hasil berupa string CSV yang bisa disimpan ke file
     * dan dibuka di Excel/Spreadsheet.
     */
    public function eksporPDF(): string
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
                $transaksi->getKasirId(),
                $transaksi->getTotal(),
            ];
        }

        $baris[] = [];
        $baris[] = ['Jumlah transaksi', $laporan['jumlah']];
        $baris[] = ['Total penjualan', $laporan['total']];

        return self::keCsv($baris);
    }

    /**
     * Mengubah array baris menjadi string CSV. Setiap nilai di-escape:
     * dibungkus tanda kutip ganda bila mengandung koma, tanda kutip,
     * atau baris baru; tanda kutip ganda di dalam nilai digandakan.
     *
     * @param array<int, array<int, int|float|string>> $baris
     */
    private static function keCsv(array $baris): string
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
}
