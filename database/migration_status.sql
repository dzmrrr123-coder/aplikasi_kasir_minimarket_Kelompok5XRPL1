-- database/migration_status.sql
-- Migrasi langkah 4: kolom status pada transaksi, dibutuhkan oleh Transaksi::batalkan().
-- (Tidak disebutkan eksplisit di skema awal spec bagian 6, ditambahkan sekarang.)

USE kasir_minimarket;

ALTER TABLE transaksi
    ADD COLUMN status ENUM('pending', 'selesai', 'batal') NOT NULL DEFAULT 'pending' AFTER total;
