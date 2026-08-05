<?php

declare(strict_types=1);

namespace App\Models;

use DateTime;
use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class Transaksi implements Subject
{
    private string $id = '';
    private DateTimeImmutable $tanggal;
    private float $total = 0.0;
    private int $kasirId = 0;
    private string $kasirNama = '';
    private array $items = []; // ItemTransaksi[]
    private ?Diskon $diskon = null;
    private ?PaymentMethod $pembayaran = null;
    private bool $selesai = false;
    /** @var Observer[] */
    private array $observers = [];

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
        if (isset($data['kasir_nama'])) {
            $this->kasirNama = (string) $data['kasir_nama'];
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
     * Nama kasir yang memproses transaksi.
     * Bila belum terisi (transaksi in-memory), diambil dari database.
     */
    public function getKasirNama(): string
    {
        if ($this->kasirNama !== '') {
            return $this->kasirNama;
        }

        if ($this->kasirId <= 0) {
            return '';
        }

        $stmt = Database::connect()->prepare('SELECT nama FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $this->kasirId]);
        $row = $stmt->fetch();

        $this->kasirNama = $row === false ? '' : (string) $row['nama'];

        return $this->kasirNama;
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

    public function getPembayaran(): ?PaymentMethod
    {
        return $this->pembayaran;
    }

    public function isSelesai(): bool
    {
        return $this->selesai;
    }

    // ------------------------------------------------------------
    // Observer Pattern (Subject)
    // ------------------------------------------------------------

    public function attach(Observer $observer): void
    {
        // Hindari duplikat observer yang sama.
        foreach ($this->observers as $terpasang) {
            if ($terpasang === $observer) {
                return;
            }
        }

        $this->observers[] = $observer;
    }

    public function detach(Observer $observer): void
    {
        foreach ($this->observers as $i => $terpasang) {
            if ($terpasang === $observer) {
                unset($this->observers[$i]);
                $this->observers = array_values($this->observers);
                return;
            }
        }
    }

    /**
     * Memberi tahu semua observer bahwa transaksi baru saja diselesaikan.
     * Dipanggil otomatis oleh prosesPembayaran() setelah transaksi sukses
     * tersimpan (lihat alur di bawah).
     */
    public function notify(): void
    {
        foreach ($this->observers as $observer) {
            $observer->update($this);
        }
    }

    /**
     * Menambahkan item ke transaksi.
     * Stok diperiksa dulu; bila stok kurang, item DITOLAK (tidak ditambahkan)
     * dan exception dilempar sesuai alur proses pada spesifikasi.
     * Qty berupa float untuk mendukung produk curah (satuan gram).
     *
     * @throws RuntimeException bila stok produk tidak cukup
     */
    public function tambahItem(Produk $produk, float $qty): void
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

        $subtotal = $produkSalinan->getHargaEfektif() * $qty;
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
     * Dependency Injection via setter: menetapkan strategi pembayaran
     * (PaymentMethod) yang akan dipakai saat transaksi diproses.
     */
    public function setMetodePembayaran(PaymentMethod $metodePembayaran): void
    {
        $this->pembayaran = $metodePembayaran;
    }

    /**
     * Proses pembayaran sesuai alur: hitung total -> proses pembayaran
     * (delegasi ke strategi PaymentMethod) -> kalau berhasil, simpan
     * transaksi + item + pembayaran, update stok produk.
     *
     * @param PaymentMethod|null $metodePembayaran strategi pembayaran
     *        (opsional; bisa diset sebelumnya lewat setMetodePembayaran)
     */
    public function prosesPembayaran(?PaymentMethod $metodePembayaran = null): bool
    {
        if ($this->selesai) {
            throw new RuntimeException('Transaksi sudah diproses.');
        }

        if ($metodePembayaran !== null) {
            $this->setMetodePembayaran($metodePembayaran);
        }

        $pembayaran = $this->pembayaran;

        if (!$pembayaran instanceof PaymentMethod) {
            throw new RuntimeException('Metode pembayaran belum ditentukan.');
        }

        $this->hitungTotal();

        // Validasi jumlah dibayar vs total didelegasikan ke strategi
        // (PembayaranTunai / PembayaranNonTunai), bukan IF/ELSE di sini.
        if (!$pembayaran->prosesBayar($this->total, $pembayaran->getJumlah())) {
            return false;
        }

        $this->selesai = true;

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $diskonId = $this->diskon !== null && $this->diskon->getId() !== ''
                ? $this->diskon->getId()
                : null;

            // Persistensi baris pembayaran: hanya strategi bertipe Pembayaran
            // yang punya representasi tabel `pembayaran`.
            $pembayaranId = $pembayaran instanceof Pembayaran ? $pembayaran->simpan() : null;

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

            // Pasca-penyelesaian: beri tahu observer (mis. Struk untuk
            // menyiapkan JSON, LaporanPenjualan untuk mencatat rekap) —
            // tanpa hardcode logika mereka di sini.
            $this->notify();
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
            'SELECT t.id, t.tanggal, t.total, t.kasir_id, u.nama AS kasir_nama
             FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             WHERE t.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }
}
