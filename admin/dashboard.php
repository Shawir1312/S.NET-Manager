<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();

$totalCust   =(int)$db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$activeCust  =(int)$db->query("SELECT COUNT(*) FROM customers WHERE is_active=1")->fetchColumn();
$totalRouters=(int)$db->query("SELECT COUNT(*) FROM routers WHERE is_active=1")->fetchColumn();
$totalFwd    =(int)$db->query("SELECT COUNT(*) FROM port_forwardings")->fetchColumn();
$totalVpn    =(int)$db->query("SELECT COUNT(*) FROM vpn_accounts WHERE status='active'")->fetchColumn();
$mainRouter  =$db->query("SELECT * FROM routers WHERE is_main=1 AND is_active=1 LIMIT 1")->fetch();

$ontTotal=$ontOnline=$ontOffline=0; $ontCards=[];
// Auto-migrasi tabel genie_config jika belum ada
try{$db->query("SELECT id FROM genie_config LIMIT 1");}catch(Exception $e){try{$db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,url VARCHAR(255) NOT NULL,username VARCHAR(100) DEFAULT '',password VARCHAR(100) DEFAULT '',is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Exception $e2){}}
$genieServers = $db->query("SELECT * FROM genie_config WHERE is_active=1 ORDER BY id ASC")->fetchAll();
$genieOk=false; $genieErrs=[]; $logoExists=file_exists(APP_BASE.'/assets/logo.png');

if(count($genieServers)>0){
    foreach($genieServers as $gs){
        $genie = GenieACS::fromDB($gs['id']);
        if($genie){
            $devs=$genie->getDevices('{}');
            if(is_array($devs)){
                $genieOk=true; 
                $ontTotal+=count($devs);
                foreach($devs as $d){
                    $inf=$genie->getInfo($d);$wf=$genie->getWifi($d);
                    if($inf['online'])$ontOnline++;else$ontOffline++;
                    if(count($ontCards)<8){
                        $tag=$inf['tags'][0]??'';$cust=null;
                        if($tag){$cs=$db->prepare("SELECT full_name,customer_id FROM customers WHERE ont_tag=? LIMIT 1");$cs->execute([$tag]);$cust=$cs->fetch();}
                        $ontCards[]=compact('inf','wf','cust') + ['genie_id' => $gs['id']];
                    }
                }
            }else{
                $genieErrs[] = $gs['name'].': '.$genie->error;
            }
        }
    }
}
$genieErr = implode(', ', $genieErrs);

$recentCust=$db->query("SELECT * FROM customers ORDER BY created_at DESC LIMIT 5")->fetchAll();
$recentFwd =$db->query("SELECT pf.*,r.name rn FROM port_forwardings pf JOIN routers r ON pf.router_id=r.id ORDER BY pf.created_at DESC LIMIT 5")->fetchAll();
$recentVpn =$db->query("SELECT v.*,r.name rn FROM vpn_accounts v JOIN routers r ON v.router_id=r.id ORDER BY v.created_at DESC LIMIT 5")->fetchAll();

startPage('Dashboard');
?>
<div class="ph">
    <div><div class="ph-t">🏠 Dashboard</div><div class="ph-s">Selamat datang, <strong><?=h(adminUser()['full_name'])?></strong> — <?=date('l, d F Y')?></div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/admin/snetmonitoring.php" class="btn bpu bsm">📊 Monitoring</a>
        <a href="/admin/customers.php" class="btn bp">➕ Tambah Pelanggan</a>
    </div>
</div>

<?php if($mainRouter):?>
<div class="alert ainf" style="margin-bottom:14px">
    ⭐ <strong>Router Utama:</strong> <?=h($mainRouter['name'])?> (<?=h($mainRouter['ip_public'])?>)
    &bull; <a href="/admin/snetmonitoring.php?rid=<?=$mainRouter['id']?>">Monitor</a>
    &bull; <a href="/admin/vpn_status.php?rid=<?=$mainRouter['id']?>">Status VPN</a>
