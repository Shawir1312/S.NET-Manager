<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
requireAdmin();

// Catch PHP fatal errors dan tampilkan sebagai JSON (bukan HTML 500)
register_shutdown_function(function(){
    $e=error_get_last();
    if($e&&in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])){
        if(!headers_sent()){header('Content-Type: application/json');}
        echo json_encode(['ok'=>false,'error'=>'PHP Error: '.$e['message'].' at '.$e['file'].':'.$e['line']]);
    }
});

$action=$_GET['action']??'';
$rid=(int)($_GET['rid']??0);
$idblParam=trim($_GET['idbl']??'');
$resellerParam=trim($_GET['reseller']??'');

if(!$rid||!$action){
    if(in_array($action,['revenue','dashboard'])){header('Content-Type: application/json');echo json_encode(['error'=>'Invalid']);exit;}
    echo '<div class="alert aerr">❌ Parameter tidak valid</div>';exit;
}
$db=db();
$router=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1 LIMIT 1");
$router->execute([$rid]);$router=$router->fetch();
if(!$router){
    if(in_array($action,['revenue','dashboard'])){header('Content-Type: application/json');echo json_encode(['error'=>'Not found']);exit;}
    echo '<div class="alert aerr">❌ Router tidak ditemukan</div>';exit;
}
$api=MikrotikAPI::fromRouter($router);
if(!$api->connect()){
    // JSON actions: return JSON error
    $jsonActions=['revenue','dashboard','batch_list','batch_users','delete_batch'];
    if(in_array($action,$jsonActions)){
        header('Content-Type: application/json');
        if($action==='dashboard'||$action==='revenue'){
            echo json_encode(['error'=>$api->error,'rev_daily'=>0,'rev_monthly'=>0,'rev_daily_count'=>0,'rev_monthly_count'=>0,'scripts'=>[],'idhr'=>'','idbl'=>'','hs_active_count'=>0,'hs_users_count'=>0,'ppp_active_count'=>0,'ppp_secrets_count'=>0,'ppp_offline_count'=>0,'ppp_active'=>[]]);
        } elseif($action==='batch_list'){
            echo json_encode(['batches'=>[],'total'=>0,'error'=>'Konek gagal: '.$api->error]);
        } elseif($action==='batch_users'){
            echo json_encode(['batch'=>[],'total'=>0,'error'=>'Konek gagal: '.$api->error]);
        } elseif($action==='delete_batch'){
            echo json_encode(['ok'=>false,'deleted'=>0,'error'=>'Konek gagal: '.$api->error]);
        }
        exit;
    }
    echo '<div class="alert aerr">❌ Konek gagal: '.h($api->error).'</div>';exit;
}
function rp(int $n):string{return 'Rp '.number_format($n,0,',','.');}

