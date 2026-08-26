<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$ch = curl_init();
$cookieFile = __DIR__ . '/tmp_cookies.txt';

// Step 1: GET login page
curl_setopt($ch, CURLOPT_URL, 'http://localhost/kasir-minimarket/public/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
echo 'Login page status: ' . $info['http_code'] . "\n";

// Extract CSRF token
preg_match('/name="csrf" value="([^"]+)"/', $resp, $m);
$csrf = $m[1] ?? '';
echo 'CSRF token: ' . substr($csrf, 0, 20) . '...\n';

// Step 2: POST login
curl_setopt($ch, CURLOPT_URL, 'http://localhost/kasir-minimarket/public/login.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, 'username=admin&password=admin123&csrf=' . $csrf);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$resp2 = curl_exec($ch);
$info2 = curl_getinfo($ch);
echo 'Login POST status: ' . $info2['http_code'] . "\n";
echo 'Login redirect: ' . ($info2['redirect_url'] ?? 'none') . "\n";

// Step 3: GET admin dashboard
curl_setopt($ch, CURLOPT_URL, 'http://localhost/kasir-minimarket/public/admin/dashboard.php');
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
$resp3 = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "Dashboard status: $code\n";
echo 'Dashboard body length: ' . strlen($resp3) . " bytes\n";

preg_match('/<title>([^<]*)<\/title>/', $resp3, $t);
echo 'Title: ' . ($t[1] ?? 'NOT FOUND') . "\n";
echo 'Has Dashboard heading: ' . (strpos($resp3, 'Dashboard') !== false ? 'yes' : 'no') . "\n";
echo 'Has sidebar: ' . (strpos($resp3, 'sidebar-admin') !== false ? 'yes' : 'no') . "\n";
echo 'Has stok menipis alert: ' . (strpos($resp3, 'Rokok') !== false ? 'yes' : 'no') . "\n";
echo 'Has total produk (6): ' . (strpos($resp3, '>6<') !== false ? 'yes' : 'no') . "\n";

// Show first 200 chars of body for debugging
echo 'Body start: ' . substr($resp3, 0, 300) . "\n";

curl_close($ch);
unlink($cookieFile);
