<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class Supplier
{
    private string $id = '';
    private string $nama = '';
    private string $kontak = '';
    private string $alamat = '';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['kontak'])) {
            $this->kontak = (string) $data['kontak'];
        }
        if (isset($data['alamat'])) {
            $this->alamat = (string) $data['alamat'];
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

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    public function setKontak(string $kontak): void
    {
        $this->kontak = $kontak;
    }

    public function setAlamat(string $alamat): void
    {
        $this->alamat = $alamat;
    }

    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (nama, kontak, alamat) VALUES (:nama, :kontak, :alamat)'
        );
        $stmt->execute([
            ':nama'   => $this->nama,
            ':kontak' => $this->kontak,
            ':alamat' => $this->alamat,
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $stmt = Database::connect()->prepare(
            'UPDATE supplier SET nama = :nama, kontak = :kontak, alamat = :alamat WHERE id = :id'
        );
        $stmt->execute([
            ':nama'   => $this->nama,
            ':kontak' => $this->kontak,
            ':alamat' => $this->alamat,
            ':id'     => $this->id,
        ]);
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM supplier WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    /**
     * Mengambil semua supplier, diurutkan berdasarkan nama.
     *
     * @return Supplier[]
     */
    public static function semua(): array
    {
        $rows = Database::connect()->query(
            'SELECT id, nama, kontak, alamat FROM supplier ORDER BY nama'
        )->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, kontak, alamat FROM supplier WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }
}
