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
    private float $pajak = 0.0;
    private int $kasirId = 0;
    private string $kasirNama = '';
    private int $memberId = 0;
    private int $gudangId = 0;
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
        if (isset($data['pajak'])) {
            $this->pajak = (float) $data['pajak'];
        }
        if (isset($data['kasir_id'])) {
            $this->kasirId = (int) $data['kasir_id'];
        }
        if (isset($data['kasir_nama'])) {
            $this->kasirNama = (string) $data['kasir_nama'];
        }
        if (isset($data['member_id'])) {
            $this->memberId = (int) $data['member_id'];
        }
        if (isset($data['gudang_id'])) {
            $this->gudangId = (int) $data['gudang_id'];
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

    /** Nilai pajak (PPN) yang dibayar pada transaksi ini. */
    public function getPajak(): float
    {
        return $this->pajak;
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

    public function getMemberId(): int
    {
        return $this->memberId;
    }

    public function setMemberId(int $memberId): void
    {
        $this->memberId = $memberId;
    }

    public function getGudangId(): int
    {
        return $this->gudangId;
    }

    public function setGudangId(int $gudangId): void
    {
        $this->gudangId = $gudangId;
    }

    /** Nama member dari database (kosong bila bukan transaksi member). */
    public function getMemberNama(): string
    {
        if ($this->memberId <= 0) {
            return '';
        }

        $member = Member::cari($this->memberId);

        return $member?->getNama() ?? '';
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
    public function tambahItem(Produk $produk, float $qty, float $potongan = 0.0): void
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

        $subtotal = round($produkSalinan->getHargaEfektif($qty) * $qty, 2) - $potongan;
        $subtotal = max(0.0, $subtotal); // cegah subtotal minus
        
        $item = new ItemTransaksi([
            'produk'  => $produkSalinan,
            'qty'     => $qty,
            'subtotal'=> $subtotal,
            'potongan'=> $potongan,
        ]);
        $this->items[] = $item;
    }

    /**
     * Menghitung total transaksi: subtotal item -> diskon (sekali) -> pajak.
     * Idempotent: diskon & pajak diterapkan dari state saat ini, jadi aman
     * dipanggil berulang (tidak akan menerapkan diskon dua kali).
     */
    public function hitungTotal(): float
    {
        $total = 0.0;

        foreach ($this->items as $item) {
            $total += $item->getSubtotal();
        }

        $total = round($total, 2);

        if ($this->diskon instanceof Diskon) {
            $total = round($this->diskon->terapkan($total), 2);
        }

        // PPN (persen) dihitung atas total setelah diskon, dari pengaturan toko.
        $persenPajak = (float) (Pengaturan::get('pajak', '0') ?: 0);
        $inklusif = (bool) (Pengaturan::get('pajak_inklusif', '0') ?: false);

        if ($persenPajak > 0) {
            if ($inklusif) {
                // Harga barang sudah termasuk pajak
                // Total = Subtotal
                // Pajak = Total - (Total / (1 + persenPajak / 100))
                $this->pajak = round($total - ($total / (1 + ($persenPajak / 100))), 2);
                $this->total = max(0.0, $total);
            } else {
                // Pajak ditambahkan ke subtotal
                $this->pajak = round($total * $persenPajak / 100, 2);
                $this->total = max(0.0, round($total + $this->pajak, 2));
            }
        } else {
            $this->pajak = 0.0;
            $this->total = max(0.0, $total);
        }

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
                'INSERT INTO transaksi (tanggal, total, pajak, kasir_id, diskon_id, pembayaran_id, member_id, gudang_id)
                 VALUES (:tanggal, :total, :pajak, :kasir_id, :diskon_id, :pembayaran_id, :member_id, :gudang_id)'
            );
            $stmt->execute([
                ':tanggal'        => $this->tanggal->format('Y-m-d H:i:s'),
                ':total'          => $this->total,
                ':pajak'          => $this->pajak,
                ':kasir_id'       => $this->kasirId,
                ':diskon_id'      => $diskonId,
                ':pembayaran_id'  => $pembayaranId,
                ':member_id'      => $this->memberId > 0 ? $this->memberId : null,
                ':gudang_id'      => $this->gudangId > 0 ? $this->gudangId : null,
            ]);

            $transaksiId = (int) $pdo->lastInsertId();
            $this->id = (string) $transaksiId;

            foreach ($this->items as $item) {
                $item->simpan($transaksiId);

                $produk = $item->getProduk();

                // Atomic stock decrement: hanya berhasil bila stok masih cukup.
                // Mencegah dua kasir menjual stok terakhir bersamaan (race).
                // Catatan: named parameter tidak boleh dipakai 2x saat
                // emulasi prepare nonaktif, jadi pakai nama berbeda.
                //
                // Stok disimpan sebagai bilangan bulat (pcs/gram), sedangkan
                // qty produk curah boleh pecahan. Mutasi stok dibulatkan ke
                // satuan terkecil supaya konsisten dan tidak menimbulkan drift
                // saat pembulatan dilakukan oleh MySQL (mis. jual 125.5 g lalu
                // batal). Minimum 1 agar UPDATE tidak menjadi no-op.
                $qtyItem = $item->getQty();
                $qtyStok = max(1, (int) round($qtyItem));
                $update = $pdo->prepare(
                    'UPDATE produk SET stok = stok - :qty WHERE id = :id AND stok >= :qty_cek'
                );
                $update->execute([
                    ':qty'     => $qtyStok,
                    ':qty_cek' => $qtyStok,
                    ':id'      => (int) $produk->getId(),
                ]);

                if ($update->rowCount() === 0) {
                    throw new RuntimeException(
                        sprintf('Stok "%s" tidak cukup saat pembayaran.', $produk->getNama())
                    );
                }

                // Multi-gudang: sync stok di gudang juga.
                if ($this->gudangId > 0) {
                    $stokGudang = Gudang::stokProduk($this->gudangId, (int) $produk->getId());
                    if ($stokGudang < $qtyStok) {
                        throw new RuntimeException(
                            sprintf('Stok "%s" di gudang tidak cukup (tersedia: %d).', $produk->getNama(), $stokGudang)
                        );
                    }
                    Gudang::setStokProduk($this->gudangId, (int) $produk->getId(), $stokGudang - $qtyStok);
                }
            }

            // Poin member: 1 poin per Rp 1.000 belanja (dibulatkan ke bawah).
            // Disimpan di kolom poin_diberikan supaya saat batalkan() bisa
            // dikembalikan persis, bukan dihitung ulang dari total.
            if ($this->memberId > 0) {
                $poinDiberikan = (int) floor($this->total / 1000);

                if ($poinDiberikan > 0) {
                    $upPoin = $pdo->prepare(
                        'UPDATE transaksi SET poin_diberikan = :poin WHERE id = :id'
                    );
                    $upPoin->execute([':poin' => $poinDiberikan, ':id' => $transaksiId]);
                    Member::tambahPoinId($this->memberId, $poinDiberikan);
                }
            }

            // Observer (Struk & LaporanPenjualan) dipanggil SEBELUM commit:
            // kalau penulisan rekap_penjualan gagal, seluruh transaksi ikut
            // di-rollback — tidak ada transaksi sukses tanpa rekap.
            $this->notify();

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
     * poin member dikembalikan, lalu baris transaksi (beserta item) dihapus.
     */
    public function batalkan(): void
    {
        if ($this->id === '') {
            throw new RuntimeException('Transaksi belum tersimpan, tidak bisa dibatalkan.');
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Ambil member_id, total & poin_diberikan dari DB (objek bisa
            // jadi belum punya nilai itu; poin dikembalikan sesuai snapshot).
            $stmtInfo = $pdo->prepare(
                'SELECT member_id, total, poin_diberikan, gudang_id FROM transaksi WHERE id = :id LIMIT 1'
            );
            $stmtInfo->execute([':id' => (int) $this->id]);
            $info = $stmtInfo->fetch();
            $memberId = $info === false ? 0 : (int) ($info['member_id'] ?? 0);
            $totalFinal = $info === false ? 0.0 : (float) ($info['total'] ?? 0);
            $poinDiberikan = $info === false ? 0 : (int) ($info['poin_diberikan'] ?? 0);
            $gudangIdDb = $info === false ? 0 : (int) ($info['gudang_id'] ?? 0);
            // Gunakan gudang_id dari DB (lebih reliable daripada dari objek in-memory)
            if ($gudangIdDb > 0) {
                $this->gudangId = $gudangIdDb;
            }

            $items = ItemTransaksi::untukTransaksi((int) $this->id);

            foreach ($items as $item) {
                $produk = $item->getProduk();

                // Kembalikan stok secara atomik (tidak mungkin negatif saat restore).
                // Bulatkan ke satuan stok integer, minimal 1 agar konsisten
                // dengan pengurangan stok saat transaksi dibuat.
                $qtyStok = max(1, (int) round($item->getQty()));
                $update = $pdo->prepare('UPDATE produk SET stok = stok + :qty WHERE id = :id');
                $update->execute([
                    ':qty' => $qtyStok,
                    ':id'  => (int) $produk->getId(),
                ]);

                // Multi-gudang: kembalikan stok di gudang juga.
                if ($this->gudangId > 0) {
                    Gudang::tambahStok($this->gudangId, (int) $produk->getId(), $qtyStok);
                }
            }

            $stmt = $pdo->prepare('DELETE FROM transaksi WHERE id = :id');
            $stmt->execute([':id' => $this->id]);

            // Kembalikan poin member sesuai snapshot yang disimpan saat
            // transaksi dibuat (bukan floor(total/1000) yang bisa salah
            // kalau member sudah menukar poin setelah transaksi).
            if ($memberId > 0 && $poinDiberikan > 0) {
                Member::tambahPoinId($memberId, -$poinDiberikan);
            }

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
