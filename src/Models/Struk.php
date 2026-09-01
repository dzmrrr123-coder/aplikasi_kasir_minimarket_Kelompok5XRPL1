<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;

/**
 * Struk: observer yang merespons penyelesaian transaksi (notify).
 *
 * Saat Transaksi (Subject) selesai diproses, Struk::update() menyiapkan
 * representasi JSON untuk kembalian/cetak layar. Logika Struk tidak
 * berada di dalam Transaksi — Transaksi hanya memanggil notify().
 */
class Struk implements Observer
{
    private string $transaksiId = '';
    private DateTimeImmutable $tanggalCetak;
    private Transaksi $transaksi;
    private ?array $jsonOutput = null;

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
     * Observer Pattern: dipanggil Subject (Transaksi) saat notify().
     * Menyiapkan format JSON struk (kembalian & detail cetak).
     */
    public function update(Subject $subject): void
    {
        if (!$subject instanceof Transaksi) {
            return;
        }

        $this->transaksi = $subject;
        $this->transaksiId = $subject->getId();
        $this->tanggalCetak = new DateTimeImmutable();
        $this->jsonOutput = $this->susunJson($subject);
    }

    /**
     * JSON struk yang disiapkan observer (null sebelum ada notify).
     *
     * @return array<string, mixed>|null
     */
    public function getJsonOutput(): ?array
    {
        return $this->jsonOutput;
    }

    public function toJson(): ?string
    {
        return $this->jsonOutput === null
            ? null
            : json_encode($this->jsonOutput);
    }

    /**
     * Menghasilkan teks struk detail: item bernomor dengan harga satuan,
     * subtotal, diskon, total, metode pembayaran, jumlah dibayar, kembalian.
     * Header toko & footer diambil dari pengaturan.
     */
    public function cetak(): string
    {
        $pengaturan = Pengaturan::semua();
        $namaToko = $pengaturan['nama_toko'] ?? 'KASIR MINIMARKET';
        $alamatToko = $pengaturan['alamat'] ?? '';
        $teleponToko = $pengaturan['telepon'] ?? '';
        $footer = $pengaturan['footer_struk'] ?? 'Terima kasih atas kunjungan Anda!';

        $w = 32; // thermal receipt width (chars) — works for 58mm & 80mm
        $sep = str_repeat('=', $w);
        $dash = str_repeat('-', $w);

        $lines = [];

        // --- Header (centered) ---
        $lines[] = $sep;
        $lines[] = $this->centerText(strtoupper($namaToko), $w);

        if ($alamatToko !== '') {
            $lines[] = $this->centerText($alamatToko, $w);
        }
        if ($teleponToko !== '') {
            $lines[] = $this->centerText('Telp: ' . $teleponToko, $w);
        }

        $lines[] = $sep;

        // --- Transaction info ---
        $lines[] = 'No. Transaksi : #' . $this->transaksiId;
        $lines[] = 'Tanggal       : ' . $this->transaksi->getTanggal()->format('d/m/Y H:i');
        $lines[] = 'Kasir         : ' . $this->namaKasir();

        $member = $this->transaksi->getMemberNama();

        if ($member !== '') {
            $lines[] = 'Member        : ' . $member;
        }

        $lines[] = $dash;
        $lines[] = $this->centerText('BARANG', $w);
        $lines[] = $dash;

        // --- Items ---
        $no = 1;

        foreach ($this->transaksi->getItems() as $item) {
            $produk = $item->getProduk();
            $hargaSatuan = $produk->getHargaEfektif($item->getQty());
            $qtyStr = $this->formatQty($item->getQty());
            $satuan = $produk->getSatuan() === 'gram' ? 'g' : 'x';

            // Line 1: product name (truncate if too long)
            $namaBarang = $produk->getNama();
            if (mb_strlen($namaBarang) > $w - 4) {
                $namaBarang = mb_substr($namaBarang, 0, $w - 7) . '...';
            }
            $lines[] = $no . '. ' . $namaBarang;

            // Line 2: qty x price = subtotal (right-aligned subtotal)
            $qtyLine = '   ' . $qtyStr . ' ' . $satuan . ' x ' . $this->formatRupiah($hargaSatuan);
            $subLine = $this->formatRupiah($item->getSubtotal());
            $gap = max(1, $w - mb_strlen($qtyLine) - mb_strlen($subLine));
            $lines[] = $qtyLine . str_repeat(' ', $gap) . $subLine;

            // Line 3: discount per item if any
            if ($item->getPotongan() > 0) {
                $discLine = '   (Diskon)';
                $discVal = '-' . $this->formatRupiah($item->getPotongan());
                $gapD = max(1, $w - mb_strlen($discLine) - mb_strlen($discVal));
                $lines[] = $discLine . str_repeat(' ', $gapD) . $discVal;
            }

            $no++;
        }

        $lines[] = $dash;

        // --- Totals (right-aligned values) ---
        $lines[] = $this->rightAligned('Subtotal', $this->formatRupiah($this->subtotalKotor()), $w);

        $diskon = $this->transaksi->getDiskon();

        if ($diskon !== null) {
            $totalSetelahDiskon = $this->transaksi->getTotal() - $this->transaksi->getPajak();
            $potongan = $this->subtotalKotor() - $totalSetelahDiskon;

            if ($potongan > 0) {
                $lines[] = $this->rightAligned('Diskon', '-' . $this->formatRupiah($potongan), $w);
            }
        }

        $pajak = $this->transaksi->getPajak();

        if ($pajak > 0) {
            $lines[] = $this->rightAligned('PPN', $this->formatRupiah($pajak), $w);
        }

        $lines[] = $dash;
        $lines[] = $this->rightAligned('TOTAL', $this->formatRupiah($this->transaksi->getTotal()), $w, true);
        $lines[] = $dash;

        // --- Payment ---
        $pembayaran = $this->transaksi->getPembayaran();

        if ($pembayaran !== null) {
            $lines[] = 'Metode   : ' . $pembayaran->getNamaMetode();
            $lines[] = $this->rightAligned('Dibayar', $this->formatRupiah($pembayaran->getJumlah()), $w);

            $kembalian = $pembayaran->hitungKembalian(
                $this->transaksi->getTotal(),
                $pembayaran->getJumlah()
            );

            if ($kembalian > 0) {
                $lines[] = $this->rightAligned('Kembalian', $this->formatRupiah($kembalian), $w, true);
            }
        }

        $lines[] = $sep;
        $lines[] = $this->centerText($footer, $w);
        $lines[] = $sep;

        return implode("\n", $lines) . "\n";
    }

