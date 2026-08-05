<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class ReturBarang
{
    private string $id = '';
    private DateTimeImmutable $tanggal;
    private Produk $produk;
    private Supplier $supplier;
    private int $qty = 0;
    private string $alasan = '';

    public function __construct(array $data = [])
    {
        $this->tanggal = new DateTimeImmutable();
        $this->produk = new Produk();
        $this->supplier = new Supplier();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['tanggal'])) {
            $this->tanggal = new DateTimeImmutable($data['tanggal']);
        }
        if (isset($data['produk_id'])) {
            $produk = Produk::cari((int) $data['produk_id']);
            $this->produk = $produk ?? new Produk();
        }
        if (isset($data['supplier_id'])) {
            $supplier = Supplier::cari((int) $data['supplier_id']);
            $this->supplier = $supplier ?? new Supplier();
        }
        if (isset($data['qty'])) {
            $this->qty = (int) $data['qty'];
        }
        if (isset($data['alasan'])) {
            $this->alasan = (string) $data['alasan'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTanggal(): DateTimeImmutable
    {
        return $this->tanggal;
    }

    public function getProduk(): Produk
    {
        return $this->produk;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function getAlasan(): string
    {
        return $this->alasan;
    }

    public function setProduk(Produk $produk): void
    {
        $this->produk = $produk;
    }

    public function setSupplier(Supplier $supplier): void
    {
        $this->supplier = $supplier;
    }

    public function setQty(int $qty): void
    {
        $this->qty = $qty;
    }

    public function setAlasan(string $alasan): void
    {
        $this->alasan = $alasan;
    }

    /**
     * Proses retur sesuai alur pada spesifikasi:
     * 1. Cek stok produk cukup untuk diretur -> kalau kurang, batalkan dengan pesan spesifik.
     * 2. Cek data supplier valid -> kalau tidak valid, batalkan.
     * 3. Kalau keduanya valid: kurangi stok produk & catat retur dalam satu transaksi DB,
     *    jadi kalau pencatatan gagal, stok ikut di-rollback (tidak terlanjur berkurang).
     *
     * @throws RuntimeException bila stok atau supplier tidak valid
     */
    public function prosesRetur(): bool
    {
        if ($this->qty <= 0) {
            throw new RuntimeException('Jumlah retur harus lebih dari 0.');
        }

        if ($this->produk->getId() === '') {
            throw new RuntimeException('Produk tidak valid.');
        }

        if ($this->produk->getStok() < $this->qty) {
            throw new RuntimeException(
                sprintf('Stok "%s" tidak cukup untuk diretur (tersedia: %d).', $this->produk->getNama(), $this->produk->getStok())
            );
        }

        if ($this->supplier->getId() === '' || $this->supplier->getNama() === '') {
            throw new RuntimeException('Data supplier tidak valid.');
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO retur_barang (tanggal, produk_id, supplier_id, qty, alasan)
                 VALUES (:tanggal, :produk_id, :supplier_id, :qty, :alasan)'
            );
            $stmt->execute([
                ':tanggal'     => $this->tanggal->format('Y-m-d H:i:s'),
                ':produk_id'   => $this->produk->getId(),
                ':supplier_id' => $this->supplier->getId(),
                ':qty'         => $this->qty,
                ':alasan'      => $this->alasan,
            ]);

            $this->id = (string) $pdo->lastInsertId();

            // Retur barang ke supplier = barang keluar dari stok toko.
            $this->produk->kurangiStok($this->qty);
            $this->produk->perbarui();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw new RuntimeException(
                'Gagal memproses retur: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return true;
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, tanggal, produk_id, supplier_id, qty, alasan FROM retur_barang WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }
}
