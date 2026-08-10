<?php
// src/Models/Transaksi.php
// Class Transaksi: transaksi penjualan oleh kasir.
// Alur (spec bagian 5): tambah item (cek stok) -> hitung total -> (opsional)
// diskon -> proses pembayaran -> jika berhasil: kurangi stok & simpan ke DB.

class Transaksi
{
    private string $id = '';
    private DateTime $tanggal;
    private float $total = 0.0;
    private array $items = []; // ItemTransaksi[]
    private ?Diskon $diskon = null;
    private ?Pembayaran $pembayaran = null;
    private string $status = 'pending';
    private ?Kasir $kasir;
    private ?Struk $struk = null;

    // Transaksi baru: tanggal sekarang, total 0, tanpa item, status pending.
    public function __construct(?Kasir $kasir = null)
    {
        $this->tanggal = new DateTime();
        $this->kasir   = $kasir;
    }

    // Menambah item ke transaksi. Di titik ini BARU cek stok (belum mengurangi
    // stok di database) — pengurangan stok baru terjadi di prosesPembayaran().
    // Stok tidak cukup -> StokTidakCukupException, supaya pemanggil bisa menolak
    // item ini saja tanpa menggagalkan seluruh transaksi.
    public function tambahItem(Produk $produk, int $qty): void
    {
        // Cari qty produk yang sama (berdasarkan id) yang sudah ada di keranjang.
        $indexSama      = null;
        $qtyDiKeranjang = 0;
        foreach ($this->items as $i => $item) {
            if ($item->getProduk()->getId() === $produk->getId()) {
                $indexSama      = $i;
                $qtyDiKeranjang = $item->getQty();
                break;
            }
        }

        // Total qty (di keranjang + yang baru) tidak boleh melebihi stok saat ini.
        if ($qtyDiKeranjang + $qty > $produk->getStok()) {
            throw new StokTidakCukupException(
                "Stok {$produk->getNama()} tidak cukup (tersedia {$produk->getStok()}, di keranjang {$qtyDiKeranjang}, diminta {$qty})."
            );
        }

        if ($indexSama !== null) {
            // Produk sudah ada di keranjang: gabung jadi satu baris ItemTransaksi
            // (qty & subtotal otomatis terjumlah lewat constructor).
            $this->items[$indexSama] = new ItemTransaksi($produk, $qtyDiKeranjang + $qty);
        } else {
            $this->items[] = new ItemTransaksi($produk, $qty);
        }
    }

    // Menjumlahkan subtotal semua item, menyimpan ke properti total, dan
    // mengembalikan nilainya.
    public function hitungTotal(): float
    {
        $this->total = 0.0;
        foreach ($this->items as $item) {
            $this->total += $item->getSubtotal();
        }

        return $this->total;
    }

    // Menerapkan diskon ke total yang sudah dihitung (panggil setelah hitungTotal).
    public function terapkanDiskon(Diskon $diskon): void
    {
        $this->total  = $diskon->terapkan($this->total);
        $this->diskon = $diskon;
    }

    // Memproses pembayaran transaksi (method tambahan untuk menjalankan alur
    // "proses pembayaran" di spec bagian 5).
    // - Pembayaran gagal  -> stok TIDAK diubah sama sekali, return false.
    // - Pembayaran sukses -> stok dikurangi (di SINI, bukan di tambahItem, karena
    //   hanya transaksi yang sudah lunas yang boleh mengubah stok), transaksi +
    //   item dicatat ke DB, status jadi 'selesai', return true.
    public function prosesPembayaran(Pembayaran $pembayaran): bool
    {
        if ($this->kasir === null) {
            throw new RuntimeException('Transaksi tidak bisa diproses tanpa kasir.');
        }

        // 1. Proses pembayaran dulu — kalau gagal, stok tidak disentuh.
        if (!$pembayaran->proses()) {
            return false;
        }

        $pdo = Database::getInstance()->getConnection();

        try {
            // Satu transaksi DB: pengurangan stok + pencatatan berhasil bersama,
            // atau dibatalkan semua (rollback) jika ada yang gagal di tengah jalan.
            $pdo->beginTransaction();

            // 2. Stok baru benar-benar dikurangi sekarang.
            foreach ($this->items as $item) {
                $item->getProduk()->kurangiStok($item->getQty());
            }

            $this->pembayaran = $pembayaran;
            $this->status     = 'selesai';

            // 3. Catat pembayaran (jenis diturunkan dari subclass-nya).
            $jenisPembayaran = $pembayaran instanceof PembayaranTunai ? 'tunai' : 'non_tunai';
            $stmt = $pdo->prepare('INSERT INTO pembayaran (jenis, jumlah) VALUES (:jenis, :jumlah)');
            $stmt->execute(['jenis' => $jenisPembayaran, 'jumlah' => $pembayaran->getJumlah()]);
            $pembayaranId = (int) $pdo->lastInsertId();

            // 4. Catat transaksi.
            $stmt = $pdo->prepare(
                'INSERT INTO transaksi (tanggal, total, status, kasir_id, diskon_id, pembayaran_id)
                 VALUES (:tanggal, :total, :status, :kasir_id, :diskon_id, :pembayaran_id)'
            );
            $stmt->execute([
                'tanggal'       => $this->tanggal->format('Y-m-d H:i:s'),
                'total'         => $this->total,
                'status'        => $this->status,
                'kasir_id'      => (int) $this->kasir->getId(),
                'diskon_id'     => $this->diskon !== null ? (int) $this->diskon->getId() : null,
                'pembayaran_id' => $pembayaranId,
            ]);
            $this->id = (string) $pdo->lastInsertId();

            // 5. Catat semua item transaksi.
            $stmt = $pdo->prepare(
                'INSERT INTO item_transaksi (transaksi_id, produk_id, qty, subtotal)
                 VALUES (:transaksi_id, :produk_id, :qty, :subtotal)'
            );
            foreach ($this->items as $item) {
                $stmt->execute([
                    'transaksi_id' => (int) $this->id,
                    'produk_id'    => (int) $item->getProduk()->getId(),
                    'qty'          => $item->getQty(),
                    'subtotal'     => $item->getSubtotal(),
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        // 6. Buat struk dari transaksi yang sudah tersimpan (siap dipakai
        //    Kasir::cetakStruk()).
        $this->struk = new Struk($this->id);

        return true;
    }

    // Membatalkan transaksi.
    // ASUMSI: method ini dipanggil saat transaksi masih 'pending' (SEBELUM
    // prosesPembayaran() sukses), sehingga stok belum pernah dikurangi dan tidak
    // perlu dikembalikan.
    public function batalkan(): void
    {
        $this->status = 'batal';

        // Update ke DB hanya jika transaksi sudah pernah tersimpan.
        if ($this->id !== '') {
            $pdo  = Database::getInstance()->getConnection();
            $stmt = $pdo->prepare("UPDATE transaksi SET status = 'batal' WHERE id = :id");
            $stmt->execute(['id' => (int) $this->id]);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTanggal(): DateTime
    {
        return $this->tanggal;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getKasir(): ?Kasir
    {
        return $this->kasir;
    }

    public function getStruk(): ?Struk
    {
        return $this->struk;
    }
}
