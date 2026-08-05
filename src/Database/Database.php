<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/../../config/config.php';
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['dbname'],
            $db['charset']
        );

        self::$pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /**
     * Membuat database dan menjalankan skema tabel.
     * Koneksi dibuka tanpa dbname karena database-nya belum tentu ada.
     */
    public static function runSchema(): void
    {
        $config = require __DIR__ . '/../../config/config.php';
        $db = $config['db'];

        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=%s',
            $db['host'],
            $db['port'],
            $db['charset']
        );

        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $db['dbname']
        ));

        $pdo->exec(sprintf('USE `%s`', $db['dbname']));

        $indexNames = $pdo->query(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ' . $pdo->quote($db['dbname']) . '
             GROUP BY INDEX_NAME'
        )->fetchAll(PDO::FETCH_COLUMN);

        $sql = file_get_contents(__DIR__ . '/../../database/schema.sql');

        if ($sql === false) {
            throw new \RuntimeException('File skema database tidak ditemukan.');
        }

        // CREATE INDEX di MySQL tidak mendukung IF NOT EXISTS,
        // jadi statement index dilewati bila index-nya sudah ada.
        // CREATE DATABASE dan USE sudah ditangani langsung di atas.
        // Statement multi-baris dipisahkan berdasarkan titik koma.
        $statements = preg_split('/;\s*/', $sql) ?: [];

        foreach ($statements as $statement) {
            $trimmed = trim($statement);

            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }

            if (preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $trimmed)) {
                continue;
            }

            if (preg_match('/^\s*CREATE\s+INDEX\s+(\S+)/i', $trimmed, $m)) {
                if (in_array($m[1], $indexNames, true)) {
                    continue;
                }
            }

            $pdo->exec($trimmed);
        }
    }
}
