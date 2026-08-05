<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Kategori
{
    private string $id = '';
    private string $nama = '';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
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

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    /**
     * Mengambil semua kategori, diurutkan berdasarkan nama.
     *
     * @return Kategori[]
     */
    public static function semua(): array
    {
        $pdo = Database::connect();
        $rows = $pdo->query('SELECT id, nama FROM kategori ORDER BY nama')->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare('SELECT id, nama FROM kategori WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('INSERT INTO kategori (nama) VALUES (:nama)');
        $stmt->execute([':nama' => $this->nama]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $stmt = Database::connect()->prepare('UPDATE kategori SET nama = :nama WHERE id = :id');
        $stmt->execute([':nama' => $this->nama, ':id' => $this->id]);
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM kategori WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }
}
