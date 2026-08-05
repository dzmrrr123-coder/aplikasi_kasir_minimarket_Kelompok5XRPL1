<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;

class Struk
{
    private string $transaksiId = '';
    private DateTimeImmutable $tanggalCetak;
    private Transaksi $transaksi;

    public function __construct(Transaksi $transaksi)
    {
        $this->transaksi = $transaksi;
        $this->transaksiId = $transaksi->getId();
        $this->tanggalCetak = new DateTimeImmutable();
    }

    public function getTransaksiId(): string
    {
        return $this->transaksiId;
    }

    public function getTanggalCetak(): DateTimeImmutable
    {
        return $this->tanggalCetak;
    }

    /**
     * Menghasilkan teks struk detail: item bernomor dengan harga satuan,
     * subtotal, diskon, total, metode pembayaran, jumlah dibayar, kembalian.
     */
    public function cetak(): string
    {
        $lines = [];
        $lines[] = '==================================';
        $lines[] = '          KASIR MINIMARKET';
        $lines[] = '==================================';
        $lines[] = 'No. Transaksi : ' . $this->transaksiId;
        $lines[] = 'Tanggal       : ' . $this->transaksi->getTanggal()->format('d-m-Y H:i');
        $lines[] = 'Kasir         : ' . $this->namaKasir();
        $lines[] = '----------------------------------';

        $no = 1;

        foreach ($this->transaksi->getItems() as $item) {
            $produk = $item->getProduk();
            $hargaSatuan = $produk->getHarga();
            $lines[] = $no . '. ' . $produk->getNama();
            $lines[] = sprintf(
                '    %d x %s',
                $item->getQty(),
                $this->formatRupiah($hargaSatuan)
            );
            $lines[] = '    Subtotal  : ' . $this->formatRupiah($item->getSubtotal());
            $no++;
        }

        $lines[] = '----------------------------------';
        $lines[] = 'Subtotal      : ' . $this->formatRupiah($this->subtotalKotor());

        $diskon = $this->transaksi->getDiskon();

        if ($diskon !== null) {
            $potongan = $this->subtotalKotor() - $this->transaksi->getTotal();

            if ($potongan > 0) {
                $lines[] = 'Diskon        : -' . $this->formatRupiah($potongan);
            }
        }

        $lines[] = 'TOTAL         : ' . $this->formatRupiah($this->transaksi->getTotal());

        $pembayaran = $this->transaksi->getPembayaran();

        if ($pembayaran !== null) {
            $lines[] = 'Metode Bayar  : ' . $pembayaran->getNamaMetode();
            $lines[] = 'Dibayar       : ' . $this->formatRupiah($pembayaran->getJumlah());

            // Kembalian dihitung polimorfik oleh strategi pembayaran
            // (tunai: selisih; non-tunai: 0).
            $kembalian = $pembayaran->hitungKembalian(
                $this->transaksi->getTotal(),
                $pembayaran->getJumlah()
            );

            if ($kembalian > 0) {
                $lines[] = 'Kembalian     : ' . $this->formatRupiah($kembalian);
            }
        }

        $lines[] = '==================================';
        $lines[] = 'Terima kasih atas kunjungan Anda!';

        return implode("\n", $lines) . "\n";
    }

    /** Subtotal seluruh item sebelum diskon. */
    private function subtotalKotor(): float
    {
        $total = 0.0;

        foreach ($this->transaksi->getItems() as $item) {
            $total += $item->getSubtotal();
        }

        return $total;
    }

    /** Nama kasir dari database (fallback: 'Kasir'). */
    private function namaKasir(): string
    {
        $kasirId = $this->transaksi->getKasirId();

        if ($kasirId <= 0) {
            return 'Kasir';
        }

        $stmt = \App\Database\Database::connect()->prepare(
            'SELECT nama FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $kasirId]);
        $row = $stmt->fetch();

        return $row === false ? 'Kasir' : (string) $row['nama'];
    }

    private function formatRupiah(float $jumlah): string
    {
        return 'Rp ' . number_format($jumlah, 0, ',', '.');
    }
}
