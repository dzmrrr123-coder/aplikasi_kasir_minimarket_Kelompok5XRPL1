<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Basis abstrak semua metode pembayaran.
 *
 * Menyimpan data nominal secara ter-enkapsulasi (private + getter/setter)
 * dan menyediakan persistensi baris `pembayaran` di database. Subclass
 * mengimplementasikan kontrak PaymentMethod (Strategy) lewat polimorfisme.
 */
abstract class Pembayaran implements PaymentMethod
{
    private string $id = '';
    private float $jumlah = 0.0;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->setId((string) $data['id']);
        }
        if (isset($data['jumlah'])) {
            $this->setJumlah((float) $data['jumlah']);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getJumlah(): float
    {
        return $this->jumlah;
    }

    public function setJumlah(float $jumlah): void
    {
        $this->jumlah = max(0.0, $jumlah);
    }

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
            ':jumlah' => $this->getJumlah(),
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $pdo->lastInsertId();
    }

    /**
     * Jenis pembayaran untuk kolom `jenis` di tabel pembayaran
     * ('tunai' / 'non_tunai'). Di-override subclass.
     */
    protected function getJenis(): string
    {
        return 'tunai';
    }
}
