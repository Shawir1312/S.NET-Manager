<?php
define('IN_APP', true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/MikrotikAPI.php';

$db = db();
$routers = $db->query("SELECT * FROM routers WHERE is_active=1")->fetchAll();

foreach ($routers as $router) {
    echo "Connecting to router: " . $router['name'] . " (" . $router['ip_public'] . ")\n";
    $api = MikrotikAPI::fromRouter($router);
    if ($api->connect()) {
        $clock = $api->parseOne($api->talk(['/system/clock/print']));
        if (!empty($clock['time-zone-name'])) @date_default_timezone_set($clock['time-zone-name']);
        
        $thisM = strtolower(date('M')); $thisY = date('Y');
        $idbl = "$thisM$thisY";
        $lastM = strtolower(date('M', strtotime('-1 month'))); $lastY = date('Y', strtotime('-1 month'));
        $idbl_prev = "$lastM$lastY";
        
        echo "Keeping scripts for: $idbl and $idbl_prev\n";
        
        // Command to remove scripts not owned by current or previous month, AND name contains '-|-' (Mikhmon format)
        $cmd = '/system script remove [find where owner!="'.$idbl.'" and owner!="'.$idbl_prev.'" and name~"-|-"]';
        echo "Running: $cmd\n";
        
        // Execute via API by adding a temporary script and running it
        $api->talk([
            '/system/script/add',
            '=name=cleanup_temp',
            '=source='.$cmd,
            '=policy=read,write,policy,test'
        ]);
        
        $api->talk([
            '/system/script/run',
            '=.id=cleanup_temp'
        ]);
        
        $api->talk([
            '/system/script/remove',
            '=.id=cleanup_temp'
        ]);
        
        echo "Cleanup command sent to Mikrotik!\n";
        $api->close();
    } else {
        echo "Failed to connect: " . $api->error . "\n";
    }
}
echo "Done.\n";
