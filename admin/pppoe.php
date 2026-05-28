<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();
$isTeknisi = (($_SESSION['admin_role'] ?? '') === 'teknisi');

// Load settings - handle case where table doesn't exist yet
$settings=[];
try{
    foreach($db->query("SELECT setting_key,setting_value FROM pppoe_settings") as $s)
        $settings[$s['setting_key']]=$s['setting_value'];
}catch(\Exception $e){
    // Table doesn't exist - show setup message
    startPage('PPPoE Manager');
    echo '<div class="card"><div class="cb" style="padding:30px;text-align:center">';
    echo '<div style="font-size:2rem;margin-bottom:12px">⚠️</div>';
    echo '<h3 style="margin-bottom:8px">Setup Database Diperlukan</h3>';
    echo '<p style="color:var(--g400);margin-bottom:16px">Tabel PPPoE belum ada. Jalankan perintah berikut di server:</p>';
    echo '<pre style="background:#1F2937;color:#F9FAFB;padding:12px;border-radius:6px;text-align:left;font-size:.8rem">mysql -u USER -p DBNAME &lt; /path/to/database.sql</pre>';
    echo '<p style="color:var(--g400);font-size:.8rem;margin-top:8px">Atau import file <strong>database.sql</strong> via phpMyAdmin/HeidiSQL.</p>';
    echo '</div></div>';
    echo '</div></div>';
    endPage();
    exit;
}

if(isset($_GET['act']) && $_GET['act']==='search_acs_sn'){
    $sn=trim($_GET['sn']??'');
    $res=['server_id'=>0,'device_id'=>''];
    if($sn){
        try{
            require_once __DIR__.'/../includes/GenieACS.php';
            $genieServers=$db->query("SELECT * FROM genie_servers")->fetchAll();
            foreach($genieServers as $gs){
                $g=new GenieACS($gs['url'],$gs['username'],$gs['password']);
                $devs=$g->searchDevices($sn);
                if(!empty($devs)){
                    $res=['server_id'=>$gs['id'],'device_id'=>$devs[0]['_id']];
                    break;
                }
            }
        }catch(\Exception $e){}
    }
    header('Content-Type: application/json');
    echo json_encode($res);
    exit;
}

// Check if tables exist, handle setup...

$routers=$db->query("SELECT id,name,ip_public,host,port,username,password FROM routers WHERE is_active=1 AND use_mikhmon=1 ORDER BY is_main DESC,name")->fetchAll();
$selRid=isset($_GET['rid']) ? (int)$_GET['rid'] : 0;
$selRouter=null;
if($selRid){
    foreach($routers as $r){if($r['id']==$selRid){$selRouter=$r;break;}}
}

// Router Selection Screen
if(!$selRid) {
    startPage('Pilih Cabang Router — PPPoE Manager');
    ?>
    <div style="max-width:800px;margin:0 auto;padding-top:20px">
        <h2 style="margin-bottom:20px;text-align:center">Pilih Cabang Router</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:15px">
            <?php foreach($routers as $r):?>
            <a href="?rid=<?=$r['id']?>" class="card" style="text-decoration:none;display:block;transition:all .2s;cursor:pointer">
                <div class="cb" style="text-align:center;padding:30px 20px">
                    <div style="font-size:2.5rem;margin-bottom:10px">📡</div>
                    <div style="font-weight:700;color:var(--blue-d);font-size:1.1rem;margin-bottom:4px"><?=h($r['name'])?></div>
                    <div style="font-size:.8rem;color:var(--g400)"><?=h($r['ip_public'])?></div>
                </div>
            </a>
            <?php endforeach;?>
            <?php if(empty($routers)):?>
            <div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--g400)">Belum ada router yang aktif. <a href="/admin/routers.php">Tambah Router</a></div>
            <?php endif;?>
        </div>
    </div>
    <style>.card:hover{transform:translateY(-3px);box-shadow:0 10px 15px -3px rgba(0,0,0,0.1)}</style>
    <?php
    endPage();
    exit;
}

$msg='';$mtype='ok';
$act=trim($_POST['action']??'');

// ── Handle POST actions ──────────────────────────────────
if($act==='save_settings'){
    $keys=['midtrans_server_key','midtrans_client_key','midtrans_mode','isolir_profile',
           'isolir_grace_days','company_name','company_phone','company_address',
           'tpl_vlan_id','tpl_wan_slot','tpl_user_suffix','tpl_password',
           'tpl_profile','tpl_price'];
    foreach($keys as $k){
        if(isset($_POST[$k])){
            $v=trim($_POST[$k]);
            $db->prepare("INSERT INTO pppoe_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$v,$v]);
        }
    }
    $chkKeys = ['tpl_bind_lan1','tpl_bind_lan2','tpl_bind_lan3','tpl_bind_lan4','tpl_bind_wlan1','tpl_bind_wlan5'];
    foreach($chkKeys as $k){
        $v=isset($_POST[$k]) ? '1' : '0';
        $db->prepare("INSERT INTO pppoe_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$k,$v,$v]);
    }
    $msg='✅ Pengaturan disimpan!';header("Location: /admin/pppoe.php?rid=$selRid&tab=settings&ok=1");exit;
}

if($act==='add_customer'&&$selRouter){
    $uname=trim($_POST['pppoe_username']??'');
    $name=trim($_POST['full_name']??'');
    $phone=trim($_POST['phone']??'');
    $address=trim($_POST['address']??'');
    $profile=trim($settings['tpl_profile']??'default');
    $price=(int)($settings['tpl_price']??0);
    $due=(int)($_POST['due_day']??1);
    $password=trim($_POST['password']??'');

    // GenieACS parameters
    $genieDeviceId = trim($_POST['genie_device_id'] ?? '');
    $genieId = (int)($_POST['genie_id'] ?? 0);
    $vlanEn = !empty($_POST['vlan_enable']);
    $vlanId = (int)($_POST['vlan_id'] ?? 200);
    $wanSlot = (int)($_POST['wan_slot'] ?? 2);
    $binds = $_POST['binds'] ?? [];
    $mappedBinds = [];
    foreach($binds as $b) {
        if(preg_match('/^LAN(\d+)$/', $b, $m)) {
            $mappedBinds[] = 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.'.$m[1];
        } elseif(preg_match('/^WLAN(\d+)$/', $b, $m)) {
            $mappedBinds[] = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration.'.$m[1];
        } else {
            $mappedBinds[] = $b;
        }
    }
    $bindStr = implode(',', $mappedBinds);

    $skipMikrotik = !empty($_POST['skip_mikrotik']);

    if(!$uname||!$name){$msg='❌ Username dan nama wajib diisi!';$mtype='err';}
    else{
        // Auto-generate password jika kosong
        if(!$password) $password = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);

        if(!$skipMikrotik) {
            // Tambah ke MikroTik
            $api=MikrotikAPI::fromRouter($selRouter);
            if($api->connect()){
                $cmd=['/ppp/secret/add','=name='.$uname,'=password='.$password,'=service=pppoe'];
                if($profile)$cmd[]='=profile='.$profile;
                $api->talk($cmd);$api->close();
            }
        }
        
        try{
            $db->prepare("INSERT INTO pppoe_customers(router_id,pppoe_username,full_name,phone,address,profile,monthly_price,due_day,created_by) VALUES(?,?,?,?,?,?,?,?,?)")
               ->execute([$selRid,$uname,$name,$phone,$address,$profile,$price,$due,$_SESSION['admin_id']??null]);
            
            // Tambahkan ke tabel customers utama untuk portal login
            $cid = generateCustomerId();
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO customers(customer_id,password,full_name,phone,address,genie_device_id,ont_tag,router_id,notes,created_by,genie_id)VALUES(?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$cid, $hash, $name, $phone, $address, $genieDeviceId, $uname, $selRid, 'Dari PPPoE Manager', $_SESSION['admin_id']??null, $genieId?:null]);
            $mainCustId = $db->lastInsertId();

            // Setting ONT via GenieACS jika ONT dipilih
            if($genieId && $genieDeviceId) {
                $g = GenieACS::fromDB($genieId);
                if($g) {
                    $dev = $g->getDevice($genieDeviceId);
                    if($dev) {
                        $cfg = [
                            'wan_cd'      => $wanSlot, // Index WAN slot (default 2)
                            'wan_slot'    => $wanSlot,
                            'conn_mode'   => 'route', // Route mode for PPPoE
                            'service_list'=> 'INTERNET',
                            'vlan_enable' => $vlanEn,
                            'vlan_id'     => $vlanId,
                            'addr_type'   => 'dhcp',
                            'pppoe_user'  => $uname,
                            'pppoe_pass'  => $password,
                            'wan_type'    => 'ppp',
                            'bind_str'    => $bindStr
                        ];
                        // Coba addObject WAN dulu
                        $ok = $g->addWan($genieDeviceId, $dev, $cfg);
                        if(!$ok) {
                            $g->setWan($genieDeviceId, $dev, $cfg);
                        }
                        
                        // Set Port Binding
                        if($bindStr) {
                            $g->bindWan($genieDeviceId, $dev, $cfg);
                        }
                        
                        // Set tag ONT
                        $g->addTag($genieDeviceId, preg_replace('/[^a-zA-Z0-9_\-]/', '_', trim($name)));
                        if($uname) $g->addTag($genieDeviceId, $uname);
                        
                        // Simpan konfigurasi
                        saveOntConfig((int)$mainCustId, $genieDeviceId, 'wan', 'WAN PPPoE Config (Auto)', $cfg);
                    }
                }
            }

            $msg='✅ Pelanggan '.$name.' berhasil ditambahkan, di-setting di MikroTik' . ($genieDeviceId ? ' & ONT' : '') . '!<br>Username: <strong>'.$uname.'</strong> | Pass: <strong>'.$password.'</strong>';
        }catch(\Exception $e){$msg='❌ Username sudah ada di database!';$mtype='err';}
    }
}

