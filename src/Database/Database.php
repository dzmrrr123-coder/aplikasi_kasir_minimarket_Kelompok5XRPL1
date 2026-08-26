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
            // Buang baris komentar SQL (-- ...) yang menempel pada statement.
            // Contoh: "-- komentar\nCREATE TABLE ..." harus tetap dieksekusi.
            $trimmed = trim((string) preg_replace('/^\s*--.*$/m', '', $statement));

            if ($trimmed === '') {
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

        // Migrasi idempotent: tambah kolom `kode` pada tabel diskon bila belum ada.
        // MySQL tidak mendukung ADD COLUMN IF NOT EXISTS, jadi cek dulu
        // keberadaan kolom lewat information_schema.
        $kolomDiskon = $pdo->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ' . $pdo->quote($db['dbname']) . '
               AND TABLE_NAME = \'diskon\' AND COLUMN_NAME = \'kode\''
        )->fetchColumn();

        if ($kolomDiskon === false) {
            $pdo->exec('ALTER TABLE diskon ADD COLUMN kode VARCHAR(50) NULL UNIQUE');
        }

        // Migrasi idempotent: kolom satuan & harga_per_gram pada tabel produk
        // (dukungan produk curah yang ditimbang, satuan gram).
        $kolomSatuan = $pdo->query(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ' . $pdo->quote($db['dbname']) . '
               AND TABLE_NAME = \'produk\' AND COLUMN_NAME = \'satuan\''
        )->fetchColumn();

        if ($kolomSatuan === false) {
            $pdo->exec("ALTER TABLE produk ADD COLUMN satuan ENUM('pcs','gram') NOT NULL DEFAULT 'pcs' AFTER stok");
            $pdo->exec('ALTER TABLE produk ADD COLUMN harga_per_gram DECIMAL(12,2) NULL AFTER satuan');
        }

        // Migrasi idempotent: ubah tipe kolom qty di item_transaksi jadi
        // DECIMAL supaya mendukung qty float (produk curah gram).
        $tipeQty = $pdo->query(
            'SELECT DATA_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ' . $pdo->quote($db['dbname']) . '
               AND TABLE_NAME = \'item_transaksi\' AND COLUMN_NAME = \'qty\''
        )->fetchColumn();

        if ($tipeQty === 'int') {
            $pdo->exec('ALTER TABLE item_transaksi MODIFY qty DECIMAL(12,3) NOT NULL');
        }

        // ------------------------------------------------------------
        // Migrasi idempotent v2 (fitur kompetisi):
        //   - produk: barcode, harga_beli, stok_minimum, supplier_id, is_active
        //   - users : is_active
        //   - transaksi: member_id (tabel member dibuat dulu, baru FK-nya)
        //   - tabel baru: member, pembelian, item_pembelian, pengaturan
        // Semua memakai pola yang sama: cek information_schema dulu,
        // baru ALTER/CREATE supaya aman dijalankan berulang kali.
        // ------------------------------------------------------------
        self::migrasiTabelBaru($pdo, $db['dbname']);
        self::migrasiProdukV2($pdo, $db['dbname']);
        self::migrasiUsersV2($pdo, $db['dbname']);
        self::migrasiTransaksiV2($pdo, $db['dbname']);

        // Migrasi v3 (debug QA):
        //   - transaksi.pajak        (nilai PPN yang dibayar, biar total & rekap akurat)
        //   - item_transaksi.harga_beli_satuan (snapshot HPP historis untuk laporan laba)
        if (!self::kolomAda($pdo, $db['dbname'], 'transaksi', 'pajak')) {
            $pdo->exec('ALTER TABLE transaksi ADD COLUMN pajak DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total');
        }
        if (!self::kolomAda($pdo, $db['dbname'], 'item_transaksi', 'harga_beli_satuan')) {
            $pdo->exec('ALTER TABLE item_transaksi ADD COLUMN harga_beli_satuan DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subtotal');
        }

        // Migrasi v4 (foto produk): kolom produk.gambar.
        if (!self::kolomAda($pdo, $db['dbname'], 'produk', 'gambar')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN gambar VARCHAR(255) NULL AFTER is_active');
        }

        // Migrasi v5 (shift kasir & audit log): tabel baru.
        if (!self::tabelAda($pdo, $db['dbname'], 'shift_kasir')) {
            $pdo->exec(
                'CREATE TABLE shift_kasir (
                    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    kasir_id           INT UNSIGNED  NOT NULL,
                    dibuka_pada        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    modal_awal         DECIMAL(12,2) NOT NULL DEFAULT 0,
                    ditutup_pada       DATETIME      NULL,
                    total_sistem       DECIMAL(12,2) NOT NULL DEFAULT 0,
                    total_kas_fisik    DECIMAL(12,2) NULL,
                    selisih            DECIMAL(12,2) NULL,
                    catatan            VARCHAR(255)  NULL,
                    status             ENUM(\'buka\',\'tutup\') NOT NULL DEFAULT \'buka\',
                    CONSTRAINT fk_shift_kasir
                        FOREIGN KEY (kasir_id) REFERENCES users(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB'
            );
        }

        // Migrasi v6 (retur terikat pembelian): kolom retur_barang.pembelian_id
        // menunjuk stok masuk (item_pembelian) asal barang yang diretur.
        if (!self::kolomAda($pdo, $db['dbname'], 'retur_barang', 'pembelian_id')) {
            $pdo->exec(
                'ALTER TABLE retur_barang ADD COLUMN pembelian_id INT UNSIGNED NULL AFTER supplier_id'
            );
        }
        if (self::kolomAda($pdo, $db['dbname'], 'retur_barang', 'pembelian_id')
            && !self::constraintAda($pdo, $db['dbname'], 'fk_retur_pembelian')) {
            $pdo->exec(
                'ALTER TABLE retur_barang ADD CONSTRAINT fk_retur_pembelian
                 FOREIGN KEY (pembelian_id) REFERENCES pembelian(id)
                 ON UPDATE CASCADE ON DELETE SET NULL'
            );
        }

        // Migrasi v7 (member akun mandiri):
        //   - member.nomor_member (nomor otomatis, unik, diisi model saat simpan)
        //   - member.password     (untuk login member di halaman member)
        //   - tabel baru katalog_penukaran (badge/hadiah yang bisa ditukar poin)
        if (!self::kolomAda($pdo, $db['dbname'], 'member', 'nomor_member')) {
            $pdo->exec('ALTER TABLE member ADD COLUMN nomor_member VARCHAR(20) NULL UNIQUE AFTER id');
        }
        if (!self::kolomAda($pdo, $db['dbname'], 'member', 'password')) {
            $pdo->exec('ALTER TABLE member ADD COLUMN password VARCHAR(255) NULL AFTER telepon');
        }

        // Migrasi v8 (K2 fix — poin member saat batalkan transaksi):
        //   transaksi.poin_diberikan = snapshot poin yang diberikan saat
        //   transaksi dibuat, dipakai saat batalkan() supaya pengembalian
        //   selalu akurat (tidak dihitung ulang dari floor(total/1000)).
        if (!self::kolomAda($pdo, $db['dbname'], 'transaksi', 'poin_diberikan')) {
            $pdo->exec(
                'ALTER TABLE transaksi ADD COLUMN poin_diberikan INT NOT NULL DEFAULT 0 AFTER member_id'
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'katalog_penukaran')) {
            $pdo->exec(
                'CREATE TABLE katalog_penukaran (
                    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    nama      VARCHAR(100) NOT NULL,
                    poin      INT          NOT NULL,
                    deskripsi VARCHAR(255) NULL,
                    aktif     TINYINT(1)   NOT NULL DEFAULT 1
                ) ENGINE=InnoDB'
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'audit_log')) {
            $pdo->exec(
                'CREATE TABLE audit_log (
                    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id    INT UNSIGNED    NULL,
                    aksi       VARCHAR(50)     NOT NULL,
                    tabel      VARCHAR(50)     NOT NULL,
                    record_id  INT UNSIGNED    NULL,
                    detail     TEXT            NULL,
                    dicatat_pada DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_audit_user
                        FOREIGN KEY (user_id) REFERENCES users(id)
                        ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB'
            );
        }

        // ------------------------------------------------------------
        // ------------------------------------------------------------
        // Migrasi v10 (device pairing per kasir):
        //   - tabel baru user_devices (printer / timbangan Web Serial)
        // Dipasangkan karena schema.sql sudah CREATE TABLE IF NOT EXISTS,
        // tetapi di sini tetap dikelola idempotent supaya DB existing pun aman.
        // ------------------------------------------------------------
        if (!self::tabelAda($pdo, $db['dbname'], 'user_devices')) {
            $pdo->exec(
                "CREATE TABLE user_devices (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id     INT UNSIGNED  NOT NULL,
                    tipe        ENUM('timbangan','printer') NOT NULL,
                    label       VARCHAR(100)  NOT NULL,
                    urutan      INT           NOT NULL DEFAULT 0,
                    is_aktif    TINYINT(1)    NOT NULL DEFAULT 1,
                    dibuat_pada DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    diubah_pada DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
                    CONSTRAINT fk_user_device
                        FOREIGN KEY (user_id) REFERENCES users(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    UNIQUE KEY uq_user_tipe (user_id, tipe)
                ) ENGINE=InnoDB"
            );
        }
        if (self::tabelAda($pdo, $db['dbname'], 'user_devices')
            && !self::constraintAda($pdo, $db['dbname'], 'uq_user_tipe')) {
            $pdo->exec('ALTER TABLE user_devices ADD UNIQUE KEY uq_user_tipe (user_id, tipe)');
        }

        // ------------------------------------------------------------
        // Migrasi v9 (notifikasi WA via n8n): outbox transaksional.
        // Observer NotifikasiWhatsApp meng-INSERT baris PENDING ini di
        // dalam DB transaction (sebelum commit) sehingga ikut ter-roll-back
        // bila transaksi penjualan gagal. Baris dikirim ke webhook n8n
        // (status 'sent'/'failed') oleh NotifikasiAntrian::proses() SETELAH
        // commit — jauh dari jalur transaksi agar tidak menghambat kasir.
        // ------------------------------------------------------------
        if (!self::tabelAda($pdo, $db['dbname'], 'notifikasi_queue')) {
            $pdo->exec(
                "CREATE TABLE notifikasi_queue (
                    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    transaksi_id  INT UNSIGNED  NOT NULL,
                    webhook_url   VARCHAR(255)  NOT NULL,
                    nomor_tujuan  VARCHAR(30)   NOT NULL,
                    payload       JSON          NOT NULL,
                    status        ENUM('pending','sent','failed')
                                  NOT NULL DEFAULT 'pending',
                    upaya         INT           NOT NULL DEFAULT 0,
                    error         TEXT          NULL,
                    dibuat_pada   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    dikirim_pada  DATETIME      NULL,
                    INDEX idx_status_dibuat (status, dibuat_pada),
                    CONSTRAINT fk_notif_transaksi
                        FOREIGN KEY (transaksi_id) REFERENCES transaksi(id)
                        ON UPDATE CASCADE ON DELETE CASCADE
                ) ENGINE=InnoDB"
            );
        }
    }

    /** Cek apakah kolom ada di tabel tertentu. */
    private static function kolomAda(PDO $pdo, string $db, string $tabel, string $kolom): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tabel AND COLUMN_NAME = :kolom'
        );
        $stmt->execute([':db' => $db, ':tabel' => $tabel, ':kolom' => $kolom]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Cek apakah tabel ada di database. */
    private static function tabelAda(PDO $pdo, string $db, string $tabel): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tabel'
        );
        $stmt->execute([':db' => $db, ':tabel' => $tabel]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Cek apakah constraint (nama FK/index) sudah ada. */
    private static function constraintAda(PDO $pdo, string $db, string $nama): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = :db AND CONSTRAINT_NAME = :nama'
        );
        $stmt->execute([':db' => $db, ':nama' => $nama]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** Kolom baru pada tabel produk (barcode, harga beli, stok minimum, supplier, aktif). */
    private static function migrasiProdukV2(PDO $pdo, string $db): void
    {
        if (!self::kolomAda($pdo, $db, 'produk', 'barcode')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN barcode VARCHAR(50) NULL UNIQUE AFTER harga_per_gram');
        }
        if (!self::kolomAda($pdo, $db, 'produk', 'harga_beli')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN harga_beli DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER barcode');
        }
        if (!self::kolomAda($pdo, $db, 'produk', 'stok_minimum')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN stok_minimum INT NOT NULL DEFAULT 0 AFTER harga_beli');
        }
        if (!self::kolomAda($pdo, $db, 'produk', 'supplier_id')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN supplier_id INT UNSIGNED NULL AFTER stok_minimum');
        }

        // Constraint FK supplier: buat hanya bila kolom & constraint belum ada.
        if (self::kolomAda($pdo, $db, 'produk', 'supplier_id')
            && !self::constraintAda($pdo, $db, 'fk_produk_supplier')) {
            $pdo->exec(
                'ALTER TABLE produk ADD CONSTRAINT fk_produk_supplier
                 FOREIGN KEY (supplier_id) REFERENCES supplier(id)
                 ON UPDATE CASCADE ON DELETE SET NULL'
            );
        }

        if (!self::kolomAda($pdo, $db, 'produk', 'is_active')) {
            $pdo->exec('ALTER TABLE produk ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER supplier_id');
        }
    }

    /** Kolom is_active pada tabel users. */
    private static function migrasiUsersV2(PDO $pdo, string $db): void
    {
        if (!self::kolomAda($pdo, $db, 'users', 'is_active')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
        }
    }

    /** Kolom member_id pada tabel transaksi. */
    private static function migrasiTransaksiV2(PDO $pdo, string $db): void
    {
        if (!self::kolomAda($pdo, $db, 'transaksi', 'member_id')) {
            $pdo->exec('ALTER TABLE transaksi ADD COLUMN member_id INT UNSIGNED NULL AFTER pembayaran_id');
        }

        // Constraint FK member: buat hanya bila kolom & constraint belum ada.
        if (self::kolomAda($pdo, $db, 'transaksi', 'member_id')
            && !self::constraintAda($pdo, $db, 'fk_transaksi_member')) {
            $pdo->exec(
                'ALTER TABLE transaksi ADD CONSTRAINT fk_transaksi_member
                 FOREIGN KEY (member_id) REFERENCES member(id)
                 ON UPDATE CASCADE ON DELETE SET NULL'
            );
        }
    }

    /** Tabel baru: member, pembelian, item_pembelian, pengaturan. */
    private static function migrasiTabelBaru(PDO $pdo, string $db): void
    {
        if (!self::tabelAda($pdo, $db, 'member')) {
            $pdo->exec(
                'CREATE TABLE member (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    nama        VARCHAR(100) NOT NULL,
                    telepon     VARCHAR(20)  NULL UNIQUE,
                    poin        INT          NOT NULL DEFAULT 0,
                    dibuat_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB'
            );
        }

        if (!self::tabelAda($pdo, $db, 'pembelian')) {
            $pdo->exec(
                'CREATE TABLE pembelian (
                    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    tanggal     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    supplier_id INT UNSIGNED    NULL,
                    total       DECIMAL(12,2)   NOT NULL DEFAULT 0,
                    keterangan  VARCHAR(255)    NULL,
                    CONSTRAINT fk_pembelian_supplier
                        FOREIGN KEY (supplier_id) REFERENCES supplier(id)
                        ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB'
            );
        }

        if (!self::tabelAda($pdo, $db, 'item_pembelian')) {
            $pdo->exec(
                'CREATE TABLE item_pembelian (
                    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    pembelian_id      INT UNSIGNED  NOT NULL,
                    produk_id         INT UNSIGNED  NOT NULL,
                    qty               DECIMAL(12,3) NOT NULL,
                    harga_beli_satuan DECIMAL(12,2) NOT NULL,
                    subtotal          DECIMAL(12,2) NOT NULL,
                    CONSTRAINT fk_item_pembelian
                        FOREIGN KEY (pembelian_id) REFERENCES pembelian(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    CONSTRAINT fk_item_pembelian_produk
                        FOREIGN KEY (produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB'
            );
        }

        if (!self::tabelAda($pdo, $db, 'pengaturan')) {
            $pdo->exec(
                'CREATE TABLE pengaturan (
                    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    kunci VARCHAR(50)  NOT NULL UNIQUE,
                    nilai VARCHAR(255) NOT NULL DEFAULT \'\'
                ) ENGINE=InnoDB'
            );
        }
    }
}
