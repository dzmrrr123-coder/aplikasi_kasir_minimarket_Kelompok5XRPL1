<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Diskon
{
    private string $id = '';
    private string $jenis = 'persen'; // persen | nominal
    private float $nilai = 0.0;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
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
        $stmt = $pdo->prepare('INSERT INTO diskon (jenis, nilai) VALUES (:jenis, :nilai)');
        $stmt->execute([':jenis' => $this->jenis, ':nilai' => $this->nilai]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $stmt = Database::connect()->prepare(
            'UPDATE diskon SET jenis = :jenis, nilai = :nilai WHERE id = :id'
        );
        $stmt->execute([':jenis' => $this->jenis, ':nilai' => $this->nilai, ':id' => $this->id]);
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
        $rows = Database::connect()->query('SELECT id, jenis, nilai FROM diskon ORDER BY id')->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare('SELECT id, jenis, nilai FROM diskon WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * Mencari diskon berdasarkan kode.
     * Karena kolom `id` bertipe integer, kode berupa string diuji
     * lewat perbandingan id; kalau tidak cocok, kembalikan null.
     */
    public static function cariBerdasarkanKode(string $kode): ?self
    {
        if ($kode === '' || !ctype_digit($kode)) {
            return null;
        }

        return self::cari((int) $kode);
    }
}
