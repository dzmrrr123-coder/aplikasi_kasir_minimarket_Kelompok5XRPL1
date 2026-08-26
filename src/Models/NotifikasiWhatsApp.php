<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Observer: notifikasi WhatsApp tiap transaksi beresiko lewat n8n.
 *
 * Dipasangkan ke Transaksi (Subject). Saat notify() (PRE-commit) ia HANYA
 * meng-INSERT satu baris ke tabel notifikasi_queue (status 'pending').
 * Itulah sebabnya aman — INSERT lokal instan, dan ikut ter-roll-back bersama
 * transaksi penjualan bila mekanisme penyimpanan gagal.
 *
 * Pengiriman HTTP ke webhook n8n dilakukan terpisah oleh
 * NotifikasiAntrian::proses() — SETELAH commit — sehingga tidak pernah
 * menghambat atau membatalkan transaksi kasir.
 *
 * Fitur OFF bila Pengaturan 'wa_webhook_url' kosong (tidak ada baris queue).
 */
class NotifikasiWhatsApp implements Observer
{
    public function update(Subject $subject): void
    {
        // Hanya reag pada transaksi yang benar-benar tersimpan.
        if (!$subject instanceof Transaksi || $subject->getId() === '') {
            return;
        }

        $webhookUrl = Pengaturan::get('wa_webhook_url', '');

        // Gate utama: kosongkan URL webhook = fitur dimatikan.
        if ($webhookUrl === '') {
            return;
        }

        $payload = $this->susunPayload($subject);

        // INSERT outbox dalam transaction yang sedang berlangsung (atomic
        // dengan baris transaksi). Jika commit gagal, baris ini ikut hilang.
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "INSERT INTO notifikasi_queue
                (transaksi_id, webhook_url, nomor_tujuan, payload)
             VALUES (:transaksi_id, :webhook_url, :nomor_tujuan, :payload)"
        );

        $stmt->execute([
            ':transaksi_id'  => (int) $subject->getId(),
            ':webhook_url'   => $this->batasiPanjang($webhookUrl, 255),
            ':nomor_tujuan'  => $this->batasiPanjang(
                Pengaturan::get('wa_tujuan_nomor', ''),
                30
            ),
            ':payload'       => json_encode(
                $payload,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
        ]);
    }

    /**
     * Susun payload JSON yang dikirim ke n8n (lihat docs/whatsapp-n8n.md).
     *
     * @return array<string, mixed>
     */
    private function susunPayload(Transaksi $t): array
    {
        $pembayaran = $t->getPembayaran();
        $kembalian = $pembayaran instanceof PaymentMethod
            ? $pembayaran->hitungKembalian($t->getTotal(), $pembayaran->getJumlah())
            : 0.0;

        $subtotalKotor = 0.0;
        $items = [];

        foreach ($t->getItems() as $item) {
            $produk = $item->getProduk();
            $subtotalKotor = round($subtotalKotor + $item->getSubtotal(), 2);
            $items[] = [
                'nama'     => $produk->getNama(),
                'qty'      => $item->getQty(),
                'harga'    => $produk->getHargaEfektif(),
                'subtotal' => $item->getSubtotal(),
            ];
        }

        // Potongan diskon = subtotal kotor - (total setelah diskon sebelum pajak).
        $potongan = round($subtotalKotor - ($t->getTotal() - $t->getPajak()), 2);

        $memberNama = $t->getMemberNama();
        $memberNomor = '';
        if ($t->getMemberId() > 0) {
            $member = Member::cari($t->getMemberId());
            if ($member !== null) {
                $memberNomor = $member->getNomorMember();
            }
        }

        return [
            'no_transaksi'   => $t->getId(),
            'tanggal'        => $t->getTanggal()->format('d-m-Y H:i'),
            'kasir'          => $t->getKasirNama(),
            'member'         => $memberNama,
            'member_nomor'   => $memberNomor,
            'tujuan'         => Pengaturan::get('wa_tujuan_nomor', ''),
            'metode'         => $pembayaran instanceof PaymentMethod ? $pembayaran->getNamaMetode() : '',
            'subtotal'       => $subtotalKotor,
            'diskon'         => $potongan > 0 ? $potongan : 0.0,
            'pajak'          => $t->getPajak(),
            'total'          => $t->getTotal(),
            'dibayar'        => $pembayaran instanceof PaymentMethod ? $pembayaran->getJumlah() : 0.0,
            'kembalian'      => $kembalian > 0 ? $kembalian : 0.0,
            'items'          => $items,
        ];
    }

    /** Potong string agar muat kolom DB (tanpa truncation error). */
    private function batasiPanjang(string $nilai, int $max): string
    {
        if ($nilai === '' || mb_strlen($nilai) <= $max) {
            return $nilai;
        }

        return mb_substr($nilai, 0, $max);
    }
}
