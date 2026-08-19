<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

class Member implements DataReporter
{
    private string $id = '';
    private string $nomorMember = '';
    private string $nama = '';
    private string $telepon = '';
    private string $password = '';
    private int $poin = 0;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nomor_member'])) {
            $this->nomorMember = (string) $data['nomor_member'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['telepon'])) {
            $this->telepon = (string) $data['telepon'];
        }
        if (isset($data['password'])) {
            $this->password = (string) $data['password'];
        }
        if (isset($data['poin'])) {
            $this->poin = (int) $data['poin'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    /** Nomor member unik (format MEM-XXXXXX), dibuat otomatis saat simpan. */
    public function getNomorMember(): string
    {
        return $this->nomorMember;
    }

    public function getNama(): string
    {
        return $this->nama;
    }

    public function getTelepon(): string
    {
        return $this->telepon;
    }

    public function getPoin(): int
    {
        return $this->poin;
    }

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    public function setTelepon(string $telepon): void
    {
        $this->telepon = $telepon;
    }

    public function setPoin(int $poin): void
    {
        $this->poin = $poin;
    }

    /**
     * Tambah/kurangi poin member. Nilai negatif diperbolehkan (mis. saat
     * transaksi dibatalkan). Penjagaan anti-negatif ada di database level
     * via constraint CHECK (poin >= 0) — aplikasi tidak memotong di sini
     * supaya pengembalian poin saat batalkan() selalu akurat.
     */
    public function tambahPoin(int $poin): void
    {
        $this->poin = $this->poin + $poin;
    }

    public function simpan(): int
    {
        $this->validasi();

        $nomor = $this->nomorMember !== '' ? $this->nomorMember : self::buatNomorMember();

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO member (nomor_member, nama, telepon, password, poin)
             VALUES (:nomor_member, :nama, :telepon, :password, :poin)'
        );
        $stmt->execute([
            ':nomor_member' => $nomor,
            ':nama'         => $this->nama,
            ':telepon'      => $this->telepon !== '' ? $this->telepon : null,
            ':password'     => $this->password !== '' ? password_hash($this->password, PASSWORD_DEFAULT) : null,
            ':poin'         => $this->poin,
        ]);

        $this->id = (string) $pdo->lastInsertId();
        $this->nomorMember = $nomor;

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $this->validasi();

        $stmt = Database::connect()->prepare(
            'UPDATE member SET nama = :nama, telepon = :telepon, poin = :poin WHERE id = :id'
        );
        $stmt->execute([
            ':nama'    => $this->nama,
            ':telepon' => $this->telepon !== '' ? $this->telepon : null,
            ':poin'    => $this->poin,
            ':id'      => $this->id,
        ]);
    }

    /**
     * Ganti password member (dipakai admin saat reset, atau member sendiri).
     *
     * @throws \RuntimeException bila password < 6 karakter
     */
    public function setPassword(string $password): void
    {
        if (strlen($password) < 6) {
            throw new \RuntimeException('Password minimal 6 karakter.');
        }

        $stmt = Database::connect()->prepare(
            'UPDATE member SET password = :password WHERE id = :id'
        );
        $stmt->execute([
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id'       => $this->id,
        ]);
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM member WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    /**
     * Validasi data member: nama tidak boleh kosong,
     * telepon tidak boleh duplikat.
     *
     * @throws \RuntimeException bila ada data yang tidak valid
     */
    private function validasi(): void
    {
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama member tidak boleh kosong.');
        }
        if ($this->telepon !== '' && $this->teleponTerpakai()) {
            throw new \RuntimeException('Nomor telepon sudah dipakai member lain.');
        }
    }

    /** Cek apakah nomor telepon sudah dipakai member lain. */
    private function teleponTerpakai(): bool
    {
        if ($this->id === '') {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM member WHERE telepon = :telepon'
            );
            $stmt->execute([':telepon' => $this->telepon]);
        } else {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM member WHERE telepon = :telepon AND id <> :id'
            );
            $stmt->execute([':telepon' => $this->telepon, ':id' => $this->id]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Mengambil semua member, diurutkan berdasarkan nama.
     *
     * @return Member[]
     */
    public static function semua(): array
    {
        $rows = Database::connect()->query(
            'SELECT id, nomor_member, nama, telepon, poin FROM member ORDER BY nama'
        )->fetchAll();

        return array_map(static fn (array $row): self => new self($row), $rows);
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, nomor_member, nama, telepon, password, poin FROM member WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /** Cari member berdasarkan nomor telepon (dipakai di layar POS). */
    public static function cariBerdasarkanTelepon(string $telepon): ?self
    {
        if (trim($telepon) === '') {
            return null;
        }

        $stmt = Database::connect()->prepare(
            'SELECT id, nomor_member, nama, telepon, password, poin FROM member WHERE telepon = :telepon LIMIT 1'
        );
        $stmt->execute([':telepon' => trim($telepon)]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /** Cari member berdasarkan nomor member (format MEM-XXXXXX). */
    public static function cariBerdasarkanNomor(string $nomor): ?self
    {
        $nomor = strtoupper(trim($nomor));

        if ($nomor === '') {
            return null;
        }

        $stmt = Database::connect()->prepare(
            'SELECT id, nomor_member, nama, telepon, password, poin FROM member WHERE nomor_member = :nomor LIMIT 1'
        );
        $stmt->execute([':nomor' => $nomor]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * Login member (nomor member, telepon, atau nama + password).
     * Mengembalikan objek Member bila kredensial valid, null bila tidak.
     */
    public static function login(string $identitas, string $password): ?self
    {
        $identitas = trim($identitas);

        if ($identitas === '') {
            return null;
        }

        $stmt = Database::connect()->prepare(
            'SELECT id, nomor_member, nama, telepon, password, poin
             FROM member
             WHERE nomor_member = :nomor OR telepon = :telepon OR nama = :nama
             LIMIT 1'
        );
        $stmt->execute([
            ':nomor'   => strtoupper($identitas),
            ':telepon' => $identitas,
            ':nama'    => $identitas,
        ]);
        $row = $stmt->fetch();

        if ($row === false || $row['password'] === null || !password_verify($password, $row['password'])) {
            return null;
        }

        return new self($row);
    }

    /**
     * Generate nomor member unik format MEM-XXXXXX (angka urut 6 digit).
     * Dijamin unik lewat loop + cek UNIQUE constraint.
     */
    public static function buatNomorMember(): string
    {
        $pdo = Database::connect();

        for ($percobaan = 0; $percobaan < 50; $percobaan++) {
            $nomor = 'MEM-' . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare('SELECT COUNT(*) FROM member WHERE nomor_member = :nomor');
            $stmt->execute([':nomor' => $nomor]);

            if ((int) $stmt->fetchColumn() === 0) {
                return $nomor;
            }
        }

        throw new \RuntimeException('Gagal membuat nomor member unik.');
    }

    /**
     * Tukar poin member dengan hadiah dari katalog.
     *
     * @throws \RuntimeException bila hadiah tidak ditemukan / poin tidak cukup
     */
    public static function tukarPoin(int $memberId, int $hadiahId): void
    {
        $member = self::cari($memberId);

        if ($member === null) {
            throw new \RuntimeException('Member tidak ditemukan.');
        }

        $stmt = Database::connect()->prepare(
            'SELECT id, nama, poin FROM katalog_penukaran WHERE id = :id AND aktif = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $hadiahId]);
        $hadiah = $stmt->fetch();

        if ($hadiah === false) {
            throw new \RuntimeException('Hadiah tidak ditemukan atau tidak aktif.');
        }

        $biaya = (int) $hadiah['poin'];

        if ($member->getPoin() < $biaya) {
            throw new \RuntimeException(
                sprintf('Poin tidak cukup: butuh %d, tersedia %d.', $biaya, $member->getPoin())
            );
        }

        $member->tambahPoin(-$biaya);
        $member->perbarui();
    }

    /**
     * Tambah poin ke member tertentu di database.
     *
     * @throws \RuntimeException bila member tidak ditemukan
     */
    public static function tambahPoinId(int $id, int $poin): void
    {
        $member = self::cari($id);

        if ($member === null) {
            throw new \RuntimeException('Member tidak ditemukan.');
        }

        $member->tambahPoin($poin);
        $member->perbarui();
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik member: total jumlah member (satu titik data).
     *
     * @param array<string, mixed> $params
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $total = (int) Database::connect()->query('SELECT COUNT(*) FROM member')->fetchColumn();

        return [
            'labels' => ['Member'],
            'series' => [
                'label' => 'Jumlah Member',
                'data'  => [$total],
            ],
        ];
    }

    /**
     * Data tabel member (nama, telepon, poin) dengan pencarian
     * & pagination (DataTables server-side).
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
            $where = 'WHERE nama LIKE :cari_nama OR telepon LIKE :cari_telepon OR nomor_member LIKE :cari_nomor';
            $bind[':cari_nama'] = '%' . $cari . '%';
            $bind[':cari_telepon'] = '%' . $cari . '%';
            $bind[':cari_nomor'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM member')->fetchColumn();

        $stmtFiltered = $pdo->prepare('SELECT COUNT(*) FROM member ' . $where);
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT id, nomor_member, nama, telepon, poin FROM member ' . $where . '
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
                'id'           => (int) $r['id'],
                'nomor_member' => $r['nomor_member'],
                'nama'         => $r['nama'],
                'telepon'      => $r['telepon'],
                'poin'         => (int) $r['poin'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }

    // ------------------------------------------------------------
    // Katalog penukaran poin (badge/hadiah member)
    // ------------------------------------------------------------

    /**
     * Daftar hadiah yang bisa ditukar poin (hanya yang aktif).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function katalogHadiah(): array
    {
        return Database::connect()->query(
            'SELECT id, nama, poin, deskripsi FROM katalog_penukaran WHERE aktif = 1 ORDER BY poin ASC'
        )->fetchAll();
    }
}
