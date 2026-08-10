<?php
// src/Models/Struk.php
// Class Struk: bukti cetak transaksi penjualan dalam bentuk teks.

class Struk
{
    private const NAMA_TOKO = 'MINIMARKET SEJAHTERA';
    private const LEBAR     = 40;

    private string $transaksiId;
    private DateTime $tanggalCetak;

    // Struk dibuat dari id transaksi yang sudah tersimpan di database.
    public function __construct(int|string $transaksiId)
    {
        $this->transaksiId  = (string) $transaksiId;
        $this->tanggalCetak = new DateTime();
    }

    // Menyusun struk teks dari data transaksi lengkap di database
    // (item + produk, diskon jika ada, jenis pembayaran).
    // Throw RuntimeException jika transaksi tidak ditemukan.
    public function cetak(): string
    {
        $pdo = Database::getInstance()->getConnection();

        // Header transaksi + jenis pembayaran.
        $stmt = $pdo->prepare(
            'SELECT t.id, t.tanggal, t.total, t.diskon_id, p.jenis AS jenis_pembayaran
             FROM transaksi t
             LEFT JOIN pembayaran p ON p.id = t.pembayaran_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $this->transaksiId]);
        $trx = $stmt->fetch();

        if ($trx === false) {
            throw new RuntimeException("Transaksi #{$this->transaksiId} tidak ditemukan.");
        }

        // Item transaksi beserta nama & harga produk.
        $stmt = $pdo->prepare(
            'SELECT i.qty, i.subtotal, pr.nama, pr.harga
             FROM item_transaksi i
             JOIN produk pr ON pr.id = i.produk_id
             WHERE i.transaksi_id = :id'
        );
        $stmt->execute(['id' => (int) $this->transaksiId]);
        $items = $stmt->fetchAll();

        $diskon = $trx['diskon_id'] !== null ? Diskon::findById($trx['diskon_id']) : null;

        return $this->format($trx, $items, $diskon);
    }

    // Merangkai data menjadi string struk yang rapi (lebar LEBAR karakter).
    private function format(array $trx, array $items, ?Diskon $diskon): string
    {
        $baris   = [];
        $baris[] = str_repeat('=', self::LEBAR);
        $baris[] = str_pad(self::NAMA_TOKO, self::LEBAR, ' ', STR_PAD_BOTH);
        $baris[] = str_repeat('=', self::LEBAR);
        $baris[] = 'No. Transaksi : ' . $trx['id'];
        $baris[] = 'Tanggal       : ' . $trx['tanggal'];
        $baris[] = str_repeat('-', self::LEBAR);

        $totalKotor = 0.0;
        foreach ($items as $item) {
            $totalKotor += (float) $item['subtotal'];
            $baris[] = $item['nama'];
            $baris[] = sprintf(
                '  %d x %s = %s',
                $item['qty'],
                number_format((float) $item['harga'], 0, ',', '.'),
                number_format((float) $item['subtotal'], 0, ',', '.')
            );
        }

        $baris[] = str_repeat('-', self::LEBAR);
        $baris[] = $this->barisTotal('Total', $totalKotor);

        if ($diskon !== null) {
            $potongan = $totalKotor - (float) $trx['total'];
            $label    = $diskon->getJenis() === 'persen'
                ? "Diskon ({$diskon->getNilai()}%)"
                : 'Diskon (nominal)';
            $baris[] = $this->barisTotal($label, -$potongan);
        }

        $baris[] = $this->barisTotal('Total Akhir', (float) $trx['total']);
        $baris[] = 'Pembayaran    : ' . ucfirst(str_replace('_', '-', (string) $trx['jenis_pembayaran']));
        $baris[] = str_repeat('=', self::LEBAR);
        $baris[] = str_pad('Terima kasih telah berbelanja', self::LEBAR, ' ', STR_PAD_BOTH);

        return implode(PHP_EOL, $baris);
    }

    // Baris "label ..... nilai" dengan nilai rata kanan.
    private function barisTotal(string $label, float $nilai): string
    {
        $teksNilai = number_format($nilai, 0, ',', '.');
        return $label . str_pad($teksNilai, self::LEBAR - strlen($label), ' ', STR_PAD_LEFT);
    }

    public function getTransaksiId(): string
    {
        return $this->transaksiId;
    }

    public function getTanggalCetak(): DateTime
    {
        return $this->tanggalCetak;
    }
}
