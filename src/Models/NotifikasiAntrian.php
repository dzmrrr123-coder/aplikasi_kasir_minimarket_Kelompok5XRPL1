<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Database\Database;
use App\Util\Http;

/**
 * Processor / outbox flusher untuk notifikasi WA (n8n).
 *
 * Membaca baris notifikasi_queue dengan status 'pending', mengirim payload ke
 * webhook_url tiap baris lewat HTTP POST, lalu mengubah statusnya jadi
 * 'sent' atau 'failed' (+ peningkatan upaya bila gagal).
 *
 * Dipanggil:
 *   - POST-commit di aksiBayar() (flush cepat setelah transaksi tersimpan).
 *   - database/notif-worker.php (retry baris yang gagal pada jalan).
 *
 * Selalu best-effort: tidak pernah melempar exception ke caller, biar tidak
 * bisa mengganggu alur penjualan kasir.
 */
class NotifikasiAntrian
{
    /**
     * Kirim semua notifikasi pending (terbatas $batch).
     *
     * @param int $batch      maks jumlah baris diproses sekaligus.
     * @param int $maksUpaya  baris dengan upaya >= nilai ini dilewati (sudah retali berulang).
     * @param int $timeoutMs  timeout HTTP per permintaan.
     *
     * @return array{sent:int, failed:int}
     */
    public static function proses(
        int $batch = 10,
        int $maksUpaya = 5,
        int $timeoutMs = 5000
    ): array {
        $sent = 0;
        $failed = 0;

        $rows = self::ambilPending($batch, $maksUpaya);

        if ($rows === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $pdo = Database::connect();

        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);

            if (!is_array($payload)) {
                $payload = [];
            }

            // Paksa tujuan pakai snapshot di kolom (jaga drift konfig).
            $payload['tujuan'] = (string) $row['nomor_tujuan'];

            [$code, , $error] = Http::post(
                (string) $row['webhook_url'],
                $payload,
                $timeoutMs
            );

            if ($error !== null) {
                $error = "HTTP error: $error";
            }

            if ($code === 200) {
                self::tandaiTerkirim($pdo, (int) $row['id']);
                $sent++;
            } else {
                self::tandaiGagal($pdo, (int) $row['id'], $code, $error ?? ('HTTP ' . $code));
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * @return array<int, array{id:int, transaksi_id:int, webhook_url:string,
     *                           nomor_tujuan:string, payload:string}>
     */
    private static function ambilPending(int $batch, int $maksUpaya): array
    {
        $pdo = Database::connect();
        $stmt = $pdo->prepare(
            "SELECT id, transaksi_id, webhook_url, nomor_tujuan, payload
               FROM notifikasi_queue
              WHERE status IN ('pending','failed') AND upaya < :maks
              ORDER BY upaya ASC, dibuat_pada ASC
              LIMIT :batch"
        );

        // LIMIT/OFFSET dan batasan upaya harus integer (bind via bindValue).
        $stmt->bindValue(':maks', $maksUpaya, PDO::PARAM_INT);
        $stmt->bindValue(':batch', $batch, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll() ?: [];
    }

    private static function tandaiTerkirim(PDO $pdo, int $id): void
    {
        $stmt = $pdo->prepare(
            "UPDATE notifikasi_queue
                SET status = 'sent',
                    dikirim_pada = NOW(),
                    error = NULL
              WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    private static function tandaiGagal(PDO $pdo, int $id, int $kodeHttp, string $error): void
    {
        $stmt = $pdo->prepare(
            "UPDATE notifikasi_queue
                SET status = 'failed',
                    upaya = upaya + 1,
                    error = :error
              WHERE id = :id"
        );
        $stmt->execute([
            ':id'    => $id,
            ':error' => substr($error, 0, 2000),
        ]);
    }
}
