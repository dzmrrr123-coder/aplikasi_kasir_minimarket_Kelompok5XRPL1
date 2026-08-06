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
        $rows = Database::connect()->query(
            'SELECT kunci, nilai FROM pengaturan ORDER BY kunci'
        )->fetchAll();

        $hasil = [];

        foreach ($rows as $row) {
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
        $stmt = $pdo->prepare(
            'INSERT INTO pengaturan (kunci, nilai) VALUES (:kunci, :nilai)
             ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
        );

        foreach ($data as $kunci => $nilai) {
            $stmt->execute([
                ':kunci' => (string) $kunci,
                ':nilai' => (string) $nilai,
            ]);
        }
    }

    /**
     * Membaca satu pengaturan dengan nilai default bila belum diset.
     */
    public static function get(string $kunci, string $default = ''): string
    {
        $stmt = Database::connect()->prepare(
            'SELECT nilai FROM pengaturan WHERE kunci = :kunci LIMIT 1'
        );
        $stmt->execute([':kunci' => $kunci]);
        $nilai = $stmt->fetchColumn();

        return $nilai === false ? $default : (string) $nilai;
    }
}
