<?php
// Test full flow: login -> dashboard -> logout.php -> login -> dashboard
$cookieFile = sys_get_temp_dir() . '/kasir_test_cookies.txt';
@unlink($cookieFile);

function req($ch, $url, $post = null) {
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, false);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [$code, substr($resp, $hlen), substr($resp, 0, $hlen)];
}

function getCsrf($html) {
    if (preg_match('/name="csrf" value="([^"]+)"/', $html, $m)) return $m[1];
    return '';
}

$ch = curl_init();
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

// 1. GET login page
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/login.php');
$csrf = getCsrf($b);
echo "1. GET login.php: $c, CSRF=" . substr($csrf, 0, 8) . "...\n";

// 2. Login as admin
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $csrf]);
echo "2. POST login: $c\n";

// 3. GET dashboard
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/dashboard.php');
echo "3. GET dashboard: $c, has stat-total: " . (strpos($b, 'stat-total') !== false ? 'yes' : 'no') . "\n";

// 4. Test all API endpoints
echo "\n--- API after login ---\n";
foreach (['dashboard.ringkasan', 'dashboard.grafik', 'dashboard.stok_kategori', 'dashboard.terlaris', 'dashboard.transaksi'] as $aksi) {
    list($c, $b, $h) = req($ch, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    echo "  $aksi: $c => " . substr($b, 0, 150) . "\n";
}

// 5. Logout via logout.php (GET)
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/logout.php');
echo "\n5. GET logout.php: $c\n";

// 6. GET login page again
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/login.php');
$csrf2 = getCsrf($b);
echo "6. GET login.php: $c, CSRF=" . substr($csrf2, 0, 8) . "...\n";

// 7. Login again
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/login.php', ['username' => 'admin', 'password' => 'admin123', 'csrf' => $csrf2]);
echo "7. POST login again: $c\n";

// 8. GET dashboard
list($c, $b, $h) = req($ch, 'http://127.0.0.1:8080/dashboard.php');
echo "8. GET dashboard after re-login: $c\n";

// 9. Test all API endpoints again
echo "\n--- API after re-login ---\n";
foreach (['dashboard.ringkasan', 'dashboard.grafik', 'dashboard.stok_kategori', 'dashboard.terlaris', 'dashboard.transaksi'] as $aksi) {
    list($c, $b, $h) = req($ch, "http://127.0.0.1:8080/api.php?aksi=$aksi");
    echo "  $aksi: $c => " . substr($b, 0, 150) . "\n";
}

curl_close($ch);
echo "\nDone.\n";