if($act==='delete_customer'&&$selRouter){
    $cid=(int)($_POST['cid']??0);
    $cust=$db->prepare("SELECT * FROM pppoe_customers WHERE id=? AND router_id=?");
    $cust->execute([$cid,$selRid]);
    if($c=$cust->fetch()){
        // Remove dari Mikrotik
        try{
            $api=MikrotikAPI::fromRouter($selRouter);
            if($api->connect()){
                $api->talk(['/ppp/secret/remove','?name='.$c['pppoe_username']]);
                $acts=$api->parse($api->talk(['/ppp/active/print','?name='.$c['pppoe_username'],'=.proplist=.id']));
                foreach($acts as $a){if(isset($a['.id']))$api->talk(['/ppp/active/remove','=.id='.$a['.id']]);}
                $api->close();
            }
        }catch(\Exception $e){}
        $db->prepare("DELETE FROM pppoe_payments WHERE customer_id=?")->execute([$cid]);
        $db->prepare("DELETE FROM pppoe_customers WHERE id=?")->execute([$cid]);
        $msg='✅ Pelanggan '.$c['full_name'].' berhasil dihapus permanen!';
    }
}

if($act==='isolir'&&$selRouter){
    $cid=(int)($_POST['cid']??0);
    $reason=trim($_POST['reason']??'Jatuh tempo');
    $row=$db->prepare("SELECT * FROM pppoe_customers WHERE id=? AND router_id=?")->execute([$cid,$selRid])?$db->prepare("SELECT * FROM pppoe_customers WHERE id=? AND router_id=?")->execute([$cid,$selRid]):null;
    $cust=$db->prepare("SELECT * FROM pppoe_customers WHERE id=?");$cust->execute([$cid]);$cust=$cust->fetch();
    if($cust){
        $isoProfile=$settings['isolir_profile']??'isolir';
        $api=MikrotikAPI::fromRouter($selRouter);
        if($api->connect()){
            // Cari .id secret lalu ubah profile ke isolir
            $sec=$api->parse($api->talk(['/ppp/secret/print','?name='.$cust['pppoe_username'],'=.proplist=.id']));
            if(!empty($sec) && isset($sec[0]['.id'])) {
                $api->talk(['/ppp/secret/set','=.id='.$sec[0]['.id'],'=profile='.$isoProfile]);
            }
            // Putus sesi aktif
            $act2=$api->parse($api->talk(['/ppp/active/print','?name='.$cust['pppoe_username'],'=.proplist=.id']));
            foreach($act2 as $a){if(isset($a['.id']))$api->talk(['/ppp/active/remove','=.id='.$a['.id']]);}
            $api->close();
        }
        $db->prepare("UPDATE pppoe_customers SET status='isolated',isolated_at=NOW(),isolated_reason=? WHERE id=?")->execute([$reason,$cid]);
        auditLog('pppoe_isolir',$cust['pppoe_username'],$reason);
        $msg='✅ Pelanggan '.$cust['full_name'].' berhasil diisolir!';
    }
}
if($act==='delete_monthly_income'){
    $m=(int)($_POST['month']??0);
    $y=(int)($_POST['year']??0);
    if($m&&$y){
        $db->prepare("DELETE FROM pppoe_payments WHERE period_month=? AND period_year=?")->execute([$m,$y]);
        $msg="✅ Data pendapatan bulan $m/$y berhasil dihapus!";
        auditLog('delete_income','SYSTEM',"Hapus riwayat pendapatan $m/$y");
    }
}
if($act==='edit_projection'){
    $val = (int)($_POST['manual_proyeksi'] ?? 0);
    if($val > 0) {
        $db->prepare("REPLACE INTO pppoe_settings(setting_key,setting_value) VALUES('manual_proyeksi',?)")->execute([$val]);
    } else {
        $db->prepare("DELETE FROM pppoe_settings WHERE setting_key='manual_proyeksi'")->execute();
    }
    $msg = "✅ Proyeksi bulanan berhasil diubah!";
    $settings['manual_proyeksi'] = $val > 0 ? $val : null;
}

if($act==='reaktivasi'&&$selRouter){
    $cid=(int)($_POST['cid']??0);
    $cust=$db->prepare("SELECT * FROM pppoe_customers WHERE id=?");$cust->execute([$cid]);$cust=$cust->fetch();
    if($cust){
        $api=MikrotikAPI::fromRouter($selRouter);
        if($api->connect()){
            $profile=$cust['profile']?:'default';
            $sec=$api->parse($api->talk(['/ppp/secret/print','?name='.$cust['pppoe_username'],'=.proplist=.id']));
            if(!empty($sec) && isset($sec[0]['.id'])) {
                $api->talk(['/ppp/secret/set','=.id='.$sec[0]['.id'],'=profile='.$profile]);
            }
            $api->close();
        }
        $db->prepare("UPDATE pppoe_customers SET status='active',isolated_at=NULL,isolated_reason='' WHERE id=?")->execute([$cid]);
        auditLog('pppoe_reaktivasi',$cust['pppoe_username'],'');
        $msg='✅ Pelanggan '.$cust['full_name'].' berhasil diaktifkan kembali!';
    }
}

