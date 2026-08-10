<?php
// src/Models/Kategori.php
// Class Kategori: kelompok produk (relasi Produk -> Kategori).

class Kategori
{
    private string $id;
    private string $nama;

    // Menerima array hasil query (['id' => ..., 'nama' => ...]) atau parameter
    // individual (id, nama). id di-cast ke string sesuai pola di User.php.
    public function __construct(array|int|string $data = '', string $nama = '')
    {
        if (is_array($data)) {
            $this->id   = (string) ($data['id'] ?? '');
            $this->nama = (string) ($data['nama'] ?? '');
        } else {
            $this->id   = (string) $data;
            $this->nama = $nama;
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

    // Ambil satu Kategori berdasarkan id; null jika tidak ditemukan.
    public static function findById(int|string $id): ?Kategori
    {
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id, nama FROM kategori WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new Kategori($row);
    }
}
