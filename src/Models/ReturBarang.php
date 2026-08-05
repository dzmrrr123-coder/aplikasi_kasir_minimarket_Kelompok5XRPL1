<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use RuntimeException;
use App\Database\Database;

class ReturBarang implements DataReporter
{
    private string $id = '';
    private DateTimeImmutable $tanggal;
    private Produk $produk;
    private Supplier $supplier;
    private int $qty = 0;
    private string $alasan = '';

    public function __construct(array $data = [])
    {
        $this->tanggal = new DateTimeImmutable();
        $this->produk = new Produk();
        $this->supplier = new Supplier();

        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['tanggal'])) {
            $this->tanggal = new DateTimeImmutable($data['tanggal']);
        }
        if (isset($data['produk_id'])) {
            $produk = Produk::cari((int) $data['produk_id']);
            $this->produk = $produk ?? new Produk();
        }
        if (isset($data['supplier_id'])) {
            $supplier = Supplier::cari((int) $data['supplier_id']);
            $this->supplier = $supplier ?? new Supplier();
        }
        if (isset($data['qty'])) {
            $this->qty = (int) $data['qty'];
        }
        if (isset($data['alasan'])) {
            $this->alasan = (string) $data['alasan'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTanggal(): DateTimeImmutable
    {
        return $this->tanggal;
    }

    public function getProduk(): Produk
    {
        return $this->produk;
    }

    public function getSupplier(): Supplier
    {
        return $this->supplier;
    }

    public function getQty(): int
    {
        return $this->qty;
    }

    public function getAlasan(): string
    {
        return $this->alasan;
    }

    public function setProduk(Produk $produk): void
    {
        $this->produk = $produk;
    }

    public function setSupplier(Supplier $supplier): void
    {
        $this->supplier = $supplier;
    }

    public function setQty(int $qty): void
    {
        $this->qty = $qty;
    }

    public function setAlasan(string $alasan): void
    {
        $this->alasan = $alasan;
    }

    /**
     * Proses retur sesuai alur pada spesifikasi:
     * 1. Cek stok produk cukup untuk diretur -> kalau kurang, batalkan dengan pesan spesifik.
     * 2. Cek data supplier valid -> kalau tidak valid, batalkan.
     * 3. Kalau keduanya valid: kurangi stok produk & catat retur dalam satu transaksi DB,
     *    jadi kalau pencatatan gagal, stok ikut di-rollback (tidak terlanjur berkurang).
     *
     * @throws RuntimeException bila stok atau supplier tidak valid
     */
    public function prosesRetur(): bool
    {
        if ($this->qty <= 0) {
            throw new RuntimeException('Jumlah retur harus lebih dari 0.');
        }

        if ($this->produk->getId() === '') {
            throw new RuntimeException('Produk tidak valid.');
        }

        if ($this->produk->getStok() < $this->qty) {
            throw new RuntimeException(
                sprintf('Stok "%s" tidak cukup untuk diretur (tersedia: %d).', $this->produk->getNama(), $this->produk->getStok())
            );
        }

        if ($this->supplier->getId() === '' || $this->supplier->getNama() === '') {
            throw new RuntimeException('Data supplier tidak valid.');
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO retur_barang (tanggal, produk_id, supplier_id, qty, alasan)
                 VALUES (:tanggal, :produk_id, :supplier_id, :qty, :alasan)'
            );
            $stmt->execute([
                ':tanggal'     => $this->tanggal->format('Y-m-d H:i:s'),
                ':produk_id'   => $this->produk->getId(),
                ':supplier_id' => $this->supplier->getId(),
                ':qty'         => $this->qty,
                ':alasan'      => $this->alasan,
            ]);

            $this->id = (string) $pdo->lastInsertId();

            // Retur barang ke supplier = barang keluar dari stok toko.
            $this->produk->kurangiStok($this->qty);
            $this->produk->perbarui();

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw new RuntimeException(
                'Gagal memproses retur: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return true;
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, tanggal, produk_id, supplier_id, qty, alasan FROM retur_barang WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    /**
     * Riwayat retur terbaru (default 100), lengkap dengan nama produk
     * dan nama supplier untuk keperluan tampilan.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function semua(int $batas = 100): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT r.id, r.tanggal, r.qty, r.alasan,
                    p.nama AS produk_nama,
                    s.nama AS supplier_nama
             FROM retur_barang r
             JOIN produk p ON p.id = r.produk_id
             JOIN supplier s ON s.id = r.supplier_id
             ORDER BY r.tanggal DESC
             LIMIT :batas'
        );
        $stmt->bindValue(':batas', $batas, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik retur: jumlah unit diretur per bulan (6 bulan terakhir).
     *
     * @param array<string, mixed> $params (opsional; jumlah bulan)
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $bulan = max(1, (int) ($params['bulan'] ?? 6));
        $labels = [];
        $data = [];

        for ($i = $bulan - 1; $i >= 0; $i--) {
            $awal = (new DateTimeImmutable())->modify("-{$i} months")->format('Y-m-01 00:00:00');
            $akhir = (new DateTimeImmutable())->modify("-{$i} months")->format('Y-m-t 23:59:59');

            $stmt = Database::connect()->prepare(
                'SELECT COALESCE(SUM(qty), 0) AS qty
                 FROM retur_barang
                 WHERE tanggal >= :mulai AND tanggal <= :akhir'
            );
            $stmt->execute([':mulai' => $awal, ':akhir' => $akhir]);
            $qty = (int) ($stmt->fetch()['qty'] ?? 0);

            $labels[] = (new DateTimeImmutable($awal))->format('M Y');
            $data[] = $qty;
        }

        return [
            'labels' => $labels,
            'series' => [
                'label' => 'Retur',
                'data'  => $data,
            ],
        ];
    }

    /**
     * Data tabel riwayat retur (tanggal, produk, supplier, qty, alasan)
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
            $where = 'WHERE p.nama LIKE :cari OR s.nama LIKE :cari OR r.alasan LIKE :cari';
            $bind[':cari'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM retur_barang')->fetchColumn();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM retur_barang r
             JOIN produk p ON p.id = r.produk_id
             JOIN supplier s ON s.id = r.supplier_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT r.id, r.tanggal, r.qty, r.alasan,
                    p.nama AS produk_nama,
                    s.nama AS supplier_nama
             FROM retur_barang r
             JOIN produk p ON p.id = r.produk_id
             JOIN supplier s ON s.id = r.supplier_id ' . $where . '
             ORDER BY r.tanggal DESC, r.id DESC
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
                'tanggal'      => (new DateTimeImmutable($r['tanggal']))->format('d-m-Y H:i'),
                'produk_nama'  => $r['produk_nama'],
                'supplier_nama' => $r['supplier_nama'],
                'qty'          => (int) $r['qty'],
                'alasan'       => $r['alasan'],
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
