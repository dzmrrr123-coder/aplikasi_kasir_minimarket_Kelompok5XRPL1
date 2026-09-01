<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Strategy Pattern: kontrak untuk metode pembayaran.
 *
 * Setiap strategi bertanggung jawab atas aturan pembayarannya sendiri
 * (berapa jumlah yang sah, bagaimana menghitung kembalian), sehingga
 * Transaksi tidak perlu mengecek tipe pembayaran satu per satu (IF/ELSE).
 */
interface PaymentMethod
{
    /**
     * Memvalidasi apakah jumlah yang dibayar sah untuk metode ini
     * terhadap total transaksi.
     */
    public function prosesBayar(float $total, float $jumlahBayar): bool;

    /**
     * Label metode pembayaran untuk tampilan struk (mis. "Tunai").
     */
    public function getNamaMetode(): string;

    /**
     * Jumlah yang dibayar oleh pelanggan.
     */
    public function getJumlah(): float;

    /**
     * Menghitung kembalian berdasarkan total dan jumlah dibayar.
     * Metode yang tidak punya konsep kembalian (non-tunai) mengembalikan 0.
     */
    public function hitungKembalian(float $total, float $jumlahBayar): float;
}
