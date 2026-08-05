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
     * Menghasilkan teks struk berisi rincian item dan total transaksi.
     */
    public function cetak(): string
    {
        $lines = [];
        $lines[] = '==================================';
        $lines[] = '          KASIR MINIMARKET';
        $lines[] = '==================================';
        $lines[] = 'No. Transaksi : ' . $this->transaksiId;
        $lines[] = 'Tanggal       : ' . $this->transaksi->getTanggal()->format('d-m-Y H:i');
        $lines[] = '----------------------------------';

        foreach ($this->transaksi->getItems() as $item) {
            $produk = $item->getProduk();
            $lines[] = $produk->getNama();
            $lines[] = sprintf(
                '  %d x %s',
                $item->getQty(),
                $this->formatRupiah($item->getSubtotal())
            );
        }

        $lines[] = '----------------------------------';
        $lines[] = 'Total         : ' . $this->formatRupiah($this->transaksi->getTotal());
        $lines[] = '==================================';
        $lines[] = 'Terima kasih atas kunjungan Anda!';

        return implode("\n", $lines) . "\n";
    }

    private function formatRupiah(float $jumlah): string
    {
        return 'Rp ' . number_format($jumlah, 0, ',', '.');
    }
}
