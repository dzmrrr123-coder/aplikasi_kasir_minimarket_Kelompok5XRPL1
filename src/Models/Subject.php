<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Observer Pattern: kontrak objek yang bisa diamati (subject).
 * Observer didaftarkan lewat attach() dan diberi tahu lewat notify().
 */
interface Subject
{
    public function attach(Observer $observer): void;

    public function detach(Observer $observer): void;

    public function notify(): void;
}
