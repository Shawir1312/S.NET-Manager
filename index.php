<?php 
$host = $_SERVER['HTTP_HOST'] ?? '';
// Jika request menggunakan domain asing (misal google.com) hasil pembelokan NAT MikroTik, langsung arahkan ke portal isolir.
if ($host && strpos($host, 'shawir.id') === false && strpos($host, 'shaiwr.id') === false && strpos($host, 'localhost') === false) {
    header('Location: http://srv5.shaiwr.id/portal/isolir.php');
    exit;
}
header('Location: /admin/login.php');
exit;
