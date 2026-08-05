-- Skema database aplikasi kasir minimarket
-- Sesuai saran skema tabel pada spesifikasi (bagian 6)

CREATE DATABASE IF NOT EXISTS kasir_minimarket
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE kasir_minimarket;

CREATE TABLE IF NOT EXISTS users (
    id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama     VARCHAR(100) NOT NULL,
    username VARCHAR(50)  NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role     ENUM('kasir', 'admin') NOT NULL DEFAULT 'kasir'
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
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    total         DECIMAL(12,2)   NOT NULL DEFAULT 0,
    kasir_id      INT UNSIGNED    NOT NULL,
    diskon_id     INT UNSIGNED    NULL,
    pembayaran_id INT UNSIGNED    NULL,
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
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transaksi_id INT UNSIGNED    NOT NULL,
    produk_id    INT UNSIGNED    NOT NULL,
    qty          DECIMAL(12,3)   NOT NULL,
    subtotal     DECIMAL(12,2)   NOT NULL,
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
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tanggal     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    produk_id   INT UNSIGNED    NOT NULL,
    supplier_id INT UNSIGNED    NOT NULL,
    qty         INT             NOT NULL,
    alasan      VARCHAR(255)    NULL,
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

CREATE INDEX idx_produk_kategori ON produk(kategori_id);
CREATE INDEX idx_transaksi_kasir ON transaksi(kasir_id);
CREATE INDEX idx_transaksi_tanggal ON transaksi(tanggal);
CREATE INDEX idx_item_transaksi_produk ON item_transaksi(produk_id);
