<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Produk implements DataReporter
{
    private string $id = '';
    private string $nama = '';
    private float $harga = 0.0;
    private int $stok = 0;
    private string $satuan = 'pcs'; // pcs | gram
    private float $hargaPerGram = 0.0;
    private string $barcode = '';
    private float $hargaBeli = 0.0;
    private int $stokMinimum = 0;
    private int $supplierId = 0;
    private bool $isActive = true;
    private string $gambar = '';
    private float $hargaGrosir = 0.0;
    private int $batasGrosir = 0;
    private Kategori $kategori;

    private const AMBANG_STOK_MENIPIS = 10;

    public function __construct(array $data = [])
    {
        $this->kategori = new Kategori();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['harga'])) {
            $this->harga = (float) $data['harga'];
        }
        if (isset($data['stok'])) {
            $this->stok = (int) $data['stok'];
        }
        if (isset($data['satuan'])) {
            $this->satuan = (string) $data['satuan'];
        }
        if (isset($data['harga_per_gram'])) {
            $this->hargaPerGram = (float) $data['harga_per_gram'];
        }
        if (isset($data['barcode'])) {
            $this->barcode = (string) $data['barcode'];
        }
        if (isset($data['harga_beli'])) {
            $this->hargaBeli = (float) $data['harga_beli'];
        }
        if (isset($data['stok_minimum'])) {
            $this->stokMinimum = (int) $data['stok_minimum'];
        }
        if (isset($data['supplier_id'])) {
            $this->supplierId = (int) $data['supplier_id'];
        }
        if (isset($data['is_active'])) {
            $this->isActive = (bool) $data['is_active'];
        }
        if (isset($data['gambar'])) {
            $this->gambar = (string) $data['gambar'];
        }
        if (isset($data['harga_grosir'])) {
            $this->hargaGrosir = (float) $data['harga_grosir'];
        }
        if (isset($data['batas_grosir'])) {
            $this->batasGrosir = (int) $data['batas_grosir'];
        }
        if (isset($data['kategori_id'])) {
            $kategori = Kategori::cari((int) $data['kategori_id']);
            $this->kategori = $kategori ?? new Kategori();
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNama(): string
    {
        return $this->nama;
    }

    public function getHarga(): float
    {
        return $this->harga;
    }

    public function getStok(): int
    {
        return $this->stok;
    }

    public function getSatuan(): string
    {
        return $this->satuan;
    }

    public function getHargaPerGram(): float
    {
        return $this->hargaPerGram;
    }

    public function getBarcode(): string
    {
        return $this->barcode;
    }

    public function getHargaBeli(): float
    {
        return $this->hargaBeli;
    }

    public function getStokMinimum(): int
    {
        return $this->stokMinimum;
    }

    public function getSupplierId(): int
    {
        return $this->supplierId;
    }

    public function getSupplierNama(): string
    {
        if ($this->supplierId <= 0) {
            return '';
        }

        return Supplier::cari($this->supplierId)?->getNama() ?? '';
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** Nama file gambar produk (relatif ke public/uploads/), kosong bila tak ada. */
    public function getGambar(): string
    {
        return $this->gambar;
    }

    public function setGambar(string $gambar): void
    {
        $this->gambar = trim($gambar);
    }

    public function getHargaGrosir(): float
    {
        return $this->hargaGrosir;
    }

    public function getBatasGrosir(): int
    {
        return $this->batasGrosir;
    }

    public function setHargaGrosir(float $harga): void
    {
        $this->hargaGrosir = $harga;
    }

    public function setBatasGrosir(int $batas): void
    {
        $this->batasGrosir = $batas;
    }

    /**
     * Harga per unit yang dipakai saat bertransaksi:
     * produk gram -> harga per gram; produk pcs -> harga satuan.
     * Jika qty melebihi batas grosir, gunakan harga grosir.
     */
    public function getHargaEfektif(float $qty = 1): float
    {
        $hargaNormal = $this->satuan === 'gram' ? $this->hargaPerGram : $this->harga;
        
        if ($this->satuan === 'pcs' && $this->batasGrosir > 0 && $this->hargaGrosir > 0 && $qty >= $this->batasGrosir) {
            return $this->hargaGrosir;
        }

        return $hargaNormal;
    }

    public function getKategori(): Kategori
    {
        return $this->kategori;
    }

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    public function setHarga(float $harga): void
    {
        $this->harga = $harga;
    }

    public function setSatuan(string $satuan): void
    {
        $this->satuan = $satuan === 'gram' ? 'gram' : 'pcs';
    }

    public function setHargaPerGram(float $hargaPerGram): void
    {
        $this->hargaPerGram = $hargaPerGram;
    }

    public function setBarcode(string $barcode): void
    {
        $this->barcode = trim($barcode);
    }

    public function setHargaBeli(float $hargaBeli): void
    {
        $this->hargaBeli = $hargaBeli;
    }

    public function setStokMinimum(int $stokMinimum): void
    {
        $this->stokMinimum = $stokMinimum;
    }

    public function setSupplierId(int $supplierId): void
    {
        $this->supplierId = $supplierId;
    }

    public function setIsActive(bool $isActive): void
    {
        $this->isActive = $isActive;
    }

    public function setKategori(Kategori $kategori): void
    {
        $this->kategori = $kategori;
    }

    public function setStok(int $stok): void
    {
        $this->stok = $stok;
    }

    public function updateStok(float $qty): void
    {
        // Stok disimpan sebagai bilangan bulat (gram/pcs); qty float
        // (produk curah) dibulatkan ke gram terdekat.
        $this->stok += (int) round($qty);
    }

    public function kurangiStok(float $qty): void
    {
        $this->stok -= (int) round($qty);
    }

    public function cekStokMenipis(): bool
    {
        // Bila stok_minimum per produk diset (> 0), pakai itu;
        // kalau belum diset, fallback ke ambang umum lama.
        $ambang = $this->stokMinimum > 0 ? $this->stokMinimum : self::AMBANG_STOK_MENIPIS;

        return $this->stok <= $ambang;
    }

    public function simpan(): int
    {
        // Alur tambah produk: cek kategori valid dulu -> validasi data produk ->
        // kalau valid, simpan. Kalau kategori/data invalid, kembalikan pesan error.
        $this->validasi();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO produk (nama, harga, stok, kategori_id, satuan, harga_per_gram,
                                 barcode, harga_beli, stok_minimum, supplier_id, is_active, gambar, harga_grosir, batas_grosir, admin_id)
             VALUES (:nama, :harga, :stok, :kategori_id, :satuan, :harga_per_gram,
                     :barcode, :harga_beli, :stok_minimum, :supplier_id, :is_active, :gambar, :harga_grosir, :batas_grosir, :admin_id)'
        );
        $stmt->execute([
            ':nama'            => $this->nama,
            ':harga'           => $this->harga,
            ':stok'            => $this->stok,
            ':kategori_id'     => $this->kategori->getId(),
            ':satuan'          => $this->satuan,
            ':harga_per_gram'  => $this->satuan === 'gram' ? $this->hargaPerGram : null,
            ':barcode'         => $this->barcode !== '' ? $this->barcode : null,
            ':harga_beli'      => $this->hargaBeli,
            ':stok_minimum'    => $this->stokMinimum,
            ':supplier_id'     => $this->supplierId > 0 ? $this->supplierId : null,
            ':is_active'       => $this->isActive ? 1 : 0,
            ':gambar'          => $this->gambar !== '' ? $this->gambar : null,
            ':harga_grosir'    => $this->hargaGrosir > 0 ? $this->hargaGrosir : null,
            ':batas_grosir'    => $this->batasGrosir > 0 ? $this->batasGrosir : null,
            ':admin_id'        => currentAdminId(),
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $this->validasi();

        $stmt = Database::connect()->prepare(
            'UPDATE produk
             SET nama = :nama, harga = :harga, stok = :stok, kategori_id = :kategori_id,
                 satuan = :satuan, harga_per_gram = :harga_per_gram,
                 barcode = :barcode, harga_beli = :harga_beli, stok_minimum = :stok_minimum,
                 supplier_id = :supplier_id, is_active = :is_active, gambar = :gambar,
                 harga_grosir = :harga_grosir, batas_grosir = :batas_grosir
             WHERE id = :id'
        );
        $stmt->execute([
            ':nama'            => $this->nama,
            ':harga'           => $this->harga,
            ':stok'            => $this->stok,
            ':kategori_id'     => $this->kategori->getId(),
            ':satuan'          => $this->satuan,
            ':harga_per_gram'  => $this->satuan === 'gram' ? $this->hargaPerGram : null,
            ':barcode'         => $this->barcode !== '' ? $this->barcode : null,
            ':harga_beli'      => $this->hargaBeli,
            ':stok_minimum'    => $this->stokMinimum,
            ':supplier_id'     => $this->supplierId > 0 ? $this->supplierId : null,
            ':is_active'       => $this->isActive ? 1 : 0,
            ':gambar'          => $this->gambar !== '' ? $this->gambar : null,
            ':harga_grosir'    => $this->hargaGrosir > 0 ? $this->hargaGrosir : null,
            ':batas_grosir'    => $this->batasGrosir > 0 ? $this->batasGrosir : null,
            ':id'              => $this->id,
        ]);
    }

    /**
     * Validasi data produk: kategori harus valid, nama tidak boleh kosong,
     * harga tidak boleh negatif, stok tidak boleh negatif, dan untuk produk
     * gram harga_per_gram harus terisi positif.
     *
     * @throws \RuntimeException bila ada data yang tidak valid
     */
    private function validasi(): void
    {
        if ($this->kategori->getId() === '' || $this->kategori->getNama() === '') {
            throw new \RuntimeException('Kategori tidak valid.');
        }
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama produk tidak boleh kosong.');
        }
        if ($this->harga < 0) {
            throw new \RuntimeException('Harga produk tidak boleh negatif.');
        }
        if ($this->stok < 0) {
            throw new \RuntimeException('Stok produk tidak boleh negatif.');
        }
        if ($this->satuan === 'gram' && $this->hargaPerGram <= 0) {
            throw new \RuntimeException('Produk gram wajib punya harga per gram lebih dari 0.');
        }
        if ($this->barcode !== '' && $this->barcodeTerpakai()) {
            throw new \RuntimeException('Barcode sudah dipakai produk lain.');
        }
        if ($this->hargaBeli < 0) {
            throw new \RuntimeException('Harga beli tidak boleh negatif.');
        }
        if ($this->stokMinimum < 0) {
            throw new \RuntimeException('Stok minimum tidak boleh negatif.');
        }
    }

    /** Cek apakah barcode sudah dipakai produk lain. */
    private function barcodeTerpakai(): bool
    {
        $adminId = currentAdminId();
        if ($this->id === '') {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM produk WHERE barcode = :barcode AND admin_id = :admin_id'
            );
            $stmt->execute([':barcode' => $this->barcode, ':admin_id' => $adminId]);
        } else {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM produk WHERE barcode = :barcode AND id <> :id AND admin_id = :admin_id'
            );
            $stmt->execute([':barcode' => $this->barcode, ':id' => $this->id, ':admin_id' => $adminId]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM produk WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    /**
     * Harga beli terakhir untuk produk ini dari supplier tertentu.
     * Prioritas:
     *   1. item_pembelian terakhir yang memakai produk + supplier itu
     *   2. fallback: harga_beli produk saat ini (kalau pernah diset manual)
     *
     * @param int $supplierId id supplier (0 = abaikan filter supplier)
     */
    public static function hargaBeliTerakhir(int $produkId, int $supplierId): float
    {
        $pdo = Database::connect();

        if ($supplierId > 0) {
            $stmt = $pdo->prepare(
                'SELECT ip.harga_beli_satuan
                 FROM item_pembelian ip
                 JOIN pembelian pb ON pb.id = ip.pembelian_id
                 WHERE ip.produk_id = :produk AND pb.supplier_id = :supplier
                 ORDER BY pb.tanggal DESC, ip.id DESC
                 LIMIT 1'
            );
            $stmt->execute([':produk' => $produkId, ':supplier' => $supplierId]);
            $nilai = $stmt->fetchColumn();

            if ($nilai !== false) {
                return (float) $nilai;
            }
        }

        // Fallback: harga beli terakhir produk (apa pun suppliernya).
        $stmt = $pdo->prepare(
            'SELECT ip.harga_beli_satuan
             FROM item_pembelian ip
             WHERE ip.produk_id = :produk
             ORDER BY ip.id DESC
             LIMIT 1'
        );
        $stmt->execute([':produk' => $produkId]);
        $nilai = $stmt->fetchColumn();

        if ($nilai !== false) {
            return (float) $nilai;
        }

        // Terakhir: harga beli yang tersimpan di produk.
        $produk = self::cari($produkId);

        return $produk?->getHargaBeli() ?? 0.0;
    }

    /**
     * Mengambil semua produk beserta kategori-nya, diurutkan berdasarkan nama.
     * Hanya produk aktif yang dikembalikan (soft-delete).
     *
     * @return Produk[]
     */
    public static function semua(): array
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id, p.satuan, p.harga_per_gram,
                    p.barcode, p.harga_beli, p.stok_minimum, p.supplier_id, p.is_active, p.gambar,
                    p.harga_grosir, p.batas_grosir
             FROM produk p
             WHERE p.is_active = 1 AND p.admin_id = :admin_id
             ORDER BY p.nama'
        );
        $stmt->execute([':admin_id' => $adminId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id, p.satuan, p.harga_per_gram,
                    p.barcode, p.harga_beli, p.stok_minimum, p.supplier_id, p.is_active, p.gambar,
                    p.harga_grosir, p.batas_grosir
             FROM produk p
             WHERE p.id = :id AND p.admin_id = :admin_id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':admin_id' => $adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /** Cari produk berdasarkan barcode (dipakai di scan layar POS). */
    public static function cariBerdasarkanBarcode(string $barcode): ?self
    {
        if (trim($barcode) === '') {
            return null;
        }

        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id, p.satuan, p.harga_per_gram,
                    p.barcode, p.harga_beli, p.stok_minimum, p.supplier_id, p.is_active, p.gambar,
                    p.harga_grosir, p.batas_grosir
             FROM produk p
             WHERE p.barcode = :barcode AND p.is_active = 1 AND p.admin_id = :admin_id
             LIMIT 1'
        );
        $stmt->execute([':barcode' => trim($barcode), ':admin_id' => $adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    public static function cariStokMenipis(): array
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, harga, stok, kategori_id, satuan, harga_per_gram,
                    barcode, harga_beli, stok_minimum, supplier_id, is_active, gambar,
                    harga_grosir, batas_grosir
             FROM produk
             WHERE is_active = 1 AND admin_id = :admin_id
               AND stok <= IF(stok_minimum > 0, stok_minimum, ' . self::AMBANG_STOK_MENIPIS . ')
             ORDER BY stok ASC'
        );
        $stmt->execute([':admin_id' => $adminId]);
        $rows = $stmt->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik inventaris: total stok per kategori.
     *
     * @param array<string, mixed> $params (tidak wajib; limit kategori)
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $limit = max(1, (int) ($params['limit'] ?? 8));

        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT k.nama AS kategori, COALESCE(SUM(p.stok), 0) AS stok
             FROM kategori k
             LEFT JOIN produk p ON p.kategori_id = k.id AND p.admin_id = :admin_id
             WHERE k.admin_id = :admin_id2
             GROUP BY k.id, k.nama
             ORDER BY stok DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':admin_id', $adminId, \PDO::PARAM_INT);
        $stmt->bindValue(':admin_id2', $adminId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $labels = [];
        $data = [];

        foreach ($stmt->fetchAll() as $row) {
            $labels[] = $row['kategori'];
            $data[] = (int) $row['stok'];
        }

        return [
            'labels' => $labels,
            'series' => [
                'label' => 'Stok',
                'data'  => $data,
            ],
        ];
    }

    /**
     * Data tabel inventaris: daftar produk (nama, kategori, harga, stok)
     * dengan pencarian & pagination (DataTables server-side).
     *
     * @param array<string, mixed> $params search/start/length
     */
    public function getDataTabel(array $params = []): array
    {
        $cari = trim((string) ($params['search'] ?? ''));
        $start = max(0, (int) ($params['start'] ?? 0));
        $length = max(1, (int) ($params['length'] ?? 10));

        $adminId = currentAdminId();
        $where = 'WHERE p.is_active = 1 AND p.admin_id = :admin_id';
        $bind = [':admin_id' => $adminId];

        if ($cari !== '') {
            $where .= ' AND (p.nama LIKE :cari_nama OR p.barcode LIKE :cari_barcode OR k.nama LIKE :cari_kategori)';
            $bind[':cari_nama'] = '%' . $cari . '%';
            $bind[':cari_barcode'] = '%' . $cari . '%';
            $bind[':cari_kategori'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM produk WHERE is_active = 1 AND admin_id = :admin_id');
        $totalStmt->execute([':admin_id' => $adminId]);
        $total = (int) $totalStmt->fetchColumn();

        $stmtFiltered = $pdo->prepare('SELECT COUNT(*) FROM produk p JOIN kategori k ON k.id = p.kategori_id ' . $where);
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.satuan, p.harga_per_gram,
                    p.barcode, p.harga_beli, p.stok_minimum, p.supplier_id, p.is_active, p.gambar,
                    k.nama AS kategori, s.nama AS supplier_nama
             FROM produk p
             JOIN kategori k ON k.id = p.kategori_id
             LEFT JOIN supplier s ON s.id = p.supplier_id ' . $where . '
             ORDER BY p.nama ASC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bind as $kunci => $nilai) {
            $stmt->bindValue($kunci, $nilai);
        }
        $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            return [
                'id'             => (int) $r['id'],
                'nama'           => $r['nama'],
                'kategori'       => $r['kategori'],
                'harga'          => (float) $r['harga'],
                'stok'           => (int) $r['stok'],
                'satuan'         => $r['satuan'],
                'harga_per_gram' => $r['harga_per_gram'] !== null ? (float) $r['harga_per_gram'] : null,
                'barcode'        => $r['barcode'] ?? '',
                'harga_beli'     => (float) $r['harga_beli'],
                'stok_minimum'   => (int) $r['stok_minimum'],
                'supplier_id'    => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null,
                'supplier_nama'  => $r['supplier_nama'] ?? '',
                'is_active'      => (bool) $r['is_active'],
                'gambar'         => $r['gambar'] ?? '',
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
