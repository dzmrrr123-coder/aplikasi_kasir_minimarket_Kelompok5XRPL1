<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditLog;
use App\Models\ShiftKasir;

/**
 * Controller Shift & Audit: data tabel shift kasir dan audit log.
 */
class ShiftController
{
    /**
     * Data ringkasan shift aktif untuk modal tutup kas: modal awal, total
     * penjualan, uang seharusnya di laci, dan daftar transaksi shift.
     *
     * @param array<string, mixed> $params
     */
    public function ringkasanShift(array $params = []): array
    {
        // Kalau kasir_id tidak dikirim (dari halaman POS), ambil dari sesi.
        $kasirId = (int) ($params['kasir_id'] ?? 0);
        if ($kasirId <= 0 && session_status() === PHP_SESSION_ACTIVE) {
            $kasirId = (int) ($_SESSION['user_id'] ?? 0);
        }

        $shift = ShiftKasir::shiftAktif($kasirId);

        if ($shift === null) {
            return ['ada' => false];
        }

        $total = $shift->totalPenjualanShift();

        return [
            'ada'            => true,
            'dibuka_pada'    => $shift->getDibukaPada(),
            'modal_awal'     => $shift->getModalAwal(),
            'total_penjualan' => $total,
            'uang_seharusnya' => round($shift->getModalAwal() + $total, 2),
            'riwayat'        => $shift->riwayatTransaksi(),
        ];
    }

    /**
     * Data tabel riwayat shift (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelShift(array $params = []): array
    {
        return (new ShiftKasir())->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }

    /**
     * Data tabel audit log (DataTables server-side).
     *
     * @param array<string, mixed> $params
     */
    public function dataTabelAudit(array $params = []): array
    {
        return (new AuditLog())->getDataTabel([
            'search' => $params['search'] ?? '',
            'start'  => $params['start'] ?? 0,
            'length' => $params['length'] ?? 10,
        ]);
    }
}
