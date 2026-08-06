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

        $lines = [];
        $lines[] = '==================================';
        $lines[] = strtoupper($namaToko);

        if ($alamatToko !== '') {
            $lines[] = $alamatToko;
        }
        if ($teleponToko !== '') {
            $lines[] = 'Telp: ' . $teleponToko;
        }

        $lines[] = '==================================';
        $lines[] = 'No. Transaksi : ' . $this->transaksiId;
        $lines[] = 'Tanggal       : ' . $this->transaksi->getTanggal()->format('d-m-Y H:i');
        $lines[] = 'Kasir         : ' . $this->namaKasir();

        $member = $this->transaksi->getMemberNama();

        if ($member !== '') {
            $lines[] = 'Member        : ' . $member;
        }

        $lines[] = '----------------------------------';

        $no = 1;

        foreach ($this->transaksi->getItems() as $item) {
            $produk = $item->getProduk();
            // Harga satuan efektif: produk gram memakai harga per gram.
            $hargaSatuan = $produk->getHargaEfektif();
            $lines[] = $no . '. ' . $produk->getNama();
            $lines[] = sprintf(
                '    %s x %s',
                $this->formatQty($item->getQty()),
                $this->formatRupiah($hargaSatuan)
            );
            $lines[] = '    Subtotal  : ' . $this->formatRupiah($item->getSubtotal());
            $no++;
        }

        $lines[] = '----------------------------------';
        $lines[] = 'Subtotal      : ' . $this->formatRupiah($this->subtotalKotor());

        $diskon = $this->transaksi->getDiskon();

        if ($diskon !== null) {
            // Potongan dihitung dari subtotal kotor vs total sebelum pajak.
            $totalSetelahDiskon = $this->transaksi->getTotal() - $this->transaksi->getPajak();
            $potongan = $this->subtotalKotor() - $totalSetelahDiskon;

            if ($potongan > 0) {
                $lines[] = 'Diskon        : -' . $this->formatRupiah($potongan);
            }
        }

        $pajak = $this->transaksi->getPajak();

        if ($pajak > 0) {
            $lines[] = 'Pajak (PPN)   : ' . $this->formatRupiah($pajak);
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
        $lines[] = $footer;

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
                'harga'    => $item->getProduk()->getHargaEfektif(),
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
}
