<?php
$cookieFile = sys_get_temp_dir() . '/kasir_cookies.txt';
@unlink($cookieFile);

function curlApi($ch, $url, $method = 'GET', $post = null) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($resp, $headerLen);
    return [$code, $body, substr($resp, 0, $headerLen)];
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);

// 1. GET login page for CSRF
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/login.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf = $m[1] ?? '';
echo "1. GET login.php: HTTP $code, CSRF=$csrf\n";

// 2. POST login
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/login.php', 'POST', [
    'username' => 'admin',
    'password' => 'admin123',
    'csrf' => $csrf,
]);
echo "2. POST login: HTTP $code\n";
echo "   Location header: " . (preg_match('/location:(.*)/i', $headers, $m2) ? trim($m2[1]) : 'none') . "\n";

// 3. GET dashboard to get CSRF for logout
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/dashboard.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf2 = $m[1] ?? '';
echo "3. GET dashboard.php: HTTP $code, CSRF=$csrf2, has_stat_total: " . (strpos($body, 'stat-total') !== false ? 'yes' : 'no') . "\n";

// 4. Test API endpoints
$endpoints = ['dashboard.ringkasan', 'dashboard.grafik', 'dashboard.stok_kategori', 'dashboard.terlaris', 'dashboard.transaksi'];
echo "\n--- API TESTS (after login) ---\n";
foreach ($endpoints as $aksi) {
    list($code, $body, $headers) = curlApi($ch, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    echo "  $aksi: HTTP $code => " . substr($body, 0, 200) . "\n";
}

// 5. Logout
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/dashboard.php', 'POST', [
    'aksi' => 'logout',
    'csrf' => $csrf2,
]);
echo "\n5. POST logout: HTTP $code\n";

// 6. GET login page again for new CSRF
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/login.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf3 = $m[1] ?? '';
echo "6. GET login.php (after logout): HTTP $code, CSRF=$csrf3\n";

// 7. Login again
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/login.php', 'POST', [
    'username' => 'admin',
    'password' => 'admin123',
    'csrf' => $csrf3,
]);
echo "7. POST login again: HTTP $code\n";

// 8. GET dashboard for CSRF
list($code, $body, $headers) = curlApi($ch, 'http://127.0.0.1:8080/dashboard.php');
preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
$csrf4 = $m[1] ?? '';
echo "8. GET dashboard.php (after re-login): HTTP $code, CSRF=$csrf4\n";

// 9. Test API endpoints again
echo "\n--- API TESTS (after re-login) ---\n";
foreach ($endpoints as $aksi) {
    list($code, $body, $headers) = curlApi($ch, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    echo "  $aksi: HTTP $code => " . substr($body, 0, 200) . "\n";
}

// Show cookie file
echo "\n--- COOKIE FILE ---\n";
echo file_get_contents($cookieFile) ?: '(empty)';

curl_close($ch);
echo "\nDone.\n";
