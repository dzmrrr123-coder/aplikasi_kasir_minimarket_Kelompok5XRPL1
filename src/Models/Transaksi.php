<?php

declare(strict_types=1);

namespace App\Models;

use DateTime;
use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class Transaksi
{
    private string $id = '';
    private DateTimeImmutable $tanggal;
    private float $total = 0.0;
    private int $kasirId = 0;
    private array $items = []; // ItemTransaksi[]
    private ?Diskon $diskon = null;
    private ?Pembayaran $pembayaran = null;
    private bool $selesai = false;

    public function __construct(array $data = [])
    {
        $this->tanggal = new DateTimeImmutable();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['tanggal'])) {
            $this->tanggal = new DateTimeImmutable($data['tanggal']);
        }
        if (isset($data['total'])) {
            $this->total = (float) $data['total'];
        }
        if (isset($data['kasir_id'])) {
            $this->kasirId = (int) $data['kasir_id'];
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

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getKasirId(): int
    {
        return $this->kasirId;
    }

    /**
     * @return ItemTransaksi[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getDiskon(): ?Diskon
    {
        return $this->diskon;
    }

    public function getPembayaran(): ?Pembayaran
    {
        return $this->pembayaran;
    }

    public function isSelesai(): bool
    {
        return $this->selesai;
    }

    /**
     * Menambahkan item ke transaksi.
     * Stok diperiksa dulu; bila stok kurang, item DITOLAK (tidak ditambahkan)
     * dan exception dilempar sesuai alur proses pada spesifikasi.
     *
     * @throws RuntimeException bila stok produk tidak cukup
     */
    public function tambahItem(Produk $produk, int $qty): void
    {
        if ($qty <= 0) {
            throw new RuntimeException('Jumlah item harus lebih dari 0.');
        }
        if ($produk->getStok() < $qty) {
            throw new RuntimeException(
                sprintf('Stok "%s" tidak cukup (tersedia: %d).', $produk->getNama(), $produk->getStok())
            );
        }

        // Klon produk supaya mutasi stok saat transaksi diproses/dibatalkan
        // tidak mengubah objek Produk milik pemanggil (stok DB tetap aktual).
        $produkSalinan = clone $produk;

        $subtotal = $produkSalinan->getHarga() * $qty;
        $item = new ItemTransaksi([
            'produk'  => $produkSalinan,
            'qty'     => $qty,
            'subtotal'=> $subtotal,
        ]);
        $this->items[] = $item;
    }

    public function hitungTotal(): float
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $total += $item->getSubtotal();
        }

        if ($this->diskon instanceof Diskon) {
            $total = $this->diskon->terapkan($total);
        }

        $this->total = max(0.0, $total);

        return $this->total;
    }

    public function terapkanDiskon(Diskon $diskon): void
    {
        $this->diskon = $diskon;
    }

    /**
     * Proses pembayaran sesuai alur: hitung total -> proses pembayaran ->
     * kalau berhasil, simpan transaksi + item + pembayaran, update stok produk.
     */
    public function prosesPembayaran(Pembayaran $pembayaran): bool
    {
        if ($this->pembayaran !== null || $this->selesai) {
            throw new RuntimeException('Transaksi sudah diproses.');
        }

        $this->hitungTotal();

        if (!$pembayaran->proses()) {
            return false;
        }

        $this->pembayaran = $pembayaran;
        $this->selesai = true;

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $diskonId = $this->diskon !== null && $this->diskon->getId() !== ''
                ? $this->diskon->getId()
                : null;

            $pembayaranId = $pembayaran->simpan();

            $stmt = $pdo->prepare(
                'INSERT INTO transaksi (tanggal, total, kasir_id, diskon_id, pembayaran_id)
                 VALUES (:tanggal, :total, :kasir_id, :diskon_id, :pembayaran_id)'
            );
            $stmt->execute([
                ':tanggal'        => $this->tanggal->format('Y-m-d H:i:s'),
                ':total'          => $this->total,
                ':kasir_id'       => $this->kasirId,
                ':diskon_id'      => $diskonId,
                ':pembayaran_id'  => $pembayaranId,
            ]);

            $transaksiId = (int) $pdo->lastInsertId();
            $this->id = (string) $transaksiId;

            foreach ($this->items as $item) {
                $item->simpan($transaksiId);

                $produk = $item->getProduk();
                $produk->updateStok(-$item->getQty());
                $produk->perbarui();
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->selesai = false;
            $this->pembayaran = null;

            throw new RuntimeException(
                'Gagal menyimpan transaksi: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return true;
    }

    /**
     * Membatalkan transaksi: item dihapus, stok produk dikembalikan,
     * lalu baris transaksi (beserta item) dihapus dari database.
     */
    public function batalkan(): void
    {
        if ($this->id === '') {
            throw new RuntimeException('Transaksi belum tersimpan, tidak bisa dibatalkan.');
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $items = ItemTransaksi::untukTransaksi((int) $this->id);

            foreach ($items as $item) {
                $produk = $item->getProduk();
                $produk->updateStok($item->getQty());
                $produk->perbarui();
            }

            $stmt = $pdo->prepare('DELETE FROM transaksi WHERE id = :id');
            $stmt->execute([':id' => $this->id]);

            $pdo->commit();

            $this->items = [];
            $this->id = '';
            $this->selesai = false;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw new RuntimeException(
                'Gagal membatalkan transaksi: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, tanggal, total, kasir_id FROM transaksi WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }
}
