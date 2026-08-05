<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Produk implements DataReporter
{
    private string $id = '';
    private string $nama = '';
    private float $harga = 0.0;
    private int $stok = 0;
    private Kategori $kategori;

    private const AMBANG_STOK_MENIPIS = 10;

    public function __construct(array $data = [])
    {
        $this->kategori = new Kategori();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['harga'])) {
            $this->harga = (float) $data['harga'];
        }
        if (isset($data['stok'])) {
            $this->stok = (int) $data['stok'];
        }
        if (isset($data['kategori_id'])) {
            $kategori = Kategori::cari((int) $data['kategori_id']);
            $this->kategori = $kategori ?? new Kategori();
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

    public function getHarga(): float
    {
        return $this->harga;
    }

    public function getStok(): int
    {
        return $this->stok;
    }

    public function getKategori(): Kategori
    {
        return $this->kategori;
    }

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    public function setHarga(float $harga): void
    {
        $this->harga = $harga;
    }

    public function setKategori(Kategori $kategori): void
    {
        $this->kategori = $kategori;
    }

    public function setStok(int $stok): void
    {
        $this->stok = $stok;
    }

    public function updateStok(int $qty): void
    {
        $this->stok += $qty;
    }

    public function kurangiStok(int $qty): void
    {
        $this->stok -= $qty;
    }

    public function cekStokMenipis(): bool
    {
        return $this->stok <= self::AMBANG_STOK_MENIPIS;
    }

    public function simpan(): int
    {
        // Alur tambah produk: cek kategori valid dulu -> validasi data produk ->
        // kalau valid, simpan. Kalau kategori/data invalid, kembalikan pesan error.
        $this->validasi();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO produk (nama, harga, stok, kategori_id)
             VALUES (:nama, :harga, :stok, :kategori_id)'
        );
        $stmt->execute([
            ':nama'        => $this->nama,
            ':harga'       => $this->harga,
            ':stok'        => $this->stok,
            ':kategori_id' => $this->kategori->getId(),
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $this->validasi();

        $stmt = Database::connect()->prepare(
            'UPDATE produk
             SET nama = :nama, harga = :harga, stok = :stok, kategori_id = :kategori_id
             WHERE id = :id'
        );
        $stmt->execute([
            ':nama'        => $this->nama,
            ':harga'       => $this->harga,
            ':stok'        => $this->stok,
            ':kategori_id' => $this->kategori->getId(),
            ':id'          => $this->id,
        ]);
    }

    /**
     * Validasi data produk: kategori harus valid, nama tidak boleh kosong,
     * harga tidak boleh negatif, stok tidak boleh negatif.
     *
     * @throws \RuntimeException bila ada data yang tidak valid
     */
    private function validasi(): void
    {
        if ($this->kategori->getId() === '' || $this->kategori->getNama() === '') {
            throw new \RuntimeException('Kategori tidak valid.');
        }
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama produk tidak boleh kosong.');
        }
        if ($this->harga < 0) {
            throw new \RuntimeException('Harga produk tidak boleh negatif.');
        }
        if ($this->stok < 0) {
            throw new \RuntimeException('Stok produk tidak boleh negatif.');
        }
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM produk WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    /**
     * Mengambil semua produk beserta kategori-nya, diurutkan berdasarkan nama.
     *
     * @return Produk[]
     */
    public static function semua(): array
    {
        $rows = Database::connect()->query(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id
             FROM produk p
             ORDER BY p.nama'
        )->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, p.kategori_id
             FROM produk p
             WHERE p.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    public static function cariStokMenipis(): array
    {
        $rows = Database::connect()->query(
            'SELECT id, nama, harga, stok, kategori_id
             FROM produk
             WHERE stok <= ' . self::AMBANG_STOK_MENIPIS . '
             ORDER BY stok ASC'
        )->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik inventaris: total stok per kategori.
     *
     * @param array<string, mixed> $params (tidak wajib; limit kategori)
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $limit = max(1, (int) ($params['limit'] ?? 8));

        $stmt = Database::connect()->prepare(
            'SELECT k.nama AS kategori, COALESCE(SUM(p.stok), 0) AS stok
             FROM kategori k
             LEFT JOIN produk p ON p.kategori_id = k.id
             GROUP BY k.id, k.nama
             ORDER BY stok DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $labels = [];
        $data = [];

        foreach ($stmt->fetchAll() as $row) {
            $labels[] = $row['kategori'];
            $data[] = (int) $row['stok'];
        }

        return [
            'labels' => $labels,
            'series' => [
                'label' => 'Stok',
                'data'  => $data,
            ],
        ];
    }

    /**
     * Data tabel inventaris: daftar produk (nama, kategori, harga, stok)
     * dengan pencarian & pagination (DataTables server-side).
     *
     * @param array<string, mixed> $params search/start/length
     */
    public function getDataTabel(array $params = []): array
    {
        $cari = trim((string) ($params['search'] ?? ''));
        $start = max(0, (int) ($params['start'] ?? 0));
        $length = max(1, (int) ($params['length'] ?? 10));

        $where = '';
        $bind = [];

        if ($cari !== '') {
            $where = 'WHERE p.nama LIKE :cari';
            $bind[':cari'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM produk')->fetchColumn();

        $stmtFiltered = $pdo->prepare('SELECT COUNT(*) FROM produk p ' . $where);
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.nama, p.harga, p.stok, k.nama AS kategori
             FROM produk p
             JOIN kategori k ON k.id = p.kategori_id ' . $where . '
             ORDER BY p.nama ASC
             LIMIT :limit OFFSET :offset'
        );

        foreach ($bind as $kunci => $nilai) {
            $stmt->bindValue($kunci, $nilai);
        }
        $stmt->bindValue(':limit', $length, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $start, \PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $r): array {
            return [
                'id'       => (int) $r['id'],
                'nama'     => $r['nama'],
                'kategori' => $r['kategori'],
                'harga'    => (float) $r['harga'],
                'stok'     => (int) $r['stok'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
