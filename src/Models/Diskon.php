<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Diskon
{
    private string $id = '';
    private string $kode = '';
    private string $jenis = 'persen'; // persen | nominal
    private float $nilai = 0.0;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['kode'])) {
            $this->kode = (string) $data['kode'];
        }
        if (isset($data['jenis'])) {
            $this->jenis = (string) $data['jenis'];
        }
        if (isset($data['nilai'])) {
            $this->nilai = (float) $data['nilai'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getKode(): string
    {
        return $this->kode;
    }

    public function setKode(string $kode): void
    {
        $this->kode = strtoupper(trim($kode));
    }

    public function getJenis(): string
    {
        return $this->jenis;
    }

    public function getNilai(): float
    {
        return $this->nilai;
    }

    public function setJenis(string $jenis): void
    {
        $this->jenis = $jenis;
    }

    public function setNilai(float $nilai): void
    {
        $this->nilai = $nilai;
    }

    /**
     * Menghitung total setelah diskon.
     * Jenis 'persen' memotong persentase dari total, jenis 'nominal'
     * memotong nilai tetap. Hasil tidak pernah negatif.
     */
    public function terapkan(float $total): float
    {
        $potongan = $this->jenis === 'persen'
            ? $total * ($this->nilai / 100)
            : $this->nilai;

        return max(0.0, $total - $potongan);
    }

    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare('INSERT INTO diskon (kode, jenis, nilai) VALUES (:kode, :jenis, :nilai)');
        $stmt->execute([
            ':kode'  => $this->kode !== '' ? $this->kode : null,
            ':jenis' => $this->jenis,
            ':nilai' => $this->nilai,
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $stmt = Database::connect()->prepare(
            'UPDATE diskon SET kode = :kode, jenis = :jenis, nilai = :nilai WHERE id = :id'
        );
        $stmt->execute([
            ':kode'  => $this->kode !== '' ? $this->kode : null,
            ':jenis' => $this->jenis,
            ':nilai' => $this->nilai,
            ':id'    => $this->id,
        ]);
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM diskon WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    /**
     * Mengambil semua diskon.
     *
     * @return Diskon[]
     */
    public static function semua(): array
    {
        $rows = Database::connect()->query('SELECT id, kode, jenis, nilai FROM diskon ORDER BY id')->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare('SELECT id, kode, jenis, nilai FROM diskon WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * Mencari diskon berdasarkan kode (case-insensitive).
     * Bila kode berupa angka, fallback ke pencarian by id (kompatibilitas).
     */
    public static function cariBerdasarkanKode(string $kode): ?self
    {
        $kode = strtoupper(trim($kode));

        if ($kode === '') {
            return null;
        }

        $stmt = Database::connect()->prepare('SELECT id, kode, jenis, nilai FROM diskon WHERE UPPER(kode) = :kode LIMIT 1');
        $stmt->execute([':kode' => $kode]);
        $row = $stmt->fetch();

        if ($row !== false) {
            return new self($row);
        }

        // Fallback: kode berupa angka -> cari by id (perilaku lama).
        return ctype_digit($kode) ? self::cari((int) $kode) : null;
    }
}
