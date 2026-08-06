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