</div>
<?php endif;?>

<div class="stats">
    <div class="stat" style="--c:var(--blue)"><div class="stat-l">Total Pelanggan</div><div class="stat-n"><?=$totalCust?></div><div class="stat-d"><?=$activeCust?> aktif</div></div>
    <div class="stat" style="--c:#22C55E"><div class="stat-l">ONT Online</div><div class="stat-n" style="color:#22C55E"><?=$ontOnline?></div><div class="stat-d">dari <?=$ontTotal?> total</div></div>
    <div class="stat" style="--c:var(--red)"><div class="stat-l">ONT Offline</div><div class="stat-n" style="color:var(--red)"><?=$ontOffline?></div><div class="stat-d"><?=$ontTotal>0?round($ontOffline/$ontTotal*100):0?>% dari total</div></div>
    <div class="stat" style="--c:var(--orange)"><div class="stat-l">Port Forwarding</div><div class="stat-n"><?=$totalFwd?></div><div class="stat-d">DST NAT rule</div></div>
    <div class="stat" style="--c:var(--purple)"><div class="stat-l">VPN Aktif</div><div class="stat-n"><?=$totalVpn?></div><div class="stat-d">L2TP user</div></div>
    <div class="stat" style="--c:var(--green)"><div class="stat-l">Router</div><div class="stat-n"><?=$totalRouters?></div><div class="stat-d">Mikrotik aktif</div></div>
</div>

<!-- ONT Grid -->
<div class="card">
    <div class="ch">
        <div class="ct">📶 Status ONT <?php if($ontTotal):?><span class="bdg bblue"><?=$ontTotal?> total</span><?php endif;?></div>
        <div style="display:flex;gap:8px">
            <?php if(!$genieOk):?><a href="/admin/genie.php" class="btn bw bsm">⚙️ Setup GenieACS</a><?php endif;?>
            <a href="/admin/ont.php" class="btn bo bsm">Lihat Semua →</a>
        </div>
    </div>
    <div class="cb">
    <?php if(!$genieOk):?>
    <div class="alert awrn">⚠️ GenieACS belum terhubung. <?=$genieErr?h("Error: $genieErr"):''?> <a href="/admin/genie.php">→ Konfigurasi</a></div>
    <?php elseif(empty($ontCards)):?>
    <div class="empty"><div class="eico">📡</div>Belum ada ONT</div>
    <?php else:?>
    <div style="display:flex;gap:16px;margin-bottom:14px;padding:10px 14px;background:var(--g50);border-radius:10px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px"><div class="dot don"></div><span style="font-size:.82rem;font-weight:700;color:#15803D">Online: <strong><?=$ontOnline?></strong></span></div>
        <div style="display:flex;align-items:center;gap:8px"><div class="dot doff"></div><span style="font-size:.82rem;font-weight:700;color:var(--red)">Offline: <strong><?=$ontOffline?></strong></span></div>
        <div style="margin-left:auto;font-size:.78rem;color:var(--g400)"><?=$ontTotal>0?round($ontOnline/$ontTotal*100):0?>% uptime ratio</div>
    </div>
    <div class="og">
    <?php foreach($ontCards as $item):
        $inf=$item['inf'];$wf=$item['wf'];$cust=$item['cust'];
        $br=$inf['brand'];$ic=$br==='FiberHome'?'🟠':($br==='CData'?'🔵':'⚪');$cls=$br==='FiberHome'?'bfh':($br==='CData'?'bcd':'bgen');
    ?>
    <div class="oc">
        <div class="och <?=$inf['online']?'on':'off'?>">
            <div class="oa <?=$cls?>"><?=$ic?></div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php $dtag=$inf['tags'][0]??'';echo $dtag?h($dtag):h($inf['serial']);?>
                </div>
                <div style="font-size:.7rem;color:var(--g400)"><?=$cust?h($cust['full_name']):h($inf['serial'])?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div class="dot <?=$inf['online']?'don':'doff'?>" style="display:block;margin:0 0 3px auto"></div>
                <span style="font-size:.65rem;font-weight:700;color:<?=$inf['online']?'#16A34A':'var(--red)'?>"><?=$inf['online']?'Online':'Offline'?></span>
            </div>
        </div>
        <div style="padding:6px 12px;font-size:.72rem;color:var(--g500);border-bottom:1px solid var(--g100);display:flex;justify-content:space-between;flex-wrap:wrap;gap:3px">
            <span>🏭 <?=h($br.' '.$inf['model'])?></span><span>🕐 <?=h($inf['last_seen'])?></span>
        </div>
        <div style="padding:7px 12px"><a href="/admin/ont.php?id=<?=urlencode($inf['id'])?>&genie_id=<?=$item['genie_id']?>" class="btn bo bsm">🔍 Detail</a></div>
    </div>
    <?php endforeach;?>
    </div>
    <?php endif;?>
    </div>
