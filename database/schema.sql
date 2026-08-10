-- database/schema.sql
-- Skema database aplikasi kasir minimarket (MySQL, InnoDB, utf8mb4).
-- Urutan CREATE TABLE mengikuti dependency foreign key.

CREATE DATABASE IF NOT EXISTS kasir_minimarket
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE kasir_minimarket;

-- Akun pengguna aplikasi (Kasir & Admin, dibedakan lewat kolom role).
CREATE TABLE users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    username   VARCHAR(50)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL, -- menyimpan hash password
    role       ENUM('kasir', 'admin') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kategori produk.
CREATE TABLE kategori (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master diskon (jenis: persen atau nominal).
CREATE TABLE diskon (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    jenis      ENUM('persen', 'nominal') NOT NULL,
    nilai      DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data pembayaran (jenis: tunai atau non_tunai).
CREATE TABLE pembayaran (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    jenis      ENUM('tunai', 'non_tunai') NOT NULL,
    jumlah     DECIMAL(12,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Data supplier untuk keperluan retur barang.
CREATE TABLE supplier (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    nama       VARCHAR(100) NOT NULL,
    kontak     VARCHAR(100),
    alamat     TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Produk yang dijual; setiap produk milik satu kategori.
CREATE TABLE produk (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nama        VARCHAR(150) NOT NULL,
    harga       DECIMAL(12,2) NOT NULL,
    stok        INT NOT NULL DEFAULT 0,
    kategori_id INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_produk_kategori FOREIGN KEY (kategori_id) REFERENCES kategori (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transaksi penjualan oleh kasir; diskon & pembayaran opsional (nullable).
CREATE TABLE transaksi (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    tanggal       DATETIME NOT NULL,
    total         DECIMAL(12,2) NOT NULL DEFAULT 0,
    kasir_id      INT NOT NULL,
    diskon_id     INT NULL,
    pembayaran_id INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transaksi_kasir      FOREIGN KEY (kasir_id)      REFERENCES users (id),
    CONSTRAINT fk_transaksi_diskon     FOREIGN KEY (diskon_id)     REFERENCES diskon (id),
    CONSTRAINT fk_transaksi_pembayaran FOREIGN KEY (pembayaran_id) REFERENCES pembayaran (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Item per transaksi (composition: ikut terhapus jika transaksi dihapus).
CREATE TABLE item_transaksi (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    transaksi_id INT NOT NULL,
    produk_id    INT NOT NULL,
    qty          INT NOT NULL,
    subtotal     DECIMAL(12,2) NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_item_transaksi FOREIGN KEY (transaksi_id) REFERENCES transaksi (id) ON DELETE CASCADE,
    CONSTRAINT fk_item_produk    FOREIGN KEY (produk_id)    REFERENCES produk (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan retur barang ke supplier.
CREATE TABLE retur_barang (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    tanggal     DATETIME NOT NULL,
    produk_id   INT NOT NULL,
    supplier_id INT NOT NULL,
    qty         INT NOT NULL,
    alasan      TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_retur_produk   FOREIGN KEY (produk_id)   REFERENCES produk (id),
    CONSTRAINT fk_retur_supplier FOREIGN KEY (supplier_id) REFERENCES supplier (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
