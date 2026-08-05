<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Observer Pattern: kontrak objek yang ingin diberi tahu saat
 * Subject (mis. Transaksi) menyelesaikan sebuah proses.
 */
interface Observer
{
    /**
     * Dipanggil oleh Subject saat notify().
     */
    public function update(Subject $subject): void;
}
