<?php
// Quick diagnostic - akses via browser untuk cek
echo "✅ PHP berjalan OK\n";
echo "Path: " . __FILE__ . "\n";
echo "PHP version: " . PHP_VERSION . "\n";

// Cek session
session_start();
echo "Session: OK\n";

// Cek file-file penting ada
$files = [
    'mikhmon_ajax.php',
    'snetmonitoring.php', 
    'pppoe.php',
    'pppoe_detail.php',
];
foreach($files as $f){
    echo ($f . ': ' . (file_exists(__DIR__.'/'.$f) ? '✅' : '❌ TIDAK ADA') . "\n");
}
