<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';

// Format validity: "24h" → "24 Jam", "1d" → "1 Hari", "7d" → "7 Hari", "1w" → "1 Minggu"
function fmtValidity(string $v):string {
    if(!$v) return '';
    $v=trim($v);
    if(preg_match('/^(\d+)h$/i',$v,$m)) return $m[1].' Jam';
    if(preg_match('/^(\d+)d$/i',$v,$m)) return $m[1].' Hari';
    if(preg_match('/^(\d+)w$/i',$v,$m)) return $m[1].' Minggu';
    if(preg_match('/^(\d+)m$/i',$v,$m)) return $m[1].' Bulan';
    // Format MikroTik uptime: "00:30:00" atau "1d00:00:00"
    if(preg_match('/^(\d+)d(\d+):(\d+):(\d+)$/',$v,$m)){
        $total=$m[1]*86400+$m[2]*3600+$m[3]*60+$m[4];
        if($m[1]>0)return $m[1].' Hari';
        if($m[2]>0)return $m[2].' Jam';
    }
    if(preg_match('/^(\d+):(\d+):(\d+)$/',$v,$m)){
        if($m[1]>0)return (int)$m[1].' Jam';
        if($m[2]>0)return (int)$m[2].' Menit';
    }
    return h($v); // fallback: tampilkan apa adanya
}
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
ini_set('max_execution_time',300);
$db=db();$msg='';$mtype='ok';

$mikhRouters=$db->query("SELECT * FROM routers WHERE is_active=1 AND use_mikhmon=1 ORDER BY is_main DESC,name ASC")->fetchAll();
if(empty($mikhRouters))$mikhRouters=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY is_main DESC,name ASC")->fetchAll();
$selRid=(int)($_GET['rid']??0);
// Hanya otomatis pilih jika cabangnya cuma 1
if(!$selRid&&count($mikhRouters)===1)$selRid=(int)$mikhRouters[0]['id'];
$selRouter=null;
foreach($mikhRouters as $r){if((int)$r['id']===$selRid){$selRouter=$r;break;}}

