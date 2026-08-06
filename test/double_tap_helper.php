<?php

declare(strict_types=1);

/**
 * Helper untuk test e2e: simulasi double-tap tombol "Bayar" di transaksi.php.
 *
 * Dipanggil dari test/e2e.php via subprocess:
 *   php test/double_tap_helper.php tap1 <session_id> <produk_id> <harga>
 *   php test/double_tap_helper.php tap2 <session_id>
 *
 * - tap1: isi keranjang session lalu submit aksi 'bayar' (harus sukses).
 * - tap2: pakai SESI YANG SAMA tapi keranjang sudah kosong -> guard
 *   "Keranjang masih kosong" harus menolak (mencegah pembayaran kedua).
 *
 * Output: JSON respons dari transaksi.php (sama seperti yang diterima browser).
 */

require __DIR__ . '/../src/autoload.php';

$mode = (string) ($argv[1] ?? '');
$sesi = (string) ($argv[2] ?? 'e2etap' . bin2hex(random_bytes(4)));

if ($sesi === '' || !preg_match('/^[A-Za-z0-9,-]+$/', $sesi)) {
    $sesi = 'e2etap' . bin2hex(random_bytes(4));
}

session_id($sesi);
session_start();

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';
$_POST['csrf'] = 'e2e-token';
$_SESSION['csrf_token'] = 'e2e-token';

if ($mode === 'tap1') {
    $produkId = (int) ($argv[3] ?? 0);
    $harga = (float) ($argv[4] ?? 0);

    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'kasir';
    $_SESSION['nama'] = 'Kasir Uji';
    $_SESSION['keranjang'] = [[
        'produk_id' => $produkId,
        'nama'      => 'Produk Uji',
        'harga'     => $harga,
        'qty'       => 1,
        'stok'      => 100,
        'subtotal'  => $harga,
    ]];
    $_POST['aksi'] = 'bayar';
    $_POST['metode'] = 'tunai';
    $_POST['jumlah_dibayar'] = 100000;
} elseif ($mode === 'tap2') {
    $_SESSION['user_id'] = 1;
    $_SESSION['role'] = 'kasir';
    $_SESSION['nama'] = 'Kasir Uji';
    // Keranjang TIDAK diisi — meniru tap 2 yang datang setelah tap 1
    // (server sudah mengosongkan keranjang).
    $_POST['aksi'] = 'bayar';
    $_POST['metode'] = 'tunai';
    $_POST['jumlah_dibayar'] = 100000;
} else {
    echo json_encode(['error' => 'mode tidak dikenal']);
    exit;
}

require __DIR__ . '/../public/transaksi.php';
