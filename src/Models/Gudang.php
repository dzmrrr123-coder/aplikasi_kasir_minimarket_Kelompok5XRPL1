<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;

class Gudang
{
    private string $id = '';
    private string $nama = '';
    private string $alamat = '';
    private bool $isUtama = false;
    private bool $isAktif = true;

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['alamat'])) {
            $this->alamat = (string) $data['alamat'];
        }
        if (isset($data['is_utama'])) {
            $this->isUtama = (bool) $data['is_utama'];
        }
        if (isset($data['is_aktif'])) {
            $this->isAktif = (bool) $data['is_aktif'];
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

    public function getAlamat(): string
    {
        return $this->alamat;
    }

    public function isUtama(): bool
    {
        return $this->isUtama;
    }

    public function isAktif(): bool
    {
        return $this->isAktif;
    }

    public function setNama(string $nama): void
    {
        $this->nama = $nama;
    }

    public function setAlamat(string $alamat): void
    {
        $this->alamat = $alamat;
    }

    public function setIsUtama(bool $isUtama): void
    {
        $this->isUtama = $isUtama;
    }

    public function setIsAktif(bool $isAktif): void
    {
        $this->isAktif = $isAktif;
    }

    /**
     * Simpan gudang baru atau perbarui yang sudah ada.
     */
    public function simpan(): int
    {
        $this->validasi();

        $pdo = Database::connect();

        // Bila gudang baru dijadikan utama, batalkan status utama gudang lain.
        if ($this->isUtama && $this->id === '') {
            $pdo->exec('UPDATE gudang SET is_utama = 0 WHERE is_utama = 1');
        }

        $stmt = $pdo->prepare(
            'INSERT INTO gudang (nama, alamat, is_utama, is_aktif, admin_id)
             VALUES (:nama, :alamat, :is_utama, :is_aktif, :admin_id)'
        );
        $stmt->execute([
            ':nama'      => $this->nama,
            ':alamat'    => $this->alamat,
            ':is_utama'  => $this->isUtama ? 1 : 0,
            ':is_aktif'  => $this->isAktif ? 1 : 0,
            ':admin_id'  => currentAdminId(),
        ]);

        $this->id = (string) $pdo->lastInsertId();

        return (int) $this->id;
    }

    public function perbarui(): void
    {
        $this->validasi();

        $pdo = Database::connect();

        // Bila gudang ini dijadikan utama, batalkan status utama gudang lain.
        if ($this->isUtama) {
            $stmt = $pdo->prepare('UPDATE gudang SET is_utama = 0 WHERE id <> :id AND is_utama = 1');
            $stmt->execute([':id' => $this->id]);
        }

        $stmt = $pdo->prepare(
            'UPDATE gudang SET nama = :nama, alamat = :alamat, is_utama = :is_utama, is_aktif = :is_aktif
             WHERE id = :id'
        );
        $stmt->execute([
            ':nama'      => $this->nama,
            ':alamat'    => $this->alamat,
            ':is_utama'  => $this->isUtama ? 1 : 0,
            ':is_aktif'  => $this->isAktif ? 1 : 0,
            ':id'        => $this->id,
        ]);
    }

    public function hapus(): void
    {
        $stmt = Database::connect()->prepare('DELETE FROM gudang WHERE id = :id');
        $stmt->execute([':id' => $this->id]);
    }

    private function validasi(): void
    {
        if (trim($this->nama) === '') {
            throw new \RuntimeException('Nama gudang tidak boleh kosong.');
        }
    }

    // ----------------------------------------------------------------
    // Stok per gudang
    // ----------------------------------------------------------------

    /**
     * Dapatkan stok produk di gudang tertentu.
     */
    public static function stokProduk(int $gudangId, int $produkId): int
    {
        $stmt = Database::connect()->prepare(
            'SELECT COALESCE(stok, 0) FROM stok_gudang WHERE gudang_id = :g AND produk_id = :p LIMIT 1'
        );
        $stmt->execute([':g' => $gudangId, ':p' => $produkId]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Set stok produk di gudang (insert/update).
     */
    public static function setStokProduk(int $gudangId, int $produkId, int $stok): void
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO stok_gudang (gudang_id, produk_id, stok)
             VALUES (:g, :p, :s)
             ON DUPLICATE KEY UPDATE stok = :s2'
        );
        $stmt->execute([
            ':g'  => $gudangId,
            ':p'  => $produkId,
            ':s'  => $stok,
            ':s2' => $stok,
        ]);
    }

    /**
     * Tambah stok produk di gudang.
     */
    public static function tambahStok(int $gudangId, int $produkId, int $qty): void
    {
        $stokSaatIni = self::stokProduk($gudangId, $produkId);
        self::setStokProduk($gudangId, $produkId, $stokSaatIni + $qty);
    }

    /**
     * Kurangi stok produk di gudang. Throw bila stok kurang.
     *
     * @throws \RuntimeException
     */
    public static function kurangiStok(int $gudangId, int $produkId, int $qty): void
    {
        $stokSaatIni = self::stokProduk($gudangId, $produkId);

        if ($stokSaatIni < $qty) {
            throw new \RuntimeException(
                'Stok di gudang tidak cukup (tersedia: ' . $stokSaatIni . ', diminta: ' . $qty . ').'
            );
        }

        self::setStokProduk($gudangId, $produkId, $stokSaatIni - $qty);
    }

    /**
     * Daftar semua produk di gudang tertentu (dengan info nama produk).
     *
     * @return array<int, array{id:int, nama:string, satuan:string, stok_gudang:int, stok_total:int}>
     */
    public static function daftarProduk(int $gudangId, string $cari = ''): array
    {
        $pdo = Database::connect();
        $where = 'WHERE sg.gudang_id = :g AND p.admin_id = :admin_id';
        $bind = [':g' => $gudangId, ':admin_id' => currentAdminId()];

        if ($cari !== '') {
            $where .= ' AND (p.nama LIKE :cari OR p.barcode LIKE :cari2)';
            $bind[':cari'] = '%' . $cari . '%';
            $bind[':cari2'] = '%' . $cari . '%';
        }

        $stmt = $pdo->prepare(
            'SELECT p.id, p.nama, p.satuan, COALESCE(sg.stok, 0) AS stok_gudang, p.stok AS stok_total
             FROM produk p
             LEFT JOIN stok_gudang sg ON sg.produk_id = p.id AND sg.gudang_id = :g2
             ' . $where . ' AND p.is_active = 1
             ORDER BY p.nama'
        );

        // Bind gudang_id kedua (untuk JOIN)
        $stmt->bindValue(':g2', $gudangId, \PDO::PARAM_INT);
        foreach ($bind as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------------
    // Transfer stok antar gudang
    // ----------------------------------------------------------------

    /**
     * Proses transfer stok dari gudang asal ke gudang tujuan.
     *
     * @param array<int, array{produk_id:int, qty:int}> $items
     * @throws \RuntimeException
     */
    public static function transferStok(
        int $gudangAsalId,
        int $gudangTujuanId,
        int $userId,
        array $items,
        string $keterangan = ''
    ): int {
        if ($gudangAsalId === $gudangTujuanId) {
            throw new \RuntimeException('Gudang asal dan tujuan tidak boleh sama.');
        }

        if ($items === []) {
            throw new \RuntimeException('Tidak ada item untuk ditransfer.');
        }

        // Validasi semua item punya stok cukup di gudang asal
        foreach ($items as $item) {
            $stok = self::stokProduk($gudangAsalId, (int) $item['produk_id']);
            if ($stok < (int) $item['qty']) {
                $produk = Produk::cari((int) $item['produk_id']);
                throw new \RuntimeException(
                    'Stok "' . ($produk?->getNama() ?? '?') . '" di gudang asal tidak cukup'
                    . ' (tersedia: ' . $stok . ', diminta: ' . (int) $item['qty'] . ').'
                );
            }
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Simpan transfer_stok
            $stmt = $pdo->prepare(
                'INSERT INTO transfer_stok (gudang_asal_id, gudang_tujuan_id, user_id, keterangan)
                 VALUES (:asal, :tujuan, :user, :ket)'
            );
            $stmt->execute([
                ':asal'   => $gudangAsalId,
                ':tujuan' => $gudangTujuanId,
                ':user'   => $userId,
                ':ket'    => $keterangan,
            ]);
            $transferId = (int) $pdo->lastInsertId();

            // Proses setiap item
            $itemStmt = $pdo->prepare(
                'INSERT INTO item_transfer (transfer_id, produk_id, qty)
                 VALUES (:t, :p, :q)'
            );

            foreach ($items as $item) {
                $produkId = (int) $item['produk_id'];
                $qty = (int) $item['qty'];

                $itemStmt->execute([
                    ':t' => $transferId,
                    ':p' => $produkId,
                    ':q' => $qty,
                ]);

                // Kurangi stok gudang asal
                self::kurangiStok($gudangAsalId, $produkId, $qty);

                // Tambah stok gudang tujuan
                self::tambahStok($gudangTujuanId, $produkId, $qty);
            }

            $pdo->commit();

            return $transferId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Riwayat transfer stok.
     *
     * @return array<int, array>
     */
    public static function riwayatTransfer(int $limit = 50, int $offset = 0): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT t.id, t.tanggal, t.keterangan,
                    ga.nama AS gudang_asal, gt.nama AS gudang_tujuan,
                    u.nama AS user_nama,
                    (SELECT COUNT(*) FROM item_transfer it WHERE it.transfer_id = t.id) AS jumlah_item
             FROM transfer_stok t
             JOIN gudang ga ON ga.id = t.gudang_asal_id
             JOIN gudang gt ON gt.id = t.gudang_tujuan_id
             JOIN users u ON u.id = t.user_id
             ORDER BY t.tanggal DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Detail item dalam satu transfer.
     *
     * @return array<int, array{produk_nama:string, qty:float, satuan:string}>
     */
    public static function detailTransfer(int $transferId): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT p.nama AS produk_nama, it.qty, p.satuan
             FROM item_transfer it
             JOIN produk p ON p.id = it.produk_id
             WHERE it.transfer_id = :t'
        );
        $stmt->execute([':t' => $transferId]);

        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------------
    // Query helpers
    // ----------------------------------------------------------------

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, alamat, is_utama, is_aktif FROM gudang WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * @return self[]
     */
    public static function semua(bool $hanyaAktif = true): array
    {
        $adminId = currentAdminId();
        $where = $hanyaAktif ? 'WHERE is_aktif = 1 AND admin_id = :admin_id' : 'WHERE admin_id = :admin_id';

        $stmt = Database::connect()->prepare(
            'SELECT id, nama, alamat, is_utama, is_aktif FROM gudang ' . $where . ' ORDER BY is_utama DESC, nama ASC'
        );
        $stmt->execute([':admin_id' => $adminId]);

        return array_map(static fn (array $row): self => new self($row), $stmt->fetchAll());
    }

    /**
     * Gudang utama (default untuk transaksi).
     */
    public static function gudangUtama(): ?self
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, alamat, is_utama, is_aktif FROM gudang WHERE is_utama = 1 AND is_aktif = 1 AND admin_id = :admin_id LIMIT 1'
        );
        $stmt->execute([':admin_id' => $adminId]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * Total stok semua gudang untuk produk tertentu.
     */
    public static function totalStokSemuaGudang(int $produkId): int
    {
        $stmt = Database::connect()->prepare(
            'SELECT COALESCE(SUM(stok), 0) FROM stok_gudang WHERE produk_id = :p'
        );
        $stmt->execute([':p' => $produkId]);

        return (int) $stmt->fetchColumn();
    }
}
