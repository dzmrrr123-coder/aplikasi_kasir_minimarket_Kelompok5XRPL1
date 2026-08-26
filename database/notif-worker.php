<?php

declare(strict_types=1);

/**
 * Worker CLI: kirim / retry notifikasi WA (transaksi) ke webhook n8n.
 *
 * Jalankan di dev box secara foreground:
 *   php database/notif-worker.php            # loop (sleep 3s tiap idle)
 *   php database/notif-worker.php --once     # satu pass lalu keluar (cocok Task
 *                                            # Scheduler: tiap menit sekal
 *
 * Di Windows, jadwalkan `php database/notif-worker.php --once` via
 * Task Scheduler (set trigger tiap 1–2 menit). Di Linux/Unix pakai cron:
 *   * * * * * php /path/to/database/notif-worker.php --once
 *
 * NOTE: worker ini bersifat "best-effort" — NotifikasiAntrian::proses() tidak
 * pernah throw, jadi worker takkan pernah crash hard.
 */

require __DIR__ . '/../src/autoload.php';

use App\Models\NotifikasiAntrian;

$once = in_array('--once', $argv ?: [], true);
$jeda = 3;

echo '[' . date('H:i:s') . '] Worker notifikasi WA (n8n) — mode '
    . ($once ? 'once' : 'loop') . "\n";

while (true) {
    try {
        $r = NotifikasiAntrian::proses(20, 5);
        $ada = ($r['sent'] + $r['failed']) > 0;
        echo '[' . date('H:i:s') . '] ' . ($ada ? 'proses: ' : 'idle')
            . "sent={$r['sent']} failed={$r['failed']}\n";
    } catch (\Throwable $e) {
        // Harusnya tidak terjadi (proses() best-effort), tapi jaga agar worker
        // tetap bangun & coba lagi.
        fwrite(STDERR, '[' . date('H:i:s') . '] error worker: ' . $e->getMessage() . "\n");
    }

    if ($once) {
        break;
    }

    // Idle = tidak ada yang dikirim/diperbarui; turun tidur sejenak agar
    // tidak spin CPU. Jika ada aktivitas, cek lagi langsung (flush cepat).
    sleep($jeda);
}