if($act==='bayar'){
    $cid=(int)($_POST['cid']??0);
    $amount=(int)($_POST['amount']??0);
    $month=(int)($_POST['month']??date('n'));
    $year=(int)($_POST['year']??date('Y'));
    $notes=trim($_POST['notes']??'');
    if($cid&&$amount>0){
        $db->prepare("INSERT INTO pppoe_payments(customer_id,amount,payment_method,period_month,period_year,notes,created_by) VALUES(?,?,'cash',?,?,?,?)")
           ->execute([$cid,$amount,$month,$year,$notes,$_SESSION['admin_id']??null]);
        $db->prepare("UPDATE pppoe_customers SET last_paid_at=CURDATE(),last_paid_amount=? WHERE id=?")->execute([$amount,$cid]);
        $msg='✅ Pembayaran Rp '.number_format($amount,0,',','.').' berhasil dicatat!';
    }
}

if($act==='run_auto_isolir'){
    // Jalankan auto-isolir manual
    $grace=(int)($settings['isolir_grace_days']??3);
    $today=date('j');$todayDate=date('Y-m-d');
    $autoStmt=$db->prepare("SELECT pc.*,r.host,r.port r_port,r.username r_user,r.password r_pass FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.status='active' AND pc.due_day<=? AND (pc.last_paid_at IS NULL OR pc.last_paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01')) AND r.is_active=1");
    $autoStmt->execute([(int)$today]);
    $candidates=$autoStmt->fetchAll();
    $isolated=0;
    foreach($candidates as $c){
        $dueDate=date('Y-m-').sprintf('%02d',$c['due_day']);
        $gracePassed=(strtotime($todayDate)-strtotime($dueDate))/86400>=$grace;
        if(!$gracePassed)continue;
        // Isolir
        $isoProfile=$settings['isolir_profile']??'isolir';
        $router=['host'=>$c['host'],'port'=>$c['r_port'],'username'=>$c['r_user'],'password'=>$c['r_pass']];
        $api=new MikrotikAPI($router['host'],(int)$router['port'],$router['username'],$router['password']);
        if($api->connect()){
            $sec=$api->parse($api->talk(['/ppp/secret/print','?name='.$c['pppoe_username'],'=.proplist=.id']));
            if(!empty($sec) && isset($sec[0]['.id'])) {
                $api->talk(['/ppp/secret/set','=.id='.$sec[0]['.id'],'=profile='.$isoProfile]);
            }
            $acts=$api->parse($api->talk(['/ppp/active/print','?name='.$c['pppoe_username'],'=.proplist=.id']));
            foreach($acts as $a){if(isset($a['.id']))$api->talk(['/ppp/active/remove','=.id='.$a['.id']]);}
            $api->close();
        }
        $db->prepare("UPDATE pppoe_customers SET status='isolated',isolated_at=NOW(),isolated_reason='Auto-isolir: jatuh tempo' WHERE id=?")->execute([$c['id']]);
        auditLog('auto_isolir',$c['pppoe_username'],'Auto-isolir jatuh tempo tgl '.$c['due_day']);
        $isolated++;
    }
    $msg="✅ Auto-isolir selesai: $isolated pelanggan diisolir.";
}

// ── Load data ────────────────────────────────────────────
$tab=trim($_GET['tab']??'list');
$search=trim($_GET['q']??'');
$filterStatus=trim($_GET['status']??'');

$sql="SELECT pc.*,(SELECT COALESCE(SUM(amount),0) FROM pppoe_payments WHERE customer_id=pc.id AND period_year=YEAR(NOW()) AND period_month=MONTH(NOW()) AND midtrans_status!='pending') as paid_this_month FROM pppoe_customers pc WHERE pc.router_id=?";
$params=[$selRid];
if($search){$sql.=" AND (pc.pppoe_username LIKE ? OR pc.full_name LIKE ?)";$params[]="%$search%";$params[]="%$search%";}
if($filterStatus){$sql.=" AND pc.status=?";$params[]=$filterStatus;}
$sql.=" ORDER BY pc.status ASC,pc.full_name ASC";
$stmt=$db->prepare($sql);$stmt->execute($params);$customers=$stmt->fetchAll();

// GenieACS devices for dropdown from ALL servers
$genieServers = $db->query("SELECT * FROM genie_config WHERE is_active=1 ORDER BY id ASC")->fetchAll();
$gdevs=[];
foreach($genieServers as $gs){
    $g = GenieACS::fromDB($gs['id']);
    if($g){
        try {
            $all=$g->getDevices('{}');
            foreach((array)$all as $d){
                $inf=$g->getInfo($d);$wf=$g->getWifi($d);
                $brand = $inf['brand'] ?? '';
                $b = strtolower(trim($brand));
                $nb = 'Unknown';
                if(str_contains($b,'huawei')) $nb='Huawei';
                elseif(str_contains($b,'fiberhome') || str_contains($b,'fiber home')) $nb='FiberHome';
                elseif(str_contains($b,'zte')) $nb='ZTE';
                elseif(str_contains($b,'cdata') || str_contains($b,'c-data')) $nb='CData';
                $gdevs[]=['server_id'=>$gs['id'],'server_name'=>$gs['name'],'id'=>$inf['id'],'serial'=>$inf['serial'],'brand'=>$nb,'model'=>$inf['model'],'ssid'=>$wf['ssid_24']??'','tags'=>$inf['tags'],'online'=>$inf['online']];
            }
        } catch(\Exception $e) {}
    }
}

// Stats
try{
    $stats=$db->query("SELECT COUNT(*) total,SUM(status='active') active,SUM(status='isolated') isolated,SUM(monthly_price) total_monthly FROM pppoe_customers WHERE router_id=$selRid")->fetch();
    $manualProj = $settings['manual_proyeksi'] ?? '';
    if (is_numeric($manualProj) && $manualProj > 0) {
        $stats['total_monthly'] = $manualProj;
    }
}catch(\Exception $e){
    // Tabel belum ada - perlu jalankan database.sql
    die('<div style="font-family:sans-serif;padding:40px;max-width:600px;margin:50px auto;background:#FEF2F2;border:1px solid #FCA5A5;border-radius:8px"><h2 style="color:#DC2626">⚠️ Setup Diperlukan</h2><p>Tabel <code>pppoe_customers</code> belum ada di database.</p><p>Silakan jalankan file <strong>database.sql</strong> terlebih dahulu:</p><pre style="background:#1F2937;color:#F9FAFB;padding:12px;border-radius:6px">mysql -u USER -p DBNAME < database.sql</pre><p style="color:#6B7280;font-size:.85rem">Error: '.$e->getMessage().'</p></div>');
}

