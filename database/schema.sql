-- Skema database aplikasi kasir minimarket
-- Sesuai saran skema tabel pada spesifikasi (bagian 6)

CREATE DATABASE IF NOT EXISTS kasir_minimarket
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE kasir_minimarket;

CREATE TABLE IF NOT EXISTS users (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama      VARCHAR(100) NOT NULL,
    username  VARCHAR(50)  NOT NULL UNIQUE,
    password  VARCHAR(255) NOT NULL,
    role      ENUM('kasir', 'admin') NOT NULL DEFAULT 'kasir',
    is_active TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kategori (
    id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS produk (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama           VARCHAR(150)    NOT NULL,
    harga          DECIMAL(12,2)   NOT NULL,
    stok           INT             NOT NULL DEFAULT 0,
    kategori_id    INT UNSIGNED    NOT NULL,
    satuan         ENUM('pcs','gram') NOT NULL DEFAULT 'pcs',
    harga_per_gram DECIMAL(12,2)   NULL,
    barcode        VARCHAR(50)     NULL UNIQUE,
    harga_beli     DECIMAL(12,2)   NOT NULL DEFAULT 0,
    stok_minimum   INT             NOT NULL DEFAULT 0,
    supplier_id    INT UNSIGNED    NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1,
    gambar         VARCHAR(255)    NULL,
    CONSTRAINT fk_produk_kategori
        FOREIGN KEY (kategori_id) REFERENCES kategori(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS diskon (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kode  VARCHAR(50)  NULL UNIQUE,
    jenis ENUM('persen', 'nominal') NOT NULL,
    nilai DECIMAL(12,2)             NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pembayaran (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    jenis  ENUM('tunai', 'non_tunai') NOT NULL,
    jumlah DECIMAL(12,2)              NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transaksi (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total          DECIMAL(12,2)   NOT NULL DEFAULT 0,
    pajak          DECIMAL(12,2)   NOT NULL DEFAULT 0,
    kasir_id       INT UNSIGNED    NOT NULL,
    diskon_id      INT UNSIGNED    NULL,
    pembayaran_id  INT UNSIGNED    NULL,
    member_id      INT UNSIGNED    NULL,
    poin_diberikan INT             NOT NULL DEFAULT 0,
    CONSTRAINT fk_transaksi_kasir
        FOREIGN KEY (kasir_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_transaksi_diskon
        FOREIGN KEY (diskon_id) REFERENCES diskon(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_transaksi_pembayaran
        FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS item_transaksi (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaksi_id       INT UNSIGNED  NOT NULL,
    produk_id          INT UNSIGNED  NOT NULL,
    qty                DECIMAL(12,3) NOT NULL,
    subtotal           DECIMAL(12,2) NOT NULL,
    harga_beli_satuan  DECIMAL(12,2) NOT NULL DEFAULT 0,
    CONSTRAINT fk_item_transaksi
        FOREIGN KEY (transaksi_id) REFERENCES transaksi(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_item_produk
        FOREIGN KEY (produk_id) REFERENCES produk(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier (
    id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama   VARCHAR(150) NOT NULL,
    kontak VARCHAR(50)  NULL,
    alamat VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS retur_barang (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    produk_id    INT UNSIGNED    NOT NULL,
    supplier_id  INT UNSIGNED    NOT NULL,
    pembelian_id INT UNSIGNED    NULL,
    qty          INT             NOT NULL,
    alasan       VARCHAR(255)    NULL,
    CONSTRAINT fk_retur_produk
        FOREIGN KEY (produk_id) REFERENCES produk(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_retur_supplier
        FOREIGN KEY (supplier_id) REFERENCES supplier(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rekap_penjualan (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaksi_id INT UNSIGNED    NOT NULL,
    tanggal      DATETIME        NOT NULL,
    total        DECIMAL(12,2)   NOT NULL,
    kasir_id     INT UNSIGNED    NOT NULL,
    metode       VARCHAR(20)     NOT NULL DEFAULT 'tunai',
    dicatat_pada DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rekap_transaksi
        FOREIGN KEY (transaksi_id) REFERENCES transaksi(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_rekap_kasir
        FOREIGN KEY (kasir_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Pelanggan (member) & poin
CREATE TABLE IF NOT EXISTS member (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nomor_member VARCHAR(20)  NULL UNIQUE,
    nama         VARCHAR(100) NOT NULL,
    telepon      VARCHAR(20)  NULL UNIQUE,
    password     VARCHAR(255) NULL,
    poin         INT          NOT NULL DEFAULT 0,
    dibuat_pada  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Katalog hadiah/badge yang bisa ditukar member dengan poin
CREATE TABLE IF NOT EXISTS katalog_penukaran (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama      VARCHAR(100) NOT NULL,
    poin      INT          NOT NULL,
    deskripsi VARCHAR(255) NULL,
    aktif     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Pembelian / stok masuk dari supplier
CREATE TABLE IF NOT EXISTS pembelian (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    supplier_id INT UNSIGNED    NULL,
    total       DECIMAL(12,2)   NOT NULL DEFAULT 0,
    keterangan  VARCHAR(255)    NULL,
    CONSTRAINT fk_pembelian_supplier
        FOREIGN KEY (supplier_id) REFERENCES supplier(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS item_pembelian (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pembelian_id       INT UNSIGNED  NOT NULL,
    produk_id          INT UNSIGNED  NOT NULL,
    qty                DECIMAL(12,3) NOT NULL,
    harga_beli_satuan  DECIMAL(12,2) NOT NULL,
    subtotal           DECIMAL(12,2) NOT NULL,
    CONSTRAINT fk_item_pembelian
        FOREIGN KEY (pembelian_id) REFERENCES pembelian(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_item_pembelian_produk
        FOREIGN KEY (produk_id) REFERENCES produk(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Pengaturan toko (nama, alamat, footer struk, pajak, dll.)
CREATE TABLE IF NOT EXISTS pengaturan (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kunci VARCHAR(50)  NOT NULL UNIQUE,
    nilai VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB;

-- Shift kasir: buka/tutup kas + rekonsiliasi
CREATE TABLE IF NOT EXISTS shift_kasir (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kasir_id           INT UNSIGNED  NOT NULL,
    dibuka_pada        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    modal_awal         DECIMAL(12,2) NOT NULL DEFAULT 0,
    ditutup_pada       DATETIME      NULL,
    total_sistem       DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_kas_fisik    DECIMAL(12,2) NULL,
    selisih            DECIMAL(12,2) NULL,
    catatan            VARCHAR(255)  NULL,
    status             ENUM('buka','tutup') NOT NULL DEFAULT 'buka',
    CONSTRAINT fk_shift_kasir
        FOREIGN KEY (kasir_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Device pairing per kasir (printer / timbangan Web Serial)
CREATE TABLE IF NOT EXISTS user_devices (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED  NOT NULL,
    tipe       ENUM('timbangan','printer') NOT NULL,
    label      VARCHAR(100)  NOT NULL,
    urutan     INT           NOT NULL DEFAULT 0,
    is_aktif   TINYINT(1)    NOT NULL DEFAULT 1,
    dibuat_pada DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    diubah_pada DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_device
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_tipe (user_id, tipe)
) ENGINE=InnoDB;

-- Audit log: riwayat perubahan data penting (harga produk, void, dll.)
CREATE TABLE IF NOT EXISTS audit_log (
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
) ENGINE=InnoDB;

CREATE INDEX idx_shift_kasir_kasir ON shift_kasir(kasir_id);
CREATE INDEX idx_shift_kasir_status ON shift_kasir(status);
CREATE INDEX idx_audit_log_user ON audit_log(user_id);
CREATE INDEX idx_audit_log_tabel ON audit_log(tabel);

CREATE INDEX idx_produk_kategori ON produk(kategori_id);
CREATE INDEX idx_produk_supplier ON produk(supplier_id);
CREATE INDEX idx_transaksi_kasir ON transaksi(kasir_id);
CREATE INDEX idx_transaksi_tanggal ON transaksi(tanggal);
CREATE INDEX idx_transaksi_member ON transaksi(member_id);
CREATE INDEX idx_item_transaksi_produk ON item_transaksi(produk_id);
CREATE INDEX idx_pembelian_supplier ON pembelian(supplier_id);
CREATE INDEX idx_item_pembelian_produk ON item_pembelian(produk_id);
