<?php
// Simulate with admin session cookie by reading from a logged-in browser is hard;
// instead just fetch login.php and print any error.
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo file_get_contents('http://localhost/kasir-minimarket/login.php');
