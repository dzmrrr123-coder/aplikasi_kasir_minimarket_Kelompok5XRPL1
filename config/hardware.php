<?php

declare(strict_types=1);

/**
 * Konfigurasi perangkat keras (Web Serial).
 * Disesuaikan dengan merek timbangan & printer thermal yang dipakai.
 */

return [
    'timbangan' => [
        'baudRate' => 9600,
        'dataBits' => 8,
        'stopBits' => 1,
        'parity'   => 'none',
        // 'default': regex berat dari baris teks (mendukung format ST/GS, S, =)
        'parser'   => 'default',
    ],
    'printer' => [
        'baudRate' => 9600,
        'dataBits' => 8,
        'stopBits' => 1,
        'parity'   => 'none',
        // charset: 'cp437' | 'utf8' — encoding teks struk ke ESC/POS
        'charset'  => 'utf8',
    ],
];
