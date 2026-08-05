<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use App\Database\Database;

/**
 * Agregasi data untuk dashboard admin.
 * Semua method memakai query agregat PDO (read-only), tidak mengubah data.
 */
class Dashboard
{
    /**
     * Ringkasan penjualan hari ini: total, jumlah transaksi,
     * rata-rata per transaksi, item terjual, dan total retur.
     *
     * @return array<string, int|float>
     */
    public static function ringkasanHariIni(): array
    {
        $pdo = Database::connect();
        $hariIni = (new DateTimeImmutable())->format('Y-m-d');

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) AS jumlah,
                    COALESCE(SUM(t.total), 0) AS total
             FROM transaksi t
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir'
        );
        $stmt->execute([
            ':mulai' => $hariIni . ' 00:00:00',
            ':akhir' => $hariIni . ' 23:59:59',
        ]);
        $row = $stmt->fetch();

        $jumlah = (int) ($row['jumlah'] ?? 0);
        $total = (float) ($row['total'] ?? 0);

        $stmtItem = $pdo->prepare(
            'SELECT COALESCE(SUM(it.qty), 0) AS item
             FROM item_transaksi it
             JOIN transaksi t ON t.id = it.transaksi_id
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir'
        );
        $stmtItem->execute([
            ':mulai' => $hariIni . ' 00:00:00',
            ':akhir' => $hariIni . ' 23:59:59',
        ]);
        $item = (int) ($stmtItem->fetch()['item'] ?? 0);

        $stmtRetur = $pdo->prepare(
            'SELECT COALESCE(SUM(r.qty), 0) AS qty
             FROM retur_barang r
             WHERE r.tanggal >= :mulai AND r.tanggal < :akhir'
        );
        $stmtRetur->execute([
            ':mulai' => $hariIni . ' 00:00:00',
            ':akhir' => $hariIni . ' 23:59:59',
        ]);
        $retur = (int) ($stmtRetur->fetch()['qty'] ?? 0);

        return [
            'jumlah'      => $jumlah,
            'total'       => $total,
            'rata_rata'   => $jumlah > 0 ? $total / $jumlah : 0.0,
            'item'        => $item,
            'retur'       => $retur,
        ];
    }

    /**
     * Penjualan 7 hari terakhir (termasuk hari ini), per hari.
     *
     * @return array<int, array{tanggal:string, total:float, jumlah:int}>
     */
    public static function penjualan7Hari(): array
    {
        $pdo = Database::connect();
        $hasil = [];

        for ($i = 6; $i >= 0; $i--) {
            $hari = (new DateTimeImmutable())->modify("-{$i} days")->format('Y-m-d');

            $stmt = $pdo->prepare(
                'SELECT COUNT(*) AS jumlah, COALESCE(SUM(total), 0) AS total
                 FROM transaksi
                 WHERE tanggal >= :mulai AND tanggal < :akhir'
            );
            $stmt->execute([
                ':mulai' => $hari . ' 00:00:00',
                ':akhir' => $hari . ' 23:59:59',
            ]);
            $row = $stmt->fetch();

            $hasil[] = [
                'tanggal' => $hari,
                'total'   => (float) ($row['total'] ?? 0),
                'jumlah'  => (int) ($row['jumlah'] ?? 0),
            ];
        }

        return $hasil;
    }

    /**
     * Produk terlaris berdasarkan qty terjual (semua periode, bisa dibatasi).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function produkTerlaris(int $limit = 5): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT p.id, p.nama, p.harga,
                    SUM(it.qty) AS qty,
                    SUM(it.subtotal) AS total
             FROM item_transaksi it
             JOIN produk p ON p.id = it.produk_id
             GROUP BY p.id, p.nama, p.harga
             ORDER BY qty DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Transaksi terbaru lengkap dengan nama kasir.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function transaksiTerbaru(int $limit = 5): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT t.id, t.tanggal, t.total, u.nama AS kasir_nama
             FROM transaksi t
             JOIN users u ON u.id = t.kasir_id
             ORDER BY t.tanggal DESC, t.id DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Breakdown metode pembayaran hari ini (tunai vs non-tunai).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function metodePembayaran(): array
    {
        $pdo = Database::connect();
        $hariIni = (new DateTimeImmutable())->format('Y-m-d');

        $stmt = $pdo->prepare(
            'SELECT p.jenis, COUNT(*) AS jumlah, COALESCE(SUM(p.jumlah), 0) AS total
             FROM transaksi t
             JOIN pembayaran p ON p.id = t.pembayaran_id
             WHERE t.tanggal >= :mulai AND t.tanggal < :akhir
             GROUP BY p.jenis'
        );
        $stmt->execute([
            ':mulai' => $hariIni . ' 00:00:00',
            ':akhir' => $hariIni . ' 23:59:59',
        ]);

        return $stmt->fetchAll();
    }
}
