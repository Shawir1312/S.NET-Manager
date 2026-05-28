<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
requireAdmin();

function rp(int $n):string{return 'Rp '.number_format($n,0,',','.');}

$action=$_GET['action']??'';
$rid=(int)($_GET['rid']??0);
$idblParam=trim($_GET['idbl']??'');
$resellerParam=trim($_GET['reseller']??'');

/* ── Hanya aksi read-only yang diizinkan ── */
if(!in_array($action,['all_revenue','report','profile_revenue'])){
    header('Content-Type: application/json');
    echo json_encode(['error'=>'Aksi tidak diizinkan']);exit;
}

$db=db();

/* ══ PROFILE_REVENUE: breakdown per profil untuk satu router ══ */
if($action==='profile_revenue'){
    header('Content-Type: application/json');
    if(!$rid){echo json_encode(['error'=>'Router tidak dipilih']);exit;}
    $stmt=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$rid]);$router=$stmt->fetch();
    if(!$router){echo json_encode(['error'=>'Router tidak ditemukan']);exit;}
    $api=MikrotikAPI::fromRouter($router);
    if(!$api->connect()){echo json_encode(['error'=>'Gagal konek: '.$api->error]);exit;}
    $clock=$api->parseOne($api->talk(['/system/clock/print']));
    if(!empty($clock['time-zone-name']))@date_default_timezone_set($clock['time-zone-name']);
    $thisD=date('d');$thisM=strtolower(date('M'));$thisY=date('Y');
    if(strlen($thisD)===1)$thisD='0'.$thisD;
    $idhr="$thisM/$thisD/$thisY";$idbl=$idblParam?:"$thisM$thisY";
    $raw=$api->parse($api->talk(['/system/script/print','?owner='.$idbl]));
    $api->close();
    $byProfile=[];$byProfileDay=[];$byProfileCnt=[];
    foreach($raw as $sc){
        $name=$sc['name']??'';$p=explode('-|-',$name);
        if(count($p)<4)continue;
        $price=(int)($p[3]??0);if($price<=0)continue;
        $dateStr=$p[0]??'';
        $prof=$p[7]??($p[6]??'-');
        if(!$prof)$prof='-';
        $byProfile[$prof]=($byProfile[$prof]??0)+$price;
        $byProfileCnt[$prof]=($byProfileCnt[$prof]??0)+1;
        if($dateStr===$idhr)$byProfileDay[$prof]=($byProfileDay[$prof]??0)+$price;
    }
    arsort($byProfile);
    $profiles=[];
    foreach($byProfile as $prof=>$total){
        $profiles[]=['name'=>$prof,'monthly'=>$total,'daily'=>$byProfileDay[$prof]??0,'count'=>$byProfileCnt[$prof]??0];
    }
    echo json_encode(['profiles'=>$profiles,'total_monthly'=>array_sum($byProfile),'total_daily'=>array_sum($byProfileDay),'router_name'=>$router['name'],'idbl'=>$idbl,'date'=>date('d M Y')],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══ ALL_REVENUE: aggregate semua router ══ */
if($action==='all_revenue'){
    header('Content-Type: application/json');
    $routers=$db->query("SELECT * FROM routers WHERE is_active=1 AND use_mikhmon=1 ORDER BY is_main DESC,name ASC")->fetchAll();
    if(empty($routers))$routers=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY is_main DESC,name ASC")->fetchAll();
    $results=[];$tDaily=0;$tMonth=0;$tDailyCnt=0;$tMonthCnt=0;
    foreach($routers as $router){
        $api=MikrotikAPI::fromRouter($router);
        if(!$api->connect()){
            $results[]=['id'=>(int)$router['id'],'name'=>$router['name'],'ip'=>$router['ip_public'],'online'=>false,'error'=>$api->error,'rev_daily'=>0,'rev_monthly'=>0,'rev_daily_count'=>0,'rev_monthly_count'=>0];
            continue;
        }
        $clock=$api->parseOne($api->talk(['/system/clock/print']));
        if(!empty($clock['time-zone-name']))@date_default_timezone_set($clock['time-zone-name']);
        $thisD=date('d');$thisM=strtolower(date('M'));$thisY=date('Y');
        if(strlen($thisD)===1)$thisD='0'.$thisD;
        $idhr="$thisM/$thisD/$thisY";$idbl="$thisM$thisY";
        $raw=$api->parse($api->talk(['/system/script/print','?owner='.$idbl]));
        $api->close();
        $tHr=0;$tBl=0;$cHr=0;$cBl=0;
        foreach($raw as $sc){
            $name=$sc['name']??'';$p=explode('-|-',$name);
            if(count($p)<4)continue;$price=(int)($p[3]??0);if($price<=0)continue;
            $tBl+=$price;$cBl++;
            if(($p[0]??'')===$idhr){$tHr+=$price;$cHr++;}
        }
        $results[]=['id'=>(int)$router['id'],'name'=>$router['name'],'ip'=>$router['ip_public'],'online'=>true,'rev_daily'=>$tHr,'rev_monthly'=>$tBl,'rev_daily_count'=>$cHr,'rev_monthly_count'=>$cBl];
        $tDaily+=$tHr;$tMonth+=$tBl;$tDailyCnt+=$cHr;$tMonthCnt+=$cBl;
    }
    echo json_encode(['routers'=>$results,'total_daily'=>$tDaily,'total_monthly'=>$tMonth,'total_daily_count'=>$tDailyCnt,'total_monthly_count'=>$tMonthCnt,'date'=>date('d M Y')],JSON_UNESCAPED_UNICODE);
    exit;
}

/* ══ REPORT: laporan per router (HTML) ══ */
if($action==='report'){
    if(!$rid){echo '<div class="alert aerr">❌ Router tidak dipilih</div>';exit;}
    $stmt=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1 LIMIT 1");
    $stmt->execute([$rid]);$router=$stmt->fetch();
    if(!$router){echo '<div class="alert aerr">❌ Router tidak ditemukan</div>';exit;}
    $api=MikrotikAPI::fromRouter($router);
    if(!$api->connect()){
        echo '<div class="alert aerr">❌ Gagal konek ke <strong>'.h($router['name']).'</strong>: '.h($api->error).'</div>';exit;
    }
    $clock=$api->parseOne($api->talk(['/system/clock/print']));
    if(!empty($clock['time-zone-name']))@date_default_timezone_set($clock['time-zone-name']);
    $thisD=date('d');$thisM=strtolower(date('M'));$thisY=date('Y');
    if(strlen($thisD)===1)$thisD='0'.$thisD;
    $idhrNow="$thisM/$thisD/$thisY";$idblNow="$thisM$thisY";
    $idbl=$idblParam?:$idblNow;$isCurrent=($idbl===$idblNow);

    // 12 bulan terakhir
    $months=[];
    for($i=0;$i<12;$i++){$ts=strtotime("-$i month");$mk=strtolower(date('M',$ts)).date('Y',$ts);$months[$mk]=date('M Y',$ts);}

    $raw=$api->parse($api->talk(['/system/script/print','?owner='.$idbl]));
    $api->close();

    $byDay=[];$byProfile=[];$all=[];$allProfileList=[];
    foreach($raw as $sc){
        $name=$sc['name']??'';$p=explode('-|-',$name);
        if(count($p)<4)continue;
        $price=(int)($p[3]??0);if($price<=0)continue;
        $dateStr=$p[0]??'';$timeStr=$p[1]??'';$user=$p[2]??'';$pProfile=$p[7]??$p[6]??'-';
        if($resellerParam&&strtolower($pProfile)!==strtolower($resellerParam))continue;
        $all[]=['date'=>$dateStr,'time'=>$timeStr,'user'=>$user,'price'=>$price,'profile'=>$pProfile];
        $byDay[$dateStr]=($byDay[$dateStr]??0)+$price;
        $byProfile[$pProfile]=($byProfile[$pProfile]??0)+$price;
    }
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
    $ridJs=(int)$rid;
    $idblJs=json_encode($idbl);
?>
<!-- Month tabs -->
<div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px">
<?php foreach($months as $mk=>$ml):?>
<button class="tk-mth <?=$mk===$idbl?'active':''?>" onclick="tkRpt(<?=$ridJs?>,<?=json_encode($mk)?>,'')"><?=$ml?></button>
<?php endforeach;?>
</div>

<?php if($allProfileList):?>
<div class="card" style="margin-bottom:12px">
<div class="cb" style="padding:10px 14px">
<div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
<span style="font-size:.73rem;font-weight:700;color:var(--g700);flex-shrink:0">📋 Filter Profil:</span>
<button class="tk-mth <?=!$resellerParam?'active':''?>" onclick="tkRpt(<?=$ridJs?>,<?=$idblJs?>,'')">Semua</button>
<?php foreach($allProfileList as $pr):?>
<button class="tk-mth <?=strtolower($resellerParam)===strtolower($pr)?'active':''?>" onclick="tkRpt(<?=$ridJs?>,<?=$idblJs?>,<?=json_encode($pr)?>)"><?=h($pr)?></button>
<?php endforeach;?>
</div>
</div>
</div>
<?php endif;?>

<!-- Stats -->
<div class="stats" style="grid-template-columns:repeat(auto-fill,minmax(130px,1fr));margin-bottom:14px">
<?php if($isCurrent&&!$resellerParam):?>
<div class="stat" style="--c:var(--blue)"><div class="stat-l">📅 Hari Ini</div><div class="stat-n" style="font-size:.82rem;color:var(--blue)"><?=rp($totalHari)?></div><div class="stat-d"><?=$countHari?> vcr</div></div>
<?php endif;?>
<div class="stat" style="--c:var(--purple)"><div class="stat-l">📆 <?=strtoupper($idbl)?></div><div class="stat-n" style="font-size:.82rem;color:var(--purple)"><?=rp($totalBulan)?></div><div class="stat-d"><?=$countBulan?> vcr</div></div>
<div class="stat" style="--c:var(--orange)"><div class="stat-l">🎫 Voucher</div><div class="stat-n" style="color:var(--orange)"><?=$countBulan?></div><div class="stat-d">transaksi</div></div>
<div class="stat" style="--c:var(--green)"><div class="stat-l">📋 Profil</div><div class="stat-n" style="color:var(--green)"><?=count($byProfile)?></div><div class="stat-d">jenis</div></div>
</div>

<?php if(!$all):?>
<div class="card"><div class="empty"><div class="eico">🧾</div>Belum ada data penjualan <?=$resellerParam?"profil <b>".h($resellerParam)."</b> ":''?>bulan <b><?=strtoupper($idbl)?></b></div></div>
<?php else:?>

<!-- Bar chart per hari -->
<div class="card" style="margin-bottom:14px">
<div class="ch">
<div class="ct">📅 Penjualan Per Hari<?=$resellerParam?" — <span style='color:var(--blue-d)'>".h($resellerParam)."</span>":''?></div>
<div style="font-size:.72rem;color:var(--g400)"><?=strtoupper($idbl)?> · <?=$countBulan?> transaksi</div>
</div>
<div class="cb" style="padding:12px 16px">
<?php $maxD=max($byDay)?:1;foreach($byDay as $d=>$t):$isT=$isCurrent&&$d===$idhrNow;$w=max(5,round($t/$maxD*100));?>
<div style="display:flex;align-items:center;gap:8px;margin-bottom:7px">
<div style="font-size:.72rem;min-width:108px;flex-shrink:0;color:<?=$isT?'var(--blue-d)':'var(--g600)'?>;font-weight:<?=$isT?700:400?>"><?=h($d)?><?=$isT?' 🟢':''?></div>
<div style="flex:1;background:var(--g100);border-radius:5px;height:20px;overflow:hidden">
<div style="width:<?=$w?>%;background:<?=$isT?'linear-gradient(90deg,#1B3FA6,#122B7A)':'linear-gradient(90deg,#7C3AED,#5B21B6)'?>;height:20px;border-radius:5px;display:flex;align-items:center;padding:0 7px;min-width:4px">
<span style="font-size:.6rem;color:#fff;white-space:nowrap;overflow:hidden"><?=rp($t)?></span>
</div>
</div>
<div style="font-size:.72rem;font-weight:700;color:var(--orange);min-width:90px;text-align:right;flex-shrink:0"><?=rp($t)?></div>
</div>
<?php endforeach;?>
</div>
</div>

<!-- Per Profile -->
<div class="card" style="margin-bottom:14px">
<div class="ch"><div class="ct">📋 Per Profil<?=$resellerParam?" — <b>".h($resellerParam)."</b>":''?></div><div style="font-size:.72rem;color:var(--g400)"><?=count($byProfile)?> profil</div></div>
<?php $maxP=max($byProfile)?:1;$byPC=[];foreach($all as $a)$byPC[$a['profile']]=($byPC[$a['profile']]??0)+1;?>
<div class="cb" style="padding:12px 16px">
<?php foreach($byProfile as $pr=>$t):$cnt=$byPC[$pr]??0;$w=max(5,round($t/$maxP*100));?>
<div style="margin-bottom:10px">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px">
<span class="bdg bblue" style="font-size:.72rem;cursor:pointer" onclick="tkRpt(<?=$ridJs?>,<?=$idblJs?>,<?=json_encode($pr)?>)"><?=h($pr)?></span>
<span style="font-size:.76rem;font-weight:700;color:var(--orange)"><?=rp($t)?> <span style="color:var(--g400);font-weight:400">(<?=$cnt?> vcr)</span></span>
</div>
<div style="background:var(--g100);border-radius:5px;height:7px;overflow:hidden">
<div style="width:<?=$w?>%;background:linear-gradient(90deg,#0EA5E9,#0284C7);height:7px;border-radius:5px;transition:.5s"></div>
</div>
</div>
<?php endforeach;?>
</div>
</div>

<!-- Log transaksi -->
<div class="card">
<div class="ch">
<div class="ct">🧾 Log Transaksi<?=$resellerParam?" — <span style='color:var(--blue-d)'>".h($resellerParam)."</span>":''?> (<?=$countBulan?>)</div>
<div style="display:flex;gap:6px">
<?php if($resellerParam):?><button onclick="tkRpt(<?=$ridJs?>,<?=$idblJs?>,'');" class="btn bo bsm">✕ Reset</button><?php endif;?>
<button onclick="tkCSV(<?=$ridJs?>)" class="btn bo bsm">⬇️ CSV</button>
</div>
</div>
<div class="tw" style="max-height:420px;overflow-y:auto">
<table class="dt" id="tkLog<?=$ridJs?>">
<thead><tr><th>Tanggal</th><th>Jam</th><th>Username</th><th>Harga</th><th>Profil</th></tr></thead>
<tbody>
<?php foreach($all as $s):$isT=$isCurrent&&$s['date']===$idhrNow;?>
<tr style="<?=$isT?'background:rgba(27,63,166,.04)':''?>">
<td style="font-size:.76rem;font-weight:<?=$isT?700:400?>;color:<?=$isT?'var(--blue-d)':'var(--g600)'?>"><?=h($s['date'])?></td>
<td style="font-family:'JetBrains Mono',monospace;font-size:.7rem"><?=h($s['time'])?></td>
<td><strong><?=h($s['user'])?></strong></td>
<td><span style="font-weight:700;color:var(--orange)"><?=rp($s['price'])?></span></td>
<td><span class="bdg bblue" style="font-size:.62rem;cursor:pointer" onclick="tkRpt(<?=$ridJs?>,<?=$idblJs?>,<?=json_encode($s['profile'])?>)"><?=h($s['profile'])?></span></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
</div>
</div>
<?php endif;?>
<script>
function tkCSV(rid){
    const tbl=document.getElementById('tkLog'+rid);if(!tbl)return;
    const rows=tbl.querySelectorAll('tbody tr');
    let csv='\uFEFFTanggal,Jam,Username,Harga,Profil\n';
    rows.forEach(r=>{const c=r.querySelectorAll('td');if(c.length>=5)csv+=`"${c[0].textContent.trim()}","${c[1].textContent.trim()}","${c[2].textContent.trim()}","${c[3].textContent.trim()}","${c[4].textContent.trim()}"\n`;});
    const b=new Blob([csv],{type:'text/csv;charset=utf-8'});
    const a=document.createElement('a');a.href=URL.createObjectURL(b);a.download='laporan-<?=h($idbl)?>-<?=h($resellerParam?:'semua')?>.csv';a.click();
}
</script>
<?php
    exit;
}
?>