// Get MikroTik PPPoE active sessions
$activeSessions=[];
if($selRouter){
    try{
        $api=MikrotikAPI::fromRouter($selRouter);
        if($api->connect()){
            $activeSessions=array_column(
                $api->parse($api->talk(['/ppp/active/print','=.proplist=name,address,uptime,service'])),
                null,'name'
            );
            $api->close();
        }
    }catch(\Exception $e){}
}

// Today isolir candidates
$grace=(int)($settings['isolir_grace_days']??3);
$isolirCandidatesStmt=$db->prepare("SELECT COUNT(*) FROM pppoe_customers WHERE router_id=? AND status='active' AND due_day<=? AND (last_paid_at IS NULL OR last_paid_at < DATE_FORMAT(CURDATE(), '%Y-%m-01'))");
$isolirCandidatesStmt->execute([$selRid,(int)date('j')]);
$isolirCandidates=$isolirCandidatesStmt->fetchColumn();

// Riwayat Pendapatan (Cash Masuk) 4 bulan terakhir
$revHistory=[];
if($selRid){
    try{
        $revHistory=$db->query("SELECT period_year, period_month, SUM(amount) as total FROM pppoe_payments p JOIN pppoe_customers c ON p.customer_id=c.id WHERE c.router_id=$selRid GROUP BY period_year, period_month ORDER BY period_year DESC, period_month DESC LIMIT 4")->fetchAll();
    }catch(\Exception $e){}
}

