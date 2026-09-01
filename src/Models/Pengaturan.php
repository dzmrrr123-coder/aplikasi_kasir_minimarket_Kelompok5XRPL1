<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Pengaturan toko (nama, alamat, telepon, footer struk, pajak, dll.)
 * Disimpan sebagai pasangan kunci-nilai di tabel `pengaturan`.
 */
class Pengaturan
{
    /**
     * Mengambil semua pengaturan sebagai array kunci => nilai.
     *
     * @return array<string, string>
     */
    public static function semua(): array
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT kunci, nilai FROM pengaturan WHERE admin_id = :admin_id ORDER BY kunci'
        );
        $stmt->execute([':admin_id' => $adminId]);

        $hasil = [];

        foreach ($stmt->fetchAll() as $row) {
            $hasil[$row['kunci']] = $row['nilai'];
        }

        return $hasil;
    }

    /**
     * Menyimpan satu atau banyak pengaturan (upsert per kunci).
     *
     * @param array<string, string> $data kunci => nilai
     */
    public static function simpan(array $data): void
    {
        $pdo = Database::connect();
        $adminId = currentAdminId();
        $stmt = $pdo->prepare(
            'INSERT INTO pengaturan (kunci, nilai, admin_id) VALUES (:kunci, :nilai, :admin_id)
             ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
        );

        foreach ($data as $kunci => $nilai) {
            $stmt->execute([
                ':kunci'    => (string) $kunci,
                ':nilai'    => (string) $nilai,
                ':admin_id' => $adminId,
            ]);
        }
    }

    /**
     * Membaca satu pengaturan dengan nilai default bila belum diset.
     */
    public static function get(string $kunci, string $default = ''): string
    {
        $adminId = currentAdminId();
        $stmt = Database::connect()->prepare(
            'SELECT nilai FROM pengaturan WHERE kunci = :kunci AND admin_id = :admin_id LIMIT 1'
        );
        $stmt->execute([':kunci' => $kunci, ':admin_id' => $adminId]);
        $nilai = $stmt->fetchColumn();

        return $nilai === false ? $default : (string) $nilai;
    }
}
