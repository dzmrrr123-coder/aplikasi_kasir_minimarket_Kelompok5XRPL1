<?php
// src/Models/ItemTransaksi.php
// Class ItemTransaksi: satu baris barang dalam sebuah transaksi (composition:
// dimiliki penuh oleh Transaksi).

class ItemTransaksi
{
    private Produk $produk;
    private int $qty;
    private float $subtotal;

    // Subtotal dihitung otomatis dari harga produk x qty.
    public function __construct(Produk $produk, int $qty)
    {
        $this->produk   = $produk;
        $this->qty      = $qty;
        $this->subtotal = $produk->getHarga() * $qty;
    }

    public function getProduk(): Produk
    {
        return $this->produk;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }
}
