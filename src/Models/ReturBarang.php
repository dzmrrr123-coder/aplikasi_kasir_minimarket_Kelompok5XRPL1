<?php
// src/Models/ReturBarang.php
// Class ReturBarang: pencatatan retur barang ke supplier (di-trigger Admin).
// Alur (spec bagian 5): cek stok cukup -> cek supplier valid -> kurangi stok
// & catat retur secara atomik.

class ReturBarang
{
    private string $id = '';
    private DateTime $tanggal;
    private Produk $produk;
    private Supplier $supplier;
    private int $qty;
    private string $alasan;

    // Retur baru: tanggal otomatis sekarang.
    public function __construct(Produk $produk, Supplier $supplier, int $qty, string $alasan)
    {
        $this->tanggal  = new DateTime();
        $this->produk   = $produk;
        $this->supplier = $supplier;
        $this->qty      = $qty;
        $this->alasan   = $alasan;
    }

    // Memproses retur. Exception yang dilempar menandai titik gagalnya:
    // - StokTidakCukupException     -> gagal di titik "cek stok retur"
    // - SupplierTidakValidException -> gagal di titik "validasi supplier"
    public function prosesRetur(): bool
    {
        // a. Cek stok produk cukup untuk diretur (belum mengubah apa pun).
        if ($this->qty > $this->produk->getStok()) {
            throw new StokTidakCukupException(
                "Retur gagal di titik cek stok retur: stok {$this->produk->getNama()} "
                . "tidak cukup (tersedia {$this->produk->getStok()}, diretur {$this->qty})."
            );
        }

        // b. Cek data supplier valid: ambil ulang dari DB untuk memastikan
        //    supplier masih ada (belum dihapus). Jika invalid, proses BERHENTI
        //    di sini — stok belum disentuh sama sekali.
        if (Supplier::findById($this->supplier->getId()) === null) {
            throw new SupplierTidakValidException(
                "Retur gagal di titik validasi supplier: supplier #{$this->supplier->getId()} tidak ditemukan."
            );
        }

        // c. Kedua validasi lolos. Pengurangan stok + pencatatan retur WAJIB
        //    atomik dalam satu transaction: kalau INSERT retur_barang gagal
        //    setelah stok dikurangi, rollback mengembalikan stok seperti semula
        //    (spec melarang "stok berkurang tapi retur gagal tercatat").
        $pdo = Database::getInstance()->getConnection();

        try {
            $pdo->beginTransaction();

            // kurangiStok() ikut dalam transaction ini (koneksi PDO yang sama)
            // dan tetap melempar StokTidakCukupException sebagai pengaman ganda.
            $this->produk->kurangiStok($this->qty);

            $stmt = $pdo->prepare(
                'INSERT INTO retur_barang (tanggal, produk_id, supplier_id, qty, alasan)
                 VALUES (:tanggal, :produk_id, :supplier_id, :qty, :alasan)'
            );
            $stmt->execute([
                'tanggal'     => $this->tanggal->format('Y-m-d H:i:s'),
                'produk_id'   => (int) $this->produk->getId(),
                'supplier_id' => (int) $this->supplier->getId(),
                'qty'         => $this->qty,
                'alasan'      => $this->alasan,
            ]);
            $this->id = (string) $pdo->lastInsertId();

            $pdo->commit();
        } catch (Throwable $e) {
            // Titik rollback: batalkan SEMUA perubahan (termasuk stok yang sudah
            // terlanjur dikurangi di DB) lalu lempar ulang errornya.
            $pdo->rollBack();
            throw $e;
        }

        return true;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTanggal(): DateTime
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
}
