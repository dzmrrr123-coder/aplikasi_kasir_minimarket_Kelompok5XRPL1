<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;
use App\Database\Database;

abstract class User
{
    protected string $id = '';
    protected string $nama = '';
    protected string $username = '';
    protected string $password = '';

    public function __construct(array $data = [])
    {
        if (isset($data['id'])) {
            $this->id = (string) $data['id'];
        }
        if (isset($data['nama'])) {
            $this->nama = (string) $data['nama'];
        }
        if (isset($data['username'])) {
            $this->username = (string) $data['username'];
        }
        if (isset($data['password'])) {
            $this->password = (string) $data['password'];
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

    public function getUsername(): string
    {
        return $this->username;
    }

    public function login(string $username, string $password): bool
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare(
            'SELECT id, nama, username, password, role FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password'])) {
            return false;
        }

        $this->id       = (string) $row['id'];
        $this->nama     = $row['nama'];
        $this->username = $row['username'];
        $this->password = $row['password'];

        return true;
    }

    public function logout(): void
    {
        $this->id       = '';
        $this->nama     = '';
        $this->username = '';
        $this->password = '';
    }
}
