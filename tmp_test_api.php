<?php
// Test dashboard API flow: login -> check API -> logout -> login -> check API
$cookieFile = tempnam(sys_get_temp_dir(), 'kasir_cookies');

function curlRequest($ch, $url, $post = null) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    return curl_exec($ch);
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// Step 1: GET login page to get CSRF token
$html = curlRequest($ch, 'http://127.0.0.1:8080/login.php');
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
echo "CSRF: $csrf\n";

// Step 2: Login as admin
$resp = curlRequest($ch, 'http://127.0.0.1:8080/login.php', [
    'username' => 'admin',
    'password' => 'admin123',
    'csrf' => $csrf,
]);
echo "Login response code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";

// Step 3: Test dashboard API endpoints
$endpoints = [
    'dashboard.ringkasan',
    'dashboard.grafik',
    'dashboard.stok_kategori',
    'dashboard.terlaris',
    'dashboard.transaksi',
];

echo "\n--- AFTER FIRST LOGIN ---\n";
foreach ($endpoints as $aksi) {
    // Get fresh CSRF for the API call (api.php doesn't require CSRF for GET)
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch2, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_URL, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    $resp = curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    echo "  $aksi: HTTP $code => " . substr($resp, 0, 200) . "\n";
    curl_close($ch2);
}

// Step 4: Logout (POST to dashboard.php with aksi=logout)
// Need to GET dashboard first to get a fresh CSRF
$resp = curlRequest($ch, 'http://127.0.0.1:8080/dashboard.php');
preg_match('/name="csrf" value="([^"]+)"/', $resp, $m);
$csrf2 = $m[1] ?? '';
echo "\nCSRF2: $csrf2\n";

curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8080/dashboard.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['aksi' => 'logout', 'csrf' => $csrf2]));
$logoutResp = curl_exec($ch);
echo "Logout: HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";

// Reset for next request
curl_setopt($ch, CURLOPT_POST, false);
curl_setopt($ch, CURLOPT_HTTPGET, true);

// Step 5: Login again
$resp = curlRequest($ch, 'http://127.0.0.1:8080/login.php');
preg_match('/name="csrf" value="([^"]+)"/', $resp, $m);
$csrf3 = $m[1] ?? '';
echo "CSRF3: $csrf3\n";

$resp = curlRequest($ch, 'http://127.0.0.1:8080/login.php', [
    'username' => 'admin',
    'password' => 'admin123',
    'csrf' => $csrf3,
]);
echo "Login2 response code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";

// Step 6: Test dashboard API endpoints again
echo "\n--- AFTER SECOND LOGIN ---\n";
foreach ($endpoints as $aksi) {
    $ch2 = curl_init();
    curl_setopt($ch2, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch2, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch2, CURLOPT_URL, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    $resp = curl_exec($ch2);
    $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    echo "  $aksi: HTTP $code => " . substr($resp, 0, 200) . "\n";
    curl_close($ch2);
}

curl_close($ch);
unlink($cookieFile);
echo "\nDone.\n";
