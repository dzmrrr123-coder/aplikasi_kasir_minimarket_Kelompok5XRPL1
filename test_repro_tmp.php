<?php
require __DIR__ . '/src/autoload.php';
$db = App\Database\Database::connect();

// Ensure a clean kasir with no active shift.
$db->exec('SET FOREIGN_KEY_CHECKS=0');
$db->exec('DELETE FROM shift_kasir');
$db->exec('SET FOREIGN_KEY_CHECKS=1');

echo "=== STEP 1: login kasir via HTTP (cookie jar) ===\n";
$jar = __DIR__ . '/test_login.cookie';
@unlink($jar);

// 1. GET login to obtain CSRF token
$ch = curl_init('http://localhost/kasir-minimarket/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
curl_setopt($ch, CURLOPT_HEADER, false);
$html = curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
$csrf = $m[1] ?? '';
echo "csrf dari login: " . substr($csrf,0,16) . " ...\n";

// 2. POST login as kasir (native form submit, no X-Requested-With)
$ch = curl_init('http://localhost/kasir-minimarket/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'username' => 'kasir',
    'password' => 'kasir123',
    'csrf' => $csrf,
]);
$resp = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);
echo "login status: " . $info['http_code'] . "\n";
echo "login location: " . ($info['redirect_url'] ?? '-') . "\n";

// 3. GET transaksi.php (should show buka kas overlay, no shift)
$ch = curl_init('http://localhost/kasir-minimarket/public/transaksi.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
curl_setopt($ch, CURLOPT_HEADER, false);
$t = curl_exec($ch);
curl_close($ch);
preg_match('/name="csrf" value="([^"]+)"/', $t, $m2);
$csrf2 = $m2[1] ?? '';
echo "\n=== STEP 2: buka kas (NATIVE POST, tanpa X-Requested-With) ===\n";
echo "overlay ada di DOM: " . (strpos($t, 'id="card-buka-kas"') !== false ? 'YA' : 'TIDAK') . "\n";
echo "csrf transaksi: " . substr($csrf2,0,16) . " ...\n";

// 4. POST buka_kas NATIVELY (simulate agent-browser requestSubmit)
$ch = curl_init('http://localhost/kasir-minimarket/public/transaksi.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'aksi' => 'buka_kas',
    'modal_awal' => '50000',
    'csrf' => $csrf2,
]);
$resp2 = curl_exec($ch);
$info2 = curl_getinfo($ch);
curl_close($ch);
echo "buka_kas NATIVE status: " . $info2['http_code'] . "\n";
echo "buka_kas location: " . ($info2['redirect_url'] ?? '-') . "\n";

// Show head of body
$body2 = substr($resp2, $info2['header_size']);
echo "buka_kas body head: " . substr($body2, 0, 120) . "\n";

// 5. Verify shift created
$shifts = $db->query('SELECT id,kasir_id,status,modal_awal FROM shift_kasir ORDER BY id DESC LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
echo "\n=== shift setelah buka kas ===\n";
var_export($shifts);

@unlink($jar);
echo "\n=== DONE ===\n";
