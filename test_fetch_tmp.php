<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
$ctx = stream_context_create(['http' => ['header' => "Cookie: dUMMY=1"]]);
echo file_get_contents('http://localhost/kasir-minimarket/login.php', false, $ctx);
