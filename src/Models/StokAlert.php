<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

/**
 * StokAlert: notifikasi otomatis ke WhatsApp saat stok produk menipis.
 *
 * Alur:
 *   1. Setelah transaksi selesai, cek semua produk di bawah stok_minimum.
 *   2. Filter produk yang belum pernah di-alert hari ini (anti-spam).
 *   3. Kirim payload ke n8n webhook (sama seperti NotifikasiWhatsApp).
 *   4. Catat di notifikasi_stok supaya tidak double-alert.
 *
 * Fitur OFF bila Pengaturan 'wa_webhook_url' kosong.
 */
class StokAlert
{
    /**
     * Cek & kirim alert stok menipis untuk semua produk.
     * Dipanggil setelah transaksi selesai (via observer atau manual).
     *
     * @return int Jumlah produk yang di-alert
     */
    public static function cekDanKirimAlert(): int
    {
        // Cek apakah fitur stok alert diaktifkan
        if (Pengaturan::get('stok_alert_enabled', '1') !== '1') {
            return 0;
        }

        $webhookUrl = Pengaturan::get('wa_webhook_url', '');
        if ($webhookUrl === '') {
            return 0; // Fitur dimatikan
        }

        $nomorTujuan = Pengaturan::get('wa_tujuan_nomor', '');
        if ($nomorTujuan === '') {
            return 0;
        }

        // Ambil produk yang stoknya di bawah minimum
        $produkMenipis = Produk::cariStokMenipis();
        if ($produkMenipis === []) {
            return 0;
        }

        $pdo = Database::connect();
        $today = date('Y-m-d');
        $alertCount = 0;

        foreach ($produkMenipis as $produk) {
            $produkId = (int) $produk->getId();

            // Cek apakah sudah di-alert hari ini (anti-spam)
            $cek = $pdo->prepare(
                'SELECT COUNT(*) FROM notifikasi_stok
                 WHERE produk_id = :pid AND DATE(dibuat_pada) = :today AND status != :failed'
            );
            $cek->execute([':pid' => $produkId, ':today' => $today, ':failed' => 'failed']);

            if ((int) $cek->fetchColumn() > 0) {
                continue; // Sudah di-alert hari ini
            }

            // Kirim alert
            $payload = self::buatPayload($produk);
            $sukses = self::kirimKeWebhook($webhookUrl, $payload);

            // Catat di notifikasi_stok
            $stmt = $pdo->prepare(
                'INSERT INTO notifikasi_stok (produk_id, webhook_url, nomor_tujuan, payload, status)
                 VALUES (:pid, :webhook, :nomor, :payload, :status)'
            );
            $stmt->execute([
                ':pid'     => $produkId,
                ':webhook' => $webhookUrl,
                ':nomor'   => $nomorTujuan,
                ':payload' => json_encode($payload),
                ':status'  => $sukses ? 'sent' : 'failed',
            ]);

            if ($sukses) {
                $alertCount++;
            }
        }

        return $alertCount;
    }

    /**
     * Kirim alert untuk satu produk spesifik.
     */
    public static function kirimAlertProduk(Produk $produk): bool
    {
        $webhookUrl = Pengaturan::get('wa_webhook_url', '');
        if ($webhookUrl === '') {
            return false;
        }

        $nomorTujuan = Pengaturan::get('wa_tujuan_nomor', '');
        if ($nomorTujuan === '') {
            return false;
        }

        $payload = self::buatPayload($produk);
        $sukses = self::kirimKeWebhook($webhookUrl, $payload);

        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            'INSERT INTO notifikasi_stok (produk_id, webhook_url, nomor_tujuan, payload, status)
             VALUES (:pid, :webhook, :nomor, :payload, :status)'
        );
        $stmt->execute([
            ':pid'     => (int) $produk->getId(),
            ':webhook' => $webhookUrl,
            ':nomor'   => $nomorTujuan,
            ':payload' => json_encode($payload),
            ':status'  => $sukses ? 'sent' : 'failed',
        ]);

        return $sukses;
    }

    /**
     * Buat payload WhatsApp untuk alert stok menipis.
     */
    private static function buatPayload(Produk $produk): array
    {
        $namaToko = Pengaturan::get('nama_toko', 'Minimarket');
        $stokMin = $produk->getStokMinimum();
        $stokSaatIni = $produk->getStok();
        $satuan = $produk->getSatuan() === 'gram' ? 'gram' : 'pcs';

        $pesan = "⚠️ *STOK MENIPIS*\n\n";
        $pesan .= "Toko: *{$namaToko}*\n";
        $pesan .= "Produk: *{$produk->getNama()}*\n";
        $pesan .= "Stok saat ini: *{$stokSaatIni} {$satuan}*\n";
        $pesan .= "Stok minimum: {$stokMin} {$satuan}\n";

        if ($stokSaatIni <= 0) {
            $pesan .= "\n🔴 *STOK HABIS — Perlu restock segera!*";
        } else {
            $pesan .= "\n🟡 *Segera lakukan restok.*";
        }

        if ($produk->getBarcode() !== '') {
            $pesan .= "\nBarcode: {$produk->getBarcode()}";
        }

        return [
            'type'    => 'stok_alert',
            'nomor'   => Pengaturan::get('wa_tujuan_nomor', ''),
            'pesan'   => $pesan,
            'produk'  => [
                'id'     => (int) $produk->getId(),
                'nama'   => $produk->getNama(),
                'stok'   => $stokSaatIni,
                'minimum' => $stokMin,
                'satuan' => $satuan,
                'barcode' => $produk->getBarcode(),
            ],
            'toko'    => $namaToko,
            'timestamp' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Kirim payload ke n8n webhook.
     */
    private static function kirimKeWebhook(string $url, array $payload): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $response !== false && $httpCode >= 200 && $httpCode < 300;
    }

    /**
     * Daftar riwayat alert stok (untuk admin panel).
     *
     * @return array<int, array{id:int, produk_nama:string, stok:int, status:string, dibuat_pada:string}>
     */
    public static function riwayat(int $limit = 50): array
    {
        $stmt = Database::connect()->prepare(
            'SELECT ns.id, p.nama AS produk_nama, p.stok, ns.status, ns.dibuat_pada
             FROM notifikasi_stok ns
             JOIN produk p ON p.id = ns.produk_id
             ORDER BY ns.dibuat_pada DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
