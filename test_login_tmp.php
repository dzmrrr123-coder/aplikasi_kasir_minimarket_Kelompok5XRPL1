<?php
require __DIR__ . '/src/autoload.php';
session_start();
unset($_SESSION['user_id'], $_SESSION['role']);
$u = App\Models\User::loginPolimorfik('admin', 'admin123');
var_export($u ? ['id' => $u->getId(), 'role' => get_class($u)] : 'NULL');
echo PHP_EOL;
