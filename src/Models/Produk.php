<?php
// src/Models/Produk.php
// Class Produk: barang yang dijual; setiap produk memiliki satu object Kategori.

class Produk
{
    // Ambang batas stok menipis (spec tidak menyebut angka pasti; asumsi default).
    public const STOK_MINIMUM = 10;

    private string $id;
    private string $nama;
    private float $harga;
    private int $stok;
    private Kategori $kategori;

    // id di-cast ke string sesuai pola di User.php.
    public function __construct(int|string $id, string $nama, float $harga, int $stok, Kategori $kategori)
    {
        $this->id       = (string) $id;
        $this->nama     = $nama;
        $this->harga    = $harga;
        $this->stok     = $stok;
        $this->kategori = $kategori;
    }

    // Menambah stok (restock / barang masuk) lalu simpan ke database.
    public function updateStok(int $qty): void
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Qty penambahan stok harus lebih dari 0.');
        }

        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('UPDATE produk SET stok = stok + :qty WHERE id = :id');
        $stmt->execute(['qty' => $qty, 'id' => (int) $this->id]);

        $this->stok += $qty;
    }

    // Mengurangi stok (dipakai saat transaksi/retur). Throw StokTidakCukupException
    // jika qty melebihi stok — stok tidak boleh minus di database.
    public function kurangiStok(int $qty): void
    {
        if ($qty <= 0) {
            throw new InvalidArgumentException('Qty pengurangan stok harus lebih dari 0.');
        }

        if ($qty > $this->stok) {
            throw new StokTidakCukupException(
                "Stok {$this->nama} tidak cukup (tersedia {$this->stok}, diminta {$qty})."
            );
        }

        // UPDATE atomik dengan kondisi stok >= qty sebagai pengaman di level DB.
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('UPDATE produk SET stok = stok - :qty WHERE id = :id AND stok >= :stok_min');
        $stmt->bindValue('qty', $qty, PDO::PARAM_INT);
        $stmt->bindValue('id', (int) $this->id, PDO::PARAM_INT);
        $stmt->bindValue('stok_min', $qty, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() === 0) {
            throw new StokTidakCukupException("Stok {$this->nama} di database tidak mencukupi.");
        }

        $this->stok -= $qty;
    }

    // Cek apakah stok saat ini berada di bawah/sama dengan ambang minimum.
    public function cekStokMenipis(): bool
    {
        return $this->stok <= self::STOK_MINIMUM;
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

    public function getKategori(): Kategori
    {
        return $this->kategori;
    }

    // Ambil satu Produk berdasarkan id, sekaligus JOIN kategori agar object
    // Kategori ikut ter-load. Return null jika tidak ditemukan.
    public static function findById(int|string $id): ?Produk
    {
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id, k.nama AS kategori_nama
             FROM produk p
             JOIN kategori k ON k.id = p.kategori_id
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        $kategori = new Kategori(['id' => $row['kategori_id'], 'nama' => $row['kategori_nama']]);

        return new Produk($row['id'], $row['nama'], (float) $row['harga'], (int) $row['stok'], $kategori);
    }
}
