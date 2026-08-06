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
    protected bool $isActive = true;

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
        if (isset($data['is_active'])) {
            $this->isActive = (bool) $data['is_active'];
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Hak akses pengguna (Inheritance + Overriding).
     * Diimplementasikan ulang (override) oleh Admin (izin penuh) dan
     * Kasir (izin terbatas). Mengembalikan daftar izin.
     *
     * @return string[]
     */
    abstract public function getHakAkses(): array;

    /**
     * Login polimorfik: memvalidasi kredensial dan mengembalikan objek
     * Admin atau Kasir yang SPESIFIK berdasarkan role di database,
     * bukan objek User generik.
     *
     * @return Admin|Kasir|null null bila kredensial salah / user tidak ditemukan
     */
    public static function loginPolimorfik(string $username, string $password): ?self
    {
        $pdo = Database::connect();

        $stmt = $pdo->prepare(
            'SELECT id, nama, username, password, role, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => $username]);
        $row = $stmt->fetch();

        if ($row === false || !password_verify($password, $row['password'])) {
            return null;
        }

        // Akun nonaktif tidak boleh login (soft-delete user).
        if ((int) $row['is_active'] !== 1) {
            return null;
        }

        // Deteksi role -> instansiasi polimorfik (Admin atau Kasir).
        return $row['role'] === 'admin' ? new Admin($row) : new Kasir($row);
    }

    /**
     * Login pada instance. Kredensial divalidasi via loginPolimorfik();
     * data pengguna diisi ke instance ini sesuai hasil deteksi role.
     * Method ini tetap ada untuk kompatibilitas pemanggilan lama.
     */
    public function login(string $username, string $password): bool
    {
        $user = self::loginPolimorfik($username, $password);

        if ($user === null) {
            return false;
        }

        $this->id       = $user->getId();
        $this->nama     = $user->getNama();
        $this->username = $user->getUsername();
        $this->password = $user->password;

        return true;
    }

    public function logout(): void
    {
        $this->id       = '';
        $this->nama     = '';
        $this->username = '';
        $this->password = '';
    }

    // ------------------------------------------------------------
    // CRUD akun kasir (tabel users, role 'kasir').
    // Pola mengikuti Kategori/Produk: model memegang CRUD tabelnya.
    // ------------------------------------------------------------

    /**
     * Mengambil semua akun kasir, diurutkan berdasarkan nama.
     *
     * @return Kasir[]
     */
    public static function daftarKasir(): array
    {
        $rows = Database::connect()->query(
            "SELECT id, nama, username, password, role, is_active FROM users WHERE role = 'kasir' ORDER BY nama"
        )->fetchAll();

        return array_map(static fn (array $row): Kasir => new Kasir($row), $rows);
    }

    public static function cariKasir(int $id): ?Kasir
    {
        $stmt = Database::connect()->prepare(
            "SELECT id, nama, username, password, role, is_active FROM users WHERE id = :id AND role = 'kasir' LIMIT 1"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : new Kasir($row);
    }

    /**
     * Mencari user berdasarkan id (untuk validasi sesi login).
     * Mengembalikan objek Admin atau Kasir sesuai role di database,
     * atau null bila user tidak ditemukan / tidak aktif.
     */
    public static function cariBerdasarkanId(int $id): ?self
    {
        $stmt = Database::connect()->prepare(
            'SELECT id, nama, username, password, role, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false || (int) $row['is_active'] !== 1) {
            return null;
        }

        return $row['role'] === 'admin' ? new Admin($row) : new Kasir($row);
    }

    /**
     * Cek apakah username sudah dipakai.
     * Bila $kecualiId diberikan, username milik kasir tersebut diabaikan
     * (dipakai saat edit supaya username sendiri tidak dianggap duplikat).
     */
    public static function usernameTerpakai(string $username, ?int $kecualiId = null): bool
    {
        if ($kecualiId !== null) {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM users WHERE username = :username AND id <> :id'
            );
            $stmt->execute([':username' => $username, ':id' => $kecualiId]);
        } else {
            $stmt = Database::connect()->prepare(
                'SELECT COUNT(*) FROM users WHERE username = :username'
            );
            $stmt->execute([':username' => $username]);
        }

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Tambah akun kasir baru.
     *
     * @param array $data berisi nama, username, password
     *
     * @return int id kasir baru
     *
     * @throws \RuntimeException bila validasi gagal
     */
    public static function simpanKasir(array $data): int
    {
        $nama = trim((string) ($data['nama'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        self::validasiDataKasir($nama, $username, $password, true);

        $stmt = Database::connect()->prepare(
            'INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :password, :kasir)'
        );
        $stmt->execute([
            ':nama'     => $nama,
            ':username' => $username,
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':kasir'    => 'kasir',
        ]);

        return (int) Database::connect()->lastInsertId();
    }

    /**
     * Perbarui nama/username kasir; password ikut diganti bila diisi.
     *
     * @param array $data berisi nama, username, dan (opsional) password baru
     *
     * @throws \RuntimeException bila validasi gagal atau kasir tidak ditemukan
     */
    public static function perbaruiKasir(int $id, array $data): void
    {
        $kasir = self::cariKasir($id);

        if ($kasir === null) {
            throw new \RuntimeException('Kasir tidak ditemukan.');
        }

        $nama = trim((string) ($data['nama'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        self::validasiDataKasir($nama, $username, $password, false, $id);

        $sql = 'UPDATE users SET nama = :nama, username = :username WHERE id = :id';
        $params = [':nama' => $nama, ':username' => $username, ':id' => $id];

        if ($password !== '') {
            $sql = 'UPDATE users SET nama = :nama, username = :username, password = :password WHERE id = :id';
            $params[':password'] = password_hash($password, PASSWORD_DEFAULT);
        }

        $stmt = Database::connect()->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Aktifkan/nonaktifkan akun kasir (soft-delete: user tidak bisa login,
     * tapi data transaksinya tetap aman).
     *
     * @throws \RuntimeException bila kasir tidak ditemukan
     */
    public static function setStatusAktifKasir(int $id, bool $aktif): void
    {
        if (self::cariKasir($id) === null) {
            throw new \RuntimeException('Kasir tidak ditemukan.');
        }

        $stmt = Database::connect()->prepare(
            'UPDATE users SET is_active = :aktif WHERE id = :id'
        );
        $stmt->execute([':aktif' => $aktif ? 1 : 0, ':id' => $id]);
    }

    /**
     * Reset password kasir.
     *
     * @throws \RuntimeException bila password < 6 karakter atau kasir tidak ditemukan
     */
    public static function resetPasswordKasir(int $id, string $password): void
    {
        if (self::cariKasir($id) === null) {
            throw new \RuntimeException('Kasir tidak ditemukan.');
        }

        if (strlen($password) < 6) {
            throw new \RuntimeException('Password minimal 6 karakter.');
        }

        $stmt = Database::connect()->prepare(
            'UPDATE users SET password = :password WHERE id = :id'
        );
        $stmt->execute([
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id'       => $id,
        ]);
    }

    /**
     * Hapus akun kasir. Ditolak bila kasir masih punya transaksi
     * (FK transaksi.kasir_id ON DELETE RESTRICT), supaya tidak muncul
     * error SQL mentah ke pengguna.
     *
     * @throws \RuntimeException bila kasir tidak ditemukan atau masih punya transaksi
     */
    public static function hapusKasir(int $id): void
    {
        if (self::cariKasir($id) === null) {
            throw new \RuntimeException('Kasir tidak ditemukan.');
        }

        $stmt = Database::connect()->prepare(
            'SELECT COUNT(*) FROM transaksi WHERE kasir_id = :id'
        );
        $stmt->execute([':id' => $id]);

        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('Kasir tidak bisa dihapus, masih punya transaksi.');
        }

        $delete = Database::connect()->prepare('DELETE FROM users WHERE id = :id');
        $delete->execute([':id' => $id]);
    }

    /**
     * Validasi data kasir: nama tidak kosong, username unik,
     * password minimal 6 karakter (wajib saat tambah, opsional saat edit).
     *
     * @throws \RuntimeException bila ada data yang tidak valid
     */
    private static function validasiDataKasir(
        string $nama,
        string $username,
        string $password,
        bool $wajibPassword,
        ?int $kecualiId = null
    ): void {
        if (trim($nama) === '') {
            throw new \RuntimeException('Nama kasir tidak boleh kosong.');
        }
        if (trim($username) === '') {
            throw new \RuntimeException('Username tidak boleh kosong.');
        }
        if ($wajibPassword && strlen($password) < 6) {
            throw new \RuntimeException('Password minimal 6 karakter.');
        }
        if (!$wajibPassword && $password !== '' && strlen($password) < 6) {
            throw new \RuntimeException('Password minimal 6 karakter.');
        }
        if (self::usernameTerpakai($username, $kecualiId)) {
            throw new \RuntimeException('Username sudah dipakai.');
        }
    }
}