/* ── Random generators (Mikhmon) ── */
function vRandN(int $l):string{$c='23456789';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandLC(int $l):string{$c='abcdefghijkmnprstuvwxyz';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandUC(int $l):string{$c='ABCDEFGHJKLMNPRSTUVWXYZ';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandULC(int $l):string{$c='ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandNLC(int $l):string{$c='23456789abcdefghijkmnprstuvwxyz';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandNUC(int $l):string{$c='23456789ABCDEFGHJKLMNPRSTUVWXYZ';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function vRandNULC(int $l):string{$c='23456789ABCDEFGHJKLMNPRSTUVWXYZabcdefghijkmnprstuvwxyz';$r='';for($i=0;$i<$l;$i++)$r.=$c[rand(0,strlen($c)-1)];return $r;}
function genVcr(int $len,string $char,string $pfx,string $mode):array{
    switch($char){case 'num':$c=vRandN($len);break;case 'lower':$c=vRandLC($len);break;case 'upper':$c=vRandUC($len);break;case 'upplow':$c=vRandULC($len);break;case 'mix':$c=vRandNLC($len);break;case 'mix1':$c=vRandNUC($len);break;default:$c=vRandNULC($len);}
    $u=$pfx.$c;$p=($mode==='vc')?$u:vRandN($len);return[$u,$p];
}
function parseProf(array $p):array{
    $ol=$p['on-login']??'';
    // Mikhmon format: :put (",expmode,price,validity,sprice,,lockuser,"); ...
    // Gunakan smart parsing berdasarkan isi, bukan posisi (handle format lama & baru)
    preg_match('/^:put \(",(.*?)"/',$ol,$m);
    $x=array_map('trim',explode(',',$m[1]??''));
    $expmode='-';$price=0;$validity='-';$sprice=0;$lockuser='Disable';
    $emos=['rem','ntf','remc','ntfc','0','noexp'];
    foreach($x as $v){
        if($v==='')continue;
        if(in_array($v,$emos)){$expmode=$v;continue;}
        // validity: angka diikuti h/d/w/m  (contoh: 24h, 1d, 7d, 30m)
        if(preg_match('/^\d+[hdwm]$/i',$v)){$validity=$v;continue;}
        if(in_array(strtolower($v),['enable','disable','mikhmon'])){$lockuser=in_array(strtolower($v),['enable','disable'])?ucfirst(strtolower($v)):$lockuser;continue;}
        // Angka: hanya ambil yang wajar sebagai harga (< 1.000.000), abaikan rand_id besar
        if(is_numeric($v)&&(int)$v>0&&(int)$v<1000000){$price===0?$price=(int)$v:($sprice===0?$sprice=(int)$v:null);}
    }
    return['name'=>$p['name']??'-','rate'=>$p['rate-limit']??'-','shared'=>$p['shared-users']??'1',
           'expmode'=>$expmode,'price'=>$price,'validity'=>$validity,'sprice'=>$sprice,
           'lockuser'=>$lockuser,'pool'=>$p['address-pool']??'any'];
}
function rp(int $n):string{return 'Rp '.number_format($n,0,',','.');}

/* ── POST handlers ── */
if($_SERVER['REQUEST_METHOD']==='POST'&&$selRouter){
    $act=$_POST['action']??'';$api=MikrotikAPI::fromRouter($selRouter);
    if($act==='generate_voucher'){
        $qty=min(2000,max(1,(int)($_POST['qty']??1)));$profile=trim($_POST['profile']??'');
        $prefix=trim($_POST['prefix']??'');$vlen=(int)($_POST['vlen']??6);$char=$_POST['char']??'mix2';
        $vmode=$_POST['vmode']??'vc';$reseller=trim($_POST['reseller']??'');$server=trim($_POST['server']??'all');
        $timelimit=trim($_POST['timelimit']??'');$datalimit=(int)($_POST['datalimit']??0);$mbgb=(int)($_POST['mbgb']??1048576);
        if(!$profile){$msg='Pilih profile terlebih dahulu!';$mtype='err';}
        elseif(!$api->connect()){$msg='❌ Konek gagal: '.$api->error;$mtype='err';}
        else{
            $dlimit=$datalimit>0?$datalimit*$mbgb:0;
            // Format comment PERSIS Mikhmon:
            // Mikhmon: $commt = $user."-".rand."-".date("m.d.y")."-".$adcomment
            // adcomment = reseller name (bisa kosong → trailing dash saja)
            // JANGAN tambahkan nama profil otomatis - biarkan kosong jika reseller tidak diisi
            // Ini agar match dengan format Mikhmon dan batch_list bisa mengelompokkan dengan benar
            $batchReseller=trim($reseller); // hanya reseller, TIDAK tambah profile
            $commt=$vmode.'-'.rand(100,999).'-'.date('m.d.y').'-'.$batchReseller;
            // Result: "vc-293-04.06.26-" (jika reseller kosong) atau "vc-293-04.06.26-RUMAH"
            // Sama persis dengan format Mikhmon!
            set_time_limit(300); // 5 menit untuk generate voucher banyak
            // Fetch existing names dengan minimal proplist (cepat)
            $existNames=array_column($api->parse($api->talk(['/ip/hotspot/user/print','=.proplist=name'])),'name');
            $existSet=array_flip($existNames); // flip untuk O(1) lookup
            $profRaw=$api->parse($api->talk(['/ip/hotspot/user/profile/print','?name='.$profile]));
            $pd=!empty($profRaw)?parseProf($profRaw[0]):[];
            $generated=[];$i=0;$tries=0;
            // Generate & tambahkan ke MikroTik
            while($i<$qty&&$tries<$qty*3){
                $tries++;list($u,$p)=genVcr($vlen,$char,$prefix,$vmode);
                if(isset($existSet[$u]))continue; // O(1) lookup instead of in_array
                $existSet[$u]=true;
                $cmd=['/ip/hotspot/user/add','=name='.$u,'=password='.$p,'=profile='.$profile,'=comment='.$commt];
                if($server!=='all')$cmd[]='=server='.$server;
                if($timelimit)$cmd[]='=limit-uptime='.$timelimit;
                if($dlimit)$cmd[]='=limit-bytes-total='.$dlimit;
                $api->talk($cmd);$generated[]=['user'=>$u,'pass'=>$p,'vmode'=>$vmode];$i++;
            }
            $api->close();
            $_SESSION['snm_gen']=['vouchers'=>$generated,'reseller'=>$reseller,'profile'=>$profile,'rid'=>$selRid,'date'=>date('d/m/Y H:i'),'pd'=>$pd,'vmode'=>$vmode,'prefix'=>$prefix,'timelimit'=>$timelimit,'batch_comment'=>$commt];
            // Track recent batch comments per router (untuk batch_list hint)
            if(!isset($_SESSION['recent_batches'][$selRid]))$_SESSION['recent_batches'][$selRid]=[];
            array_unshift($_SESSION['recent_batches'][$selRid],$commt);
            $_SESSION['recent_batches'][$selRid]=array_unique(array_slice($_SESSION['recent_batches'][$selRid],0,20));
            auditLog('generate_voucher',"$qty vcr profile=$profile reseller=$reseller");
            $msg='✅ <strong>'.count($generated).'</strong> voucher berhasil dibuat! Comment batch: <code>'.$commt.'</code>';$mtype='ok';
            // Tetap di tab voucher untuk preview, user bisa klik HS Users untuk print
            $_SESSION['snm_active_tab_'.$selRid]='voucher';
        }
    }
    if($act==='add_profile'){
        $name=preg_replace('/\s+/','-',trim($_POST['pname']??''));$rate=trim($_POST['ratelimit']??'');$shared=(int)($_POST['shared']??1);
        $validity=trim($_POST['validity']??'1d');$price=(int)($_POST['price']??0);$sprice=(int)($_POST['sprice']??0);
        $expmode=$_POST['expmode']??'remc';$pool=trim($_POST['addrpool']??'');$lockuser=$_POST['lockuser']??'Disable';
        if(!$name){$msg='Nama profil kosong!';$mtype='err';}
        elseif(!$api->connect()){$msg='❌ Konek gagal';$mtype='err';}
        else{
            $lock=($lockuser==='Enable')?'; [:local mac $"mac-address"; /ip hotspot user set mac-address=$mac [find where name=$user]]':'';
            $record='; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'.$price.'-|-$address-|-$mac-|-'.$validity.'-|-'.$name.'-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
            // On-login Mikhmon LENGKAP — persis format resmi dengan kalkulasi tanggal h/d/w/m
            $mode=in_array($expmode,['rem','ntf'])?'S':'R'; // S=simple, R=record
            $olHead=':put (\",'.$expmode.','.$price.','.$validity.','.$sprice.',,,'.$lockuser.',,mikhmon,'.rand(1000000,9999999).',\"); :local mode \"'.$mode.'\"; ';
            $olBody='{:local itv do={:local getITV [:pic $v 0 ([ :len $v ] - 1)]; :return $getITV;}; :local ndays do={:local mdays {31;28;31;30;31;30;31;31;30;31;30;31}; :local months {"jan"=1;"feb"=2;"mar"=3;"apr"=4;"may"=5;"jun"=6;"jul"=7;"aug"=8;"sep"=9;"oct"=10;"nov"=11;"dec"=12}; :local monthr {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"}; :local dd [:tonum [:pick $date 4 6]]; :local yy [:tonum [:pick $date 7 11]]; :local month [:pick $date 0 3]; :local mm (:$months->$month); :set dd ($dd+$days); :local dm [:pick $mdays ($mm-1)]; :if ($mm=2 && (($yy&3=0 && ($yy/100*100 != $yy)) || $yy/400*400=$yy) ) do={ :set dm 29 }; :while ($dd>$dm) do={ :set dd ($dd-$dm); :set mm ($mm+1); :if ($mm>12) do={ :set mm 1; :set yy ($yy+1);}; :set dm [:pick $mdays ($mm-1)]; :if ($mm=2 && (($yy&3=0 && ($yy/100*100 != $yy)) || $yy/400*400=$yy) ) do={ :set dm 29 };}; :local res "$[:pick $monthr ($mm-1)]/"; :if ($dd<10) do={ :set res ($res."0") }; :set $res "$res$dd/$yy"; :return $res;}; :local convert do={:local monthr {"jan";"feb";"mar";"apr";"may";"jun";"jul";"aug";"sep";"oct";"nov";"dec"};:local dd [:pick $date 8 11];:local yy [:tonum [:pick $date 0 4]];:local mm [:tonum [:pick $date 5 7]];:local mmm "$[:pick $monthr ($mm-1)]";:local newdate "$mmm/$dd/$yy";:return $newdate;}; :local validity \"'.$validity.'\"; :local date [ /system clock get date ]; if ([:pick $date 4 5] = \"-\" and [:pick $date 7 8] = \"-\" ) do={:set date [$convert date=$date];} else={:set date $date;}; :local year [ :pick $date 7 11 ];:local month [ :pick $date 0 3 ]; :local time [ /system clock get time ]; :local comment [ /ip hotspot user get [/ip hotspot user find where name=\"$user\"] comment]; :local ucode [:pic $comment 0 2]; :if ($ucode = \"vc\" or $ucode = \"up\" or $comment = \"\") do={ :local days \"\"; :local ndate \"\"; :local ctime \"\"; :local getDT [:pic $validity ([ :len $validity ] - 1) [ :len $validity ]]; :if ($getDT= \"d\") do={ :set days [$itv v=$validity]; :set ndate [$ndays date=$date days=$days ]; :set ctime $time;}; :if ($getDT = \"h\" or $getDT = \"m\") do={ :local curt ([/system clock get time]+$validity); :if ([:len $curt] > 8) do={ :set validity [:pic $curt 0 ([ :len $curt ] - 8) ]; :set curt [:pic $curt [ :len $validity ] [ :len $curt ] ]; :set days [$itv v=$validity]; :set ndate [$ndays date=$date days=$days ]; :set ctime $curt; } else={ :set days 0; :set ndate $date; :set ctime $curt;}}; /ip hotspot user set comment=\"$ndate $ctime $mode\" [find where name=\"$user\"]; ';
            $olRec='; :local mac $"mac-address"; :local time [/system clock get time ]; /system script add name="$date-|-$time-|-$user-|-'.$price.'-|-$address-|-$mac-|-'.$validity.'-|-'.$name.'-|-$comment" owner="$month$year" source="$date" comment="mikhmon"';
            if($expmode==='rem'){$ol=$olHead.$olBody.$lock.'}}';$olMode='remove';}
            elseif($expmode==='ntf'){$ol=$olHead.$olBody.$lock.'}}';$olMode='set limit-uptime=1s';}
            elseif($expmode==='remc'){$ol=$olHead.$olBody.$olRec.$lock.'}}';$olMode='remove';}
            elseif($expmode==='ntfc'){$ol=$olHead.$olBody.$olRec.$lock.'}}';$olMode='set limit-uptime=1s';}
            elseif($expmode==='0'){$ol=':put (\",,0,,0,,,Disable,\")'.$lock;$olMode='';}
            else{$ol='';$olMode='';}
            $cmd=['/ip/hotspot/user/profile/add','=name='.$name,'=rate-limit='.$rate,'=shared-users='.$shared,'=status-autorefresh=1m','=on-login='.$ol];
            if($pool)$cmd[]='=address-pool='.$pool;$r=$api->talk($cmd);$api->close();
            $ok=in_array('!done',$r);$msg=$ok?"✅ Profil <b>$name</b> berhasil dibuat!":'❌ Gagal buat profil';$mtype=$ok?'ok':'err';
            if($ok)$_SESSION['snm_active_tab_'.$selRid]='profil';
        }
    }
    if($act==='del_profile'){$pname=trim($_POST['pname']??'');if($pname&&$api->connect()){$raw=$api->parse($api->talk(['/ip/hotspot/user/profile/print','?name='.$pname]));if(!empty($raw[0]['.id']))$api->talk(['/ip/hotspot/user/profile/remove','=.id='.$raw[0]['.id']]);$api->close();$msg="Profil $pname dihapus.";$mtype='ok';}}
    if($act==='disc_hotspot'||$act==='disc_pppoe'){$sid=$_POST['session_id']??'';if($sid&&$api->connect()){$ok=($act==='disc_hotspot')?$api->disconnectHotspot($sid):$api->disconnectPpp($sid);$api->close();$msg=$ok?'✅ Sesi diputus!':'❌ Gagal';$mtype=$ok?'ok':'err';}}
}

/* ── Load basic data ── */
/* ── Load basic data ── */
$profiles=[];$servers=[];$pools=[];$hsActive=[];$hsCount=0;
// Data loaded via AJAX init_data to prevent slow page load
// Clear session jika diminta atau jika router berbeda
if(isset($_GET['clear_gen'])){unset($_SESSION['snm_gen']);header("Location: /admin/snetmonitoring.php?rid=$selRid");exit;}
$lastGen=$_SESSION['snm_gen']??null;
if($lastGen&&(int)($lastGen['rid']??0)!==$selRid){$lastGen=null;unset($_SESSION['snm_gen']);}
$genVouchers=$lastGen?$lastGen['vouchers']:[];
$genPD=$lastGen?($lastGen['pd']??[]):[];
$genProfile=$lastGen?h($lastGen['profile']??''):'';
$genReseller=$lastGen?h($lastGen['reseller']??''):'';
$genDate=$lastGen?($lastGen['date']??''):'';
$genVmode=$lastGen?($lastGen['vmode']??'vc'):'vc';
$genTimelimit=$lastGen?h($lastGen['timelimit']??''):''; // durasi pakai override

startPage('S.NET Monitoring');
?>
<style>
/* ══ LAYOUT ═════════════════════════════════════════════════ */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.gr{display:grid;grid-template-columns:1fr 360px;gap:14px;align-items:start}
.f2{display:grid;grid-template-columns:1fr 1fr;gap:11px}
.pbar{background:var(--g200);border-radius:20px;height:7px;margin-top:4px;overflow:hidden}
.pbar-i{height:7px;border-radius:20px;transition:.5s}
@keyframes spin{to{transform:rotate(360deg)}}

/* ══ MONTH / RESELLER FILTER TABS ════════════════════════════ */
.mth-tabs{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:12px}
.mth-btn{padding:5px 13px;border-radius:20px;border:2px solid var(--g200);background:#fff;
         font-family:'Exo 2',sans-serif;font-size:.74rem;font-weight:600;cursor:pointer;color:var(--g600);transition:.2s}
.mth-btn.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.mth-btn:hover:not(.on){border-color:var(--blue);color:var(--blue)}

/* ══ BAR CHART ═══════════════════════════════════════════════ */
.rbar-row{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.rbar-lbl{font-size:.72rem;min-width:108px;flex-shrink:0;color:var(--g600)}
.rbar-lbl.today{color:var(--blue-d);font-weight:700}
.rbar-bg{flex:1;background:var(--g100);border-radius:5px;height:22px;overflow:hidden;min-width:60px}
.rbar-fill{height:22px;border-radius:5px;display:flex;align-items:center;padding:0 7px;transition:.5s;min-width:4px}
.rbar-val{font-size:.72rem;font-weight:700;color:var(--orange);min-width:90px;text-align:right;flex-shrink:0}

/* ══ PREVIEW VOUCHER (screen) ════════════════════════════════ */
.vcr-prev-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px}
.vcr-prev{background:#fff;border:2px solid var(--g200);border-radius:10px;padding:11px 12px;position:relative;transition:.15s}
.vcr-prev:hover{border-color:var(--blue);box-shadow:0 4px 14px rgba(27,63,166,.12)}

/* ══ PRINT AREA ═══════════════════════════════════════════════ */
#printArea{display:none}
.print-header{font-family:Arial,sans-serif;font-size:8.5pt;display:flex;justify-content:space-between;
    align-items:center;padding-bottom:3pt;margin-bottom:4pt;color:#444;
    border-bottom:2pt solid #122B7A}
.print-header .ph-left{font-weight:900;font-size:11pt;color:#122B7A}
.print-header .ph-mid{font-size:8pt;color:#555;text-align:center}
.print-header .ph-right{font-size:8pt;color:#888}
/* Grid voucher — garis dashed sebagai tanda potong */
.vcr-grid{display:grid;grid-template-columns:repeat(5,1fr);
    border-top:1pt dashed #ccc;border-left:1pt dashed #ccc}
.vcr{padding:0;font-family:Arial,sans-serif;position:relative;
    box-sizing:border-box;page-break-inside:avoid;break-inside:avoid;
    border-right:1pt dashed #ccc;border-bottom:1pt dashed #ccc;
    background:#fff}
/* Header biru */
.vcr-hdr{background:linear-gradient(135deg,#0F2266,#1B3FA6);
    padding:2pt 3pt;display:flex;align-items:center;justify-content:space-between;
    margin:2pt 2pt 0}
.vcr-brand{font-size:5.5pt;font-weight:900;color:#fff;letter-spacing:.5pt}
.vcr-badge{font-size:4.5pt;font-weight:700;background:rgba(255,255,255,.25);
    color:#fff;padding:1pt 3pt;border-radius:10pt;white-space:nowrap;
    max-width:40pt;overflow:hidden;text-overflow:ellipsis}
/* Kode voucher */
.vcr-body{text-align:center;padding:2pt 2pt 1pt}
.vcr-clbl{font-size:4pt;color:#9CA3AF;font-weight:700;letter-spacing:.5pt;
    text-transform:uppercase;margin-bottom:1pt}
.vcr-code{font-family:'Courier New',monospace;font-size:9.5pt;font-weight:900;
    color:#0F2266;letter-spacing:1pt;line-height:1.1;word-break:break-all;
    background:#EEF4FF;border:0.5pt solid #BFDBFE;border-radius:2pt;
    padding:2pt;display:block;text-align:center;margin:0 2pt}
/* Password */
.vcr-pass{display:flex;justify-content:space-between;align-items:center;
    background:#FFFBEB;border-top:0.5pt dashed #FDE68A;border-bottom:0.5pt dashed #FDE68A;
    padding:1.5pt 3pt;margin-top:1.5pt}
.vcr-plbl{font-size:4pt;color:#92400E;font-weight:800;text-transform:uppercase;letter-spacing:.3pt}
.vcr-pval{font-family:'Courier New',monospace;font-size:7pt;font-weight:700;color:#B45309;letter-spacing:0.5pt}
/* Footer: masa aktif + durasi + harga */
.vcr-foot{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;
    background:#F0FDF4;padding:1.5pt 3pt;margin:0;gap:1.5pt}
.vcr-foot-info{display:flex;flex-direction:column;gap:.5pt;flex:1 1 auto;min-width:0}
.vcr-info-row{display:flex;align-items:center;gap:1.5pt;flex-wrap:wrap}
.vcr-info-lbl{font-size:4pt;color:#6B7280;font-weight:700;min-width:16pt;
    text-transform:uppercase;letter-spacing:.2pt}
.vcr-valid{font-size:5pt;font-weight:900;color:#15803D;white-space:nowrap}
.vcr-dur{font-size:5pt;font-weight:800;color:#1D4ED8;white-space:nowrap}
.vcr-price{font-size:6pt;font-weight:900;color:#D97706;flex:0 0 auto;text-align:right}
.vcr-num{position:absolute;top:1pt;right:3pt;font-size:4.5pt;
    color:rgba(255,255,255,.6);font-weight:700;z-index:5}

/* ══ MOBILE ══════════════════════════════════════════════════ */
@media(max-width:900px){
    .g2,.gr{grid-template-columns:1fr}
    .f2{grid-template-columns:1fr}
    .vcr-prev-grid{grid-template-columns:repeat(auto-fill,minmax(145px,1fr))}
    .rbar-lbl{min-width:80px;font-size:.68rem}
    .rbar-val{min-width:70px;font-size:.68rem}
}
@media(max-width:480px){
    .vcr-prev-grid{grid-template-columns:1fr 1fr}
}

/* ══ PRINT STYLES ════════════════════════════════════════════ */
@media print{
    .no-print,.hdr,.sb,.ftr,.ov,main>*:not(#printArea){display:none!important}
    main{margin:0!important;padding:0!important}
    body,html{background:#fff!important;margin:0;padding:0}
    #printArea{display:block!important}
    .vcr-grid{display:grid!important}
    .vcr::before{display:none!important}
    @page{size:A4 landscape;margin:4mm 5mm} /* default landscape 80/A4 */
}
</style>

<!-- ══ PRINT AREA — hanya muncul saat print ══════════════════ -->
<div id="printArea">
<?php if($genVouchers):
    // Format validity: "24h" → "24 Jam", "1d" → "1 Hari", "7d" → "7 Hari", "1w" → "1 Minggu"
    $validityRaw=$genPD['validity']??'';
    $validity=fmtValidity($validityRaw);
    $timelimitDisplay=fmtValidity($lastGen['timelimit']??'');// durasi pakai dari generate
    // Jika timelimit di-override saat generate, tampilkan. Jika tidak, cek dari profil
    // Mikhmon: on-login script set limit-uptime dari profil - kita tidak store di session tapi dari profile
    $price=$genPD['sprice']??$genPD['price']??0;
?>
<div class="print-header">
    <div class="ph-left">📡 S.NET</div>
    <div class="ph-mid">
        <strong><?=$genProfile?></strong><?php if($genReseller):?> &nbsp;·&nbsp; <?=$genReseller?><?php endif;?>
        <?php if($validity):?> &nbsp;·&nbsp; ⏱ <?=$validity?><?php endif;?>
        <?php if($price>0):?> &nbsp;·&nbsp; Rp <?=number_format($price,0,',','.')?><?php endif;?>
    </div>
    <div class="ph-right"><?=count($genVouchers)?> vcr &nbsp;·&nbsp; <?=$genDate?></div>
</div>
<div class="vcr-grid" id="vcrGrid" style="grid-template-columns:repeat(8,1fr)">
<?php foreach($genVouchers as $idx=>$v):
    $isVC=($v['vmode']??$genVmode)==='vc';
?>
<div class="vcr">
    <span class="vcr-num">#<?=$idx+1?></span>
    <div class="vcr-hdr">
        <div class="vcr-brand">S.NET</div>
        <div class="vcr-badge"><?=h($genProfile)?></div>
    </div>
    <div class="vcr-body">
        <div class="vcr-clbl"><?=$isVC?'KODE VOUCHER':'USERNAME'?></div>
        <div class="vcr-code"><?=h($v['user'])?></div>
    </div>
    <?php if(!$isVC):?>
    <div class="vcr-pass">
        <div class="vcr-plbl">Password</div>
        <div class="vcr-pval"><?=h($v['pass'])?></div>
    </div>
    <?php endif;?>
    <div class="vcr-foot">
        <div class="vcr-foot-info">
            <?php if($validity):?><div class="vcr-info-row"><span class="vcr-info-lbl">Masa Aktif</span><span class="vcr-valid"><?=strtoupper($validity)?></span></div><?php endif;?>
            <?php if($timelimitDisplay):?><div class="vcr-info-row"><span class="vcr-info-lbl">Durasi</span><span class="vcr-dur"><?=strtoupper($timelimitDisplay)?></span></div><?php endif;?>
        </div>
        <?php if($price>0):?><div class="vcr-price">Rp <?=number_format($price,0,',','.')?></div><?php endif;?>
    </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>
</div>
<!-- ── end printArea ── -->

<div class="no-print">
<div class="ph">
    <div>
        <div class="ph-t">📡 S.NET Monitoring</div>
        <div class="ph-s">Dashboard · Generate Voucher · Profil Reseller · Laporan Penjualan</div>
    </div>
    <?php if($genVouchers):?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <span style="font-size:.78rem;color:rgba(255,255,255,.8);background:rgba(255,255,255,.15);padding:4px 10px;border-radius:6px">✅ <?=count($genVouchers)?> voucher berhasil dibuat</span>
        <a href="/admin/snetmonitoring.php?rid=<?=$selRid?>&clear_gen=1" class="btn bo bsm">✕ Clear</a>
    </div>
    <?php endif;?>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<!-- Router selector -->
<?php if(count($mikhRouters)>1):?>
<div class="card"><div class="cb" style="padding:10px 14px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1;min-width:180px;max-width:360px"><label class="fl">🌐 Router</label>
            <select name="rid" class="fsel" onchange="this.form.submit()">
                <?php if(!$selRid):?><option value="">— Pilih Router —</option><?php endif;?>
                <?php foreach($mikhRouters as $r):?>
                <option value="<?=$r['id']?>" <?=$selRid==$r['id']?'selected':''?>><?=h($r['name'])?> — <?=h($r['ip_public'])?><?=$r['is_main']?' ⭐':''?></option>
                <?php endforeach;?>
            </select>
        </div>
    </form>
</div></div>
<?php endif;?>

<?php if(!$selRouter):?>
    <?php if(count($mikhRouters)>1):?>
    <div class="card" style="max-width:600px;margin:40px auto;text-align:center">
        <div class="ch"><div class="ct" style="justify-content:center;font-size:1.2rem">🏢 Pilih Cabang / Router</div></div>
        <div class="cb" style="padding:30px">
            <p style="color:var(--g500);margin-bottom:20px">Silakan pilih cabang (router) untuk membuka S.NET Monitoring.</p>
            <div style="display:grid;gap:10px">
            <?php foreach($mikhRouters as $r):?>
                <a href="?rid=<?=$r['id']?>" class="btn bo" style="padding:15px;font-size:1.1rem;display:flex;justify-content:space-between;align-items:center;text-decoration:none">
                    <span>🌐 <?=h($r['name'])?></span>
                    <span style="font-size:0.8rem;color:var(--g500)"><?=h($r['ip_public'])?></span>
                </a>
            <?php endforeach;?>
            </div>
        </div>
    </div>
    <?php else:?>
    <div class="alert awrn">⚠️ Tidak ada router. <a href="/admin/routers.php">Tambah router</a></div>
    <?php endif;?>
<?php else:?>

<!-- ════════════════════════════════════════════════════════════
     MAIN TABS  —  id="mainTab-xxx" dan tp id="tp-xxx"
     TIDAK memakai swTab() dari layout — pakai goTab() sendiri
════════════════════════════════════════════════════════════ -->
<div class="tabnav" id="mainTabNav">
    <button class="tab" id="mainTab-dashboard" onclick="goTab('dashboard')">📊 Dashboard</button>
    <button class="tab" id="mainTab-voucher"   onclick="goTab('voucher')">🎫 Generate<?php if($genVouchers):?> <span style="background:var(--red);color:#fff;border-radius:10px;padding:1px 6px;font-size:.62rem;margin-left:3px"><?=count($genVouchers)?></span><?php endif;?></button>
    <button class="tab" id="mainTab-profil"    onclick="goTab('profil')">👥 Profil (<?=count($profiles)?>)</button>
    <button class="tab" id="mainTab-laporan"   onclick="goTab('laporan')">📈 Laporan</button>
    <button class="tab" id="mainTab-users"     onclick="goTab('users')">📋 HS Users</button>
</div>

<!-- ════ TAB: DASHBOARD ═════════════════════════════════════ -->
<div class="tp" id="tp-dashboard">

<!-- Stat cards -->
<div class="stats" style="grid-template-columns:repeat(auto-fill,minmax(125px,1fr));margin-bottom:14px">
    <div class="stat" style="--c:var(--blue)"><div class="stat-l">🟢 HS Aktif</div><div class="stat-n" style="color:var(--blue)" id="d-hsa">⏳</div><div class="stat-d" id="d-hst">sesi aktif</div></div>
    <div class="stat" style="--c:var(--green)"><div class="stat-l">🔌 PPPoE On</div><div class="stat-n" style="color:var(--green)" id="d-ppa">⏳</div><div class="stat-d" id="d-ppt">sesi aktif</div></div>
    <div class="stat" style="--c:var(--red)"><div class="stat-l">📴 PPPoE Off</div><div class="stat-n" style="color:var(--red)" id="d-ppo">⏳</div><div class="stat-d" id="d-ppod">offline</div></div>
    <div class="stat" style="--c:var(--orange)"><div class="stat-l">📅 Hari Ini</div><div class="stat-n" style="font-size:.82rem;color:var(--orange)" id="d-revd">⏳</div><div class="stat-d" id="d-revdc">-</div></div>
    <div class="stat" style="--c:var(--purple)"><div class="stat-l">📆 Bulan Ini</div><div class="stat-n" style="font-size:.82rem;color:var(--purple)" id="d-revm">⏳</div><div class="stat-d" id="d-revmc">-</div></div>
</div>

<div class="g2" style="margin-bottom:14px">
<div class="card" style="margin:0">
    <div class="ch"><div class="ct" id="d-identity">🖥️ Resource</div></div>
    <div class="cb" id="d-resource" style="padding:12px 16px">
        <div style="text-align:center;padding:20px;color:var(--g400)"><span style="animation:spin 1s linear infinite;display:inline-block;font-size:1.5rem">⏳</span></div>
    </div>
</div>
<div class="card" style="margin:0">
    <div class="ch"><div class="ct" id="dash-hs-title">🟢 Hotspot Aktif (0)</div></div>
    <div id="dash-hs-active" style="max-height:270px;overflow-y:auto">
        <div style="text-align:center;padding:20px;color:var(--g400)"><span style="animation:spin 1s linear infinite;display:inline-block;font-size:1.5rem">⏳</span><br>Memuat data...</div>
    </div>
</div>
</div>

<!-- PPPoE sub-tabs -->
<div class="tabnav" id="dashTabNav">
    <button class="tab" id="dashTab-ppp-on" onclick="goDashTab('ppp-on')">🔌 PPPoE Aktif</button>
    <button class="tab" id="dashTab-ppp-off" onclick="goDashTab('ppp-off')">📴 PPPoE Offline</button>
</div>
<div class="tp" id="tp-ppp-on"><div class="card" id="ppp-on-wrap"><div class="ch"><div class="ct">🔌 PPPoE Aktif</div></div><div style="text-align:center;padding:24px;color:var(--g400)"><span style="animation:spin 1s linear infinite;display:inline-block">⏳</span> Memuat...</div></div></div>
<div class="tp" id="tp-ppp-off"><div id="ppp-off-wrap"><div class="card"><div class="cb" style="text-align:center;padding:20px;color:var(--g400)">Klik tab untuk memuat...</div></div></div></div>
</div><!-- /tp-dashboard -->

<!-- ════ TAB: GENERATE VOUCHER ══════════════════════════════ -->
<div class="tp" id="tp-voucher">
<div class="gr">

<!-- Form generate -->
<div class="card" style="margin:0">
    <div class="ch"><div class="ct">🎫 Generate Voucher Hotspot</div><div style="font-size:.72rem;color:var(--g400)">Maks 2000 voucher/sekali</div></div>
    <div class="cb">
        <form method="POST" id="genForm">
            <input type="hidden" name="action" value="generate_voucher">
            <div class="f2">
                <div class="fg"><label class="fl">🔢 Jumlah *</label><input type="number" name="qty" class="fc" min="1" max="2000" value="10" style="font-size:1.1rem;font-weight:700;text-align:center"></div>
                <div class="fg"><label class="fl">📋 Profile *</label>
                    <select name="profile" class="fsel" id="selProf" onchange="showPI(this.value)">
                        <option value="">⏳ Memuat Profile...</option>
                    </select>
                    <div id="profInfo" class="fhint" style="display:none;margin-top:4px;padding:5px 8px;background:var(--g50);border-radius:6px;border:1px solid var(--g200)"></div>
                </div>
                <div class="fg"><label class="fl">🖧 Server</label><select name="server" id="selServer" class="fsel"><option value="all">all (semua)</option></select></div>
                <div class="fg"><label class="fl">👤 Nama Reseller</label><input type="text" name="reseller" class="fc" placeholder="reseller01" maxlength="20"><div class="fhint">Untuk filter laporan per reseller</div></div>
                <div class="fg"><label class="fl">🔤 Prefix Voucher</label><input type="text" name="prefix" class="fc" placeholder="SN-" maxlength="8" style="font-family:'JetBrains Mono',monospace;letter-spacing:1px"></div>
                <div class="fg"><label class="fl">📏 Panjang Kode</label><select name="vlen" class="fsel"><?php for($i=4;$i<=12;$i++):?><option value="<?=$i?>" <?=$i===6?'selected':''?>><?=$i?> karakter</option><?php endfor;?></select></div>
                <div class="fg"><label class="fl">🔡 Tipe Karakter</label><select name="char" class="fsel"><option value="mix2">Angka+Huruf Campur ★</option><option value="mix">Angka+huruf kecil</option><option value="mix1">Angka+huruf besar</option><option value="lower">Huruf kecil (abcd)</option><option value="upper">Huruf besar (ABCD)</option><option value="upplow">Campur aBcD</option><option value="num">Angka saja</option></select></div>
                <div class="fg"><label class="fl">🎯 Mode Voucher</label><select name="vmode" class="fsel"><option value="vc">Voucher — user = password ★</option><option value="up">User+Pass — user ≠ password</option></select></div>
                <div class="fg"><label class="fl">⏱ Time Limit Override</label><input type="text" name="timelimit" class="fc" placeholder="Kosong = ikut profile"></div>
                <div class="fg"><label class="fl">📦 Data Limit Override</label><div style="display:flex;gap:6px"><input type="number" name="datalimit" class="fc" min="0" placeholder="0"><select name="mbgb" class="fsel" style="width:70px"><option value="1048576">MB</option><option value="1073741824">GB</option></select></div></div>
            </div>
            <div class="alert ainf" style="margin:4px 0 10px;font-size:.75rem">⚡ Generate 2000 voucher ±2 menit. Jangan tutup halaman.</div>
            <button type="button" class="btn bp" style="width:100%;padding:12px;font-size:.95rem" id="genBtn" onclick="onGen(this)">🎫 Generate Voucher Sekarang</button>
        </form>
    </div>
</div>

<!-- Panel kanan: hasil + preview -->
<div>
<?php if($genVouchers):?>
<div class="card" style="margin:0 0 12px">
    <div class="ch">
        <div>
            <div class="ct">✅ <?=count($genVouchers)?> Voucher Siap</div>
            <div style="font-size:.7rem;color:var(--g400)"><?=$genDate?> · <?=$genProfile?><?php if($genReseller):?> · <strong style="color:var(--purple)"><?=$genReseller?></strong><?php endif;?></div>
        </div>
        <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center">
            <span style="font-size:.75rem;color:var(--g500)">Untuk print/cek voucher → Tab <strong>HS Users</strong> → Voucher Per Batch</span>
        </div>
    </div>
    <div class="cb" style="padding:10px">
        <!-- Preview 30 pertama, sisanya dicetak semua -->
        <div style="font-size:.7rem;color:var(--g400);margin-bottom:8px">
            Preview <?=min(30,count($genVouchers))?> voucher — layout cetak: <strong>5 kolom × 12 baris = 60 voucher/lembar A4</strong>
        </div>
        <div class="vcr-prev-grid">
        <?php foreach(array_slice($genVouchers,0,30) as $idx=>$v):
            $validity=fmtValidity($genPD['validity']??'');$price=$genPD['sprice']??$genPD['price']??0;
        ?>
        <div class="vcr-prev">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
                <span style="font-size:.63rem;font-weight:900;color:var(--blue-d)">📡 S.NET</span>
                <span style="font-size:.58rem;background:var(--blue-d);color:#fff;padding:1px 5px;border-radius:3px;overflow:hidden;max-width:70px;text-overflow:ellipsis;white-space:nowrap"><?=$genProfile?></span>
            </div>
            <div style="background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border-radius:6px;padding:7px 5px;text-align:center;margin-bottom:5px;border:1px solid #BFDBFE">
                <div style="font-size:.58rem;color:var(--blue-d);font-weight:700;letter-spacing:.5px;margin-bottom:2px"><?=($v['vmode']??$genVmode)==='vc'?'KODE VOUCHER':'USERNAME'?></div>
                <div style="font-family:'JetBrains Mono',monospace;font-size:.92rem;font-weight:900;color:var(--blue-d);letter-spacing:1.5px;word-break:break-all"><?=h($v['user'])?></div>
            </div>
            <?php if(($v['vmode']??$genVmode)!=='vc'):?>
            <div style="background:#FFFBEB;border:1px solid #FDE68A;border-radius:5px;padding:3px 7px;display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
                <span style="font-size:.58rem;color:#92400E;font-weight:700">PASS</span>
                <span style="font-family:'JetBrains Mono',monospace;font-size:.78rem;font-weight:700;color:#92400E"><?=h($v['pass'])?></span>
            </div>
            <?php endif;?>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px">
                <?php if($validity):?><span style="font-size:.63rem;background:#DCFCE7;color:#15803D;padding:1px 6px;border-radius:4px;font-weight:700">⏱ <?=$validity?></span><?php else:?><span></span><?php endif;?>
                <?php if($price>0):?><span style="font-size:.65rem;color:var(--orange);font-weight:700"><?=rp($price)?></span><?php else:?><span></span><?php endif;?>
            </div>
            <div style="font-size:.55rem;color:var(--g400);margin-top:2px;text-align:center">#<?=$idx+1?></div>
        </div>
        <?php endforeach;?>
        <?php if(count($genVouchers)>30):?>
        <div style="grid-column:1/-1;text-align:center;padding:14px;background:var(--g50);border-radius:8px;border:2px dashed var(--g200)">
            <div style="font-size:1.5rem">📄</div>
            <div style="font-size:.78rem;color:var(--g500);margin-top:4px">+<?=count($genVouchers)-30?> voucher lagi akan tercetak</div>
            <div style="font-size:.7rem;color:var(--g400)">Total <?=count($genVouchers)?> voucher → <?=ceil(count($genVouchers)/60)?> lembar A4</div>
        </div>
        <?php endif;?>
        </div>
    </div>
</div>
<?php else:?>
<div class="card" style="margin:0 0 12px"><div class="ch"><div class="ct">ℹ️ Panduan Generate</div></div>
<div class="cb" style="font-size:.81rem;line-height:2.1">
    🖨️ <b>Print</b>: 60 voucher per lembar A4 (5 kolom × 12 baris)<br>
    👤 <b>Reseller</b>: nama reseller untuk filter laporan penjualan<br>
    🔢 <b>Prefix</b>: huruf depan voucher — SN-, VCR-, HOME-<br>
    📋 <b>Mode remc/ntfc</b>: transaksi otomatis masuk laporan<br>
    🎯 <b>Mode Voucher</b>: user = password (paling mudah diingat)
</div></div>
<?php endif;?>

<div class="card" style="margin:0"><div class="ch"><div class="ct" id="gen-hs-title">🟢 HS Aktif (0)</div></div>
<div id="gen-hs-active" style="max-height:180px;overflow-y:auto">
    <div style="padding:16px;text-align:center;color:var(--g400);font-size:.8rem">⏳ Memuat...</div>
</div>
</div>
</div><!-- /right -->
</div><!-- /gr -->
</div><!-- /tp-voucher -->

<!-- ════ TAB: PROFIL RESELLER ═══════════════════════════════ -->
<div class="tp" id="tp-profil">
<div class="gr">
<div class="card" style="margin:0">
    <div class="ch"><div class="ct" id="prof-table-title">📋 Profil Hotspot / Reseller (0)</div></div>
    <div class="tw"><table class="dt">
        <thead><tr><th>Nama</th><th>Rate</th><th>Valid</th><th>Harga Beli</th><th>Harga Jual</th><th>Mode</th><th>Lock</th><th></th></tr></thead>
        <tbody id="profTbody">
            <tr><td colspan="8" style="text-align:center;padding:20px;color:var(--g400)">⏳ Memuat profil...</td></tr>
        </tbody>
    </table></div>
</div>
<div class="card" style="margin:0">
    <div class="ch"><div class="ct">➕ Tambah Profil Reseller</div></div>
    <div class="cb">
        <form method="POST"><input type="hidden" name="action" value="add_profile">
        <div class="fg"><label class="fl">Nama Profil *</label><input type="text" name="pname" class="fc" required placeholder="HOME-1H"><div class="fhint">Spasi→strip. Contoh: HOME-1H, WARNET-3H, VCR-6H</div></div>
        <div class="f2">
            <div class="fg"><label class="fl">Rate Limit</label><input type="text" name="ratelimit" class="fc" placeholder="2M/2M"><div class="fhint">Kosong=unlimited</div></div>
            <div class="fg"><label class="fl">Shared Users</label><input type="number" name="shared" class="fc" value="1" min="1"><div class="fhint">Max device/vcr</div></div>
            <div class="fg"><label class="fl">Validity *</label><input type="text" name="validity" class="fc" placeholder="1d" value="1d"><div class="fhint">1d=hari, 12h=jam, 1w=minggu</div></div>
            <div class="fg"><label class="fl">Expired Mode</label>
                <select name="expmode" class="fsel">
                    <option value="remc">🗑📝 Hapus + Catat ★</option>
                    <option value="ntfc">⚠️📝 Notif + Catat</option>
                    <option value="rem">🗑 Hapus saja</option>
                    <option value="ntf">⚠️ Notif saja</option>
                    <option value="0">∞ Tidak kadaluarsa</option>
                </select><div class="fhint">remc/ntfc = masuk laporan penjualan</div>
            </div>
            <div class="fg"><label class="fl">Harga Beli (Rp)</label><input type="number" name="price" class="fc" placeholder="3000" min="0" value="0"></div>
            <div class="fg"><label class="fl">Harga Jual / Reseller (Rp)</label><input type="number" name="sprice" class="fc" placeholder="5000" min="0" value="0"></div>
            <div class="fg"><label class="fl">Address Pool</label><select name="addrpool" id="selPool" class="fsel"><option value="">any (default)</option></select></div>
            <div class="fg"><label class="fl">Lock MAC Address</label><select name="lockuser" class="fsel"><option value="Disable">Disable — multi device</option><option value="Enable">Enable — 1 device saja</option></select></div>
        </div>
        <button type="submit" class="btn bp" style="width:100%">➕ Buat Profil</button>
        </form>
    </div>
</div>
</div>
</div><!-- /tp-profil -->

<!-- ════ TAB: LAPORAN PENJUALAN ═════════════════════════════ -->
<div class="tp" id="tp-laporan">
<div id="laporan-wrap"><div class="card"><div class="cb" style="text-align:center;padding:30px;color:var(--g400)">
    <span style="font-size:1.5rem;display:inline-block">⏳</span><br>Memuat laporan...
</div></div></div>
</div>

<!-- ════ TAB: HS USERS ══════════════════════════════════════ -->
<div class="tp" id="tp-users">

<!-- ── Batch Voucher Section ────────────────────────── -->
<div class="card" style="margin-bottom:14px" id="batchCard">
    <div class="ch">
        <div class="ct">🎟 Voucher Per Batch</div>
        <div style="display:flex;gap:6px;align-items:center">
            <span id="batchDebug" style="font-size:.67rem;color:var(--g400)"></span>
            <button onclick="loadBatchList()" class="btn bo bsm" style="font-size:.7rem">🔄 Refresh</button>
        </div>
    </div>
    <div class="cb" style="padding:12px 16px">
        <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:10px">
            <div style="flex:1;min-width:200px">
                <label class="fl">Pilih Batch / Comment</label>
                <select id="batchSel" class="fsel">
                    <option value="">⏳ Memuat daftar batch...</option>
                </select>
            </div>
            <div>
                <label class="fl">Layout Cetak</label>
                <select id="batchLayout" class="fsel" style="width:auto">
                    <option value="landscape8" selected>🖨 Landscape — 80/A4 ★</option>
                    <option value="landscape6">Landscape — 72/A4</option>
                    <option value="portrait5">Portrait — 60/A4</option>
                </select>
            </div>
            <button onclick="loadBatch()" class="btn bo" style="align-self:flex-end">🔍 Cek Voucher</button>
            <button onclick="printBatch()" class="btn bp" id="printBatchBtn" disabled style="align-self:flex-end">🖨️ Print</button>
            <button onclick="deleteBatch()" class="btn bd" id="deleteBatchBtn" disabled style="align-self:flex-end">🗑️ Hapus</button>
        </div>
        <div id="batchInfo" style="font-size:.78rem;color:var(--g400);min-height:20px"></div>
        <div id="batchPreviewWrap" style="display:none;margin-top:10px">
            <div id="batchList"></div>
        </div>
    </div>
</div>

<!-- ── HS Users List ─────────────────────────────────── -->
<div id="users-wrap"><div class="card"><div class="cb" style="text-align:center;padding:30px;color:var(--g400)">
    Klik tab untuk memuat daftar user...
</div></div></div>
</div>

<?php endif;?>
</div><!-- /no-print -->

<script>
/* ════ DATA ════════════════════════════════════════════════ */
const RID = <?=$selRid?>;
let PROFS = [];
let isInitDataLoaded = false;
function loadInitData() {
    if (isInitDataLoaded || !RID) return;
    fetch('mikhmon_ajax.php?action=init_data&rid='+RID)
        .then(r=>r.json())
        .then(d=>{
            isInitDataLoaded = true;
            PROFS = d.profiles;
            const profTab = document.getElementById('mainTab-profil');
            if(profTab) profTab.innerHTML = `👥 Profil (${d.profiles.length})`;
            const profTitle = document.getElementById('prof-table-title');
            if(profTitle) profTitle.innerHTML = `📋 Profil Hotspot / Reseller (${d.profiles.length})`;
            const selProf = document.getElementById('selProf');
            if(selProf) {
                let phtml = '<option value="">— Pilih Profile —</option>';
                d.profiles.forEach(p => { phtml += `<option value="${p.name}">${p.name}</option>`; });
                const curVal = selProf.value;
                selProf.innerHTML = phtml;
                if(curVal) selProf.value = curVal;
                const genProfile = "<?=h($genProfile)?>";
                if(genProfile) { selProf.value = genProfile; showPI(genProfile); }
            }
            const selServer = document.getElementById('selServer');
            if(selServer) {
                let shtml = '<option value="all">all (semua)</option>';
                d.servers.forEach(s => { shtml += `<option value="${s}">${s}</option>`; });
                selServer.innerHTML = shtml;
            }
            const selPool = document.getElementById('selPool');
            if(selPool) {
                let poolhtml = '<option value="">any (default)</option>';
                d.pools.forEach(p => { poolhtml += `<option value="${p}">${p}</option>`; });
                selPool.innerHTML = poolhtml;
            }
            const profTbody = document.getElementById('profTbody');
            if(profTbody) {
                if(d.profiles.length === 0) {
                    profTbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:var(--g400)">Belum ada profil</td></tr>';
                } else {
                    let tbhtml = '';
                    const rp = n => n>0?'Rp '+n.toLocaleString('id-ID'):'-';
                    const emMap = {'rem':'🗑 Hapus','ntf':'⚠️ Notif','remc':'🗑📝 Catat','ntfc':'⚠️📝 Catat','0':'∞ No exp'};
                    d.profiles.forEach(pd => {
                        const emLabel = emMap[pd.expmode] || pd.expmode;
                        const lockLabel = pd.lockuser === 'Enable' ? '<span class="bdg bon">🔒</span>' : '<span class="bdg bgray">OFF</span>';
                        tbhtml += `<tr>
                            <td><strong style="color:var(--blue-d)">${pd.name}</strong>
                                <button type="button" onclick="setProf('${pd.name}')" class="btn bo bxs" style="margin-left:4px;font-size:.6rem" title="Generate voucher profil ini">🎫</button>
                            </td>
                            <td class="ipm" style="font-size:.7rem">${pd.rate}</td>
                            <td><span class="bdg bblue">${pd.validity}</span></td>
                            <td style="color:var(--g600)">${rp(pd.price)}</td>
                            <td style="font-weight:700;color:var(--orange)">${rp(pd.sprice)}</td>
                            <td style="font-size:.75rem">${emLabel}</td>
                            <td>${lockLabel}</td>
                            <td><form method="POST" style="display:inline" onsubmit="return confirm('Hapus profil ${pd.name}?')"><input type="hidden" name="action" value="del_profile"><input type="hidden" name="pname" value="${pd.name}"><button type="submit" class="btn bd bxs">🗑️</button></form></td>
                        </tr>`;
                    });
                    profTbody.innerHTML = tbhtml;
                }
            }
            const dashTitle = document.getElementById('dash-hs-title');
            if(dashTitle) dashTitle.innerHTML = `🟢 Hotspot Aktif (${d.hsCount})`;
            const genTitle = document.getElementById('gen-hs-title');
            if(genTitle) genTitle.innerHTML = `🟢 HS Aktif (${d.hsCount})`;
            const dashHs = document.getElementById('dash-hs-active');
            const genHs = document.getElementById('gen-hs-active');
            if(d.hsCount === 0) {
                if(dashHs) dashHs.innerHTML = '<div class="empty"><div class="eico">📡</div>Tidak ada sesi aktif</div>';
                if(genHs) genHs.innerHTML = '<div style="padding:16px;text-align:center;color:var(--g400);font-size:.8rem">Tidak ada sesi aktif</div>';
            } else if (d.hsActive.length === 0) {
                if(dashHs) dashHs.innerHTML = '<div class="empty"><div class="eico">📡</div>'+d.hsCount.toLocaleString('id-ID')+' sesi aktif (disembunyikan untuk menjaga performa)</div>';
                if(genHs) genHs.innerHTML = '<div style="padding:16px;text-align:center;color:var(--g400);font-size:.8rem">'+d.hsCount.toLocaleString('id-ID')+' sesi aktif</div>';
            } else {
                let dashHtml = '';
                d.hsActive.forEach(h => {
                    dashHtml += `<div style="display:flex;justify-content:space-between;align-items:center;padding:7px 14px;border-bottom:1px solid var(--g100);gap:8px">
                        <div style="min-width:0"><div style="font-weight:700;font-size:.83rem;color:var(--blue-d);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${h.user}</div><div style="font-size:.68rem;color:var(--g400)">${h.address} &bull; ${h['mac-address']}</div></div>
                        <div style="text-align:right;flex-shrink:0"><div style="font-weight:700;font-size:.77rem;color:#16A34A">${h.uptime}</div>
                            <form method="POST"><input type="hidden" name="action" value="disc_hotspot"><input type="hidden" name="session_id" value="${h['.id']}"><button type="submit" class="btn bd bxs" style="margin-top:3px" onclick="return confirm('Putus sesi ${h.user}?')">✂️</button></form>
                        </div>
                    </div>`;
                });
                if(dashHs) dashHs.innerHTML = dashHtml;
                let genHtml = '';
                d.hsActive.slice(0, 8).forEach(h => {
                    genHtml += `<div style="padding:5px 12px;border-bottom:1px solid var(--g100);display:flex;justify-content:space-between;font-size:.77rem"><strong style="color:var(--blue-d)">${h.user}</strong><span style="color:#16A34A">${h.uptime}</span></div>`;
                });
                if(d.hsCount > 8) {
                    genHtml += `<div style="text-align:center;padding:5px;font-size:.7rem;color:var(--g400)">+${d.hsCount - 8} lagi...</div>`;
                }
                if(genHs) genHs.innerHTML = genHtml;
            }
        });
}
const SK = 'snm_tab_'+RID; // sessionStorage key
// Batch comment terbaru (dari generate) - untuk auto-select di dropdown
const LAST_BATCH = <?=json_encode($lastGen['batch_comment']??'')?>;
// Recent batches untuk hint ke batch_list (router besar mungkin truncate data)
const RECENT_BATCHES = <?=json_encode($_SESSION['recent_batches'][$selRid]??[],JSON_UNESCAPED_UNICODE)?>;

/* ════ MAIN TAB ENGINE ═════════════════════════════════════
   Semua tab main punya id="tp-xxx" dan button id="mainTab-xxx"
   Tidak bergantung pada swTab() dari layout.php
══════════════════════════════════════════════════════════ */
const MAIN_TABS = ['dashboard','voucher','profil','laporan','users'];
function goTab(id){
    MAIN_TABS.forEach(t=>{
        const panel=document.getElementById('tp-'+t);
        const btn=document.getElementById('mainTab-'+t);
        if(panel)panel.style.display=(t===id)?'block':'none';
        if(btn){btn.classList.toggle('on',t===id);}
    });
    sessionStorage.setItem(SK,id);
    // lazy load
    if(id==='laporan'&&!laporanLoaded)initLaporan();
    if(id==='users'){
        if(!usersLoaded)loadUsers();
        loadBatchList(); // refresh batch list setiap buka HS Users tab
    }
}

/* ════ DASH SUB-TABS ═══════════════════════════════════════ */
const DASH_TABS = ['ppp-on','ppp-off'];
function goDashTab(id){
    DASH_TABS.forEach(t=>{
        const p=document.getElementById('tp-'+t);const b=document.getElementById('dashTab-'+t);
        if(p)p.style.display=(t===id)?'block':'none';
        if(b)b.classList.toggle('on',t===id);
    });
    if(id==='ppp-off'&&!pppOffLoaded)loadPPPOff();
}

/* ════ PROFILE INFO ════════════════════════════════════════ */
function showPI(name){
    const p=PROFS.find(x=>x.name===name);
    const el=document.getElementById('profInfo');
    if(!p){el.style.display='none';return;}
    el.style.display='block';
    const rp=n=>n>0?'Rp '+n.toLocaleString('id-ID'):'';
    const em={'rem':'Hapus','ntf':'Notif','remc':'Hapus+Catat','ntfc':'Notif+Catat','0':'No exp'};
    el.innerHTML=`⏱ <b>${p.validity}</b> &nbsp;|&nbsp; Jual: <b>${rp(p.sprice)||rp(p.price)||'-'}</b> &nbsp;|&nbsp; Shared: ${p.shared} &nbsp;|&nbsp; ${em[p.expmode]||p.expmode}`;
}
function setProf(name){
    document.getElementById('selProf').value=name;showPI(name);
    goTab('voucher');
}

/* ════ GENERATE ════════════════════════════════════════════ */
let _genSubmitting=false; // flag anti-double submit
function onGen(btn){
    if(_genSubmitting){alert('Generate sedang berjalan, harap tunggu!');return;}
    const prof=document.getElementById('selProf').value;
    if(!prof){
        alert('Pilih profile hotspot terlebih dahulu!');
        goTab('voucher');
        document.getElementById('selProf').focus();
        return;
    }
    const qty=document.querySelector('#genForm [name="qty"]').value;
    if(!qty||parseInt(qty)<1){alert('Jumlah voucher harus minimal 1!');return;}
    
    _genSubmitting=true;
    btn.disabled=true;
    btn.innerHTML='⏳ Generating '+qty+' voucher...';
    btn.style.opacity='.7';
    sessionStorage.setItem(SK,'voucher');
    // Warning jika user coba navigasi saat generate
    window.onbeforeunload=()=>'Generate voucher sedang berjalan. Yakin keluar?';
    document.getElementById('genForm').submit();
}

/* ════ PRINT LAYOUT ════════════════════════════════════════ */
// Format validity untuk tampilan voucher: "24h" → "24 Jam", "1d" → "1 Hari"
function fmtVal(v){
    if(!v||v==='0s'||v==='0')return '';
    v=v.trim();
    const m24h=v.match(/^(\d+)h$/i);if(m24h)return m24h[1]+' Jam';
    const m1d=v.match(/^(\d+)d$/i);if(m1d)return m1d[1]+' Hari';
    const m1w=v.match(/^(\d+)w$/i);if(m1w)return m1w[1]+' Minggu';
    const m1m=v.match(/^(\d+)m$/i);if(m1m)return m1m[1]+' Bulan';
    // MikroTik uptime format: "1d00:00:00" atau "HH:MM:SS"
    const mu=v.match(/^(\d+)d(\d+):(\d+):(\d+)$/);if(mu){return mu[1]>0?mu[1]+' Hari':parseInt(mu[2])>0?parseInt(mu[2])+' Jam':v;}
    const mhms=v.match(/^(\d+):(\d+):(\d+)$/);if(mhms){return parseInt(mhms[1])>0?parseInt(mhms[1])+' Jam':parseInt(mhms[2])>0?parseInt(mhms[2])+' Menit':v;}
    return v.toUpperCase();
}

function applyLayout(val){
    const grid=document.getElementById('vcrGrid');
    if(!grid)return;
    const configs={
        'portrait5' :{cols:5, land:false, margin:'8mm 5mm'},
        'landscape6' :{cols:6, land:true,  margin:'5mm 8mm'},
        'portrait4' :{cols:4, land:false, margin:'8mm 5mm'},
        'landscape8' :{cols:8, land:true,  margin:'4mm 5mm'}, // 80 voucher
    };
    const cfg=configs[val]||configs['portrait5'];
    grid.style.gridTemplateColumns=`repeat(${cfg.cols},1fr)`;
    let st=document.getElementById('snm-print-page');
    if(!st){st=document.createElement('style');st.id='snm-print-page';document.head.appendChild(st);}
    const size=cfg.land?'A4 landscape':'A4 portrait';
    st.textContent=`@media print{@page{size:${size};margin:${cfg.margin}}.vcr-grid{grid-template-columns:repeat(${cfg.cols},1fr)!important}}`;
}
function doPrint(){
    const layout=document.getElementById('printLayout')?.value||'landscape8';
    applyLayout(layout);
    // Also update @page for printArea
    const layoutCfg={'portrait5':{cols:5,size:'A4 portrait',margin:'8mm 5mm'},
        'landscape6':{cols:6,size:'A4 landscape',margin:'5mm 8mm'},
        'landscape8':{cols:8,size:'A4 landscape',margin:'4mm 5mm'},
        'portrait4':{cols:4,size:'A4 portrait',margin:'8mm 5mm'}};
    const lcfg=layoutCfg[layout]||layoutCfg['landscape8'];
    let st=document.getElementById('snm-print-page');
    if(!st){st=document.createElement('style');st.id='snm-print-page';document.head.appendChild(st);}
    st.textContent=`@media print{@page{size:${lcfg.size};margin:${lcfg.margin}}.vcr-grid{grid-template-columns:repeat(${lcfg.cols},1fr)!important}}`;
    setTimeout(()=>window.print(),150);
}

/* ════ COPY ALL ════════════════════════════════════════════ */
function cpAll(){
    const items=document.querySelectorAll('#printArea .vcr');
    let t='';
    items.forEach((c,i)=>{
        const code=c.querySelector('.vcr-code')?.textContent?.trim()||'';
        const pass=c.querySelector('.vcr-pval')?.textContent?.trim()||code;
        t+=`${i+1}\t${code}\t${pass}\n`;
    });
    navigator.clipboard.writeText(t).then(()=>alert('✅ '+items.length+' voucher disalin ke clipboard!'));
}

/* ════ DOWNLOAD CSV ════════════════════════════════════════ */
function dlCSV(){
    const items=document.querySelectorAll('#printArea .vcr');
    let csv='\uFEFFNo,Username,Password,Profile,Validity,Harga\n';
    items.forEach((c,i)=>{
        const code=c.querySelector('.vcr-code')?.textContent?.trim()||'';
        const pass=c.querySelector('.vcr-pval')?.textContent?.trim()||code;
        const valid=c.querySelector('.vcr-valid')?.textContent?.replace('⏱ ','').trim()||'';
        const price=c.querySelector('.vcr-price')?.textContent?.trim()||'';
        csv+=`${i+1},"${code}","${pass}","<?=$genProfile?>","${valid}","${price}"\n`;
    });
    const b=new Blob([csv],{type:'text/csv;charset=utf-8'});
    const a=document.createElement('a');a.href=URL.createObjectURL(b);
    a.download='voucher-<?=$genProfile?>-<?=date('YmdHi')?>.csv';a.click();
}

/* ════ DASHBOARD DATA ══════════════════════════════════════ */
function loadDash(){
    fetch('mikhmon_ajax.php?action=dashboard&rid='+RID)
        .then(r=>r.json()).then(d=>{
            document.getElementById('d-identity').textContent='🖥️ '+d.identity+' — Resource';
            document.getElementById('d-hsa').textContent=d.hs_active_count;
            const dashTitle = document.getElementById('dash-hs-title');
            if(dashTitle) dashTitle.innerHTML = `🟢 Hotspot Aktif (${d.hs_active_count})`;
            const genTitle = document.getElementById('gen-hs-title');
            if(genTitle) genTitle.innerHTML = `🟢 HS Aktif (${d.hs_active_count})`;
            
            document.getElementById('d-hst').textContent='sesi hotspot aktif';
            document.getElementById('d-ppa').textContent=d.ppp_active_count;
            document.getElementById('d-ppt').textContent='sesi pppoe aktif';
            // PPPoE offline = user pppoe yang tidak sedang online
            document.getElementById('d-ppo').textContent=d.ppp_offline_count;
            const ppoEl=document.getElementById('d-ppod');
            if(ppoEl)ppoEl.textContent=d.ppp_secrets_count>0?'dari '+d.ppp_secrets_count+' akun':'tidak ada akun pppoe';
            document.getElementById('d-resource').innerHTML=`
                <div style="margin-bottom:10px"><div style="display:flex;justify-content:space-between;font-size:.8rem"><span style="color:var(--g600)">CPU</span><strong style="color:${d.cpu>80?'var(--red)':d.cpu>60?'var(--orange)':'#16A34A'}">${d.cpu}%</strong></div><div class="pbar"><div class="pbar-i" style="width:${d.cpu}%;background:${d.cpu>80?'var(--red)':d.cpu>60?'var(--orange)':'#22C55E'}"></div></div></div>
                <div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:.8rem"><span style="color:var(--g600)">RAM</span><strong>${d.mem_used}/${d.mem_total} MB (${d.mem_pct}%)</strong></div><div class="pbar"><div class="pbar-i" style="width:${d.mem_pct}%;background:${d.mem_pct>85?'var(--red)':'var(--blue)'}"></div></div></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:5px;font-size:.75rem">${[['Board',d.board],['Model',d.model],['ROS',d.version],['Uptime',d.uptime],['HDD Free',d.free_hdd],['IP',d.ip]].map(([l,v])=>`<div style="padding:3px 0;border-bottom:1px solid var(--g100)"><span style="color:var(--g400);font-size:.62rem;text-transform:uppercase">${l}</span><br><strong>${v}</strong></div>`).join('')}</div>`;
            let prows='';
            if(d.ppp_active && d.ppp_active.length > 0) {
                d.ppp_active.slice(0,30).forEach(p=>{prows+=`<tr><td><strong style="color:var(--blue-d)">${p.name||'-'}</strong></td><td class="ipm">${p.address||'-'}</td><td style="color:#16A34A;font-weight:600">${p.uptime||'-'}</td><td class="ipm" style="font-size:.7rem">${p['caller-id']||'-'}</td></tr>`;});
                document.getElementById('ppp-on-wrap').innerHTML='<div class="ch"><div class="ct">🔌 PPPoE Aktif ('+d.ppp_active_count+')</div></div><div class="tw"><table class="dt"><thead><tr><th>Username</th><th>IP</th><th>Uptime</th><th>Caller ID</th></tr></thead><tbody>'+prows+'</tbody></table></div>';
            } else if (d.ppp_active_count > 0) {
                document.getElementById('ppp-on-wrap').innerHTML='<div class="ch"><div class="ct">🔌 PPPoE Aktif ('+d.ppp_active_count+')</div></div><div class="empty"><div class="eico">🔌</div>'+d.ppp_active_count.toLocaleString('id-ID')+' sesi aktif (disembunyikan untuk menjaga performa)</div>';
            } else {
                document.getElementById('ppp-on-wrap').innerHTML='<div class="ch"><div class="ct">🔌 PPPoE Aktif (0)</div></div><div class="empty"><div class="eico">🔌</div>Tidak ada sesi PPPoE aktif</div>';
            }
        }).catch(()=>{});
    fetch('mikhmon_ajax.php?action=revenue&rid='+RID)
        .then(r=>r.json()).then(d=>{
            const f=n=>'Rp '+n.toLocaleString('id-ID');
            document.getElementById('d-revd').textContent=f(d.rev_daily);
            document.getElementById('d-revdc').textContent=d.rev_daily_count+' voucher';
            document.getElementById('d-revm').textContent=f(d.rev_monthly);
            document.getElementById('d-revmc').textContent=d.rev_monthly_count+' voucher';
        }).catch(()=>{});
}

/* ════ LAZY LOADERS ════════════════════════════════════════ */
let pppOffLoaded=false,laporanLoaded=false,usersLoaded=false;

function loadPPPOff(){
    if(pppOffLoaded)return;
    const wrap=document.getElementById('ppp-off-wrap');
    wrap.innerHTML='<div class="card"><div class="cb" style="text-align:center;padding:20px"><span style="animation:spin 1s linear infinite;display:inline-block">⏳</span> Memuat PPPoE offline...</div></div>';
    fetch('mikhmon_ajax.php?action=ppp_offline&rid='+RID)
        .then(r=>r.text()).then(h=>{pppOffLoaded=true;wrap.innerHTML=h;})
        .catch(()=>{wrap.innerHTML='<div class="alert aerr">❌ Gagal memuat. <button onclick="pppOffLoaded=false;loadPPPOff()" class="btn bo bsm">Retry</button></div>';});
}
function initLaporan(){if(laporanLoaded)return;laporanLoaded=true;loadLaporan(null,'');}
function loadLaporan(idbl,reseller){
    laporanLoaded=true;
    let url='mikhmon_ajax.php?action=penjualan&rid='+RID;
    if(idbl)url+='&idbl='+encodeURIComponent(idbl);
    if(reseller)url+='&reseller='+encodeURIComponent(reseller);
    document.getElementById('laporan-wrap').innerHTML='<div class="card"><div class="cb" style="text-align:center;padding:24px;color:var(--g400)"><span style="animation:spin 1s linear infinite;display:inline-block;font-size:1.5rem">⏳</span><br>Memuat laporan...</div></div>';
    fetch(url).then(r=>r.text()).then(h=>{document.getElementById('laporan-wrap').innerHTML=h;});
}
function loadUsers(){
    if(usersLoaded)return;
    usersLoaded=true;
    const wrap=document.getElementById('users-wrap');
    if(wrap)wrap.innerHTML='<div style="padding:20px;text-align:center;color:var(--g400)">⏳ Memuat daftar user hotspot...</div>';
    fetch('mikhmon_ajax.php?action=hs_users&rid='+RID)
        .then(r=>r.text())
        .then(h=>{if(wrap)wrap.innerHTML=h;})
        .catch(()=>{if(wrap)wrap.innerHTML='<div class="alert aerr">❌ Gagal memuat user. <button onclick="usersLoaded=false;loadUsers()" class="btn bo bsm">Coba Lagi</button></div>';});
}

/* ════ AUTO-REFRESH DENGAN TAB RESTORE ════════════════════ */
let refreshTimer;
function schedRefresh(){
    if(refreshTimer) clearInterval(refreshTimer);
    refreshTimer=setInterval(()=>{
        const active=MAIN_TABS.find(t=>document.getElementById('tp-'+t)?.style?.display==='block')||'dashboard';
        if(active==='dashboard'){loadDash();}
    }, 5000); // 5 detik untuk pembaruan real-time
}

/* ════ BATCH PRINT ════════════════════════════════════════ */
let batchData=[];

function loadBatchList(){
    // Pass recent batch comments as hint (untuk router besar yang mungkin truncate)
    const recentBatches=RECENT_BATCHES||[];
    const recentParam=recentBatches.length?'&recent='+encodeURIComponent(JSON.stringify(recentBatches)):'';
    const sel=document.getElementById('batchSel');
    if(sel)sel.innerHTML='<option value="">⏳ Memuat batch list...</option>';
    fetch('mikhmon_ajax.php?action=batch_list&rid='+RID+recentParam)
        .then(r=>{
            // Check if response is JSON (not HTML error)
            const ct=r.headers.get('content-type')||'';
            if(!ct.includes('application/json')){
                return r.text().then(t=>{throw new Error('Server error: '+t.substring(0,100));});
            }
            return r.json();
        })
        .then(d=>{
            if(!sel)return;
            if(d.error){
                sel.innerHTML='<option value="">⚠️ Gagal: '+d.error+'</option>';
                return;
            }
            if(!d.batches||d.batches.length===0){
                sel.innerHTML='<option value="">Belum ada batch di router ini (generate dulu)</option>';
                return;
            }
            sel.innerHTML='<option value="">— Pilih Batch —</option>';
            d.batches.forEach(b=>{
                const opt=document.createElement('option');
                opt.value=b.comment;
                const lbl=b.label||b.profiles||'?';
                const dateStr=b.date||'';
                opt.textContent=`${dateStr} — ${lbl} [${b.count} vcr] · ${b.comment}`;
                sel.appendChild(opt);
            });
            // Show debug info
            const dbg=document.getElementById('batchDebug');
            if(dbg&&d.debug){dbg.textContent=`${d.debug.users_fetched} user dipindai, ${d.total} batch ditemukan`;}
            // Auto-select batch terbaru yang baru di-generate
            if(LAST_BATCH){
                const match=[...sel.options].find(o=>o.value===LAST_BATCH||o.value.startsWith(LAST_BATCH));
                if(match){sel.value=match.value;loadBatch();}
            }
        }).catch(e=>{
            if(sel)sel.innerHTML='<option value="">❌ Error: '+e.message+'</option>';
            console.error('loadBatchList error:',e);
        });
}

function loadBatch(){
    const comment=document.getElementById('batchSel').value;
    if(!comment){alert('Pilih batch terlebih dahulu!');return;}
    document.getElementById('batchInfo').innerHTML='<span style="animation:spin 1s linear infinite;display:inline-block">⏳</span> Memuat data batch...';
    document.getElementById('batchPreviewWrap').style.display='none';
    fetch('mikhmon_ajax.php?action=batch_users&rid='+RID+'&comment='+encodeURIComponent(comment))
        .then(r=>r.json()).then(d=>{
            batchData=d.batch||[];
            const parts=comment.split('-');
            const reseller=parts.slice(3).join('-').trim()||'-';
            const selOpt=document.getElementById('batchSel').selectedOptions[0];
            const batchLabel=selOpt?selOpt.textContent:'';
            document.getElementById('batchInfo').innerHTML=
                `✅ <strong>${batchData.length}</strong> voucher &nbsp;|&nbsp; Profile: <strong>${batchData[0]?.profile||'-'}</strong>`;
            document.getElementById('printBatchBtn').disabled=batchData.length===0;
            const delBtn=document.getElementById('deleteBatchBtn');
            if(delBtn)delBtn.disabled=batchData.length===0;
            buildBatchPreview(batchData,comment);
        }).catch(()=>{
            document.getElementById('batchInfo').textContent='❌ Gagal memuat data batch';
        });
}

function buildBatchPreview(vouchers,comment){
    // Status: online=sedang aktif sekarang, used=pernah pakai (ada bytes), belum=bersih
    const online = vouchers.filter(v=>v.online).length;
    const used   = vouchers.filter(v=>!v.online && v.used).length;
    const fresh  = vouchers.filter(v=>!v.online && !v.used).length;

    const rows=vouchers.map((v,i)=>{
        let stBg='', stBdg='bgray', stLbl='⚫ Belum';
        if(v.online){stBg='background:#DCFCE7'; stBdg='bon'; stLbl='🟢 Online';}
        else if(v.used){stBg='background:#FEF3C7'; stBdg='borg'; stLbl='🟡 Pernah';}
        const sess=v.session_uptime?` (${v.session_uptime})`:'';
        const durasi=v.limit_uptime&&v.limit_uptime!=='0s'?fmtVal(v.limit_uptime):'-';
        return `<tr style="${stBg}">
            <td style="color:var(--g400)">${i+1}</td>
            <td><strong style="font-family:'JetBrains Mono',monospace;color:var(--blue-d)">${v.user}</strong></td>
            <td style="font-family:'JetBrains Mono',monospace;font-size:.75rem">${v.pass}</td>
            <td style="font-size:.72rem;color:var(--g500)">${durasi}</td>
            <td><span class="bdg ${stBdg}">${stLbl}${sess}</span></td>
        </tr>`;
    }).join('');

    document.getElementById('batchList').innerHTML=`
        <div style="margin-bottom:8px;font-size:.78rem;padding:8px 12px;background:var(--g50);border-radius:8px;display:flex;gap:16px;flex-wrap:wrap">
            <span>Total: <strong>${vouchers.length}</strong></span>
            <span style="color:#16A34A">🟢 Online sekarang: <strong>${online}</strong></span>
            <span style="color:#D97706">🟡 Pernah digunakan: <strong>${used}</strong></span>
            <span style="color:var(--g500)">⚫ Belum digunakan: <strong>${fresh}</strong></span>
        </div>
        <table class="dt"><thead><tr><th>#</th><th>Username</th><th>Password</th><th>Durasi Limit</th><th>Status</th></tr></thead><tbody>${rows}</tbody></table>`;
    document.getElementById('batchPreviewWrap').style.display='block';
}

function deleteBatch(){
    if(!batchData.length){alert('Load batch terlebih dahulu!');return;}
    const comment=document.getElementById('batchSel').value;
    const selOpt=document.getElementById('batchSel').selectedOptions[0];
    const label=selOpt?selOpt.textContent.substring(0,50):'batch ini';
    const total=batchData.length;
    if(!confirm(`⚠️ HAPUS BATCH VOUCHER\n\n${label}\n\n${total} voucher akan DIHAPUS PERMANEN dari MikroTik!\n\nLanjutkan?`)){return;}
    // Second confirm for safety
    if(!confirm(`Konfirmasi hapus ${total} voucher dari router?\nTindakan ini TIDAK BISA DIBATALKAN!`)){return;}
    
    const btn=document.getElementById('deleteBatchBtn');
    btn.disabled=true;
    btn.innerHTML='⏳ Menghapus...';
    
    // Timeout lebih panjang untuk batch besar (5 menit)
    const ctrl=new AbortController();
    const tmo=setTimeout(()=>ctrl.abort(),300000);
    fetch('mikhmon_ajax.php?action=delete_batch&rid='+RID+'&comment='+encodeURIComponent(comment),
        {signal:ctrl.signal})
        .then(r=>{
            clearTimeout(tmo);
            const ct=r.headers.get('content-type')||'';
            if(!ct.includes('json'))return r.text().then(t=>{throw new Error('Server error: '+t.substring(0,150));});
            return r.json();
        })
        .then(d=>{
            if(d.ok){
                alert(d.msg||'✅ '+d.deleted+' voucher berhasil dihapus!');
                batchData=[];
                document.getElementById('batchPreviewWrap').style.display='none';
                document.getElementById('batchInfo').textContent='';
                btn.innerHTML='🗑️ Hapus';
                loadBatchList();
            } else if(d.deleted>0){
                // Partial delete
                alert((d.msg||'⚠️ Sebagian terhapus')+
                    '\n\nTotal ditemukan: '+d.total+
                    '\nBerhasil dihapus: '+d.deleted+
                    '\nMasih tersisa: '+d.remaining+
                    '\n\nCoba klik Hapus lagi untuk menghapus sisa.');
                btn.disabled=false;
                btn.innerHTML='🗑️ Hapus';
                loadBatchList();
            } else {
                alert('❌ '+(d.error||d.msg||'Gagal menghapus voucher'));
                btn.disabled=false;
                btn.innerHTML='🗑️ Hapus';
            }
        })
        .catch(e=>{
            clearTimeout(tmo);
            const msg=e.name==='AbortError'?'Timeout — proses terlalu lama. Coba hapus batch lebih kecil.':e.message;
            alert('❌ Error: '+msg);
            btn.disabled=false;
            btn.innerHTML='🗑️ Hapus';
        });
}

function printBatch(){
    if(!batchData.length){alert('Load batch terlebih dahulu!');return;}
    const comment=document.getElementById('batchSel').value;
    const layout=document.getElementById('batchLayout')?.value||'landscape8';
    const layoutCfg={'portrait5':{cols:5,size:'A4 portrait',margin:'8mm 5mm'},
        'landscape6':{cols:6,size:'A4 landscape',margin:'5mm 8mm'},
        'landscape8':{cols:8,size:'A4 landscape',margin:'4mm 5mm'},
        'portrait4':{cols:4,size:'A4 portrait',margin:'8mm 5mm'}};
    const lcfg=layoutCfg[layout]||layoutCfg['portrait5'];
    const cols=lcfg.cols, pageSize=lcfg.size, pageMargin=lcfg.margin;
    // Parse reseller from comment: vc-rand-date-RESELLER or vc-rand-date- RESELLER
    const parts=comment.split('-');
    const reseller=parts.slice(3).join('-').trim();

    if (!PROFS || PROFS.length === 0) {
        alert("Data profil sedang dimuat, mohon tunggu sebentar lalu klik Print lagi.");
        if(typeof loadInitData === 'function') loadInitData();
        return;
    }

    const validUser = batchData.find(b => b.profile && b.profile !== 'default' && b.profile !== '-') || batchData[0] || {};
    const prof = validUser.profile || '';
    
    // Ambil validity dan price dari profil data (dari PROFS)
    const profData = PROFS.find(p => p.name.trim().toLowerCase() === prof.trim().toLowerCase()) || {};
    let vcrValidity = profData.validity || '';
    let vcrPrice = profData.sprice || profData.price || 0;
    
    let vcrHTML=batchData.map((v,i)=>`
        <div class="vcr">
            <span class="vcr-num">#${i+1}</span>
            <div class="vcr-hdr">
                <div class="vcr-brand">S.NET</div>
                <div class="vcr-badge">${prof || 'Voucher'}</div>
            </div>
            <div class="vcr-body">
                <div class="vcr-clbl">${v.user===v.pass?'KODE VOUCHER':'USERNAME'}</div>
                <div class="vcr-code">${v.user}</div>
            </div>
            ${v.user!==v.pass?`<div class="vcr-pass"><div class="vcr-plbl">Password</div><div class="vcr-pval">${v.pass}</div></div>`:''}
            <div class="vcr-foot">
                <div class="vcr-foot-info">
                    <div class="vcr-info-row"><span class="vcr-info-lbl">Masa Aktif</span><span class="vcr-valid">${vcrValidity?fmtVal(vcrValidity):'-'}</span></div>
                    ${v.limit_uptime&&v.limit_uptime!=='0s'&&v.limit_uptime!=='0'?`<div class="vcr-info-row"><span class="vcr-info-lbl">Durasi</span><span class="vcr-dur">${fmtVal(v.limit_uptime)}</span></div>`:''}
                </div>
                <div class="vcr-price">${vcrPrice>0?'Rp '+vcrPrice.toLocaleString('id-ID'):'Rp 0'}</div>
            </div>
        </div>`).join('');

    const printDoc=`<!DOCTYPE html>
<html><head><title>Voucher ${prof}</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#fff;font-family:Arial,sans-serif}
.print-header{font-size:8pt;display:flex;justify-content:space-between;align-items:center;
  border-bottom:2pt solid #122B7A;padding-bottom:3pt;margin-bottom:4pt;color:#444}
.ph-left{font-weight:900;font-size:10pt;color:#122B7A}
.ph-mid{font-size:7.5pt;color:#555;text-align:center}
.ph-right{font-size:7.5pt;color:#888}
/* Grid + garis potong dashed */
.vcr-grid{display:grid;grid-template-columns:repeat(${cols},1fr);
  border-top:1pt dashed #ccc;border-left:1pt dashed #ccc}
.vcr{padding:0;position:relative;box-sizing:border-box;
  page-break-inside:avoid;break-inside:avoid;
  border-right:1pt dashed #ccc;border-bottom:1pt dashed #ccc;
  background:#fff;overflow:hidden}
.vcr-hdr{background:linear-gradient(135deg,#0F2266,#1B3FA6);
  padding:2pt 3pt;display:flex;align-items:center;justify-content:space-between;
  margin:2pt 2pt 0}
.vcr-brand{font-size:5.5pt;font-weight:900;color:#fff;letter-spacing:.5pt}
.vcr-badge{font-size:4.5pt;font-weight:700;background:rgba(255,255,255,.25);color:#fff;
  padding:1pt 3pt;border-radius:10pt;white-space:nowrap;max-width:40pt;
  overflow:hidden;text-overflow:ellipsis}
.vcr-body{text-align:center;padding:2pt 2pt 1pt}
.vcr-clbl{font-size:4pt;color:#9CA3AF;font-weight:700;letter-spacing:.5pt;
  text-transform:uppercase;margin-bottom:1pt}
.vcr-code{font-family:'Courier New',monospace;font-size:9.5pt;font-weight:900;
  color:#0F2266;letter-spacing:1pt;line-height:1.1;word-break:break-all;
  background:#EEF4FF;border:.5pt solid #BFDBFE;border-radius:2pt;
  padding:2pt;display:block;text-align:center;margin:0 2pt}
.vcr-pass{display:flex;justify-content:space-between;align-items:center;
  background:#FFFBEB;border-top:.5pt dashed #FDE68A;border-bottom:.5pt dashed #FDE68A;
  padding:1.5pt 3pt;margin-top:1.5pt}
.vcr-plbl{font-size:4pt;color:#92400E;font-weight:800;text-transform:uppercase;letter-spacing:.3pt}
.vcr-pval{font-family:'Courier New',monospace;font-size:7pt;font-weight:700;color:#B45309;letter-spacing:.5pt}
.vcr-foot{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;
  background:#F0FDF4;padding:1.5pt 3pt;gap:1.5pt}
.vcr-foot-info{display:flex;flex-direction:column;gap:.5pt;flex:1 1 auto;min-width:0}
.vcr-info-row{display:flex;align-items:center;gap:1.5pt;flex-wrap:wrap}
.vcr-info-lbl{font-size:4pt;color:#6B7280;font-weight:700;min-width:16pt;
  text-transform:uppercase;letter-spacing:.2pt}
.vcr-valid{font-size:5pt;font-weight:900;color:#15803D;white-space:nowrap}
.vcr-dur{font-size:5pt;font-weight:800;color:#1D4ED8;white-space:nowrap}
.vcr-price{font-size:6pt;font-weight:900;color:#D97706;flex:0 0 auto;text-align:right}
.vcr-num{position:absolute;top:1pt;right:3pt;font-size:4.5pt;
  color:rgba(255,255,255,.6);font-weight:700;z-index:5}
@page{size:${pageSize};margin:${pageMargin}}
</style></head>
<body>
<div class="print-header">
    <div class="ph-left">📡 S.NET</div>
    <div class="ph-mid"><strong>${prof}</strong>${reseller?' · '+reseller:''} · ⏱ ${vcrValidity||'-'} · ${priceLabel||'No price'}</div>
    <div class="ph-right">${batchData.length} vcr · ${new Date().toLocaleDateString('id-ID')}</div>
</div>
<div class="vcr-grid">${vcrHTML}</div>
<script>window.onload=()=>window.print();<\/script>
</body></html>`;
    const w=window.open('','_blank');
    w.document.write(printDoc);
    w.document.close();
}

/* ════ INIT ════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded',()=>{
    // Clear generate lock jika halaman sudah selesai load (generate selesai)
    window.onbeforeunload=null;
    _genSubmitting=false;
    // PHP session tab (setelah POST) punya prioritas tertinggi
    const phpTab = '<?=($_SESSION['snm_active_tab_'.$selRid]??'')?>';
    if(phpTab){
        sessionStorage.setItem(SK,phpTab);
        <?php unset($_SESSION['snm_active_tab_'.$selRid]); // hapus setelah dibaca ?>
    }
    // Restore tab: PHP session > sessionStorage > default dashboard
    const saved = phpTab || sessionStorage.getItem(SK) || 'dashboard';
    goTab(saved);
    goDashTab('ppp-on');
    loadDash();
    loadInitData();
    // Batch list dimuat saat tab HS Users dibuka
    schedRefresh();
});
</script>
<?php endPage();?>