startPage('PPPoE Manager');
?>
<style>
.pstats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}
.pstat{background:var(--white);border-radius:10px;padding:14px;border-left:4px solid var(--blue);box-shadow:var(--shadow-sm)}
.pstat.red{border-color:var(--red)}
.pstat.green{border-color:#16A34A}
.pstat.purple{border-color:#7C3AED}
.pstat-n{font-size:1.6rem;font-weight:900;color:var(--ink);line-height:1.1}
.pstat-l{font-size:.7rem;color:var(--g400);text-transform:uppercase;margin-top:2px}
.sts-active{background:#DCFCE7;color:#15803D;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700}
.sts-isolated{background:#FEE2E2;color:#DC2626;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700}
.sts-suspended{background:#FEF3C7;color:#D97706;padding:2px 8px;border-radius:10px;font-size:.7rem;font-weight:700}
.online-dot{width:7px;height:7px;border-radius:50%;background:#22C55E;display:inline-block;box-shadow:0 0 0 3px rgba(34,197,94,.2);animation:dp 2s infinite}
@media(max-width:800px){
    .pstats{grid-template-columns:1fr 1fr;}
    .dt, .dt tbody, .dt tr, .dt td { display: block; width: 100%; box-sizing: border-box; }
    .dt thead { display: none; }
    .dt tr { margin-bottom: 12px; border: 1px solid var(--g200); border-radius: 12px; overflow: hidden; background: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
    .dt td { border-bottom: 1px solid var(--g100); padding: 10px 14px; text-align: right; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .dt td::before { content: attr(data-label); font-weight: 800; font-size: .7rem; color: var(--g400); text-transform: uppercase; }
    .dt td:last-child { border-bottom: none; }
    .dt td > div { justify-content: flex-end; }
    .tw { overflow: visible; }
}
@media(max-width:480px){.pstats{grid-template-columns:1fr;}}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
    <div>
        <h2 class="ph-t" style="margin:0">🏠 PPPoE Manager</h2>
        <div style="font-size:.78rem;color:var(--g400)">Kelola pelanggan PPPoE + Auto Isolir</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if($isolirCandidates>0):?>
        <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="run_auto_isolir">
        <button class="btn" style="background:#DC2626;color:#fff" onclick="return confirm('Jalankan auto-isolir untuk <?=$isolirCandidates?> pelanggan jatuh tempo?')">
            ⚠️ <?=$isolirCandidates?> Jatuh Tempo — Isolir Sekarang
        </button></form>
        <?php endif;?>
        <button onclick="oM('moAddCustomer')" class="btn bp">➕ Tambah Pelanggan</button>
        <a href="?rid=<?=$selRid?>&tab=settings" class="btn bo">⚙️ Pengaturan</a>
    </div>
</div>

<?php if($msg):?>
<div class="alert a<?=$mtype?>" style="margin-bottom:14px"><?=$msg?></div>
<?php endif;?>

<!-- Router selector -->
<?php if(count($routers)>1):?>
<div class="card" style="padding:10px 14px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:10px">
        <label style="font-size:.75rem;font-weight:700;color:var(--g600);white-space:nowrap">📡 ROUTER</label>
        <select onchange="location='?rid='+this.value+'&tab=<?=$tab?>'" class="fsel" style="max-width:280px">
            <?php foreach($routers as $r):?>
            <option value="<?=$r['id']?>" <?=$selRid==$r['id']?'selected':''?>><?=h($r['name'])?> — <?=h($r['ip_public'])?></option>
            <?php endforeach;?>
        </select>
    </div>
</div>
<?php endif;?>

<!-- Stats -->
<div class="pstats">
    <div class="pstat green"><div class="pstat-n"><?=$stats['active']??0?></div><div class="pstat-l">✅ Aktif</div></div>
    <div class="pstat red"><div class="pstat-n"><?=$stats['isolated']??0?></div><div class="pstat-l">🔴 Diisolir</div></div>
    <div class="pstat"><div class="pstat-n"><?=$stats['total']??0?></div><div class="pstat-l">👥 Total Pelanggan</div></div>
    <div class="pstat purple">
        <div class="pstat-n" style="font-size:1rem;display:flex;align-items:center;gap:4px">
            Rp <?=number_format($stats['total_monthly']??0,0,',','.')?>
            <button onclick="let v=prompt('Masukkan manual proyeksi bulanan (ketik 0 untuk auto-hitung dari pelanggan):', '<?=($settings['manual_proyeksi']??'')?>'); if(v!==null){ document.getElementById('eProj').value=v; document.getElementById('fProj').submit(); }" style="background:none;border:none;cursor:pointer;font-size:1rem">✏️</button>
        </div>
        <div class="pstat-l">💰 Proyeksi Bulanan</div>
    </div>
</div>
<form id="fProj" method="POST" style="display:none">
    <input type="hidden" name="action" value="edit_projection">
    <input type="hidden" name="manual_proyeksi" id="eProj">
</form>

<?php if(!empty($revHistory)):?>
<div class="card" style="padding:14px;margin-bottom:14px;background:linear-gradient(135deg, #0F2266, #1B3FA6);color:#fff">
    <div style="font-size:.75rem;font-weight:800;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px">📈 Riwayat Pendapatan Real (Cash Masuk)</div>
    <div style="display:flex;gap:15px;overflow-x:auto;padding-bottom:5px">
        <?php $mths=['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; ?>
        <?php foreach($revHistory as $rh):?>
        <div style="background:rgba(255,255,255,0.15);padding:10px 14px;border-radius:10px;min-width:140px;flex-shrink:0;border:1px solid rgba(255,255,255,0.2);position:relative">
            <div style="font-size:.7rem;font-weight:700;color:#FCD34D;margin-bottom:3px"><?=$mths[(int)$rh['period_month']]?> <?=$rh['period_year']?></div>
            <div style="font-size:1.1rem;font-weight:900;font-family:'JetBrains Mono',monospace">Rp <?=number_format($rh['total'],0,',','.')?></div>
            <form method="POST" style="position:absolute;top:5px;right:5px" onsubmit="return confirm('Hapus semua data pendapatan bulan <?=$mths[(int)$rh['period_month']]?> <?=$rh['period_year']?>?')">
                <input type="hidden" name="action" value="delete_monthly_income">
                <input type="hidden" name="month" value="<?=$rh['period_month']?>">
                <input type="hidden" name="year" value="<?=$rh['period_year']?>">
                <button type="submit" class="btn bxs" style="background:none;border:none;color:#FCA5A5;padding:0;font-size:.8rem" title="Hapus">✖</button>
            </form>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<?php if($tab==='settings'): // ── SETTINGS TAB ──────────
    $sk=[];foreach($db->query("SELECT setting_key,setting_value FROM pppoe_settings") as $s)$sk[$s['setting_key']]=$s['setting_value'];
?>
<div class="card">
    <div class="ch">
        <div class="ct">⚙️ Pengaturan PPPoE Manager & Midtrans</div>
        <a href="?rid=<?=$selRid?>" class="btn bo">⬅️ Kembali</a>
    </div>
    <div class="cb">
        <form method="POST">
            <?=csrfField()?>
            <input type="hidden" name="action" value="save_settings">
            <div class="f2">
                <div class="fg"><label class="fl">Midtrans Server Key</label><input type="text" name="midtrans_server_key" class="fc" value="<?=h($sk['midtrans_server_key']??'')?>" placeholder="SB-Mid-server-xxx"></div>
                <div class="fg"><label class="fl">Midtrans Client Key</label><input type="text" name="midtrans_client_key" class="fc" value="<?=h($sk['midtrans_client_key']??'')?>" placeholder="SB-Mid-client-xxx"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">Mode Midtrans</label>
                    <select name="midtrans_mode" class="fsel">
                        <option value="sandbox" <?=($sk['midtrans_mode']??'sandbox')==='sandbox'?'selected':''?>>Sandbox (Testing)</option>
                        <option value="production" <?=($sk['midtrans_mode']??'')==='production'?'selected':''?>>Production</option>
                    </select>
                </div>
                <div class="fg"><label class="fl">Profile MikroTik Isolir</label><input type="text" name="isolir_profile" class="fc" value="<?=h($sk['isolir_profile']??'isolir')?>" placeholder="isolir"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">Toleransi Hari Setelah Jatuh Tempo</label><input type="number" name="isolir_grace_days" class="fc" value="<?=h($sk['isolir_grace_days']??'3')?>" min="0" max="30"><div class="fhint">0 = isolir langsung di hari jatuh tempo</div></div>
                <div class="fg"><label class="fl">Nama Perusahaan</label><input type="text" name="company_name" class="fc" value="<?=h($sk['company_name']??'')?>"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">No. Telepon/WA Perusahaan</label><input type="text" name="company_phone" class="fc" value="<?=h($sk['company_phone']??'')?>"></div>
                <div class="fg"><label class="fl">Alamat Perusahaan</label><input type="text" name="company_address" class="fc" value="<?=h($sk['company_address']??'')?>"></div>
            </div>
            
            <hr style="margin:20px 0;border:0;border-top:1px solid var(--g200)">
            <h4 style="margin-bottom:12px;color:var(--blue-d)">⚙️ Template Default ONT & PPPoE</h4>
            
            <div class="f2">
                <div class="fg"><label class="fl">Default VLAN ID PPPoE</label><input type="number" name="tpl_vlan_id" class="fc" value="<?=h($sk['tpl_vlan_id']??'200')?>"></div>
                <div class="fg"><label class="fl">Default Slot WAN</label><input type="number" name="tpl_wan_slot" class="fc" value="<?=h($sk['tpl_wan_slot']??'2')?>"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">Default Profil PPPoE</label><input type="text" name="tpl_profile" class="fc" value="<?=h($sk['tpl_profile']??'default')?>"></div>
                <div class="fg"><label class="fl">Default Harga/Bulan (Rp)</label><input type="number" name="tpl_price" class="fc" value="<?=h($sk['tpl_price']??'0')?>"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">Suffix Username Auto</label><input type="text" name="tpl_user_suffix" class="fc" value="<?=h($sk['tpl_user_suffix']??'@snet')?>"><div class="fhint">Ditambahkan ke username saat mengetik nama</div></div>
                <div class="fg"><label class="fl">Default Password PPPoE & ONT</label><input type="text" name="tpl_password" class="fc" value="<?=h($sk['tpl_password']??'snet12')?>"></div>
            </div>
            <div class="fg"><label class="fl">Default Port Binding</label>
                <div style="display:flex;gap:15px;flex-wrap:wrap;font-size:.85rem;padding:10px;background:var(--g50);border-radius:6px;border:1px solid var(--g200)">
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_lan1" value="1" <?=($sk['tpl_bind_lan1']??'1')==='1'?'checked':''?>> LAN 1</label>
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_lan2" value="1" <?=($sk['tpl_bind_lan2']??'0')==='1'?'checked':''?>> LAN 2</label>
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_lan3" value="1" <?=($sk['tpl_bind_lan3']??'0')==='1'?'checked':''?>> LAN 3</label>
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_lan4" value="1" <?=($sk['tpl_bind_lan4']??'0')==='1'?'checked':''?>> LAN 4</label>
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_wlan1" value="1" <?=($sk['tpl_bind_wlan1']??'1')==='1'?'checked':''?>> WLAN 2.4G (1)</label>
                    <label style="cursor:pointer"><input type="checkbox" name="tpl_bind_wlan5" value="1" <?=($sk['tpl_bind_wlan5']??'1')==='1'?'checked':''?>> WLAN 5G (5)</label>
                </div>
            </div>

            <div style="padding:14px;border-top:1px solid var(--g200);display:flex;justify-content:flex-end">
            <button class="btn bp">💾 Simpan Pengaturan</button>
        </div>
    </form>
</div>

<div class="card">
    <div class="ch"><div class="ct">📜 Script Isolir RouterOS v7</div></div>
    <div class="cb">
        <p style="font-size:.85rem;color:var(--g500);margin-bottom:10px">Copy-paste script di bawah ini ke terminal MikroTik Anda. <strong>Karena Anda menggunakan Cloudflare Tunnels / Port khusus</strong>, silakan isi IP Lokal dan Port Lokal dari web server S.NET Anda.</p>
        <?php
        $hostDomain = $_SERVER['HTTP_HOST'] ?? 'srv5.shaiwr.id';
        // Hapus port jika ada di HTTP_HOST (misal srv5.shaiwr.id:8080)
        $hostDomain = preg_replace('/:\d+$/', '', $hostDomain);
        ?>
        <div style="margin-bottom:10px;padding:10px 14px;background:#FEF3C7;border-left:4px solid #D97706;border-radius:6px;font-size:.82rem;color:#92400E">
            ⚠️ <strong>Penting:</strong> Hapus semua rule isolir lama (address-list, NAT, filter) sebelum menjalankan script di bawah ini. Isi <code>WebServerIP</code> dan <code>WebServerPort</code> sesuai IP lokal server S.NET Anda (cek di Winbox: IP → Addresses).
        </div>
        <div class="sbox" style="user-select:all">## ============================================
## SCRIPT ISOLIR PPPoE S.NET — RouterOS v7
## Jalankan satu per satu di terminal Winbox/SSH
## ============================================

## 1. Variabel — Ganti sesuai kondisi Anda
:local WebServerIP "192.168.9.9"
:local WebServerPort "2040"

## 2. Buat PPPoE Profile "isolir"
##    (Lewati jika profile sudah ada)
/ppp profile
add name=isolir local-address=0.0.0.0 address-list=isolir_users comment="Isolir Otomatis S.NET"

## 3. Address-list bypass (server & payment gateway)
/ip firewall address-list
add list=isolir_bypass address=$WebServerIP comment="S.NET Web Server (lokal)"
add list=isolir_bypass address=[:resolve "<?=h($hostDomain)?>"] comment="S.NET Domain"
add list=isolir_bypass address=[:resolve "app.midtrans.com"] comment="Midtrans"
add list=isolir_bypass address=[:resolve "app.sandbox.midtrans.com"] comment="Midtrans Sandbox"
add list=isolir_bypass address=[:resolve "api.midtrans.com"] comment="Midtrans API"
add list=isolir_bypass address=[:resolve "api.sandbox.midtrans.com"] comment="Midtrans API Sandbox"

## 4. NAT — Redirect HTTP port 80 ke portal isolir
/ip firewall nat
# Izinkan traffic balik dari server S.NET ke isolir users
add action=masquerade chain=srcnat comment="MASQ ISOLIR REPLY" src-address=$WebServerIP

# Redirect semua HTTP dari isolir_users ke portal (kecuali tujuan bypass)
add action=dst-nat chain=dstnat comment="ISOLIR: Redirect HTTP ke Portal" \
    dst-port=80 protocol=tcp \
    src-address-list=isolir_users \
    dst-address-list=!isolir_bypass \
    to-addresses=$WebServerIP to-ports=$WebServerPort

## 5. Filter — Izinkan DNS, akses portal & payment; blokir sisanya
/ip firewall filter
add action=accept chain=forward comment="ISOLIR: Allow DNS UDP" \
    src-address-list=isolir_users dst-port=53 protocol=udp
add action=accept chain=forward comment="ISOLIR: Allow DNS TCP" \
    src-address-list=isolir_users dst-port=53 protocol=tcp
add action=accept chain=forward comment="ISOLIR: Allow to Portal & Payment" \
    src-address-list=isolir_users dst-address-list=isolir_bypass
add action=accept chain=forward comment="ISOLIR: Allow reply from Portal" \
    dst-address-list=isolir_users src-address-list=isolir_bypass
add action=drop chain=forward comment="ISOLIR: Drop semua traffic lain" \
    src-address-list=isolir_users</div>
        <div style="margin-top:10px;padding:10px 14px;background:#EFF6FF;border-left:4px solid #3B82F6;border-radius:6px;font-size:.82rem;color:#1E40AF">
            💡 <strong>Cara verifikasi:</strong> Setelah script dijalankan, ubah 1 pelanggan ke profil "isolir" dari admin. Kemudian di Winbox buka <strong>IP → Firewall → Address Lists</strong>, cek apakah pelanggan tsb masuk di list <code>isolir_users</code> setelah konek. Jika iya, redirect akan bekerja.
        </div>
    </div>
</div>

<?php else: // ── LIST PELANGGAN TAB ──────────────────────?>

<!-- Search & filter -->
<div class="card" style="padding:10px 14px;margin-bottom:12px">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="rid" value="<?=$selRid?>">
        <div style="flex:1;min-width:180px"><input type="text" name="q" class="fc" placeholder="🔍 Cari username / nama..." value="<?=h($search)?>"></div>
        <select name="status" class="fsel" style="width:140px">
            <option value="">Semua Status</option>
            <option value="active" <?=$filterStatus==='active'?'selected':''?>>✅ Aktif</option>
            <option value="isolated" <?=$filterStatus==='isolated'?'selected':''?>>🔴 Diisolir</option>
        </select>
        <button type="submit" class="btn bo">Cari</button>
        <?php if($search||$filterStatus):?><a href="?rid=<?=$selRid?>" class="btn">✕ Reset</a><?php endif;?>
    </form>
</div>

<div class="card">
    <div class="ch">
        <div class="ct">👥 Pelanggan PPPoE (<?=count($customers)?>)</div>
        <div style="font-size:.7rem;color:var(--g400)"><?=count($activeSessions)?> sesi online</div>
    </div>
    <div class="tw">
    <table class="dt">
        <thead><tr>
            <th>Status</th><th>Username PPPoE</th><th>Nama</th><th>Profil</th>
            <th>Jatuh Tempo</th><th>Harga/Bln</th><th>Sesi Online</th><th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach($customers as $c):
            $online=isset($activeSessions[$c['pppoe_username']]);
            $today=date('j');
            $isLate=$c['status']==='active'&&$c['due_day']<=$today&&!$c['paid_this_month'];
            $daysLeft=$c['due_day']-(int)$today;
        ?>
        <tr style="<?=$c['status']==='isolated'?'background:#FFF5F5':''; ?>">
            <td data-label="Status">
                <?php if($c['status']==='active'&&$online):?>
                    <span class="sts-active">🟢 Online</span>
                <?php elseif($c['status']==='active'&&$isLate):?>
                    <span class="sts-suspended">⚠️ Jatuh Tempo</span>
                <?php elseif($c['status']==='active'):?>
                    <span class="sts-active">✅ Aktif</span>
                <?php else:?>
                    <span class="sts-isolated">🔴 Isolir</span>
                <?php endif;?>
            </td>
            <td data-label="Username"><strong style="font-family:'JetBrains Mono',monospace;color:var(--blue-d);word-break:break-all"><?=h($c['pppoe_username'])?></strong></td>
            <td data-label="Nama" style="text-align:right"><?=h($c['full_name'])?><?php if($c['phone']):?><br><span style="font-size:.7rem;color:var(--g400)"><?=h($c['phone'])?></span><?php endif;?></td>
            <td data-label="Profil"><span class="bdg" style="font-size:.7rem"><?=h($c['profile'])?:'-'?></span></td>
            <td data-label="Jatuh Tempo">
                <strong>Tgl <?=$c['due_day']?></strong>
                <?php if($daysLeft>0&&$daysLeft<=5&&$c['status']==='active'):?>
                    <span style="font-size:.65rem;color:#D97706;margin-left:4px">⚡ <?=$daysLeft?> hari lagi</span>
                <?php elseif($isLate):?>
                    <span style="font-size:.65rem;color:var(--red);margin-left:4px">⚠️ Terlambat!</span>
                <?php endif;?>
            </td>
            <td data-label="Biaya">Rp <?=number_format($c['monthly_price'],0,',','.')?></td>
            <td data-label="Sesi">
                <?php if($online):?>
                <div style="display:flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                    <span class="online-dot"></span>
                    <span style="font-size:.7rem"><?=$activeSessions[$c['pppoe_username']]['address']??''?></span>
                    <span style="font-size:.65rem;color:#16A34A"><?=$activeSessions[$c['pppoe_username']]['uptime']??''?></span>
                </div>
                <?php else:?>
                <span style="color:var(--g400);font-size:.75rem">Offline</span>
                <?php endif;?>
            </td>
            <td data-label="Aksi">
                <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <button onclick="openDetail(<?=$c['id']?>)" class="btn bo bxs" title="Detail">👤</button>
                    <button onclick="openBayar(<?=$c['id']?>,'<?=h($c['full_name'])?>',<?=$c['monthly_price']?>)" class="btn bxs" style="background:#16A34A;color:#fff" title="Catat Bayar">💰</button>
                    <?php if($c['status']==='active'):?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Isolir <?=h($c['pppoe_username'])?>?')">
                        <input type="hidden" name="action" value="isolir">
                        <input type="hidden" name="cid" value="<?=$c['id']?>">
                        <input type="hidden" name="reason" value="Manual oleh admin">
                        <button class="btn bxs" style="background:#DC2626;color:#fff" title="Isolir">🔴</button>
                    </form>
                    <?php else:?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Aktifkan kembali <?=h($c['pppoe_username'])?>?')">
                        <input type="hidden" name="action" value="reaktivasi">
                        <input type="hidden" name="cid" value="<?=$c['id']?>">
                        <button class="btn bxs" style="background:#16A34A;color:#fff" title="Aktifkan">✅</button>
                    </form>
                    <?php endif;?>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Hapus pelanggan <?=h($c['pppoe_username'])?> secara permanen dari Database dan MikroTik?')">
                        <input type="hidden" name="action" value="delete_customer">
                        <input type="hidden" name="cid" value="<?=$c['id']?>">
                        <button class="btn bxs" style="background:#B91C1C;color:#fff" title="Hapus">🗑️</button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach;?>
        <?php if(empty($customers)):?>
        <tr><td colspan="8" style="text-align:center;padding:30px;color:var(--g400)">Belum ada pelanggan PPPoE. <button onclick="oM('moAddCustomer')" class="btn bp bsm">➕ Tambah</button></td></tr>
        <?php endif;?>
        </tbody>
    </table>
    </div>
</div>
<?php endif;?>

<!-- Modal Tambah Pelanggan -->
<div class="mo" id="moAddCustomer">
    <div class="md" style="max-width:600px">
        <div class="mh"><div class="mt">➕ Tambah Pelanggan PPPoE & ONT</div><button class="mx" onclick="cM('moAddCustomer')">✕</button></div>
        <div class="mb">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="add_customer">
                <div style="background:var(--g50);border-radius:9px;padding:14px;margin-bottom:12px;border:1px solid var(--g200)">
                    <div style="font-weight:700;color:var(--blue-d);margin-bottom:10px;font-size:.82rem">📡 Pilih ONT dari GenieACS (Opsional)</div>
                    <?php if(!empty($genieServers)):?>
                    
                    <div class="fg">
                        <label class="fl">Cari ONT Berdasarkan Serial Number (SN)</label>
                        <div style="display:flex;gap:6px">
                            <input type="text" id="ontSN" class="fc" placeholder="Masukkan Serial Number ONT...">
                            <button type="button" class="btn bo" onclick="searchSN()">🔍 Cari & Pilih</button>
                        </div>
                        <div id="snResult" style="font-size:0.75rem;font-weight:bold;margin-top:6px"></div>
                    </div>

                    <div class="fg" <?= $isTeknisi ? 'style="display:none"' : '' ?>>
                        <select name="genie_device_id" class="fsel" id="aGenieSel" size="4" style="height:auto" onchange="onGenieSel(this)">
                            <option value="">-- Lewati / Tidak Setting ONT --</option>
                            <?php foreach($gdevs as $gd):?>
                            <option value="<?=h($gd['id'])?>" data-genie="<?=h($gd['server_id'])?>"
                                data-search="<?=strtolower(h($gd['server_name'].' '.$gd['serial'].' '.$gd['ssid'].' '.implode(' ',$gd['tags'])))?>">
                                [<?=h($gd['server_name'])?>] <?=$gd['online']?'🟢':'🔴'?> [<?=h($gd['brand'])?>] <?=h($gd['serial'])?><?=$gd['ssid']?' — '.h($gd['ssid']):''?><?=!empty($gd['tags'])?' 🏷'.h($gd['tags'][0]):'';?>
                            </option>
                            <?php endforeach;?>
                        </select>
                    </div>
                    <input type="hidden" name="genie_id" id="aGenieId">
                    <div <?= $isTeknisi ? 'style="display:none"' : '' ?>>
                        <div id="vlanConfig" style="display:none;margin-top:10px;background:#fff;padding:10px;border-radius:6px;border:1px solid var(--g200)">
                        <?php
                        $tplVlan = h($settings['tpl_vlan_id']??'200');
                        $tplWan  = h($settings['tpl_wan_slot']??'2');
                        $tL1 = ($settings['tpl_bind_lan1']??'1')==='1'?'checked':'';
                        $tL2 = ($settings['tpl_bind_lan2']??'0')==='1'?'checked':'';
                        $tL3 = ($settings['tpl_bind_lan3']??'0')==='1'?'checked':'';
                        $tL4 = ($settings['tpl_bind_lan4']??'0')==='1'?'checked':'';
                        $tW1 = ($settings['tpl_bind_wlan1']??'1')==='1'?'checked':'';
                        $tW5 = ($settings['tpl_bind_wlan5']??'1')==='1'?'checked':'';
                        ?>
                        <div style="font-weight:600;font-size:.8rem;margin-bottom:8px">⚙️ Setting ONT (Template)</div>
                        <div class="frow2" style="margin-bottom:8px">
                            <div class="fg"><label class="fl">Slot WAN</label><input type="number" name="wan_slot" class="fc" value="<?=$tplWan?>" min="1"></div>
                            <div class="fg"><label class="fl">VLAN PPPoE</label>
                                <div style="display:flex;gap:6px">
                                    <label style="display:flex;align-items:center;gap:4px;font-size:.8rem"><input type="checkbox" name="vlan_enable" value="1" checked> Aktif</label>
                                    <input type="number" name="vlan_id" class="fc" value="<?=$tplVlan?>" style="width:70px">
                                </div>
                            </div>
                        </div>
                        <div class="fg" style="margin-bottom:0"><label class="fl">Port Binding</label>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;font-size:.8rem">
                                <label><input type="checkbox" name="binds[]" value="LAN1" <?=$tL1?>> LAN 1</label>
                                <label><input type="checkbox" name="binds[]" value="LAN2" <?=$tL2?>> LAN 2</label>
                                <label><input type="checkbox" name="binds[]" value="LAN3" <?=$tL3?>> LAN 3</label>
                                <label><input type="checkbox" name="binds[]" value="LAN4" <?=$tL4?>> LAN 4</label>
                                <label><input type="checkbox" name="binds[]" value="WLAN1" <?=$tW1?>> WLAN 2.4G</label>
                                <label><input type="checkbox" name="binds[]" value="WLAN5" <?=$tW5?>> WLAN 5G</label>
                            </div>
                        </div>
                    </div>
                    </div>
                    <?php else:?>
                    <div class="alert awrn" style="margin:0">⚠️ GenieACS belum terhubung. <a href="/admin/genie.php">Setup GenieACS</a></div>
                    <?php endif;?>
                </div>
                
                <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;background:var(--g50);padding:10px 14px;border-radius:8px;border:1px solid var(--g200);margin-bottom:14px;cursor:pointer">
                    <input type="checkbox" name="skip_mikrotik" value="1"> 
                    <span>✅ <b>Pelanggan sudah ada di MikroTik</b> (Lewati tambah Secret ke MikroTik, hanya simpan ke Database)</span>
                </label>

                <div class="fg"><label class="fl">Nama Lengkap *</label><input type="text" name="full_name" class="fc" required placeholder="Budi Santoso" onkeyup="autoUser(this.value)"></div>
                <div class="f2" <?= $isTeknisi ? 'style="display:none"' : '' ?>>
                    <div class="fg"><label class="fl">Username PPPoE *</label>
                        <div style="display:flex;gap:6px">
                            <input type="text" name="pppoe_username" id="pUser" class="fc" <?= $isTeknisi ? '' : 'required' ?> placeholder="pelanggan01">
                            <button type="button" class="btn bo" onclick="document.getElementById('pUser').value='user'+Math.floor(1000+Math.random()*9000)" title="Auto Generate">🎲</button>
                        </div>
                    </div>
                    <div class="fg"><label class="fl">Password MikroTik & ONT</label>
                        <div style="display:flex;gap:6px">
                            <input type="text" name="password" id="pPass" class="fc" value="<?=h($settings['tpl_password']??'snet12')?>" placeholder="Kosong = Auto generate">
                            <button type="button" class="btn bo" onclick="document.getElementById('pPass').value=Math.random().toString(36).substring(2,8)" title="Auto Generate">🎲</button>
                        </div>
                        <div class="fhint">Akan dikirim ke PPPoE & ONT</div>
                    </div>
                </div>
                <div class="f2">
                    <div class="fg"><label class="fl">No. HP/WA</label><input type="text" name="phone" class="fc" placeholder="08xxxxxxxxxx"></div>
                    <div class="fg"><label class="fl">Tanggal Jatuh Tempo</label><input type="number" name="due_day" class="fc" value="<?=date('j')?>" min="1" max="28"></div>
                </div>
                <div class="fg"><label class="fl">Alamat</label><textarea name="address" class="fc" rows="2"></textarea></div>
                <button type="submit" class="btn bp" style="width:100%;padding:10px;font-size:1.05rem;font-weight:bold">➕ Tambah Pelanggan & Set ONT</button>
            </form>
        </div>
    </div>
</div>

<!-- Modal Catat Bayar -->
<div class="mo" id="moBayar">
    <div class="md" style="max-width:380px">
        <div class="mh"><div class="mt" id="bayarTitle">💰 Catat Pembayaran</div><button class="mx" onclick="cM('moBayar')">✕</button></div>
        <div class="mb">
            <form method="POST">
                <?=csrfField()?>
                <input type="hidden" name="action" value="bayar">
                <input type="hidden" name="cid" id="bayarCid">
                <div class="fg"><label class="fl">Jumlah Bayar (Rp)</label><input type="number" name="amount" id="bayarAmount" class="fc" min="0"></div>
                <div class="f2">
                    <div class="fg"><label class="fl">Bulan</label>
                        <select name="month" class="fsel">
                            <?php for($m=1;$m<=12;$m++):?>
                            <option value="<?=$m?>" <?=$m==date('n')?'selected':''?>><?=date('F',mktime(0,0,0,$m,1))?></option>
                            <?php endfor;?>
                        </select>
                    </div>
                    <div class="fg"><label class="fl">Tahun</label><input type="number" name="year" class="fc" value="<?=date('Y')?>" min="2020"></div>
                </div>
                <div class="fg"><label class="fl">Catatan</label><input type="text" name="notes" class="fc" placeholder="Bayar tunai..."></div>
                <button type="submit" class="btn bp" style="width:100%;padding:10px">💾 Simpan Pembayaran</button>
            </form>
        </div>
    </div>
</div>

<script>
function searchSN() {
    let sn = document.getElementById('ontSN').value.trim().toLowerCase();
    if(sn.length < 4) {
        document.getElementById('snResult').innerHTML = '<span style="color:var(--red)">SN terlalu pendek!</span>';
        return;
    }
    
    let sel = document.getElementById('aGenieSel');
    // 1. Cari dulu di list dropdown yang sudah ada
    for(let i=0; i<sel.options.length; i++) {
        let opt = sel.options[i];
        if(opt.dataset.search && opt.dataset.search.includes(sn)) {
            sel.selectedIndex = i;
            onGenieSel(sel);
            document.getElementById('snResult').innerHTML = '<span style="color:#16A34A">✅ ONT berhasil ditemukan dan otomatis dipilih!</span>';
            return;
        }
    }

    // 2. Jika tidak ada di dropdown, baru cari live ke ACS via AJAX
    document.getElementById('snResult').innerHTML = '⏳ Mencari ONT di ACS...';
    fetch('?rid=<?=$selRid?>&act=search_acs_sn&sn=' + encodeURIComponent(sn))
    .then(r=>r.json())
    .then(data => {
        if(data.server_id) {
            let exists = false;
            for(let i=0; i<sel.options.length; i++) {
                if(sel.options[i].value === data.device_id) {
                    sel.selectedIndex = i;
                    exists = true;
                    break;
                }
            }
            if(!exists) {
                let opt = document.createElement('option');
                opt.value = data.device_id;
                opt.text = '[Ditemukan via SN] ' + sn;
                opt.dataset.genie = data.server_id;
                sel.add(opt);
                sel.value = data.device_id;
            }
            onGenieSel(sel);
            document.getElementById('snResult').innerHTML = '<span style="color:#16A34A">✅ ONT berhasil ditemukan dan otomatis dipilih!</span>';
        } else {
            document.getElementById('snResult').innerHTML = '<span style="color:#DC2626">❌ ONT tidak ditemukan. Pastikan ONT sudah menyala dan terhubung ke OLT.</span>';
        }
    }).catch(e=>{
        document.getElementById('snResult').innerHTML = '<span style="color:#DC2626">❌ Gagal menghubungi server!</span>';
    });
}

function autoUser(val) {
    let name = val.toLowerCase().replace(/[^a-z0-9]/g, '');
    let suf = <?=json_encode($settings['tpl_user_suffix']??'@snet')?>;
    if (name) {
        document.getElementById('pUser').value = name + suf;
    } else {
        document.getElementById('pUser').value = '';
    }
}
function filterOntList(q){
    q=q.toLowerCase().trim();
    const sel=document.getElementById('aGenieSel');
    if(!sel)return;
    Array.from(sel.options).forEach(o=>{
        if(!o.value){o.style.display='';return;}
        o.style.display=(!q||o.dataset.search.includes(q))?'':'none';
    });
}
function onGenieSel(sel){
    const o=sel.options[sel.selectedIndex];
    if(o && o.value){
        document.getElementById('aGenieId').value = o.dataset.genie || '';
        document.getElementById('vlanConfig').style.display = 'flex';
    } else {
        document.getElementById('aGenieId').value = '';
        document.getElementById('vlanConfig').style.display = 'none';
    }
}
function openBayar(cid,name,price){
    document.getElementById('bayarCid').value=cid;
    document.getElementById('bayarAmount').value=price;
    document.getElementById('bayarTitle').textContent='💰 Bayar — '+name;
    oM('moBayar');
}
function openDetail(cid){
    window.location='/admin/pppoe_detail.php?cid='+cid+'&rid=<?=$selRid?>';
}
</script>

<?php endPage();?>
