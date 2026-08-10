<?php
// src/Models/Supplier.php
// Class Supplier: pemasok barang, terkait dengan ReturBarang.

class Supplier
{
    private string $id;
    private string $nama;
    private string $kontak;
    private string $alamat;

    // Menerima array hasil query atau parameter individual (id, nama, kontak,
    // alamat). id di-cast ke string sesuai pola class lain.
    public function __construct(array|int|string $data = '', string $nama = '', string $kontak = '', string $alamat = '')
    {
        if (is_array($data)) {
            $this->id     = (string) ($data['id'] ?? '');
            $this->nama   = (string) ($data['nama'] ?? '');
            $this->kontak = (string) ($data['kontak'] ?? '');
            $this->alamat = (string) ($data['alamat'] ?? '');
        } else {
            $this->id     = (string) $data;
            $this->nama   = $nama;
            $this->kontak = $kontak;
            $this->alamat = $alamat;
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

    public function getKontak(): string
    {
        return $this->kontak;
    }

    public function getAlamat(): string
    {
        return $this->alamat;
    }

    // Ambil satu Supplier berdasarkan id; null jika tidak ditemukan.
    public static function findById(int|string $id): ?Supplier
    {
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id, nama, kontak, alamat FROM supplier WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new Supplier($row);
    }
}
