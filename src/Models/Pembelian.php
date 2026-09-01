<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Pembelian / stok masuk dari supplier.
 *
 * Menyimpan pembelian + item-nya dalam SATU transaksi database, lalu
 * memperbarui stok dan harga beli produk — kalau satu langkah gagal,
 * semuanya di-rollback (tidak ada stok terlanjur bertambah).
 */
class Pembelian implements DataReporter
{
    private string $id = '';
    private string $tanggal = '';
    private int $supplierId = 0;
    private string $supplierNama = '';
    private float $total = 0.0;
    private string $keterangan = '';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['tanggal'])) {
            $this->tanggal = (string) $data['tanggal'];
        }
        if (isset($data['supplier_id'])) {
            $this->supplierId = (int) $data['supplier_id'];
        }
        if (isset($data['supplier_nama'])) {
            $this->supplierNama = (string) $data['supplier_nama'];
        }
        if (isset($data['total'])) {
            $this->total = (float) $data['total'];
        }
        if (isset($data['keterangan'])) {
            $this->keterangan = (string) $data['keterangan'];
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTanggal(): string
    {
        return $this->tanggal;
    }

    public function getSupplierId(): int
    {
        return $this->supplierId;
    }

    public function getSupplierNama(): string
    {
        if ($this->supplierNama !== '' || $this->supplierId <= 0) {
            return $this->supplierNama;
        }

        $supplier = Supplier::cari($this->supplierId);

        $this->supplierNama = $supplier?->getNama() ?? '';

        return $this->supplierNama;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getKeterangan(): string
    {
        return $this->keterangan;
    }

    /**
     * Simpan pembelian beserta item-nya, lalu update stok & harga beli produk.
     *
     * @param array<int, array{produk_id: int, qty: float, harga_beli: float}> $items
     *        daftar item: ['produk_id' => int, 'qty' => float, 'harga_beli' => float]
     *
     * @return int id pembelian baru
     *
     * @throws \RuntimeException bila validasi gagal
     */
    public function simpan(array $items = []): int
    {
        $this->validasi($items);

        // Merge item dengan produk yang sama: jumlah qty, harga beli terakhir
        // dipakai. Mencegah stok hilang & harga beli saling menimpa.
        $gabung = [];

        foreach ($items as $item) {
            $produkId = (int) $item['produk_id'];
            $qty = (float) $item['qty'];
            $hargaBeli = (float) $item['harga_beli'];

            if (isset($gabung[$produkId])) {
                $gabung[$produkId]['qty'] += $qty;
                $gabung[$produkId]['harga_beli'] = $hargaBeli;
            } else {
                $gabung[$produkId] = [
                    'produk_id'  => $produkId,
                    'qty'        => $qty,
                    'harga_beli' => $hargaBeli,
                ];
            }
        }

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $total = 0.0;
            $itemRows = [];

            foreach ($gabung as $row) {
                $produk = Produk::cari($row['produk_id']);

                if ($produk === null) {
                    throw new \RuntimeException('Produk tidak ditemukan.');
                }

                $qty = $row['qty'];
                $hargaBeli = $row['harga_beli'];
                $subtotal = round($hargaBeli * $qty, 2);
                $total = round($total + $subtotal, 2);

                $itemRows[] = [
                    'produk'      => $produk,
                    'qty'         => $qty,
                    'harga_beli'  => $hargaBeli,
                    'subtotal'    => $subtotal,
                ];
            }

            $stmt = $pdo->prepare(
                'INSERT INTO pembelian (tanggal, supplier_id, total, keterangan)
                 VALUES (:tanggal, :supplier_id, :total, :keterangan)'
            );
            $stmt->execute([
                ':tanggal'     => date('Y-m-d H:i:s'),
                ':supplier_id' => $this->supplierId > 0 ? $this->supplierId : null,
                ':total'       => $total,
                ':keterangan'  => $this->keterangan !== '' ? $this->keterangan : null,
            ]);

            $pembelianId = (int) $pdo->lastInsertId();
            $this->id = (string) $pembelianId;
            $this->total = $total;

            $stmtItem = $pdo->prepare(
                'INSERT INTO item_pembelian (pembelian_id, produk_id, qty, harga_beli_satuan, subtotal)
                 VALUES (:pembelian_id, :produk_id, :qty, :harga_beli, :subtotal)'
            );

            $stmtUpdateStok = $pdo->prepare(
                'UPDATE produk SET stok = stok + :qty, harga_beli = :harga_beli WHERE id = :id'
            );

            foreach ($itemRows as $row) {
                $stmtItem->execute([
                    ':pembelian_id' => $pembelianId,
                    ':produk_id'    => $row['produk']->getId(),
                    ':qty'          => $row['qty'],
                    ':harga_beli'   => $row['harga_beli'],
                    ':subtotal'     => $row['subtotal'],
                ]);

                // Stok kolom integer, qty boleh pecahan (produk gram).
                // Bulatkan ke satuan terkecil & minimum 1 supaya mutasi stok
                // konsisten, tidak bergantung pembulatan implisit MySQL.
                $qtyStok = max(1, (int) round($row['qty']));

                // Update stok & harga beli produk secara atomik.
                $stmtUpdateStok->execute([
                    ':qty'        => $qtyStok,
                    ':harga_beli' => $row['harga_beli'],
                    ':id'         => (int) $row['produk']->getId(),
                ]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $this->id = '';

            throw new \RuntimeException(
                'Gagal menyimpan pembelian: ' . $e->getMessage(),
                0,
                $e
            );
        }

        return $pembelianId;
    }

    /**
     * Validasi pembelian: supplier valid, minimal satu item,
     * tiap item qty > 0 dan harga beli >= 0.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @throws \RuntimeException bila ada data yang tidak valid
     */
    private function validasi(array $items): void
    {
        if ($this->supplierId > 0 && Supplier::cari($this->supplierId) === null) {
            throw new \RuntimeException('Supplier tidak valid.');
        }
        if (count($items) === 0) {
            throw new \RuntimeException('Minimal satu produk untuk stok masuk.');
        }

        foreach ($items as $item) {
            if ((int) ($item['produk_id'] ?? 0) <= 0) {
                throw new \RuntimeException('Produk tidak valid.');
            }
            if ((float) ($item['qty'] ?? 0) <= 0) {
                throw new \RuntimeException('Jumlah stok masuk harus lebih dari 0.');
            }
            if ((float) ($item['harga_beli'] ?? 0) < 0) {
                throw new \RuntimeException('Harga beli tidak boleh negatif.');
            }
        }
    }

    public static function cari(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.tanggal, p.supplier_id, s.nama AS supplier_nama, p.total, p.keterangan
             FROM pembelian p
             LEFT JOIN supplier s ON s.id = p.supplier_id
             WHERE p.id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new self($row);
    }

    // ------------------------------------------------------------
    // DataReporter (Polimorfisme) — untuk Chart.js & DataTables
    // ------------------------------------------------------------

    /**
     * Data grafik pembelian: total nilai pembelian per bulan (6 bulan terakhir).
     *
     * @param array<string, mixed> $params
     */
    public function getAgregasiGrafik(array $params = []): array
    {
        $rows = Database::connect()->query(
            'SELECT DATE_FORMAT(tanggal, "%Y-%m") AS bulan, SUM(total) AS total
             FROM pembelian
             WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
             GROUP BY bulan
             ORDER BY bulan'
        )->fetchAll();

        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            $labels[] = $row['bulan'];
            $data[] = (float) $row['total'];
        }

        return [
            'labels' => $labels,
            'series' => [
                'label' => 'Pembelian',
                'data'  => $data,
            ],
        ];
    }

    /**
     * Data tabel riwayat pembelian (tanggal, supplier, total, keterangan)
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
            $where = 'WHERE s.nama LIKE :cari_nama OR p.keterangan LIKE :cari_keterangan';
            $bind[':cari_nama'] = '%' . $cari . '%';
            $bind[':cari_keterangan'] = '%' . $cari . '%';
        }

        $pdo = Database::connect();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM pembelian')->fetchColumn();

        $stmtFiltered = $pdo->prepare(
            'SELECT COUNT(*) FROM pembelian p LEFT JOIN supplier s ON s.id = p.supplier_id ' . $where
        );
        $stmtFiltered->execute($bind);
        $filtered = (int) $stmtFiltered->fetchColumn();

        $stmt = $pdo->prepare(
            'SELECT p.id, p.tanggal, p.supplier_id, s.nama AS supplier_nama, p.total, p.keterangan
             FROM pembelian p
             LEFT JOIN supplier s ON s.id = p.supplier_id ' . $where . '
             ORDER BY p.tanggal DESC
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
                'id'             => (int) $r['id'],
                'tanggal'        => $r['tanggal'],
                'supplier_id'    => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : null,
                'supplier_nama'  => $r['supplier_nama'] ?? '',
                'total'          => (float) $r['total'],
                'keterangan'     => $r['keterangan'] ?? '',
            ];
        }, $stmt->fetchAll());

        return [
            'total'    => $total,
            'filtered' => $filtered,
            'rows'     => $rows,
        ];
    }
}
