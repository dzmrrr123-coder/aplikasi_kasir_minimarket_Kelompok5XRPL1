<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

abstract class Pembayaran
{
    protected string $id = '';
    protected float $jumlah = 0.0;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['jumlah'])) {
            $this->jumlah = (float) $data['jumlah'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getJumlah(): float
    {
        return $this->jumlah;
    }

    abstract public function proses(): bool;

    /**
     * Simpan baris pembayaran ke tabel `pembayaran`.
     * `jenis` diisi oleh subclass via getJenis().
     */
    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO pembayaran (jenis, jumlah) VALUES (:jenis, :jumlah)'
        );
        $stmt->execute([
            ':jenis'  => $this->getJenis(),
            ':jumlah' => $this->jumlah,
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $pdo->lastInsertId();
    }

    protected function getJenis(): string
    {
        return 'tunai';
    }
}
