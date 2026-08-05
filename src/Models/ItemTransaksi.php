<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class ItemTransaksi
{
    private string $id = '';
    private Transaksi $transaksi;
    private Produk $produk;
    private float $qty = 0.0;
    private float $subtotal = 0.0;

    public function __construct(array $data = [])
    {
        $this->transaksi = new Transaksi();
        $this->produk = new Produk();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['transaksi_id'])) {
            $transaksi = Transaksi::cari((int) $data['transaksi_id']);
            $this->transaksi = $transaksi ?? new Transaksi();
        }
        if (isset($data['produk_id'])) {
            $produk = Produk::cari((int) $data['produk_id']);
            $this->produk = $produk ?? new Produk();
        }
        if (isset($data['produk'])) {
            $this->produk = $data['produk'];
        }
        if (isset($data['qty'])) {
            $this->qty = (float) $data['qty'];
        }
        if (isset($data['subtotal'])) {
            $this->subtotal = (float) $data['subtotal'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTransaksi(): Transaksi
    {
        return $this->transaksi;
    }

    public function getProduk(): Produk
    {
        return $this->produk;
    }

    public function getQty(): float
    {
        return $this->qty;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function simpan(int $transaksiId): void
    {
        $stmt = Database::connect()->prepare(
            'INSERT INTO item_transaksi (transaksi_id, produk_id, qty, subtotal)
             VALUES (:transaksi_id, :produk_id, :qty, :subtotal)'
        );
        $stmt->execute([
            ':transaksi_id' => $transaksiId,
            ':produk_id'    => $this->produk->getId(),
            ':qty'          => $this->qty,
            ':subtotal'     => $this->subtotal,
        ]);

        $this->id = (string) Database::connect()->lastInsertId();
    }

    /**
     * Mengambil semua item milik sebuah transaksi.
     * Stok produk di-refresh dari database supaya nilainya selalu aktual
     * (penting saat transaksi dibatalkan: stok harus dikembalikan dari
     * nilai terkini, bukan nilai lama yang sudah diubah transaksi lain).
     *
     * @return ItemTransaksi[]
     */
    public static function untukTransaksi(int $transaksiId): array
    {
        $rows = Database::connect()->query(
            'SELECT id, transaksi_id, produk_id, qty, subtotal
             FROM item_transaksi
             WHERE transaksi_id = ' . $transaksiId . '
             ORDER BY id ASC'
        )->fetchAll();

        return array_map(static function (array $row): self {
            $item = new self($row);

            $produk = Produk::cari((int) $row['produk_id']);
            if ($produk !== null) {
                $item->produk = $produk;
            }

            return $item;
        }, $rows);
    }
}