/* ══════════════════════════════════════════════════════════════
   INIT DATA — JSON: profiles, servers, pools, hsActive
══════════════════════════════════════════════════════════════ */
if($action==='init_data'){
    header('Content-Type: application/json');
    $profiles=$api->getHotspotProfiles();
    $serversRaw=$api->parse($api->talk(['/ip/hotspot/print','=.proplist=name']));
    $poolsRaw=$api->parse($api->talk(['/ip/pool/print','=.proplist=name']));
    
    $hsCount = 0;
    $hsCountRaw = $api->talk(['/ip/hotspot/active/print','=count-only=']);
    foreach($hsCountRaw as $w) {
        if(preg_match('/=ret=(\d+)/',$w,$m)) { $hsCount=(int)$m[1]; break; }
        if(is_numeric(trim($w))) { $hsCount=(int)trim($w); break; }
    }
    
    $hsActiveRaw = [];
    if ($hsCount <= 150) {
        $hsActiveRaw = $api->getHotspotActive();
    }
    
    $api->close();

    if(!function_exists('parseProf')){
        function parseProf(array $p):array{
            $ol=$p['on-login']??'';
            preg_match('/^:put \(",(.*?)"/',$ol,$m);
            $x=array_map('trim',explode(',',$m[1]??''));
            $expmode='-';$price=0;$validity='-';$sprice=0;$lockuser='Disable';
            $emos=['rem','ntf','remc','ntfc','0','noexp'];
            foreach($x as $v){
                if($v==='')continue;
                if(in_array($v,$emos)){$expmode=$v;continue;}
                if(preg_match('/^\d+[hdwm]$/i',$v)){$validity=$v;continue;}
                if(in_array(strtolower($v),['enable','disable','mikhmon'])){$lockuser=in_array(strtolower($v),['enable','disable'])?ucfirst(strtolower($v)):$lockuser;continue;}
                if(is_numeric($v)&&(int)$v>0&&(int)$v<1000000){$price===0?$price=(int)$v:($sprice===0?$sprice=(int)$v:null);}
            }
            return['name'=>$p['name']??'-','rate'=>$p['rate-limit']??'-','shared'=>$p['shared-users']??'1',
                   'expmode'=>$expmode,'price'=>$price,'validity'=>$validity,'sprice'=>$sprice,
                   'lockuser'=>$lockuser,'pool'=>$p['address-pool']??'any'];
        }
    }

    $profsParsed = array_map('parseProf', $profiles);
    $servers = array_column($serversRaw, 'name');
    $pools = array_column($poolsRaw, 'name');
    
    // Clean up hsActive to only needed fields to keep response light
    $hsActive = array_map(function($h) {
        return [
            'user' => $h['user'] ?? '-',
            'address' => $h['address'] ?? '-',
            'mac-address' => $h['mac-address'] ?? '-',
            'uptime' => $h['uptime'] ?? '-',
            '.id' => $h['.id'] ?? ''
        ];
    }, $hsActiveRaw);

    echo json_encode([
        'profiles' => $profsParsed,
        'servers' => $servers,
        'pools' => $pools,
        'hsActive' => $hsActive,
        'hsCount' => $hsCount
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   DASHBOARD — JSON: resource + hotspot + pppoe counts + pppoe list
══════════════════════════════════════════════════════════════ */
if($action==='dashboard'){
    header('Content-Type: application/json');
    // Gabungkan resource + identity dalam 1 call lebih sedikit round-trips
    $res=$api->parse($api->talk(['/system/resource/print','=.proplist=cpu-load,total-memory,free-memory,uptime,version,board-name,free-hdd-space']));
    $res=$res[0]??[];
    $tot=(int)($res['total-memory']??1);$free=(int)($res['free-memory']??0);
    $identity=$api->getIdentity();
    $rb=$api->parse($api->talk(['/system/routerboard/print','=.proplist=model']));

    // Hotspot users count-only (ROS 7: returns =ret=N, ROS 6: returns N directly)
    $raw=$api->talk(['/ip/hotspot/user/print','=count-only=']);
    $hsCount=0;foreach($raw as $w){
        if(preg_match('/=ret=(\d+)/',$w,$m)){$hsCount=(int)$m[1];break;}
        if(is_numeric(trim($w))){$hsCount=(int)trim($w);break;}
    }

    // PPPoE active sessions
    // Approach: ALL /ppp/active MINUS yang jelas L2TP/PPTP/SSTP/OVPN
    // Karena di x86 ROS7, PPPoE bisa punya service='ppp','pppoe','' dll
    $pppActiveCount = $api->getPppActiveCount();
    $pppAct = [];
    if($pppActiveCount <= 150) {
        $pppAll=$api->getPppActive();
        $excludedSvc=['l2tp','pptp','sstp','ovpn','l2tp-client'];
        $pppAct=array_values(array_filter($pppAll,function($s)use($excludedSvc){
            $svc=strtolower($s['service']??'');
            return !in_array($svc,$excludedSvc);
        }));
    }

    // PPPoE secrets count — gunakan count-only dengan filter service=pppoe
    // Lebih efisien dari ambil semua records (bisa ribuan di x86)
    $pppActNames=array_column($pppAct,'name');
    
    // Method 1: count-only dengan filter query (ROS 7)
    $rawPpoeCount=$api->talk(['/ppp/secret/print','?service=pppoe','=count-only=']);
    $pppTotal=0;
    foreach($rawPpoeCount as $w){
        if(preg_match('/=ret=(\d+)/',$w,$m)){$pppTotal=(int)$m[1];break;}
        if(is_numeric(trim($w))){$pppTotal=(int)trim($w);break;}
    }
    // Method 2: jika filter tidak bekerja (service='' juga valid untuk pppoe)
    if($pppTotal===0){
        $rawAllCount=$api->talk(['/ppp/secret/print','=count-only=']);
        foreach($rawAllCount as $w){
            if(preg_match('/=ret=(\d+)/',$w,$m)){$pppTotal=(int)$m[1];break;}
            if(is_numeric(trim($w))){$pppTotal=(int)trim($w);break;}
        }
        // Kurangi L2TP accounts (biasanya sedikit)
        $l2tpCountRaw=$api->talk(['/ppp/secret/print','?service=l2tp','=count-only=']);
        $l2tpCount=0;foreach($l2tpCountRaw as $w){if(preg_match('/=ret=(\d+)/',$w,$m)){$l2tpCount=(int)$m[1];break;}if(is_numeric(trim($w))){$l2tpCount=(int)trim($w);break;}}
        $pppTotal=max(0,$pppTotal-$l2tpCount);
    }
    $pppEnabled=$pppTotal; // assume all enabled (disabled check needs full list)
    $pppOffline=max(0,$pppTotal-count($pppAct)); // approximate

    // HS active — pakai count-only untuk count, full list hanya untuk display
    $rawHsCount=$api->talk(['/ip/hotspot/active/print','=count-only=']);
    $finalHsCount=0;
    foreach($rawHsCount as $w){
        if(preg_match('/=ret=(\d+)/',$w,$m)){$finalHsCount=(int)$m[1];break;}
        if(is_numeric(trim($w))){$finalHsCount=(int)trim($w);break;}
    }
    // $api->getHotspotActive() removed because it's completely unused in dashboard response and causes massive delays
    $api->close();

    echo json_encode([
        'identity'=>$identity,'board'=>$res['board-name']??'-','model'=>($rb[0]['model']??$res['board-name']??'-'),
        'version'=>$res['version']??'-','uptime'=>$res['uptime']??'-',
        'free_hdd'=>round((int)($res['free-hdd-space']??0)/1048576,1).' MB','ip'=>$router['ip_public'],
        'cpu'=>(int)($res['cpu-load']??0),
        'mem_pct'=>$tot>0?round(($tot-$free)/$tot*100):0,
        'mem_used'=>round(($tot-$free)/1048576,1),'mem_total'=>round($tot/1048576,1),
        'hs_active_count'=>$finalHsCount,'hs_users_count'=>$hsCount,
        'ppp_active_count'=>count($pppAct),'ppp_secrets_count'=>$pppEnabled,
        'ppp_offline_count'=>$pppOffline,
        'ppp_active'=>array_map(fn($p)=>[
            'name'=>$p['name']??'','address'=>$p['address']??'',
            'uptime'=>$p['uptime']??'','caller-id'=>$p['caller-id']??'','service'=>$p['service']??'pppoe'
        ],array_slice($pppAct,0,50)),
    ],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   REVENUE — JSON: pendapatan hari ini & bulan ini
══════════════════════════════════════════════════════════════ */
if($action==='revenue'){
    header('Content-Type: application/json');
    $clock=$api->parseOne($api->talk(['/system/clock/print']));
    if(!empty($clock['time-zone-name']))@date_default_timezone_set($clock['time-zone-name']);
    $thisD=date('d');$thisM=strtolower(date('M'));$thisY=date('Y');
    if(strlen($thisD)===1)$thisD='0'.$thisD;
    $idhr="$thisM/$thisD/$thisY";$idbl="$thisM$thisY";
    $rawAll=$api->parse($api->talk(['/system/script/print','=.proplist=name,owner']));
    $raw=[]; foreach($rawAll as $sc){ if(($sc['owner']??'')===$idbl) $raw[]=$sc; }
    $api->close();
    $tHr=0;$tBl=0;$cHr=0;$cBl=0;$scList=[];
    foreach($raw as $sc){
        $name=$sc['name']??'';$p=explode('-|-',$name);
        if(count($p)<4)continue;$price=(int)($p[3]??0);if($price<=0)continue;
        $tBl+=$price;$cBl++;$scList[]=['name'=>$name];
        if(($p[0]??'')===$idhr){$tHr+=$price;$cHr++;}
    }
    echo json_encode(['rev_daily'=>$tHr,'rev_monthly'=>$tBl,'rev_daily_count'=>$cHr,'rev_monthly_count'=>$cBl,'scripts'=>$scList,'idhr'=>$idhr,'idbl'=>$idbl,'total_scripts'=>count($raw)],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   REPORT — HTML: laporan multi-bulan + filter per reseller
   Script Mikhmon format:
   name = "date-|-time-|-user-|-price-|-ip-|-mac-|-validity-|-profile-|-comment"
   owner = "monthYear" (e.g. apr2026)
   comment = "mikhmon"   (field dari script sendiri)
   Reseller diambil dari COMMENT user hotspot (prefix sebelum -rand-date)
══════════════════════════════════════════════════════════════ */
if($action==='penjualan'){
    set_time_limit(300);
    $clock=$api->parseOne($api->talk(['/system/clock/print']));
    if(!empty($clock['time-zone-name']))@date_default_timezone_set($clock['time-zone-name']);
    $thisD=date('d');$thisM=strtolower(date('M'));$thisY=date('Y');
    if(strlen($thisD)===1)$thisD='0'.$thisD;
    $idhrNow="$thisM/$thisD/$thisY";$idblNow="$thisM$thisY";
    $idbl=$idblParam?:$idblNow;$isCurrent=($idbl===$idblNow);

    // 12 bulan terakhir
    $months=[];
    for($i=0;$i<12;$i++){$ts=strtotime("-$i month");$mk=strtolower(date('M',$ts)).date('Y',$ts);$months[$mk]=date('M Y',$ts);}

    // Ambil semua scripts dengan proplist minimal (SANGAT CEPAT dibanding filter ?owner di Mikrotik)
    $rawAll=$api->parse($api->talk(['/system/script/print','=.proplist=name,owner']));
    $raw=[]; foreach($rawAll as $sc){ if(($sc['owner']??'')===$idbl) $raw[]=$sc; }
    $api->close();

    // Parse semua transaksi
    $byDay=[];$byProfile=[];$all=[];
    $allProfileList=[];// semua profil yang ada bulan ini

    foreach($raw as $sc){
        $name=$sc['name']??'';$p=explode('-|-',$name);
        if(count($p)<4)continue;
        $price=(int)($p[3]??0);if($price<=0)continue;
        $dateStr=$p[0]??'';$timeStr=$p[1]??'';$user=$p[2]??'';$pProfile=$p[7]??$p[6]??'-';

        // Filter per profil jika ada
        if($resellerParam&&strtolower($pProfile)!==strtolower($resellerParam))continue;

        $all[]=['date'=>$dateStr,'time'=>$timeStr,'user'=>$user,'price'=>$price,'profile'=>$pProfile];
        $byDay[$dateStr]=($byDay[$dateStr]??0)+$price;
        $byProfile[$pProfile]=($byProfile[$pProfile]??0)+$price;
    }
    // Kumpulkan semua profil untuk tab filter
    foreach($raw as $sc){
        $name=$sc['name']??'';$p=explode('-|-',$name);
        if(count($p)<7)continue;$pr=$p[7]??'';
        if($pr&&!in_array($pr,$allProfileList))$allProfileList[]=$pr;
    }
    sort($allProfileList);

    arsort($byDay);arsort($byProfile);
    $all=array_reverse($all);
    $totalBulan=array_sum(array_column($all,'price'));
    $countBulan=count($all);
    $totalHari=0;$countHari=0;
    if($isCurrent)foreach($all as $a){if($a['date']===$idhrNow){$totalHari+=$a['price'];$countHari++;}}
    ?>
<style id="snm-ajax-css-check">
.mth-btn{padding:5px 13px;border-radius:20px;border:2px solid var(--g200);background:#fff;font-family:'Exo 2',sans-serif;font-size:.74rem;font-weight:600;cursor:pointer;color:var(--g600);transition:.2s}
.mth-btn.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.mth-btn:hover:not(.on){border-color:var(--blue);color:var(--blue)}
.mth-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px}
.rbar-row{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.rbar-lbl{font-size:.72rem;min-width:108px;flex-shrink:0;color:var(--g600)}
.rbar-lbl.today{color:var(--blue-d);font-weight:700}
.rbar-bg{flex:1;background:var(--g100);border-radius:5px;height:22px;overflow:hidden;min-width:60px}
.rbar-fill{height:22px;border-radius:5px;display:flex;align-items:center;padding:0 7px;transition:.5s;min-width:4px}
.rbar-val{font-size:.72rem;font-weight:700;color:var(--orange);min-width:90px;text-align:right;flex-shrink:0}
@media(max-width:768px){.rbar-lbl{min-width:70px}.rbar-val{min-width:60px}}
</style>
<!-- ─── Month tabs ─── -->
<div class="mth-tabs">
    <?php foreach($months as $mk=>$ml):?>
    <button class="mth-btn <?=$mk===$idbl?'on':''?>" onclick="loadLaporan('<?=$mk?>','')"><?=$ml?></button>
    <?php endforeach;?>
</div>

<!-- ─── Profile filter ─── -->
<?php if($allProfileList):?>
<div class="card" style="margin-bottom:12px">
    <div class="cb" style="padding:10px 14px">
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <span style="font-size:.73rem;font-weight:700;color:var(--g700);flex-shrink:0">📋 Filter Profil:</span>
            <button class="mth-btn <?=!$resellerParam?'on':''?>" onclick="loadLaporan('<?=h($idbl)?>','')">Semua</button>
            <?php foreach($allProfileList as $pr):?>
            <button class="mth-btn <?=strtolower($resellerParam)===strtolower($pr)?'on':''?>" onclick="loadLaporan('<?=h($idbl)?>','<?=h($pr)?>')"><?=h($pr)?></button>
            <?php endforeach;?>
            <?php if($resellerParam):?>
            <span style="font-size:.75rem;background:var(--blue);color:#fff;padding:3px 10px;border-radius:15px">📋 <?=h($resellerParam)?></span>
            <button onclick="loadLaporan('<?=h($idbl)?>','')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:.9rem;padding:0 4px">✕</button>
            <?php endif;?>
        </div>
    </div>
</div>
<?php endif;?>

<!-- ─── Stats ─── -->
<div class="stats" style="grid-template-columns:repeat(auto-fill,minmax(120px,1fr));margin-bottom:14px">
    <?php if($isCurrent&&!$resellerParam):?>
    <div class="stat" style="--c:var(--blue)"><div class="stat-l">📅 Hari Ini</div><div class="stat-n" style="font-size:.85rem;color:var(--blue)"><?=rp($totalHari)?></div><div class="stat-d"><?=$countHari?> vcr</div></div>
    <?php endif;?>
    <div class="stat" style="--c:var(--purple)"><div class="stat-l">📆 <?=strtoupper($idbl)?></div><div class="stat-n" style="font-size:.85rem;color:var(--purple)"><?=rp($totalBulan)?></div><div class="stat-d"><?=$countBulan?> vcr</div></div>
    <div class="stat" style="--c:var(--orange)"><div class="stat-l">🎫 Voucher</div><div class="stat-n" style="color:var(--orange)"><?=$countBulan?></div><div class="stat-d">transaksi</div></div>
    <div class="stat" style="--c:var(--green)"><div class="stat-l">📋 Profil</div><div class="stat-n" style="color:var(--green)"><?=count($byProfile)?></div><div class="stat-d">jenis</div></div>
    <div class="stat" style="--c:var(--red)"><div class="stat-l">📋 Profil</div><div class="stat-n" style="color:var(--red)"><?=count($byProfile)?></div><div class="stat-d">jenis profil</div></div>
</div>

<?php if(!$all):?>
<div class="card"><div class="empty"><div class="eico">🧾</div>Belum ada data penjualan <?=$resellerParam?"reseller <b>$resellerParam</b> ":''?>bulan <b><?=strtoupper($idbl)?></b></div></div>
<?php else:?>

<!-- ─── Bar chart per hari ─── -->
<div class="card" style="margin-bottom:14px">
    <div class="ch">
        <div class="ct">📅 Penjualan Per Hari<?=$resellerParam?" — Profile: <span style='color:var(--blue-d)'>".h($resellerParam)."</span>":''?></div>
        <div style="font-size:.72rem;color:var(--g400)"><?=strtoupper($idbl)?> · <?=$countBulan?> transaksi</div>
    </div>
    <div class="cb" style="overflow-x:auto">
    <?php $maxD=max($byDay)?:1;foreach($byDay as $d=>$t):$isT=$isCurrent&&$d===$idhrNow;$w=max(5,round($t/$maxD*100));?>
    <div class="rbar-row">
        <div class="rbar-lbl <?=$isT?'today':''?>"><?=h($d)?><?=$isT?' 🟢':''?></div>
        <div class="rbar-bg">
            <div class="rbar-fill" style="width:<?=$w?>%;background:<?=$isT?'linear-gradient(90deg,#1B3FA6,#122B7A)':'linear-gradient(90deg,#7C3AED,#5B21B6)'?>">
                <span style="font-size:.63rem;color:#fff;white-space:nowrap;overflow:hidden"><?=rp($t)?></span>
            </div>
        </div>
        <div class="rbar-val"><?=rp($t)?></div>
    </div>
    <?php endforeach;?>
    </div>
</div>

<!-- ─── Per Profile ─── -->
<div class="card" style="margin-bottom:14px">
    <div class="ch"><div class="ct">📋 Per Profile<?=$resellerParam?" — <span style='color:var(--blue)'>".h($resellerParam)."</span>":''?></div><div style="font-size:.72rem;color:var(--g400)"><?=count($byProfile)?> profil aktif</div></div>
    <?php $maxP=max($byProfile)?:1;$byProfCnt=[];foreach($all as $a)$byProfCnt[$a['profile']]=($byProfCnt[$a['profile']]??0)+1;?>
    <div class="cb" style="padding:12px 16px">
    <?php foreach($byProfile as $pr=>$t):$cnt=$byProfCnt[$pr]??0;$w=max(5,round($t/$maxP*100));$isF=strtolower($resellerParam)===strtolower($pr);?>
    <div style="margin-bottom:10px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
            <span class="bdg bblue" style="font-size:.72rem;cursor:pointer" onclick="loadLaporan('<?=h($idbl)?>','<?=h($pr)?>')"><?=h($pr)?></span>
            <span style="font-size:.76rem;font-weight:700;color:var(--orange)"><?=rp($t)?> <span style="color:var(--g400);font-weight:400">(<?=$cnt?> vcr)</span></span>
        </div>
        <div class="rbar-bg"><div class="rbar-fill" style="width:<?=$w?>%;background:<?=$isF?'linear-gradient(90deg,var(--blue),var(--blue-d))':'linear-gradient(90deg,#0EA5E9,#0284C7)'?>"></div></div>
    </div>
    <?php endforeach;?>
    </div>
</div>

<!-- ─── Log transaksi ─── -->
<div class="card">
    <div class="ch">
        <div class="ct">🧾 Log Transaksi<?=$resellerParam?" — Profile: <span style='color:var(--blue-d)'>".h($resellerParam)."</span>":''?> (<?=$countBulan?>)</div>
        <div style="display:flex;gap:6px">
            <?php if($resellerParam):?><button onclick="loadLaporan('<?=h($idbl)?>','')" class="btn bo bsm">✕ Reset Filter</button><?php endif;?>
            <button onclick="expCSV()" class="btn bo bsm">⬇️ CSV</button>
        </div>
    </div>
    <div class="tw" style="max-height:480px;overflow-y:auto"><table class="dt" id="logTbl">
        <thead><tr><th>Tanggal</th><th>Jam</th><th>Username</th><th>Harga</th><th>Profile</th></tr></thead>
        <tbody>
        <?php foreach($all as $s):$isT=$isCurrent&&$s['date']===$idhrNow;?>
        <tr style="<?=$isT?'background:rgba(27,63,166,.04)':''?>">
            <td style="font-size:.76rem;font-weight:<?=$isT?'700':'400'?>;color:<?=$isT?'var(--blue-d)':'var(--g600)'?>"><?=h($s['date'])?></td>
            <td class="ipm" style="font-size:.7rem"><?=h($s['time'])?></td>
            <td><strong><?=h($s['user'])?></strong></td>
            <td><span style="font-weight:700;color:var(--orange)"><?=rp($s['price'])?></span></td>
            <td><span class="bdg bblue" style="font-size:.62rem;cursor:pointer" onclick="loadLaporan('<?=h($idbl)?>','<?=h($s['profile'])?>')" title="Filter profil ini"><?=h($s['profile'])?></span></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
</div>
<?php endif;?>

<script>
function expCSV(){
    const rows=document.querySelectorAll('#logTbl tbody tr');
    let csv='\uFEFFTanggal,Jam,Username,Harga,Profile\n';
    rows.forEach(r=>{
        const c=r.querySelectorAll('td');
        if(c.length>=5)csv+=`"${c[0].textContent.trim()}","${c[1].textContent.trim()}","${c[2].textContent.trim()}","${c[3].textContent.trim()}","${c[4].textContent.trim()}"\n`;
    });
    const b=new Blob([csv],{type:'text/csv;charset=utf-8'});
    const a=document.createElement('a');a.href=URL.createObjectURL(b);
    a.download='laporan-<?=h($idbl)?>-<?=h($resellerParam?:'semua')?>.csv';a.click();
}
</script>
    <?php
    exit;
}

/* ══════════════════════════════════════════════════════════════
   PPPoE OFFLINE
══════════════════════════════════════════════════════════════ */
if($action==='ppp_offline'){
    $pppAll=$api->getPppActive();
    // Filter aktif: hanya pppoe atau kosong (bukan l2tp/pptp/sstp/ovpn)
    $l2tpT=['l2tp','pptp','sstp','ovpn','l2tp-client'];
    $pppAct=array_values(array_filter($pppAll,fn($s)=>in_array(strtolower($s['service']??''),['','pppoe','any'])));
    $activeNames=array_column($pppAct,'name');
    // Ambil secrets dengan filter service bukan l2tp
    $secrets=$api->parse($api->talk(['/ppp/secret/print','=.proplist=name,profile,comment,service,disabled']));
    $pppoeSecrets=array_values(array_filter($secrets,function($s)use($l2tpT){
        $svc=strtolower($s['service']??'');
        return $svc===''||$svc==='pppoe'||$svc==='any';
    }));
    // Hanya yang tidak disabled dan tidak online
    $offline=array_values(array_filter($pppoeSecrets,function($s)use($activeNames){
        return ($s['disabled']??'false')==='false'&&!in_array($s['name']??'',$activeNames);
    }));
    $api->close();
    ?>
    <div class="card">
        <div class="ch"><div class="ct">📴 PPPoE Offline (<?=count($offline)?>)</div></div>
        <?php if(!$offline):?><div class="empty"><div class="eico">✅</div>Semua PPPoE user online!</div><?php else:?>
        <div class="tw"><table class="dt">
            <thead><tr><th>Username</th><th>Profile</th><th>Comment</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach($offline as $s):$dis=($s['disabled']??'false')==='true';?>
            <tr>
                <td><strong style="color:<?=$dis?'var(--g400)':'var(--red)'?>"><?=h($s['name']??'-')?></strong></td>
                <td><span class="bdg bgray" style="font-size:.63rem"><?=h($s['profile']??'-')?></span></td>
                <td style="font-size:.73rem;color:var(--g500);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($s['comment']??'-')?></td>
                <td><span class="bdg <?=$dis?'boff':'bgray'?>"><?=$dis?'Disabled':'Offline'?></span></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table></div>
        <?php endif;?>
    </div>
    <?php exit;
}

/* ══════════════════════════════════════════════════════════════
   HOTSPOT USERS — sorted newest first, filter by profile/reseller
══════════════════════════════════════════════════════════════ */
if($action==='hs_users'){
    $filterProf=trim($_GET['prof']??'');
    $filterRes=trim($_GET['res']??'');

    $hsUsersRaw=$api->getHotspotUsers();
    $hsActive=$api->getHotspotActive();
    $activeMap=[];foreach($hsActive as $h){if(isset($h['user']))$activeMap[$h['user']]=$h;}
    $api->close();

    // Sort: online dulu, lalu offline. Dalam tiap grup urutkan berdasarkan comment (terbaru di atas)
    usort($hsUsersRaw,function($a,$b){
        $aOn=isset($activeMap[$a['name']??''])?1:0;
        $bOn=isset($activeMap[$b['name']??''])?1:0;
        if($aOn!==$bOn)return $bOn-$aOn; // online dulu
        // Sort by comment descending (newest generate = comment terbaru)
        return strcmp($b['comment']??'',$a['comment']??'');
    });

    // Kumpulkan semua profil dan reseller yang ada untuk filter tab
    $allProfs=array_unique(array_filter(array_column($hsUsersRaw,'profile')));
    sort($allProfs);

    // Filter
    $hsUsers=$hsUsersRaw;
    if($filterProf)$hsUsers=array_values(array_filter($hsUsers,fn($u)=>($u['profile']??'')===$filterProf));
    if($filterRes)$hsUsers=array_values(array_filter($hsUsers,fn($u)=>strpos(strtolower($u['comment']??''),strtolower($filterRes))!==false));
    ?>
<style>.mth-btn{padding:5px 13px;border-radius:20px;border:2px solid var(--g200);background:#fff;font-family:'Exo 2',sans-serif;font-size:.74rem;font-weight:600;cursor:pointer;color:var(--g600);transition:.2s}.mth-btn.on{background:var(--blue);color:#fff;border-color:var(--blue)}</style>
    <div class="card">
        <div class="ch">
            <div class="ct">👥 User Hotspot (<?=count($hsUsers)?>/<?=count($hsUsersRaw)?>)</div>
            <div style="display:flex;gap:6px">
                <button onclick="printUsers()" class="btn bo bsm">🖨️ Print</button>
                <button onclick="dlUsersCSV()" class="btn bo bsm">⬇️ CSV</button>
            </div>
        </div>
        <!-- Filter bar -->
        <div class="cb" style="padding:10px 14px;border-bottom:1px solid var(--g200)">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px">
                <input type="text" id="usrSearch" class="fc" placeholder="🔍 Cari username / profile / comment..." oninput="fltrUsr(this.value)" style="flex:1;min-width:180px;max-width:360px">
                <?php if($filterProf||$filterRes):?><button onclick="loadUsers()" class="btn bo bsm">✕ Reset</button><?php endif;?>
            </div>
            <!-- Filter by profile -->
            <?php if($allProfs):?>
            <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center">
                <span style="font-size:.7rem;font-weight:700;color:var(--g600)">Profile:</span>
                <button class="mth-btn <?=!$filterProf?'on':''?>" onclick="filterUsrProf('')">Semua</button>
                <?php foreach($allProfs as $pr):?>
                <button class="mth-btn <?=$filterProf===$pr?'on':''?>" onclick="filterUsrProf('<?=h($pr)?>')"><?=h($pr)?></button>
                <?php endforeach;?>
            </div>
            <?php endif;?>
        </div>
        <?php if(!$hsUsers):?>
        <div class="empty"><div class="eico">👥</div>Tidak ada user<?=$filterProf?" profile $filterProf":''?></div>
        <?php else:?>
        <div class="tw" style="max-height:580px;overflow-y:auto"><table class="dt" id="usrTbl">
            <thead><tr>
                <th>#</th><th>Username</th><th>Profile</th><th>Comment / Reseller</th>
                <th>Status</th><th>Bytes In</th><th>Uptime</th>
            </tr></thead>
            <tbody>
            <?php foreach($hsUsers as $i=>$u):
                $on=isset($activeMap[$u['name']??'']);$hi=$on?$activeMap[$u['name']]:[];
                $comment=$u['comment']??'';
                // Coba detect reseller dari comment (format: reseller-rand-date)
                $resParts=explode('-',$comment);$res=trim($resParts[0]??'');
                if(strlen($res)<2||is_numeric($res))$res='';
            ?>
            <tr data-s="<?=strtolower(h($u['name']??'')).' '.strtolower(h($u['profile']??'')).' '.strtolower(h($comment))?>">
                <td style="color:var(--g400);font-size:.75rem"><?=$i+1?></td>
                <td><strong style="color:<?=$on?'#16A34A':'var(--g700)'?>"><?=h($u['name']??'-')?></strong></td>
                <td><span class="bdg bblue" style="font-size:.63rem"><?=h($u['profile']??'-')?></span></td>
                <td style="font-size:.72rem;max-width:150px">
                    <?php if($res):?><span style="color:var(--purple);font-weight:600;font-size:.7rem"><?=h($res)?></span><br><?php endif;?>
                    <span style="color:var(--g500);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block" title="<?=h($comment)?>"><?=h($comment)?:'-'?></span>
                </td>
                <td><span class="bdg <?=$on?'bon':'bgray'?>"><?=$on?'🟢 Online':'⚫ Offline'?></span></td>
                <td class="ipm" style="font-size:.7rem"><?=h($u['bytes-in']??'0')?></td>
                <td style="font-size:.72rem;color:#16A34A;font-weight:600"><?=$on?h($hi['uptime']??'-'):'-'?></td>
            </tr>
            <?php endforeach;?>
            </tbody>
        </table></div>
        <?php endif;?>
    </div>
    <script>
    function fltrUsr(q){
        document.querySelectorAll('#usrTbl tbody tr').forEach(r=>{
            r.style.display=(!q||r.dataset.s.includes(q.toLowerCase()))?'':'none';
        });
    }
    function filterUsrProf(prof){
        fetch('/admin/mikhmon_ajax.php?action=hs_users&rid=<?=$rid?>&prof='+encodeURIComponent(prof))
            .then(r=>r.text()).then(h=>{document.getElementById('users-wrap').innerHTML=h;});
    }
    function loadUsers(){
        fetch('/admin/mikhmon_ajax.php?action=hs_users&rid=<?=$rid?>')
            .then(r=>r.text()).then(h=>{document.getElementById('users-wrap').innerHTML=h;});
    }
    function printUsers(){
        const rows=document.querySelectorAll('#usrTbl tbody tr');
        let html='<html><head><title>User Hotspot</title><style>body{font-family:Arial,sans-serif;font-size:11px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:4px 6px}th{background:#1B3FA6;color:#fff}tr:nth-child(even){background:#f9f9f9}.on{color:#16A34A;font-weight:700}</style></head><body>';
        html+='<h3 style="color:#1B3FA6">👥 User Hotspot — <?=date('d/m/Y H:i')?></h3>';
        html+='<table><thead><tr><th>#</th><th>Username</th><th>Profile</th><th>Comment</th><th>Status</th></tr></thead><tbody>';
        rows.forEach((r,i)=>{
            if(r.style.display==='none')return;
            const c=r.querySelectorAll('td');
            const st=c[4]?.textContent?.includes('Online');
            html+=`<tr><td>${c[0]?.textContent}</td><td>${c[1]?.textContent}</td><td>${c[2]?.textContent}</td><td>${c[3]?.textContent?.trim()}</td><td class="${st?'on':''}">${c[4]?.textContent}</td></tr>`;
        });
        html+='</tbody></table></body></html>';
        const w=window.open('','_blank');w.document.write(html);w.document.close();w.print();
    }
    function dlUsersCSV(){
        const rows=document.querySelectorAll('#usrTbl tbody tr');
        let csv='\uFEFF#,Username,Profile,Comment,Status,Bytes In,Uptime\n';
        rows.forEach((r,i)=>{
            if(r.style.display==='none')return;
            const c=r.querySelectorAll('td');
            if(c.length>=7)csv+=`${c[0].textContent},"${c[1].textContent}","${c[2].textContent}","${c[3].textContent?.trim()}","${c[4].textContent}","${c[5].textContent}","${c[6].textContent}"\n`;
        });
        const b=new Blob([csv],{type:'text/csv;charset=utf-8'});
        const a=document.createElement('a');a.href=URL.createObjectURL(b);
        a.download='hs-users-<?=date('YmdHi')?>.csv';a.click();
    }
    </script>
    <?php exit;
}


/* ══ batch_list ══ */
if($action==='batch_list'){
    set_time_limit(120); // Naikkan limit untuk router besar
    // Fetch SEMUA user dengan proplist MINIMAL (name+profile+comment only)
    // Tidak pakai ?~ filter karena tidak support semua versi ROS
    // Data minimal: ~35 bytes/user × 18000 = 630KB - cukup cepat dengan timeout 60s
    $recentComments=json_decode(trim($_GET['recent']??'[]'),true)??[];
    
    $allUsers=$api->parse($api->talk(['/ip/hotspot/user/print',
        '=.proplist=name,profile,comment']));
    $api->close();
    // Group by comment, collect profile and names per batch
    $batches=[];  // comment => ['count'=>N, 'profiles'=>[], 'names'=>[]]
    foreach($allUsers as $u){
        $cmt=trim($u['comment']??'');if(!$cmt)continue;
        // Harus match format Mikhmon: vc-rand-mm.dd.yy[-label]
        if(!preg_match('/^(vc|up)-\d{3}-\d{2}\.\d{2}\.\d{2}/',$cmt))continue;
        $prof=trim($u['profile']??'');$name=$u['name']??'';
        if(!isset($batches[$cmt])){$batches[$cmt]=['count'=>0,'profiles'=>[],'names'=>[]];}
        $batches[$cmt]['count']++;
        if($prof&&!in_array($prof,$batches[$cmt]['profiles']))$batches[$cmt]['profiles'][]=$prof;
        if($name)$batches[$cmt]['names'][]=$name;
    }
    // Cache batch names in session untuk batch_users (hindari double fetch)
    $_SESSION['batch_cache_'.$rid]=[];
    foreach($batches as $cmt=>$info){
        $_SESSION['batch_cache_'.$rid][$cmt]=$info['names'];
    }
    // Sort by DATE DESC (terbaru di atas), kemudian by count DESC
    uasort($batches,function($a,$b){
        // Extract sort key dari comment (key dari $batches array)
        return 0; // placeholder, will be replaced by key-aware sort below
    });
    // Re-sort with access to comment key
    $batchesWithKey=[];
    foreach($batches as $cmt=>$info){
        // mm.dd.yy → convert to yyyymmdd for sorting
        $parts=explode('-',$cmt,4);
        $dp=explode('.',$parts[2]??'');
        // yy=dp[2], mm=dp[0], dd=dp[1]
        $sortKey=count($dp)===3?('20'.$dp[2].$dp[0].$dp[1]):'00000000';
        $batchesWithKey[]=[$cmt,$info,$sortKey];
    }
    usort($batchesWithKey,fn($a,$b)=>strcmp($b[2],$a[2])); // DESC date
    $batches=[];
    foreach($batchesWithKey as $item)$batches[$item[0]]=$item[1];
    header('Content-Type: application/json');
    $result=[];
    foreach($batches as $cmt=>$info){
        // Extract suffix label from comment: vc-rand-mm.dd.yy-LABEL
        $parts=explode('-',$cmt,4);
        $labelFromComment=trim($parts[3]??'');
        // Profile dari user dalam batch (ambil yang paling banyak muncul)
        $profileStr=implode('/',$info['profiles']);
        // Gunakan label dari comment jika ada, jika tidak gunakan profil
        $displayLabel=$labelFromComment?:$profileStr;
        // Parse date: mm.dd.yy dari parts[2]
        $dp=explode('.',$parts[2]??'');
        // Convert mm.dd.yy → DD/MM/YYYY (tampilan lebih jelas)
        $dateDisp=count($dp)===3?sprintf('%s/%s/%s',$dp[1],$dp[0],'20'.$dp[2]):'';
        // Juga format tanggal lebih readable: "05 Apr 2026"
        if($dateDisp){
            $ts=mktime(0,0,0,(int)$dp[0],(int)$dp[1],2000+(int)$dp[2]);
            $dateDisp=$ts?date('d M Y',$ts):$dateDisp;
        }
        $result[]=[
            'comment'  =>$cmt,
            'count'    =>$info['count'],
            'label'    =>$displayLabel,     // untuk tampilan dropdown
            'profiles' =>$profileStr,       // profil asli
            'date'     =>$dateDisp,
            'mode'     =>$parts[0]??'vc'
        ];
    }
    echo json_encode([
        'batches'=>$result,
        'total'=>count($result),
        'debug'=>['users_fetched'=>count($allUsers),'matched'=>array_sum(array_column($result,'count'))]
    ],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══ batch_users ══ */
if($action==='batch_users'){
    set_time_limit(120);
    $comment=trim($_GET['comment']??'');
    if(!$comment){header('Content-Type: application/json');echo json_encode(['batch'=>[],'total'=>0]);$api->close();exit;}

    $commentBase=rtrim($comment,'-');

    // Gunakan session cache dari batch_list (batch_list sudah fetch semua user)
    // Ini MENGHINDARI double fetch 10000+ user yang menyebabkan timeout!
    $cachedNames=$_SESSION['batch_cache_'.$rid][$comment]??null;
    if($cachedNames===null){
        // Cari di cache dengan prefix match
        foreach(($_SESSION['batch_cache_'.$rid]??[]) as $ck=>$cn){
            if(strpos($ck,$commentBase)===0){$cachedNames=$cn;$comment=$ck;break;}
        }
    }
    $matchNames=$cachedNames??[];

    // Jika tidak ada cache: fetch minimal saja (name+comment)
    if(empty($matchNames)&&$cachedNames===null){
        $rawAll=$api->parse($api->talk(['/ip/hotspot/user/print','=.proplist=name,comment']));
        foreach($rawAll as $u){
            $uCmt=trim($u['comment']??'');
            if(strpos($uCmt,$commentBase)===0)$matchNames[]=$u['name'];
        }
    }

    // Fetch detail: ambil SEMUA dengan full proplist lalu filter by name
    // Jauh lebih cepat dari 500 individual query (1 bulk fetch!)
    $batch=[];
    if(!empty($matchNames)){
        $nameSet=array_flip($matchNames);
        $allDetail=$api->parse($api->talk(['/ip/hotspot/user/print',
            '=.proplist=name,password,profile,comment,limit-uptime,limit-bytes-total']));
        foreach($allDetail as $u){
            if(isset($nameSet[$u['name']??'']))$batch[]=$u;
        }
    }

    // Ambil sesi aktif untuk status online (SEBELUM close!)
    $hsActive=$api->getHotspotActive();
    $api->close();
    $onlineMap=[];
    foreach($hsActive as $h){
        if(isset($h['user']))$onlineMap[$h['user']]=['uptime'=>$h['uptime']??'','bytes_in'=>(int)($h['bytes-in']??0)];
    }

    $result=array_map(function($u)use($onlineMap,$comment,$commentBase){
        $name=$u['name']??'';
        $isOnline=isset($onlineMap[$name]);
        $sesData=$isOnline?$onlineMap[$name]:[];
        $uCmt=trim($u['comment']??'');
        // "Pernah": comment sudah berubah dari format batch (Mikhmon on-login ubah ke expire date)
        $stillBatch=(strpos($uCmt,$commentBase)===0);
        $used=!$stillBatch&&!$isOnline; // comment berubah = sudah pernah login
        return [
            'user'          =>$name,
            'pass'          =>$u['password']??$name,
            'profile'       =>$u['profile']??'-',
            'comment'       =>$uCmt,
            'limit_uptime'  =>$u['limit-uptime']??'',
            'bytes_in'      =>$sesData['bytes_in']??0,
            'online'        =>$isOnline,
            'used'          =>$used,
            'session_uptime'=>$sesData['uptime']??''
        ];
    },$batch);
    header('Content-Type: application/json');
    echo json_encode(['batch'=>$result,'total'=>count($result),'comment'=>$comment],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══ delete_batch ══ */
if($action==='delete_batch'){
    set_time_limit(300);
    header('Content-Type: application/json');
    $comment=trim($_GET['comment']??'');
    if(!$comment){echo json_encode(['ok'=>false,'error'=>'Comment kosong']);$api->close();exit;}

    $commentBase=rtrim($comment,'-'); // "vc-136-04.07.26" (tanpa trailing dash)

    // ════════════════════════════════════════════════════
    // STEP 1: Ambil SEMUA .id user yang match comment
    //         WAJIB fetch dulu — remove ?comment= selalu
    //         return !done meski 0 user terhapus!
    // ════════════════════════════════════════════════════
    $toDelete=[];

    // Coba dari session cache dulu (lebih cepat)
    $cachedNames=$_SESSION['batch_cache_'.$rid][$comment]??null;
    if($cachedNames===null){
        // Cari di cache dengan prefix match
        foreach(($_SESSION['batch_cache_'.$rid]??[]) as $ck=>$cn){
            if(strpos($ck,$commentBase)===0){$cachedNames=$cn;break;}
        }
    }

    if(!empty($cachedNames)){
        // Ada cache names → fetch .id untuk nama-nama ini saja
        // Lebih cepat dari fetch semua 20k user
        foreach(array_chunk($cachedNames,50) as $chunk){
            foreach($chunk as $nm){
                $res=$api->parse($api->talk([
                    '/ip/hotspot/user/print',
                    '?name='.$nm,
                    '=.proplist=name,.id,comment'
                ]));
                foreach($res as $u){
                    $uCmt=trim($u['comment']??'');
                    // Double-check comment match untuk keamanan
                    if(($uCmt===$comment||strpos($uCmt,$commentBase)===0)&&isset($u['.id'])){
                        $toDelete[$u['.id']]=$u['name']??$nm;
                    }
                }
            }
        }
    }

    // Jika cache kosong atau tidak ditemukan → fetch semua lalu filter
    if(empty($toDelete)){
        $allRaw=$api->parse($api->talk([
            '/ip/hotspot/user/print',
            '=.proplist=name,comment,.id'
        ]));
        foreach($allRaw as $u){
            $uCmt=trim($u['comment']??'');
            if(($uCmt===$comment||strpos($uCmt,$commentBase)===0)&&isset($u['.id'])){
                $toDelete[$u['.id']]=$u['name']??'';
            }
        }
    }

    if(empty($toDelete)){
        $api->close();
        echo json_encode(['ok'=>false,'error'=>"Tidak ada voucher ditemukan untuk batch: $comment",'comment'=>$comment]);
        exit;
    }

    // ════════════════════════════════════════════════════
    // STEP 2: Hapus berdasarkan .id (pasti akurat)
    //         Batch 50 per call untuk hindari timeout
    // ════════════════════════════════════════════════════
    $deleted=0;$failed=0;
    $ids=array_keys($toDelete);

    foreach(array_chunk($ids,50) as $chunk){
        $cmd=['/ip/hotspot/user/remove'];
        foreach($chunk as $id)$cmd[]='=.id='.$id;
        $r=$api->talk($cmd);
        if(in_array('!done',$r))$deleted+=count($chunk);
        else $failed+=count($chunk);
    }

    // ════════════════════════════════════════════════════
    // STEP 3: Verify — hitung sisa user dengan comment ini
    // ════════════════════════════════════════════════════
    $verifyRaw=$api->parse($api->talk([
        '/ip/hotspot/user/print',
        '?comment='.$comment,
        '=.proplist=name'
    ]));
    $remaining=count($verifyRaw);

    // Bersihkan session cache
    if(isset($_SESSION['batch_cache_'.$rid][$comment]))
        unset($_SESSION['batch_cache_'.$rid][$comment]);

    $api->close();

    $totalFound=count($ids);
    $realDeleted=$totalFound-$remaining;
    $ok=$remaining===0;

    auditLog('delete_batch_voucher',$comment,"Found:$totalFound Deleted:$realDeleted Remaining:$remaining");
    echo json_encode([
        'ok'        =>$ok,
        'deleted'   =>$realDeleted,
        'failed'    =>$remaining,
        'total'     =>$totalFound,
        'remaining' =>$remaining,
        'comment'   =>$comment,
        'msg'       =>$ok
            ?"✅ $realDeleted voucher berhasil dihapus!"
            :"⚠️ $realDeleted dari $totalFound terhapus. $remaining masih tersisa.",
    ],JSON_UNESCAPED_UNICODE);
    exit;
}

echo '<div class="alert aerr">Action tidak dikenal: '.h($action).'</div>';
?>
