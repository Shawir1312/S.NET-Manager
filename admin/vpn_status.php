<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='disconnect'){
        $rid=(int)($_POST['router_id']??0);$sid=$_POST['session_id']??'';
        $router=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1");$router->execute([$rid]);$router=$router->fetch();
        if($router&&$sid){$api=MikrotikAPI::fromRouter($router);if($api->connect()){$ok=$api->disconnectPpp($sid);$api->close();$msg=$ok?'✅ Sesi VPN diputus!':'❌ Gagal memutus sesi: '.$api->error;$mtype=$ok?'ok':'err';}else{$msg='❌ Tidak bisa konek: '.$api->error;$mtype='err';}}
    }
}

$routers=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY name")->fetchAll();
$selRid=(int)($_GET['rid']??($routers[0]['id']??0));
$sessions=[];$l2tpStatus=[];$connErr='';
if($selRid){
    $router=array_filter($routers,fn($r)=>$r['id']===$selRid);$router=reset($router);
    if($router){
        $api=MikrotikAPI::fromRouter($router);
        if($api->connect()){
            $sessions=$api->listActivePpp();
            $l2tpStatus=$api->getL2tpStatus();
            $api->close();
        }else{$connErr=$api->error;}
    }
}

startPage('Status VPN');
?>
<div class="ph">
    <div><div class="ph-t">📡 Status VPN — Live Sessions</div><div class="ph-s">Monitor sesi aktif L2TP/PPP di Mikrotik secara real-time</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/admin/vpn_status.php<?=$selRid?"?rid=$selRid":''?>" class="btn bo bsm">🔄 Refresh</a>
        <a href="/admin/vpn.php" class="btn bo bsm">⚙️ Kelola VPN</a>
    </div>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<!-- Router selector -->
<?php if(count($routers)>1):?>
<div class="card"><div class="cb" style="padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end">
        <div style="flex:1;max-width:300px"><label class="fl">Pilih Router</label>
            <select name="rid" class="fsel" onchange="this.form.submit()">
                <?php foreach($routers as $r):?><option value="<?=$r['id']?>" <?=$selRid==$r['id']?'selected':''?>><?=h($r['name'])?> — <?=h($r['ip_public'])?></option><?php endforeach;?>
            </select>
        </div>
    </form>
</div></div>
<?php endif;?>

<?php if($connErr):?>
<div class="alert aerr">❌ Tidak bisa konek ke router: <?=h($connErr)?></div>
<?php elseif($selRid):?>

<!-- L2TP Server status -->
<?php if(!empty($l2tpStatus)):
    $enabled=($l2tpStatus['enabled']??'no')==='yes';
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;margin-bottom:18px">
    <div class="stat" style="--c:<?=$enabled?'#22C55E':'var(--red)'?>">
        <div class="stat-l">L2TP Server</div>
        <div class="stat-n" style="font-size:1.2rem;color:<?=$enabled?'#22C55E':'var(--red)'?>"><?=$enabled?'✅ ON':'❌ OFF'?></div>
        <div class="stat-d">Status server</div>
    </div>
    <div class="stat" style="--c:var(--blue)">
        <div class="stat-l">Sesi Aktif</div>
        <div class="stat-n"><?=count($sessions)?></div>
        <div class="stat-d">Koneksi sekarang</div>
    </div>
    <?php if(isset($l2tpStatus['ipsec-secret'])&&$l2tpStatus['ipsec-secret']):?>
    <div class="stat" style="--c:var(--purple)">
        <div class="stat-l">IPSec</div>
        <div class="stat-n" style="font-size:.9rem;color:var(--purple)">ON</div>
        <div class="stat-d"><?=h(str_repeat('•',min(8,strlen($l2tpStatus['ipsec-secret']))))?></div>
    </div>
    <?php endif;?>
    <?php if(isset($l2tpStatus['authentication'])):?>
    <div class="stat" style="--c:var(--orange)">
        <div class="stat-l">Auth Method</div>
        <div class="stat-n" style="font-size:.75rem;font-family:'JetBrains Mono',monospace"><?=h($l2tpStatus['authentication'])?></div>
    </div>
    <?php endif;?>
</div>
<?php endif;?>

<!-- Active sessions -->
<div class="card">
    <div class="ch">
        <div class="ct">🖥️ Sesi VPN Aktif (<?=count($sessions)?>)</div>
        <a href="/admin/vpn_status.php?rid=<?=$selRid?>" class="btn bo bsm">🔄 Refresh</a>
    </div>
    <?php if(empty($sessions)):?>
    <div class="empty"><div class="eico">📡</div><div>Tidak ada sesi VPN aktif saat ini</div></div>
    <?php else:?>
    <div class="tw"><table class="dt">
        <thead><tr><th>#</th><th>Username</th><th>IP Address</th><th>Service</th><th>Caller ID</th><th>Uptime</th><th>Encoding</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($sessions as $i=>$s):?>
        <tr>
            <td style="color:var(--g400)"><?=$i+1?></td>
            <td><strong style="color:var(--blue-d)"><?=h($s['name']??'-')?></strong></td>
            <td class="ipm"><?=h($s['address']??'-')?></td>
            <td><span class="bdg bblue"><?=strtoupper(h($s['service']??'ppp'))?></span></td>
            <td class="ipm" style="font-size:.76rem"><?=h($s['caller-id']??'-')?></td>
            <td style="font-weight:600;color:var(--green-d)"><?=h($s['uptime']??'-')?></td>
            <td style="font-size:.76rem;color:var(--g600)"><?=h($s['encoding']??'-')?></td>
            <td>
                <form method="POST">
                    <input type="hidden" name="action" value="disconnect">
                    <input type="hidden" name="router_id" value="<?=$selRid?>">
                    <input type="hidden" name="session_id" value="<?=h($s['.id']??'')?>">
                    <button type="submit" class="btn bd bxs" onclick="return cDel('Putus sesi <?=h($s['name']??'ini')?>?')">✂️ Putus</button>
                </form>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php endif;?>
</div>
<?php endif;?>

<?php if(!empty($routers)):?>
<div style="font-size:.75rem;color:var(--g400);text-align:center;margin-top:8px">
    Auto-refresh setiap 30 detik. <a href="#" onclick="location.reload();return false">Refresh sekarang</a>
</div>
<script>setTimeout(()=>location.reload(),30000);</script>
<?php endif;?>
<?php endPage();?>