    /**
     * Menyusun struktur JSON struk dari transaksi.
     *
     * @return array<string, mixed>
     */
    private function susunJson(Transaksi $transaksi): array
    {
        $pembayaran = $transaksi->getPembayaran();
        $kembalian = $pembayaran instanceof PaymentMethod
            ? $pembayaran->hitungKembalian($transaksi->getTotal(), $pembayaran->getJumlah())
            : 0.0;

        $items = [];

        foreach ($transaksi->getItems() as $item) {
            $items[] = [
                'nama'     => $item->getProduk()->getNama(),
                'qty'      => $item->getQty(),
                'harga'    => $item->getProduk()->getHargaEfektif($item->getQty()),
                'subtotal' => $item->getSubtotal(),
            ];
        }

        return [
            'no_transaksi' => $transaksi->getId(),
            'tanggal'      => $transaksi->getTanggal()->format('d-m-Y H:i'),
            'kasir'        => $transaksi->getKasirNama(),
            'member'       => $transaksi->getMemberNama(),
            'items'        => $items,
            'subtotal'     => $this->subtotalKotor(),
            'diskon'       => $transaksi->getDiskon()?->getNilai() ?? 0.0,
            'pajak'        => $transaksi->getPajak(),
            'total'        => $transaksi->getTotal(),
            'metode'       => $pembayaran?->getNamaMetode() ?? '',
            'dibayar'      => $pembayaran?->getJumlah() ?? 0.0,
            'kembalian'    => $kembalian,
        ];
    }

    /** Format qty: angka utuh tanpa desimal, angka pecahan dengan 2 desimal. */
    private function formatQty(float $qty): string
    {
        return fmod($qty, 1) === 0.0
            ? (string) (int) $qty
            : rtrim(rtrim(number_format($qty, 2, ',', '.'), '0'), ',');
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

    /** Center text within a given width. */
    private function centerText(string $text, int $width): string
    {
        $len = mb_strlen($text);
        if ($len >= $width) return $text;
        $pad = (int) (($width - $len) / 2);
        return str_repeat(' ', $pad) . $text;
    }

    /** Right-aligned label + value pair. Bold lines prefix with '>>' when $bold. */
    private function rightAligned(string $label, string $value, int $width, bool $bold = false): string
    {
        $prefix = $bold ? '>> ' : '';
        $labelFull = $prefix . $label;
        $gap = max(1, $width - mb_strlen($labelFull) - mb_strlen($value));
        return $labelFull . str_repeat(' ', $gap) . $value;
    }
}
