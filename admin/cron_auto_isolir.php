<?php
/**
 * Auto-Isolir Cron Job
 * Jalankan setiap hari via cron: 0 1 * * * php /path/to/admin/cron_auto_isolir.php
 * 
 * Logic:
 * - Setiap pelanggan punya due_day (tanggal jatuh tempo) berbeda-beda
 * - Cek pelanggan yang due_day <= hari ini
 * - Cek apakah sudah bayar bulan ini
 * - Jika belum bayar + sudah lewat grace period → isolir
 */
define('IN_APP',true);
define('IS_CRON',true);
require_once __DIR__.'/../includes/config.php';

$db=db();
$settings=[];
foreach($db->query("SELECT setting_key,setting_value FROM pppoe_settings") as $s)
    $settings[$s['setting_key']]=$s['setting_value'];

$grace=(int)($settings['isolir_grace_days']??3);
$isoProfile=$settings['isolir_profile']??'isolir';
$today=date('j'); // hari ini (1-31)
$todayDate=date('Y-m-d');
$thisMonth=date('n');
$thisYear=date('Y');

echo "[".date('Y-m-d H:i:s')."] Starting auto-isolir check...\n";
echo "Grace days: $grace | Today: $todayDate | Profile isolir: $isoProfile\n";

// Ambil semua pelanggan aktif yang DUE DAY sudah lewat
// Dan belum bayar bulan ini
$sql="SELECT pc.*,
    r.host,r.port r_port,r.username r_user,r.password r_pass,r.name r_name,
    (SELECT COUNT(*) FROM pppoe_payments pp 
     WHERE pp.customer_id=pc.id 
     AND pp.period_month=$thisMonth 
     AND pp.period_year=$thisYear
     AND pp.midtrans_status NOT IN ('pending','cancel','deny','expire')
    ) as paid_count
FROM pppoe_customers pc 
JOIN routers r ON pc.router_id=r.id 
WHERE pc.status='active' 
AND r.is_active=1
ORDER BY pc.router_id,pc.due_day";

$candidates=$db->query($sql)->fetchAll();
echo "Total active customers: ".count($candidates)."\n";

$isolated=0;$skipped=0;$errors=0;
$apiPool=[]; // cache API connections per router

foreach($candidates as $c){
    $dueDay=(int)$c['due_day'];
    
    // Hitung tanggal jatuh tempo bulan ini
    // Jika due_day=25 → jatuh tempo tgl 25 bulan ini
    $dueDate=date('Y-m-').sprintf('%02d',min($dueDay,date('t'))); // handle bulan pendek
    $dueDateTs=strtotime($dueDate);
    $todayTs=strtotime($todayDate);
    
    // Belum jatuh tempo → skip
    if($todayTs<$dueDateTs){$skipped++;continue;}
    
    // Hitung hari keterlambatan
    $daysLate=round(($todayTs-$dueDateTs)/86400);
    
    // Grace period belum lewat → skip
    if($daysLate<$grace){$skipped++;continue;}
    
    // Sudah bayar bulan ini → skip
    if($c['paid_count']>0){$skipped++;continue;}
    
    // === ISOLIR ===
    echo "  Isolir: {$c['pppoe_username']} ({$c['full_name']}) router={$c['r_name']} due_day=$dueDay late={$daysLate}d\n";
    
    try{
        $rid=$c['router_id'];
        if(!isset($apiPool[$rid])){
            $api=new MikrotikAPI($c['host'],(int)$c['r_port'],$c['r_user'],$c['r_pass']);
            $apiPool[$rid]=$api->connect()?$api:null;
        }
        $api=$apiPool[$rid];
        
        if($api){
            // Ganti profile ke isolir
            $api->talk(['/ppp/secret/set','?name='.$c['pppoe_username'],'=profile='.$isoProfile]);
            // Putus sesi aktif
            $actSessions=$api->parse($api->talk(['/ppp/active/print','?name='.$c['pppoe_username'],'=.proplist=.id']));
            foreach($actSessions as $s){if(isset($s['.id']))$api->talk(['/ppp/active/remove','=.id='.$s['.id']]);}
        }
        
        // Update DB
        $db->prepare("UPDATE pppoe_customers SET status='isolated',isolated_at=NOW(),isolated_reason=? WHERE id=?")
           ->execute(["Auto-isolir: jatuh tempo tgl $dueDay, terlambat {$daysLate} hari",$c['id']]);
        auditLog('auto_isolir_cron',$c['pppoe_username'],"Late: {$daysLate}d, Due: $dueDay");
        $isolated++;
        
    }catch(\Exception $e){
        echo "  ERROR: {$c['pppoe_username']}: ".$e->getMessage()."\n";
        $errors++;
    }
}

// Close all API connections
foreach($apiPool as $api){if($api)$api->close();}

echo "\n[".date('Y-m-d H:i:s')."] Done. Isolated: $isolated | Skipped: $skipped | Errors: $errors\n";
