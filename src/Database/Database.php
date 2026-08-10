<?php
// src/Database/Database.php
// Class koneksi database PDO dengan pola Singleton:
// hanya ada satu instance koneksi selama aplikasi berjalan.

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    // Constructor private agar tidak bisa di-instansiasi langsung dari luar.
    private function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';

        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset={$config['charset']}";

        try {
            $this->connection = new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // error dilempar sebagai PDOException
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Koneksi database gagal: ' . $e->getMessage());
        }
    }

    // Akses satu-satunya instance Database (lazy initialization).
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }

        return self::$instance;
    }

    // Ambil objek PDO untuk dipakai query oleh class lain.
    public function getConnection(): PDO
    {
        return $this->connection;
    }
}
