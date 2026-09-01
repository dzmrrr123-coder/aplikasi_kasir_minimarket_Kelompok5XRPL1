<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class Supplier implements DataReporter
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
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama supplier tidak boleh kosong.');
        }

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO supplier (nama, kontak, alamat, admin_id) VALUES (:nama, :kontak, :alamat, :admin_id)'
        );
        $stmt->execute([
            ':nama'     => $this->nama,
            ':kontak'   => $this->kontak,
            ':alamat'   => $this->alamat,
            ':admin_id' => currentAdminId(),
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
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, kontak, alamat FROM supplier WHERE admin_id = :admin_id ORDER BY nama'
        );
        $stmt->execute([':admin_id' => $adminId]);

        return array_map(static fn (array $row): self => new self($row), $stmt->fetchAll());
    }

    public static function cari(int $id): ?self
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, kontak, alamat FROM supplier WHERE id = :id AND admin_id = :admin_id LIMIT 1'
        );
        $stmt->execute([':id' => $id, ':admin_id' => $adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik supplier: total jumlah supplier (satu titik data).
     *
     * @param array<string, mixed> $params
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare('SELECT COUNT(*) FROM supplier WHERE admin_id = :admin_id');
        $stmt->execute([':admin_id' => $adminId]);
        $total = (int) $stmt->fetchColumn();

        return [
            'labels' => ['Supplier'],
            'series' => [
                'label' => 'Jumlah Supplier',
                'data'  => [$total],
            ],
        ];
    }

    /**
     * Data tabel supplier (nama, kontak, alamat) dengan pencarian
     * & pagination (DataTables server-side).
     *
     * @param array<string, mixed> $params search/start/length
     */
    public function getDataTabel(array $params = []): array
    {
        $cari = trim((string) ($params['search'] ?? ''));
        $start = max(0, (int) ($params['start'] ?? 0));
        $length = max(1, (int) ($params['length'] ?? 10));

        $adminId = currentAdminId();
        $where = 'WHERE admin_id = :admin_id';
        $bind = [':admin_id' => $adminId];

        if ($cari !== '') {
            $where .= ' AND (nama LIKE :cari_nama OR kontak LIKE :cari_kontak OR alamat LIKE :cari_alamat)';
            $bind[':cari_nama'] = '%' . $cari . '%';
            $bind[':cari_kontak'] = '%' . $cari . '%';
            $bind[':cari_alamat'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $totalStmt = $pdo->prepare('SELECT COUNT(*) FROM supplier WHERE admin_id = :admin_id');
        $totalStmt->execute([':admin_id' => $adminId]);
        $total = (int) $totalStmt->fetchColumn();

        $stmtFiltered = $pdo->prepare('SELECT COUNT(*) FROM supplier ' . $where);
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, nama, kontak, alamat FROM supplier ' . $where . '
             ORDER BY nama ASC
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
                'id'     => (int) $r['id'],
                'nama'   => $r['nama'],
                'kontak' => $r['kontak'],
                'alamat' => $r['alamat'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
