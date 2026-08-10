<?php
// src/Models/Diskon.php
// Class Diskon: potongan harga transaksi, jenis 'persen' atau 'nominal'.

class Diskon
{
    private string $id;
    private string $jenis; // 'persen' | 'nominal'
    private float $nilai;

    // Menerima array hasil query (['id' => ..., 'jenis' => ..., 'nilai' => ...])
    // atau parameter individual (id, jenis, nilai). id di-cast ke string.
    public function __construct(array|int|string $data = '', string $jenis = '', float $nilai = 0.0)
    {
        if (is_array($data)) {
            $this->id    = (string) ($data['id'] ?? '');
            $this->jenis = (string) ($data['jenis'] ?? '');
            $this->nilai = (float) ($data['nilai'] ?? 0);
        } else {
            $this->id    = (string) $data;
            $this->jenis = $jenis;
            $this->nilai = $nilai;
        }
    }

    // Menerapkan diskon ke total. Hasil tidak boleh negatif: jika potongan
    // lebih besar dari total, hasil di-clamp ke 0.
    public function terapkan(float $total): float
    {
        $hasil = $this->jenis === 'persen'
            ? $total - ($total * $this->nilai / 100)
            : $total - $this->nilai;

        return max(0, $hasil);
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

    // Ambil satu Diskon berdasarkan id; null jika tidak ditemukan.
    public static function findById(int|string $id): ?Diskon
    {
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id, jenis, nilai FROM diskon WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int) $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new Diskon($row);
    }
}
