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
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare('SELECT id, nama FROM kategori WHERE admin_id = :admin_id ORDER BY nama');
        $stmt->execute([':admin_id' => $adminId]);

        return array_map(static fn (array $row): self => new self($row), $stmt->fetchAll());
    }

    public static function cari(int $id): ?self
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare('SELECT id, nama FROM kategori WHERE id = :id AND admin_id = :admin_id LIMIT 1');
        $stmt->execute([':id' => $id, ':admin_id' => $adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    public function simpan(): int
    {
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama kategori tidak boleh kosong.');
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare('INSERT INTO kategori (nama, admin_id) VALUES (:nama, :admin_id)');
        $stmt->execute([':nama' => $this->nama, ':admin_id' => currentAdminId()]);

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
