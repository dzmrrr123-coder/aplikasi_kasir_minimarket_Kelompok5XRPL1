<?php
// src/Models/User.php
// Abstract class dasar untuk semua pengguna aplikasi (Kasir & Admin).

abstract class User
{
    private string $id;
    private string $nama;
    private string $username;
    private string $password;

    // id diterima sebagai int (dari DB) atau string, lalu di-cast ke string
    // agar konsisten dengan tipe di class diagram spec.
    public function __construct(int|string $id = '', string $nama = '', string $username = '', string $password = '')
    {
        $this->id       = (string) $id;
        $this->nama     = $nama;
        $this->username = $username;
        $this->password = $password;
    }

    // Verifikasi kredensial ke tabel users via PDO (prepared statement).
    // Return false untuk kredensial salah; error sistem (PDOException) dilempar
    // ke pemanggil.
    public function login(string $username, string $password): bool
    {
        $pdo  = Database::getInstance()->getConnection();
        $stmt = $pdo->prepare('SELECT id, nama, username, password FROM users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password'])) {
            return false;
        }

        // Isi state object dari hasil query (id di-cast ke string).
        $this->id       = (string) $row['id'];
        $this->nama     = $row['nama'];
        $this->username = $row['username'];
        $this->password = $row['password'];

        return true;
    }

    // Reset state login di object.
    public function logout(): void
    {
        $this->id       = '';
        $this->nama     = '';
        $this->username = '';
        $this->password = '';
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getNama(): string
    {
        return $this->nama;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    // Mengembalikan hash password (bukan plain text).
    public function getPassword(): string
    {
        return $this->password;
    }
}
