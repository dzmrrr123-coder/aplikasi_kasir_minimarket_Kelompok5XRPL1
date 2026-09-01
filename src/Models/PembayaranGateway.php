<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * Strategi pembayaran via Payment Gateway (QRIS / E-Wallet / VA / Card).
 *
 * Mengextend Pembayaran untuk menyimpan data gateway-specific
 * (gateway_order_id, payment_url, status, dll) ke database.
 *
 * Mendukung: Midtrans, Xendit, dll.
 */
class PembayaranGateway extends Pembayaran
{
    private string $gatewayName = '';
    private string $gatewayOrderId = '';
    private string $gatewayPaymentUrl = '';
    private string $gatewayStatus = 'pending';
    private string $paymentMethod = ''; // qris, gopay, ovo, bca_va, etc.

    public function __construct(array $data = [])
    {
        parent::__construct($data);

        if (isset($data['gateway_name'])) {
            $this->gatewayName = $data['gateway_name'];
        }
        if (isset($data['gateway_order_id'])) {
            $this->gatewayOrderId = $data['gateway_order_id'];
        }
        if (isset($data['gateway_payment_url'])) {
            $this->gatewayPaymentUrl = $data['gateway_payment_url'];
        }
        if (isset($data['gateway_status'])) {
            $this->gatewayStatus = $data['gateway_status'];
        }
        if (isset($data['payment_method'])) {
            $this->paymentMethod = $data['payment_method'];
        }
    }

    protected function getJenis(): string
    {
        return 'gateway';
    }

    public function prosesBayar(float $total, float $jumlahBayar): bool
    {
        // Gateway payment: amount harus sesuai (tidak lebih, tidak kurang)
        return $jumlahBayar > 0 && abs($jumlahBayar - $total) < 1.0;
    }

    public function getNamaMetode(): string
    {
        $labels = [
            'qris' => 'QRIS',
            'gopay' => 'GoPay',
            'ovo' => 'OVO',
            'dana' => 'Dana',
            'shopeepay' => 'ShopeePay',
            'bca_va' => 'BCA Virtual Account',
            'mandiri_va' => 'Mandiri Virtual Account',
            'bri_va' => 'BRI Virtual Account',
            'bni_va' => 'BNI Virtual Account',
            'credit_card' => 'Kartu Kredit/Debit',
        ];

        return $labels[$this->paymentMethod] ?? 'Online Payment';
    }

    public function hitungKembalian(float $total, float $jumlahBayar): float
    {
        return 0.0; // Gateway tidak ada kembalian
    }

    // --- Gateway-specific methods ---

    public function getGatewayName(): string
    {
        return $this->gatewayName;
    }

    public function setGatewayName(string $name): void
    {
        $this->gatewayName = $name;
    }

    public function getGatewayOrderId(): string
    {
        return $this->gatewayOrderId;
    }

    public function setGatewayOrderId(string $id): void
    {
        $this->gatewayOrderId = $id;
    }

    public function getGatewayPaymentUrl(): string
    {
        return $this->gatewayPaymentUrl;
    }

    public function setGatewayPaymentUrl(string $url): void
    {
        $this->gatewayPaymentUrl = $url;
    }

    public function getGatewayStatus(): string
    {
        return $this->gatewayStatus;
    }

    public function setGatewayStatus(string $status): void
    {
        $this->gatewayStatus = $status;
    }

    public function getPaymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function setPaymentMethod(string $method): void
    {
        $this->paymentMethod = $method;
    }