</div>

<!-- Bottom 3 cols -->
<div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
<div class="card">
    <div class="ch"><div class="ct">👥 Pelanggan Baru</div><a href="/admin/customers.php" class="btn bo bsm">Semua</a></div>
    <div class="tw"><table class="dt"><thead><tr><th>ID</th><th>Nama</th><th>Brand</th><th>Status</th></tr></thead><tbody>
    <?php foreach($recentCust as $c):?>
    <tr><td><span class="code"><?=h($c['customer_id'])?></span></td><td><strong style="font-size:.82rem"><?=h($c['full_name'])?></strong></td><td><span class="bdg borg" style="font-size:.6rem"><?=h($c['device_brand'])?></span></td><td><span class="bdg <?=$c['is_active']?'bon':'boff'?>"><?=$c['is_active']?'Aktif':'Non'?></span></td></tr>
    <?php endforeach;?>
    <?php if(empty($recentCust)):?><tr><td colspan="4" class="empty">Belum ada data</td></tr><?php endif;?>
    </tbody></table></div>
</div>
<div class="card">
    <div class="ch"><div class="ct">🔀 Port Forwarding</div><a href="/admin/port_forward.php" class="btn bo bsm">Semua</a></div>
    <div class="tw"><table class="dt"><thead><tr><th>Router</th><th>Port Pub</th><th>IP:Port Lokal</th><th>Status</th></tr></thead><tbody>
    <?php foreach($recentFwd as $f):?>
    <tr><td style="font-size:.76rem"><?=h($f['rn'])?></td><td class="ipm"><?=$f['public_port']?></td><td class="ipm" style="font-size:.72rem"><?=h($f['ip_lokal'].':'.$f['port_lokal'])?></td><td><span class="bdg <?=$f['status']==='active'?'bon':'boff'?>"><?=$f['status']?></span></td></tr>
    <?php endforeach;?>
    <?php if(empty($recentFwd)):?><tr><td colspan="4" class="empty">Belum ada data</td></tr><?php endif;?>
    </tbody></table></div>
</div>
<div class="card">
    <div class="ch"><div class="ct">🔐 VPN L2TP</div><a href="/admin/vpn.php" class="btn bo bsm">Semua</a></div>
    <div class="tw"><table class="dt"><thead><tr><th>Router</th><th>Username</th><th>Service</th><th>Status</th></tr></thead><tbody>
    <?php foreach($recentVpn as $v):?>
    <tr><td style="font-size:.76rem"><?=h($v['rn'])?></td><td><strong style="font-size:.82rem"><?=h($v['username'])?></strong></td><td><span class="bdg bblue"><?=strtoupper($v['service'])?></span></td><td><span class="bdg <?=$v['status']==='active'?'bon':'boff'?>"><?=$v['status']?></span></td></tr>
    <?php endforeach;?>
    <?php if(empty($recentVpn)):?><tr><td colspan="4" class="empty">Belum ada data</td></tr><?php endif;?>
    </tbody></table></div>
</div>
</div>
<?php endPage();?>
