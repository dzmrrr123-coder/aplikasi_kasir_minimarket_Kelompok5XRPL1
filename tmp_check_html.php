<?php
$cookieFile = sys_get_temp_dir() . '/kasir_flow2.txt';
@unlink($cookieFile);

function login($ch, $cookieFile) {
    // GET login page
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8080/login.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $resp = curl_exec($ch);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $body = substr($resp, $hlen);
    preg_match('/name="csrf" value="([^"]+)"/', $body, $m);
    $csrf = $m[1] ?? '';
    
    // POST login
    curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8080/login.php');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['username' => 'admin', 'password' => 'admin123', 'csrf' => $csrf]));
    curl_exec($ch);
    curl_setopt($ch, CURLOPT_POST, false);
}

$ch = curl_init();
login($ch, $cookieFile);

// Fetch dashboard HTML
curl_setopt($ch, CURLOPT_URL, 'http://127.0.0.1:8080/dashboard.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$resp = curl_exec($ch);
$hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$html = substr($resp, $hlen);

// Check for PHP errors in the HTML
echo "HTTP code: " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
echo "HTML length: " . strlen($html) . "\n";

// Check for PHP errors/warnings
if (preg_match('/Fatal error|Warning|Deprecated|Notice|Parse error/i', $html, $m)) {
    echo "PHP ERROR FOUND: " . $m[0] . "\n";
    // Show context
    $pos = strpos($html, $m[0]);
    echo "Context: " . substr($html, max(0, $pos - 100), 300) . "\n";
} else {
    echo "No PHP errors found in HTML.\n";
}

// Check if CSRF token is present in meta
if (preg_match('/meta name="csrf-token" content="([^"]*)"/', $html, $m)) {
    echo "CSRF meta token: " . substr($m[1], 0, 20) . "... (length=" . strlen($m[1]) . ")\n";
} else {
    echo "CSRF meta tag NOT FOUND!\n";
}

// Check if the logout form has CSRF (injected by theme.js)
if (preg_match('/<form[^>]*method="post"[^>]*>.*name="aksi".*value="logout"/s', $html, $m)) {
    echo "Logout form found (no CSRF in HTML - relies on JS injection)\n";
    if (strpos($m[0], 'name="csrf"') !== false) {
        echo "Logout form HAS CSRF in HTML\n";
    }
}

// Check for key JS elements
$checks = [
    'stat-total' => 'Stat card total',
    'stat-jumlah' => 'Stat card jumlah',
    'stat-item' => 'Stat card item',
    'stat-rata' => 'Stat card rata',
    'grafik-penjualan' => 'Sales chart canvas',
    'grafik-stok' => 'Stock chart canvas',
    'tabel-terlaris' => 'Best products table',
    'tabel-transaksi' => 'Recent transactions table',
    'chart.umd.min.js' => 'Chart.js script',
    'jquery.min.js' => 'jQuery script',
    'dataTables.min.js' => 'DataTables script',
    'theme.js' => 'Theme script',
];

echo "\n--- Element checks ---\n";
foreach ($checks as $needle => $desc) {
    echo "  $desc: " . (strpos($html, $needle) !== false ? 'FOUND' : 'MISSING') . "\n";
}

// Check script loading order
echo "\n--- Script tags ---\n";
if (preg_match_all('/<script[^>]*src="([^"]*)"/i', $html, $matches)) {
    foreach ($matches[1] as $src) {
        // Verify the file actually exists
        $path = 'public/' . ltrim($src, '/');
        echo "  $src => " . (file_exists($path) ? 'EXISTS' : 'MISSING!') . "\n";
    }
}

curl_close($ch);
@unlink($cookieFile);
echo "\nDone.\n";