    /**
     * Simpan pembayaran gateway ke database.
     * Extended untuk menyimpan kolom tambahan.
     */
    public function simpan(): int
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO pembayaran (jenis, jumlah) VALUES (:jenis, :jumlah)'
        );
        $stmt->execute([
            ':jenis'  => $this->getJenis(),
            ':jumlah' => $this->getJumlah(),
        ]);

        $id = (int) $pdo->lastInsertId();
        $this->setId((string) $id);

        // Store gateway-specific data in a separate table or JSON
        // For now, store in a JSON column approach via pembayaran_gateway table
        $this->saveGatewayData($id);

        return $id;
    }

    /**
     * Simpan data gateway ke tabel pembayaran_gateway.
     */
    private function saveGatewayData(int $pembayaranId): void
    {
        try {
            $pdo = Database::connect();

            // Create table if not exists
            $pdo->exec('CREATE TABLE IF NOT EXISTS pembayaran_gateway (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pembayaran_id INT UNSIGNED NOT NULL,
                gateway_name VARCHAR(50) NOT NULL,
                gateway_order_id VARCHAR(100) NOT NULL,
                gateway_payment_url TEXT,
                gateway_status VARCHAR(30) NOT NULL DEFAULT \'pending\',
                payment_method VARCHAR(50) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (pembayaran_id) REFERENCES pembayaran(id) ON DELETE CASCADE
            ) ENGINE=InnoDB');

            $stmt = $pdo->prepare(
                'INSERT INTO pembayaran_gateway
                (pembayaran_id, gateway_name, gateway_order_id, gateway_payment_url, gateway_status, payment_method)
                VALUES (:pid, :gw, :oid, :url, :status, :method)'
            );
            $stmt->execute([
                ':pid'    => $pembayaranId,
                ':gw'     => $this->gatewayName,
                ':oid'    => $this->gatewayOrderId,
                ':url'    => $this->gatewayPaymentUrl,
                ':status' => $this->gatewayStatus,
                ':method' => $this->paymentMethod,
            ]);
        } catch (\Throwable $e) {
            error_log('PembayaranGateway save failed: ' . $e->getMessage());
        }
    }

    /**
     * Update status pembayaran gateway.
     */
    public static function updateStatus(string $gatewayOrderId, string $status): bool
    {
        try {
            $pdo = Database::connect();
            $stmt = $pdo->prepare(
                'UPDATE pembayaran_gateway SET gateway_status = :status WHERE gateway_order_id = :oid'
            );
            return $stmt->execute([':status' => $status, ':oid' => $gatewayOrderId]);
        } catch (\Throwable $e) {
            error_log('PembayaranGateway updateStatus failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Simpan data gateway langsung (tanpa relasi ke tabel pembayaran).
     * Dipakai oleh API endpoint untuk tracking transaksi gateway.
     */
    public static function simpanData(array $data): bool
    {
        try {
            $pdo = Database::connect();
            $pdo->exec('CREATE TABLE IF NOT EXISTS pembayaran_gateway (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                pembayaran_id INT UNSIGNED DEFAULT NULL,
                gateway VARCHAR(50) NOT NULL DEFAULT \'midtrans\',
                gateway_order_id VARCHAR(100) NOT NULL,
                jumlah DECIMAL(15,2) NOT NULL DEFAULT 0,
                metode VARCHAR(50) NOT NULL DEFAULT \'qris\',
                gateway_status VARCHAR(30) NOT NULL DEFAULT \'pending\',
                response_json TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_gateway_order (gateway_order_id)
            ) ENGINE=InnoDB');

            $stmt = $pdo->prepare(
                'INSERT INTO pembayaran_gateway
                (gateway, gateway_order_id, jumlah, metode, gateway_status, response_json)
                VALUES (:gw, :oid, :jumlah, :metode, :status, :resp)
                ON DUPLICATE KEY UPDATE
                    gateway_status = VALUES(gateway_status),
                    response_json = VALUES(response_json),
                    updated_at = NOW()'
            );
            return $stmt->execute([
                ':gw'     => $data['gateway'] ?? 'midtrans',
                ':oid'    => $data['gateway_order_id'] ?? '',
                ':jumlah' => $data['jumlah'] ?? 0,
                ':metode' => $data['metode'] ?? 'qris',
                ':status' => $data['gateway_status'] ?? 'pending',
                ':resp'   => $data['response_json'] ?? '{}',
            ]);
        } catch (\Throwable $e) {
            error_log('PembayaranGateway::simpanData failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Cari pembayaran gateway berdasarkan gateway_order_id.
     *
     * @return array|null
     */
    public static function findByGatewayOrderId(string $gatewayOrderId): ?array
    {
        try {
            $pdo = Database::connect();
            // Try with JOIN first
            $stmt = $pdo->prepare(
                'SELECT pg.*
                 FROM pembayaran_gateway pg
                 WHERE pg.gateway_order_id = :oid LIMIT 1'
            );
            $stmt->execute([':oid' => $gatewayOrderId]);
            return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
