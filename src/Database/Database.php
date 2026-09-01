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

            // Lewati perintah yang berbahaya bila dijalankan berulang kali:
            // CREATE DATABASE & USE sudah ditangani di atas.
            // DROP TABLE akan menghapus data pengguna — jangan dijalankan otomatis.
            // SET FOREIGN_KEY_CHECKS tidak perlu dijalankan berulang kali.
            if (preg_match('/^\s*(CREATE\s+DATABASE|USE|SET)\b/i', $trimmed)) {
                continue;
            }

            if (preg_match('/^\s*DROP\s+TABLE\b/i', $trimmed)) {
                continue;
            }

            if (preg_match('/^\s*CREATE\s+INDEX\s+(\S+)/i', $trimmed, $m)) {
                if (in_array($m[1], $indexNames, true)) {
                    continue;
                }
            }

            // Konversi CREATE TABLE -> CREATE TABLE IF NOT EXISTS
            // supaya aman dijalankan berulang kali tanpa menghapus data.
            $trimmed = preg_replace(
                '/^\s*CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i',
                'CREATE TABLE IF NOT EXISTS ',
                $trimmed
            );

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

        // Migrasi v13: promo & stock_opname tables
        if (!self::tabelAda($pdo, $db['dbname'], 'promo')) {
            $pdo->exec(
                "CREATE TABLE promo (
                    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    nama                 VARCHAR(100) NOT NULL,
                    tipe                 ENUM('buy_x_get_y','diskon_persen','diskon_nominal') NOT NULL DEFAULT 'buy_x_get_y',
                    syarat_produk_id     INT UNSIGNED NULL,
                    syarat_qty           INT          NOT NULL DEFAULT 1,
                    reward_produk_id     INT UNSIGNED NULL,
                    reward_qty           INT          NOT NULL DEFAULT 1,
                    reward_diskon_persen DECIMAL(5,2) NOT NULL DEFAULT 100.00,
                    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
                    mulai                DATETIME     NULL,
                    selesai              DATETIME     NULL,
                    CONSTRAINT fk_promo_syarat
                        FOREIGN KEY (syarat_produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE SET NULL,
                    CONSTRAINT fk_promo_reward
                        FOREIGN KEY (reward_produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE SET NULL
                ) ENGINE=InnoDB"
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'stock_opname')) {
            $pdo->exec(
                "CREATE TABLE stock_opname (
                    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    gudang_id  INT UNSIGNED  NOT NULL,
                    user_id    INT UNSIGNED  NOT NULL,
                    status     ENUM('draft','selesai','dibatalkan') NOT NULL DEFAULT 'draft',
                    catatan    TEXT          NULL,
                    dibuat_pada DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    selesai_pada DATETIME    NULL,
                    CONSTRAINT fk_opname_gudang
                        FOREIGN KEY (gudang_id) REFERENCES gudang(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT,
                    CONSTRAINT fk_opname_user
                        FOREIGN KEY (user_id) REFERENCES users(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB"
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'stock_opname_item')) {
            $pdo->exec(
                "CREATE TABLE stock_opname_item (
                    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    opname_id       INT UNSIGNED  NOT NULL,
                    produk_id       INT UNSIGNED  NOT NULL,
                    stok_sistem     INT           NOT NULL DEFAULT 0,
                    stok_fisik      INT           NULL,
                    selisih         INT           NULL,
                    catatan         VARCHAR(255)  NULL,
                    CONSTRAINT fk_opname_item_opname
                        FOREIGN KEY (opname_id) REFERENCES stock_opname(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    CONSTRAINT fk_opname_item_produk
                        FOREIGN KEY (produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB"
            );
        }

        // Migrasi v12b: transaksi.gudang_id (multi-gudang integration)
        if (!self::kolomAda($pdo, $db['dbname'], 'transaksi', 'gudang_id')) {
            $pdo->exec('ALTER TABLE transaksi ADD COLUMN gudang_id INT UNSIGNED NULL AFTER member_id');
        }
        if (self::kolomAda($pdo, $db['dbname'], 'transaksi', 'gudang_id')
            && !self::constraintAda($pdo, $db['dbname'], 'fk_transaksi_gudang')) {
            $pdo->exec(
                'ALTER TABLE transaksi ADD CONSTRAINT fk_transaksi_gudang
                 FOREIGN KEY (gudang_id) REFERENCES gudang(id)
                 ON UPDATE CASCADE ON DELETE SET NULL'
            );
        }

        // Migrasi v12: notifikasi_stok (log alert stok menipis via WhatsApp)
        if (!self::tabelAda($pdo, $db['dbname'], 'notifikasi_stok')) {
            $pdo->exec(
                "CREATE TABLE notifikasi_stok (
                    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    produk_id     INT UNSIGNED  NOT NULL,
                    webhook_url   VARCHAR(255)  NOT NULL,
                    nomor_tujuan  VARCHAR(30)   NOT NULL,
                    payload       JSON          NOT NULL,
                    status        ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                    dibuat_pada   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    CONSTRAINT fk_notif_stok_produk
                        FOREIGN KEY (produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE CASCADE
                ) ENGINE=InnoDB"
            );
        }

        // Migrasi v11b: perbesar kolom metode di rekap_penjualan (VARCHAR(20) -> VARCHAR(50)).
        $tipeMetode = $pdo->query(
            'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ' . $pdo->quote($db['dbname']) . '
               AND TABLE_NAME = \'rekap_penjualan\' AND COLUMN_NAME = \'metode\''
        )->fetch();

        if ($tipeMetode !== false && (int) ($tipeMetode['CHARACTER_MAXIMUM_LENGTH'] ?? 0) < 50) {
            $pdo->exec('ALTER TABLE rekap_penjualan MODIFY metode VARCHAR(50) NOT NULL DEFAULT \'tunai\'');
        }

        // ------------------------------------------------------------
        // Migrasi v11 (multi-gudang): tabel gudang, stok_gudang,
        //   transfer_stok, item_transfer.
        // ------------------------------------------------------------
        if (!self::tabelAda($pdo, $db['dbname'], 'gudang')) {
            $pdo->exec(
                "CREATE TABLE gudang (
                    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    nama      VARCHAR(100) NOT NULL,
                    alamat    VARCHAR(255) NULL,
                    is_utama  TINYINT(1)   NOT NULL DEFAULT 0,
                    is_aktif  TINYINT(1)   NOT NULL DEFAULT 1
                ) ENGINE=InnoDB"
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'stok_gudang')) {
            $pdo->exec(
                "CREATE TABLE stok_gudang (
                    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    gudang_id  INT UNSIGNED  NOT NULL,
                    produk_id  INT UNSIGNED  NOT NULL,
                    stok       INT           NOT NULL DEFAULT 0,
                    CONSTRAINT fk_stok_gudang
                        FOREIGN KEY (gudang_id) REFERENCES gudang(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    CONSTRAINT fk_stok_produk
                        FOREIGN KEY (produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    UNIQUE KEY uq_gudang_produk (gudang_id, produk_id)
                ) ENGINE=InnoDB"
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'transfer_stok')) {
            $pdo->exec(
                "CREATE TABLE transfer_stok (
                    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    gudang_asal_id  INT UNSIGNED  NOT NULL,
                    gudang_tujuan_id INT UNSIGNED NOT NULL,
                    tanggal         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    user_id         INT UNSIGNED  NOT NULL,
                    keterangan      VARCHAR(255)  NULL,
                    CONSTRAINT fk_transfer_asal
                        FOREIGN KEY (gudang_asal_id) REFERENCES gudang(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT,
                    CONSTRAINT fk_transfer_tujuan
                        FOREIGN KEY (gudang_tujuan_id) REFERENCES gudang(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT,
                    CONSTRAINT fk_transfer_user
                        FOREIGN KEY (user_id) REFERENCES users(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB"
            );
        }

        if (!self::tabelAda($pdo, $db['dbname'], 'item_transfer')) {
            $pdo->exec(
                "CREATE TABLE item_transfer (
                    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    transfer_id    INT UNSIGNED  NOT NULL,
                    produk_id      INT UNSIGNED  NOT NULL,
                    qty            DECIMAL(12,3) NOT NULL,
                    CONSTRAINT fk_item_transfer
                        FOREIGN KEY (transfer_id) REFERENCES transfer_stok(id)
                        ON UPDATE CASCADE ON DELETE CASCADE,
                    CONSTRAINT fk_item_transfer_produk
                        FOREIGN KEY (produk_id) REFERENCES produk(id)
                        ON UPDATE CASCADE ON DELETE RESTRICT
                ) ENGINE=InnoDB"
            );
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

        // ------------------------------------------------------------
        // Migrasi v14 (multi-tenancy): admin_id pada semua tabel
        //   supaya data tiap admin/pemilik toko terisolasi.
        //   - users: kasir punya admin_id (penunjuk pemilik/admin)
        //   - semua tabel data: admin_id untuk scope data per-toko
        // ------------------------------------------------------------
        self::migrasiMultiTenancy($pdo, $db['dbname']);
    }

    /**
     * Migrasi multi-tenancy: tambah kolom admin_id ke semua tabel
     * supaya data tiap admin/pemilik toko terisolasi.
     */
    private static function migrasiMultiTenancy(PDO $pdo, string $db): void
    {
        // Tabel yang perlu admin_id untuk scope data per-toko
        $tabelData = [
            'produk', 'kategori', 'supplier', 'diskon',
            'pengaturan', 'gudang', 'member', 'promo',
            'katalog_penukaran', 'transaksi', 'pembelian',
            'retur_barang', 'shift_kasir', 'rekap_penjualan',
            'stock_opname', 'notifikasi_stok', 'notifikasi_queue',
        ];

        foreach ($tabelData as $tabel) {
            if (self::kolomAda($pdo, $db, $tabel, 'admin_id')) {
                continue;
            }
            $pdo->exec(
                "ALTER TABLE `{$tabel}` ADD COLUMN admin_id INT UNSIGNED NULL"
            );
        }

        // users: admin_id untuk kasir (指向 pemilik/admin)
        if (!self::kolomAda($pdo, $db, 'users', 'admin_id')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN admin_id INT UNSIGNED NULL AFTER is_active');
        }

        // Set admin_id = id untuk admin (biar punya data sendiri)
        // Hanya jika kolom baru saja ditambahkan (semua NULL)
        $cek = $pdo->query(
            'SELECT COUNT(*) FROM users WHERE admin_id IS NULL AND role = \'admin\''
        )->fetchColumn();

        if ($cek > 0) {
            $pdo->exec('UPDATE users SET admin_id = id WHERE role = \'admin\' AND admin_id IS NULL');
        }

        // UNIQUE constraints perlu dikonversi ke composite (kunci, admin_id)
        // supaya tiap admin bisa punya data dengan nama/kunci yang sama.
        self::dropUniqueJikaAda($pdo, $db, 'kategori', 'uq_kategori_nama');
        if (!self::constraintAda($pdo, $db, 'uq_kategori_nama_admin')) {
            $pdo->exec('ALTER TABLE kategori ADD UNIQUE KEY uq_kategori_nama_admin (nama, admin_id)');
        }

        // Pengaturan: UNIQUE kunci harus per-admin
        self::dropUniqueJikaAda($pdo, $db, 'pengaturan', 'kunci');
        if (!self::constraintAda($pdo, $db, 'uq_pengaturan_kunci_admin')) {
            $pdo->exec('ALTER TABLE pengaturan ADD UNIQUE KEY uq_pengaturan_kunci_admin (kunci, admin_id)');
        }

        // Diskon: UNIQUE kode harus per-admin
        self::dropUniqueJikaAda($pdo, $db, 'diskon', 'kode');
        if (!self::constraintAda($pdo, $db, 'uq_diskon_kode_admin')) {
            $pdo->exec('ALTER TABLE diskon ADD UNIQUE KEY uq_diskon_kode_admin (kode, admin_id)');
        }

        // Member: UNIQUE nomor_member dan telepon harus per-admin
        self::dropUniqueJikaAda($pdo, $db, 'member', 'nomor_member');
        if (!self::constraintAda($pdo, $db, 'uq_member_nomor_admin')) {
            $pdo->exec('ALTER TABLE member ADD UNIQUE KEY uq_member_nomor_admin (nomor_member, admin_id)');
        }
        self::dropUniqueJikaAda($pdo, $db, 'member', 'telepon');
        if (!self::constraintAda($pdo, $db, 'uq_member_telepon_admin')) {
            $pdo->exec('ALTER TABLE member ADD UNIQUE KEY uq_member_telepon_admin (telepon, admin_id)');
        }
    }

    /** Hapus UNIQUE constraint bila ada (UNTUK konversi ke composite). */
    private static function dropUniqueJikaAda(PDO $pdo, string $db, string $tabel, string $kolom): void
    {
        // MySQL: cari nama constraint UNIQUE pada kolom tertentu
        $stmt = $pdo->prepare(
            'SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tabel
               AND COLUMN_NAME = :kolom AND NON_UNIQUE = 0
             LIMIT 1'
        );
        $stmt->execute([':db' => $db, ':tabel' => $tabel, ':kolom' => $kolom]);
        $nama = $stmt->fetchColumn();

        if ($nama !== false && $nama !== 'PRIMARY') {
            $pdo->exec('ALTER TABLE `' . $tabel . '` DROP INDEX `' . $nama . '`');
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
        if (!self::kolomAda($pdo, $db, 'users', 'data_sesi')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN data_sesi JSON NULL AFTER is_active');
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
