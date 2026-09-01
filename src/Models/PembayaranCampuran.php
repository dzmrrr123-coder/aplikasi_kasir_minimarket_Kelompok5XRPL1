<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Strategi pembayaran campuran (Tunai + Non-Tunai / QRIS).
 *
 * Aturan: jumlah tunai + jumlah non tunai harus menutupi total transaksi.
 * Kembalian hanya dihitung dari jumlah tunai (kalau jumlah tunai > bagian yang kurang).
 */
class PembayaranCampuran extends Pembayaran
{
    private float $jumlahTunai = 0.0;
    private float $jumlahNonTunai = 0.0;

    public function __construct(array $data = [])
    {
        parent::__construct($data);
        if (isset($data['jumlah_tunai'])) {
            $this->jumlahTunai = (float) $data['jumlah_tunai'];
        }
        if (isset($data['jumlah_non_tunai'])) {
            $this->jumlahNonTunai = (float) $data['jumlah_non_tunai'];
        }
        $this->setJumlah($this->jumlahTunai + $this->jumlahNonTunai);
    }

    protected function getJenis(): string
    {
        return 'campuran';
    }

    public function getJumlahTunai(): float
    {
        return $this->jumlahTunai;
    }

    public function getJumlahNonTunai(): float
    {
        return $this->jumlahNonTunai;
    }

    public function prosesBayar(float $total, float $jumlahBayar): bool
    {
        return ($this->jumlahTunai + $this->jumlahNonTunai) >= $total;
    }

    public function getNamaMetode(): string
    {
        return 'Campuran';
    }

    public function hitungKembalian(float $total, float $jumlahBayar): float
    {
        // Kembalian hanya diberikan jika total uang yang diterima melebihi total tagihan.
        return max(0.0, ($this->jumlahTunai + $this->jumlahNonTunai) - $total);
    }

    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO pembayaran (jenis, jumlah, jumlah_tunai, jumlah_non_tunai) VALUES (:jenis, :jumlah, :jumlah_tunai, :jumlah_non_tunai)'
        );
        $stmt->execute([
            ':jenis'            => $this->getJenis(),
            ':jumlah'           => $this->getJumlah(),
            ':jumlah_tunai'     => $this->jumlahTunai,
            ':jumlah_non_tunai' => $this->jumlahNonTunai,
        ]);

        $id = (int) $pdo->lastInsertId();
        $this->setId((string) $id);

        return $id;
    }
}
