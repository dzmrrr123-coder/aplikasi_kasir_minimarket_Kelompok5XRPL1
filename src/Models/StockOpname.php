<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;
use Exception;

class StockOpname
{
    /**
     * Buat draft opname baru untuk suatu gudang.
     */
    public static function buatDraft(int $gudangId, int $userId, string $keterangan = ''): int
    {
        $pdo = Database::connect();
        $pdo->beginTransaction();
        try {
            // Cek apakah ada draft yang belum selesai untuk gudang ini
            $stmt = $pdo->prepare("SELECT id FROM stock_opname WHERE gudang_id = ? AND status = 'draft'");
            $stmt->execute([$gudangId]);
            if ($stmt->fetch()) {
                throw new Exception("Gudang ini masih memiliki sesi opname berstatus draft yang belum diselesaikan.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO stock_opname (gudang_id, user_id, tanggal, status, keterangan)
                VALUES (?, ?, NOW(), 'draft', ?)
            ");
            $stmt->execute([$gudangId, $userId, $keterangan]);
            $opnameId = (int) $pdo->lastInsertId();

            // Isi tabel stock_opname_item dengan snapshot stok sistem saat ini
            $stmt = $pdo->prepare("
                INSERT INTO stock_opname_item (opname_id, produk_id, stok_sistem, stok_fisik, selisih)
                SELECT ?, p.id, COALESCE(sg.stok, 0), 0, 0
                FROM produk p
                LEFT JOIN stok_gudang sg ON p.id = sg.produk_id AND sg.gudang_id = ?
                WHERE p.is_active = 1 AND p.admin_id = ?
            ");
            $stmt->execute([$opnameId, $gudangId, currentAdminId()]);

            $pdo->commit();
            return $opnameId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ambil data header opname.
     */
    public static function cari(int $id): ?array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT o.*, g.nama as gudang_nama, u.username as user_nama 
            FROM stock_opname o
            JOIN gudang g ON o.gudang_id = g.id
            JOIN users u ON o.user_id = u.id
            WHERE o.id = ?
        ");
        $stmt->execute([$id]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Ambil item dari sesi opname.
     */
    public static function getItems(int $opnameId): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT i.*, p.nama as produk_nama, p.satuan, p.barcode 
            FROM stock_opname_item i
            JOIN produk p ON i.produk_id = p.id
            WHERE i.opname_id = ?
        ");
        $stmt->execute([$opnameId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update stok fisik pada item opname. (Masih dalam draft)
     */
    public static function updateStokFisik(int $opnameId, int $itemId, int $stokFisik): void
    {
        $pdo = Database::connect();
        // Hitung selisih
        $stmt = $pdo->prepare("UPDATE stock_opname_item SET stok_fisik = ?, selisih = (? - stok_sistem) WHERE id = ? AND opname_id = ?");
        $stmt->execute([$stokFisik, $stokFisik, $itemId, $opnameId]);
    }

    /**
     * Selesaikan opname. Ini akan mengubah stok nyata di sistem.
     */
    public static function selesaikan(int $opnameId): void
    {
        $pdo = Database::connect();
        $pdo->beginTransaction();
        try {
            $opname = self::cari($opnameId);
            if (!$opname) throw new Exception("Opname tidak ditemukan.");
            if ($opname['status'] !== 'draft') throw new Exception("Opname sudah selesai.");

            $gudangId = (int)$opname['gudang_id'];
            $items = self::getItems($opnameId);

            foreach ($items as $item) {
                // Selisih = Fisik - Sistem.
                // Jika fisik 0 dan selisih = -Sistem, ini mungkin user belum input. 
                // Opsional: kita bisa asumsi yang tidak diubah berarti stoknya sesuai sistem (stok fisik = sistem) 
                // jika kita tidak mau otomatis mengosongkan stok.
                // Untuk keamanan, kita anggap stok fisik yang diinput = stok final.
                
                $stokFinal = (int)$item['stok_fisik'];
                
                // Update tabel stok_gudang
                $stmt = $pdo->prepare("
                    INSERT INTO stok_gudang (gudang_id, produk_id, stok) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE stok = ?
                ");
                $stmt->execute([$gudangId, $item['produk_id'], $stokFinal, $stokFinal]);
                
                // Update total stok di tabel produk
                $stmt = $pdo->prepare("
                    UPDATE produk SET stok = (SELECT SUM(stok) FROM stok_gudang WHERE produk_id = ?) WHERE id = ?
                ");
                $stmt->execute([$item['produk_id'], $item['produk_id']]);

                // Catat di audit log jika ada selisih
                if ($item['selisih'] != 0) {
                    $stmtLog = $pdo->prepare("
                        INSERT INTO audit_log (user_id, aksi, tabel, record_id, detail)
                        VALUES (?, 'OPNAME_ADJUSTMENT', 'produk', ?, ?)
                    ");
                    $detail = "Opname #{$opnameId} Gudang #{$gudangId}: Stok disesuaikan dari {$item['stok_sistem']} menjadi {$item['stok_fisik']} (Selisih: {$item['selisih']})";
                    $stmtLog->execute([$opname['user_id'], $item['produk_id'], $detail]);
                }
            }

            // Tandai selesai
            $stmt = $pdo->prepare("UPDATE stock_opname SET status = 'selesai' WHERE id = ?");
            $stmt->execute([$opnameId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Ambil riwayat opname.
     */
    public static function riwayat(int $limit = 20): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare("
            SELECT o.*, g.nama as gudang_nama, u.username as user_nama 
            FROM stock_opname o
            JOIN gudang g ON o.gudang_id = g.id
            JOIN users u ON o.user_id = u.id
            ORDER BY o.tanggal DESC LIMIT " . (int)$limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
