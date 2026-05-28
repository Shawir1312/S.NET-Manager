<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();
$msg='';$mtype='ok';
$genieId = isset($_GET['genie_id']) ? (int)$_GET['genie_id'] : 0;
// Auto-migrasi tabel genie_config jika belum ada
try{$db->query("SELECT id FROM genie_config LIMIT 1");}catch(Exception $e){try{$db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,url VARCHAR(255) NOT NULL,username VARCHAR(100) DEFAULT '',password VARCHAR(100) DEFAULT '',is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Exception $e2){}}
$genie = $genieId > 0 ? GenieACS::fromDB($genieId) : GenieACS::fromDB();
$genieServers = $db->query("SELECT id, name FROM genie_config ORDER BY id ASC")->fetchAll();
// Set current genieId to the active one for UI
if($genie && empty($genieId)) {
    // try to get current id from the loaded genie, but we don't have an ID exposed. 
    // It's fine, we'll just show the first one or leave it empty.
}

// ── AJAX: live optical data (dipanggil JS setiap 30 detik) ───────────
// GET ?ajax=optical&id=<device_id>
if(isset($_GET['ajax']) && $_GET['ajax']==='optical' && isset($_GET['id']) && $genie){
    header('Content-Type: application/json');
    $ajId=$_GET['id'];
    $ajDev=$genie->getDevice($ajId);
    if($ajDev){
        $opt=$genie->getOptical($ajDev);
        echo json_encode([
            'ok'        => true,
            'rx_power'  => $opt['rx_power'],
            'tx_power'  => $opt['tx_power'],
            'temp'      => $opt['temp'],
            'rx_status' => $opt['rx_status'],
            'rx_raw'    => $opt['rx_raw'],
            'vp_rx'     => $opt['vp_rx'],
        ]);
    } else {
        echo json_encode(['ok'=>false,'error'=>$genie->error]);
    }
    exit;
}

// ── POST HANDLERS ────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'&&$genie){
    $act=$_POST['action']??'';
    $did=$_POST['device_id']??'';

    if($did&&($dev=$genie->getDevice($did))){

        // ── SET WIFI ──────────────────────────────────────────────
        if($act==='set_wifi'){
            $s24=trim($_POST['ssid_24']??'');
            $k24=trim($_POST['key_24']??'');
            $s5g=trim($_POST['ssid_5g']??'');
            $samePass=!empty($_POST['same_pass']);
            // Jika same_pass, k5g = k24; jika tidak, ambil dari field terpisah
            $k5g=$samePass?$k24:trim($_POST['key_5g']??'');

            $ok=$genie->setWifi($did,$dev,$s24?:null,$k24?:null,$s5g?:null,$k5g?:null,$samePass);
            if($ok){
                $msg='✅ Perintah WiFi berhasil dikirim ke ONT!';$mtype='ok';
                // Simpan config jika ada customer
                $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
                $custRow->execute([$did]);$custId=$custRow->fetchColumn();
                if($custId){
                    saveOntConfig((int)$custId,$did,'wifi','WiFi Config',[
                        'ssid_24'=>$s24,'key_24'=>$k24,'ssid_5g'=>$s5g,'key_5g'=>$k5g,'same_pass'=>$samePass
                    ]);
                }
                auditLog('set_wifi',$did,"SSID24=$s24,samePass=".($samePass?'1':'0'));
            }else{$msg='❌ Gagal kirim WiFi: '.$genie->error;$mtype='err';}
        }

        // ── SET WAN ───────────────────────────────────────────────
        if($act==='set_wan'){
            $cfg=[
                'wan_slot'    =>(int)($_POST['wan_slot']??1),
                'conn_mode'   =>$_POST['conn_mode']??'route',  // route | bridge_ip | bridge_ppp
                'service_list'=>$_POST['service_list']??'INTERNET',
                'wan_name'    =>trim($_POST['wan_name']??''),
                'vlan_enable' =>!empty($_POST['vlan_enable']),
                'vlan_id'     =>(int)($_POST['vlan_id']??0),
                'vlan_priority'=>(int)($_POST['vlan_priority']??0),
                // Route-only params
                'addr_type'   =>$_POST['addr_type']??'dhcp',
                'static_ip'   =>trim($_POST['static_ip']??''),
                'static_mask' =>trim($_POST['static_mask']??''),
                'static_gw'   =>trim($_POST['static_gw']??''),
                'dns1'        =>trim($_POST['dns1']??''),
                'dns2'        =>trim($_POST['dns2']??''),
                // Bridge PPP-only
                'pppoe_user'  =>trim($_POST['pppoe_user']??''),
                'pppoe_pass'  =>trim($_POST['pppoe_pass']??''),
            ];

            $ok=$genie->setWan($did,$dev,$cfg);
            if($ok){
                $msg='✅ Konfigurasi WAN ('.$cfg['conn_mode'].') berhasil dikirim ke ONT!';$mtype='ok';
                // Simpan config
                $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
                $custRow->execute([$did]);$custId=$custRow->fetchColumn();
                if($custId){
                    $cname='WAN Slot '.$cfg['wan_slot'].' '.$cfg['conn_mode'].($cfg['vlan_enable']?' VLAN'.$cfg['vlan_id']:'');
                    saveOntConfig((int)$custId,$did,'wan',$cname,$cfg);
                }
                auditLog('set_wan',$did,"Mode=".$cfg['conn_mode'].",Slot=".$cfg['wan_slot']);
            }else{$msg='❌ Gagal kirim WAN: '.$genie->error;$mtype='err';}
        }

        // ── ADD WAN (addObject + setParameterValues) ────────────────────
        if($act==='add_wan'){
            $cfg=[
                'wan_cd'      =>(int)($_POST['wan_cd']??1),        // WANConnectionDevice index
                'conn_mode'   =>$_POST['conn_mode']??'bridge_ppp',
                'service_list'=>$_POST['service_list']??'INTERNET',
                'wan_name'    =>trim($_POST['wan_name']??''),
                'vlan_enable' =>!empty($_POST['vlan_enable']),
                'vlan_id'     =>(int)($_POST['vlan_id']??0),
                'vlan_priority'=>(int)($_POST['vlan_priority']??0),
                // Route-only
                'addr_type'   =>$_POST['addr_type']??'dhcp',
                'static_ip'   =>trim($_POST['static_ip']??''),
                'static_mask' =>trim($_POST['static_mask']??''),
                'static_gw'   =>trim($_POST['static_gw']??''),
                'dns1'        =>trim($_POST['dns1']??''),
                'dns2'        =>trim($_POST['dns2']??''),
                // PPPoE
                'pppoe_user'  =>trim($_POST['pppoe_user']??''),
                'pppoe_pass'  =>trim($_POST['pppoe_pass']??''),
            ];
            $ok=$genie->addWan($did,$dev,$cfg);
            if($ok){
                $msg='✅ Add WAN berhasil! addObject + setParameterValues dikirim ke ONT (WANConnectionDevice.'.$cfg['wan_cd'].'.'.
                     ($cfg['conn_mode']==='bridge_ppp'?'WANPPPConnection':'WANIPConnection').')';$mtype='ok';
                $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
                $custRow->execute([$did]);$custId=$custRow->fetchColumn();
                if($custId){
                    $cname='Add WAN CD'.$cfg['wan_cd'].' '.$cfg['conn_mode'].($cfg['vlan_enable']?' VLAN'.$cfg['vlan_id']:'');
                    saveOntConfig((int)$custId,$did,'wan',$cname,$cfg);
                }
                auditLog('add_wan',$did,"CD=".$cfg['wan_cd'].",Mode=".$cfg['conn_mode'].",VLAN=".$cfg['vlan_id']);
            }else{$msg='❌ Gagal Add WAN: '.$genie->error;$mtype='err';}
        }

        // ── BIND WAN ──────────────────────────────────────────────
        if($act==='bind_wan'){
            // Bangun bindStr dari checkbox yang dicentang + field manual
            $manualStr=trim($_POST['bind_manual']??'');
            $selected=[];

            // LAN ports
            foreach(($_POST['bind_lan']??[]) as $idx){
                $selected[]="InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.".((int)$idx);
            }
            // SSID (semua nomor WLAN, tidak dibedakan 2.4/5G karena sesuai dokumen)
            foreach(($_POST['bind_ssid']??[]) as $idx){
                $selected[]="InternetGatewayDevice.LANDevice.1.WLANConfiguration.".((int)$idx);
            }
            // Gabungkan dengan input manual (jika ada)
            $bindStr='';
            if($selected) $bindStr=implode(',',$selected);
            if($manualStr){
                $bindStr=$bindStr?$bindStr.','.$manualStr:$manualStr;
            }

            $wanSlot=(int)($_POST['wan_slot']??1);
            $wanType=$_POST['wan_type']??'ip';
            $wanInterface=trim($_POST['wan_interface']??'');

            $ok=$genie->bindWan($did,$dev,[
                'bind_str'     =>$bindStr,
                'wan_slot'     =>$wanSlot,
                'wan_type'     =>$wanType,
                'wan_interface'=>$wanInterface,
            ]);
            if($ok){
                $msg='✅ Binding WAN→LAN/WiFi berhasil dikirim!';$mtype='ok';
                // Simpan config
                $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
                $custRow->execute([$did]);$custId=$custRow->fetchColumn();
                if($custId){
                    saveOntConfig((int)$custId,$did,'binding','WAN Binding Slot '.$wanSlot,[
                        'wan_slot'=>$wanSlot,'wan_type'=>$wanType,
                        'bind_str'=>$bindStr,'wan_interface'=>$wanInterface
                    ]);
                }
                auditLog('bind_wan',$did,"Slot=$wanSlot,Bind=$bindStr");
            }else{$msg='❌ Gagal binding: '.$genie->error;$mtype='err';}
        }

        // ── DELETE SAVED CONFIG ───────────────────────────────────
        if($act==='delete_saved'){
            $cfgId=(int)($_POST['cfg_id']??0);
            if($cfgId){
                $chk=$db->prepare("SELECT id,config_name FROM ont_configs WHERE id=? LIMIT 1");
                $chk->execute([$cfgId]);$chkRow=$chk->fetch();
                if($chkRow){
                    $db->prepare("DELETE FROM ont_configs WHERE id=?")->execute([$cfgId]);
                    $msg='🗑️ Config "'.h($chkRow['config_name']).'" berhasil dihapus.';$mtype='ok';
                    auditLog('delete_ont_config',$did,'Config ID='.$cfgId);
                } else {$msg='❌ Config tidak ditemukan.';$mtype='err';}
            }
        }

        // ── PUSH SAVED CONFIG ─────────────────────────────────────
        if($act==='push_saved'){
            $cfgId=(int)($_POST['cfg_id']??0);
            $cfgRow=$db->prepare("SELECT * FROM ont_configs WHERE id=? LIMIT 1");
            $cfgRow->execute([$cfgId]);$cfgRow=$cfgRow->fetch();
            if($cfgRow){
                $data=json_decode($cfgRow['config_data'],true)??[];
                $ok=false;
                if($cfgRow['config_type']==='wifi'){
                    $ok=$genie->setWifi($did,$dev,$data['ssid_24']??null,$data['key_24']??null,$data['ssid_5g']??null,$data['key_5g']??null,(bool)($data['same_pass']??false));
                }elseif($cfgRow['config_type']==='wan'){
                    $ok=$genie->setWan($did,$dev,$data);
                }elseif($cfgRow['config_type']==='binding'){
                    $ok=$genie->bindWan($did,$dev,$data);
                }
                if($ok){
                    $db->prepare("UPDATE ont_configs SET push_status='success',push_count=push_count+1,last_pushed=NOW() WHERE id=?")->execute([$cfgId]);
                    $msg='✅ Konfigurasi "'.$cfgRow['config_name'].'" berhasil di-push ulang!';$mtype='ok';
                }else{
                    $db->prepare("UPDATE ont_configs SET push_status='failed' WHERE id=?")->execute([$cfgId]);
                    $msg='❌ Gagal push "'.h($cfgRow['config_name']).'": '.$genie->error;$mtype='err';
                }
            }
        }

        // ── PUSH ALL SAVED ────────────────────────────────────────
        if($act==='push_all_saved'){
            $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
            $custRow->execute([$did]);$custId=$custRow->fetchColumn();
            if($custId){
                $allCfg=$db->prepare("SELECT * FROM ont_configs WHERE customer_id=? AND genie_device_id=? ORDER BY created_at ASC");
                $allCfg->execute([$custId,$did]);$allCfg=$allCfg->fetchAll();
                $ok2=0;$fail=0;
                foreach($allCfg as $cfgRow){
                    $data=json_decode($cfgRow['config_data'],true)??[];
                    $ok=false;
                    if($cfgRow['config_type']==='wifi') $ok=$genie->setWifi($did,$dev,$data['ssid_24']??null,$data['key_24']??null,$data['ssid_5g']??null,$data['key_5g']??null,(bool)($data['same_pass']??false));
                    elseif($cfgRow['config_type']==='wan') $ok=$genie->setWan($did,$dev,$data);
                    elseif($cfgRow['config_type']==='binding') $ok=$genie->bindWan($did,$dev,$data);
                    $ok?$ok2++:$fail++;
                    $db->prepare("UPDATE ont_configs SET push_status=?,push_count=push_count+1,last_pushed=NOW() WHERE id=?")->execute([$ok?'success':'failed',$cfgRow['id']]);
                    usleep(500000); // 0.5s delay antar push
                }
                $msg="Push semua: <strong>$ok2 berhasil</strong>".($fail?" | $fail gagal":"");
                $mtype=$fail?'wrn':'ok';
            }
        }

        // ── REBOOT ────────────────────────────────────────────────
        if($act==='reboot'){
            $ok=$genie->reboot($did);
            $msg=$ok?'✅ Reboot ONT berhasil dikirim!':'❌ Gagal reboot: '.$genie->error;
            $mtype=$ok?'ok':'err';
            auditLog('reboot_ont',$did);
        }

        // ── REFRESH ───────────────────────────────────────────────
        if($act==='refresh'){
            $genie->refresh($did);
            $msg='✅ Refresh parameter dikirim. Data akan diperbarui dalam beberapa detik.';$mtype='ok';
        }

        // ── REFRESH OPTIK ────────────────────────────────────────
        if($act==='refresh_optical'){
            $optPaths=$genie->getOpticalPaths($dev);
            $optPaths[]='InternetGatewayDevice.DeviceInfo.';
            $ok=$genie->task($did,['name'=>'getParameterValues','parameterNames'=>$optPaths]);
            $msg=$ok?'✅ Perintah baca sinyal optik dikirim ke ONT! Data akan diperbarui dalam beberapa detik.':'❌ Gagal kirim perintah optik: '.$genie->error;
            $mtype=$ok?'ok':'err';
        }
    }
}

// ── SINGLE DEVICE VIEW ───────────────────────────────────────────
$sid=$_GET['id']??'';
$sdev=null;$sinf=[];$swifi=[];$sclients=[];$swan=[];$savedCfgs=[];

if($sid&&$genie){
    $sdev=$genie->getDevice($sid);
    if($sdev){
        $sinf    =$genie->getInfo($sdev);
        $swifi   =$genie->getWifi($sdev);
        $sclients=$genie->getClients($sdev);
        $swan    =$genie->getWanList($sdev);
        $soptical=$genie->getOptical($sdev);
        // Load saved configs jika ada customer
        $custRow=$db->prepare("SELECT id FROM customers WHERE genie_device_id=? LIMIT 1");
        $custRow->execute([$sid]);$cid=$custRow->fetchColumn();
        if($cid){
            $sc=$db->prepare("SELECT * FROM ont_configs WHERE customer_id=? AND genie_device_id=? ORDER BY config_type,created_at DESC");
            $sc->execute([$cid,$sid]);$savedCfgs=$sc->fetchAll();
        }
    }
}

// ── LIST VIEW DATA ───────────────────────────────────────────────
$search=trim($_GET['q']??'');
$allDevicesGrouped=[]; $total=$online=$offline=0;
$genieOk=false;$genieErrs=[];$genieErr='';

if(!$sid){
    $serversToQuery = $db->query("SELECT * FROM genie_config WHERE is_active=1 ORDER BY id ASC")->fetchAll();
    if(empty($serversToQuery)){
        $genieErr="GenieACS belum dikonfigurasi.";
    }else{
        foreach($serversToQuery as $gs){
            $g = GenieACS::fromDB($gs['id']);
            if($g){
                $all=$search?$g->searchDevices($search):$g->getDevices('{}');
                if(is_array($all)){
                    $genieOk=true;
                    $groupDevs=[];
                    foreach($all as $d){
                        $inf=$g->getInfo($d);$wf=$g->getWifi($d);
                        $custName='-';
                        foreach($inf['tags'] as $t){
                            $cs=$db->prepare("SELECT full_name,customer_id FROM customers WHERE ont_tag=? LIMIT 1");
                            $cs->execute([$t]);$c=$cs->fetch();
                            if($c){$custName=h($c['full_name'].' ('.$c['customer_id'].')');break;}
                        }
                        $groupDevs[]=compact('d','inf','wf','custName') + ['genie_id' => $gs['id']];
                        $total++;
                        if($inf['online'])$online++;else$offline++;
                    }
                    $allDevicesGrouped[$gs['name']] = $groupDevs;
                }else{
                    $genieErrs[]=$gs['name'].': '.$g->error;
                }
            }
        }
        if(!empty($genieErrs)) $genieErr=implode(', ', $genieErrs);
    }
}

// Helper: TR-069 binding path labels sesuai dokumen
$lanPaths=[
    1=>'LAN 1 (LANEthernetInterfaceConfig.1)',
    2=>'LAN 2 (LANEthernetInterfaceConfig.2)',
    3=>'LAN 3 (LANEthernetInterfaceConfig.3)',
    4=>'LAN 4 (LANEthernetInterfaceConfig.4)',
];
$ssidPaths=[
    1=>'SSID 1 / 2.4G (WLANConfiguration.1)',
    2=>'SSID 2 / 2.4G (WLANConfiguration.2)',
    3=>'SSID 3 / 2.4G (WLANConfiguration.3)',
    4=>'SSID 4 / 2.4G (WLANConfiguration.4)',
    5=>'SSID 5 / 5G   (WLANConfiguration.5)',
    6=>'SSID 6 / 5G   (WLANConfiguration.6)',
    7=>'SSID 7 / 5G   (WLANConfiguration.7)',
    8=>'SSID 8 / 5G   (WLANConfiguration.8)',
];

startPage($sid?'Detail ONT':'Monitor ONT');
// Handle redirect dari temp_ont port forward
if(isset($_GET['temp_ok'])&&isset($_GET['url'])){
    $tempUrl=filter_var(urldecode($_GET['url']),FILTER_SANITIZE_URL);
}
?>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<?php if($sid&&$sdev): // ════════════════ DETAIL VIEW ════════════════ ?>

<?php if(isset($tempUrl)&&$tempUrl):?>
<script>
// Save active remote to sessionStorage (survives page refresh within session)
(function(){
    const url='<?=h($tempUrl)?>';
    // Extract WAN IP from POST data is not possible here, use URL port to identify
    // Store by URL as key since we don't have WAN IP in redirect
    const key='snet_active_remotes';
    try{
        const remotes=JSON.parse(sessionStorage.getItem(key)||'{}');
        // Update expiry if already exists, or set new
        Object.keys(remotes).forEach(wanIp=>{
            if(remotes[wanIp].url===url){
                remotes[wanIp].expireAt=Date.now()+10*60*1000;
            }
        });
        sessionStorage.setItem(key,JSON.stringify(remotes));
    }catch(e){}
})();
</script>
<?php endif;?>
<?php if(isset($tempUrl)&&$tempUrl):?>
<div class="alert aok" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
    <div>
        ✅ <strong>Akses Remote ONT aktif!</strong>
        &nbsp;—&nbsp; 🌐 <a href="<?=h($tempUrl)?>" target="_blank" style="font-weight:700;color:var(--blue-d)"><?=h($tempUrl)?></a>
    </div>
    <div id="remoteCountdown" style="font-weight:700;font-size:.85rem;color:var(--green-d)">
        ⏱ <span id="cdTimer">10:00</span> lagi
    </div>
</div>
<script>
// Start countdown from 10 minutes
(function(){
    let secs=600;
    const el=document.getElementById('cdTimer');
    const tick=()=>{
        if(!el)return;
        if(secs<=0){el.closest('.alert').remove();return;}
        const m=Math.floor(secs/60),s=secs%60;
        el.textContent=m+':'+(s<10?'0':'')+s;
        el.style.color=secs<120?'var(--red)':'var(--green-d)';
        secs--;
    };
    tick();setInterval(tick,1000);
})();
</script>
<?php endif;?>
<div class="ph">
    <div>
        <a href="/admin/ont.php" style="color:var(--g400);font-size:.8rem;text-decoration:none;display:inline-flex;align-items:center;gap:4px;margin-bottom:4px">← Semua ONT</a>
        <div class="ph-t">📶 <?=h($sinf['brand'].' '.$sinf['model'])?></div>
        <div class="ph-s">
            Serial: <strong><?=h($sinf['serial'])?></strong> &bull;
            <span style="color:<?=$sinf['online']?'#16A34A':'var(--red)'?>;font-weight:700"><?=$sinf['online']?'● Online':'● Offline'?></span>
            &bull; <?=h($sinf['last_seen'])?>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">

        <?php if($sid): ?>
        <form method="POST" style="display:inline"><input type="hidden" name="action" value="refresh"><input type="hidden" name="device_id" value="<?=h($sid)?>"><button type="submit" class="btn bo bsm">⟳ Refresh</button></form>
        <form method="POST" style="display:inline" onsubmit="return confirm('Reboot ONT sekarang?')"><input type="hidden" name="action" value="reboot"><input type="hidden" name="device_id" value="<?=h($sid)?>"><button type="submit" class="btn bw bsm">🔄 Reboot</button></form>
        <?php if(!empty($savedCfgs)):?>
        <form method="POST" style="display:inline" onsubmit="return confirm('Push semua konfigurasi tersimpan ke ONT ini?')"><input type="hidden" name="action" value="push_all_saved"><input type="hidden" name="device_id" value="<?=h($sid)?>"><button type="submit" class="btn bpu bsm">⚡ Push Semua Config</button></form>
        <?php endif;?>
        <?php endif;?>
    </div>
</div>

<!-- Info + Current WiFi -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
<div class="card" style="margin:0">
    <div class="ch">
        <div class="ct">ℹ️ Info Perangkat</div>
        <span id="optLiveIco" style="font-size:.7rem;color:var(--g400);display:flex;align-items:center;gap:4px">
            <span id="optLiveDot" style="width:6px;height:6px;border-radius:50%;background:#94A3B8;display:inline-block"></span>
            <span id="optLiveTxt">memuat...</span>
        </span>
    </div>
    <div class="cb" style="padding:12px 16px">
    <?php
    // Bangun baris info (tanpa Redaman — akan ditambah manual di bawah loop)
    $infoRows=[
        'Brand/Model' => h($sinf['brand'].' '.$sinf['model']),
        'Serial'      => '<span class="code">'.h($sinf['serial']).'</span>',
        'IP WAN'      => '<span class="ipm">'.h($sinf['ip_wan']).'</span>',
        'Firmware'    => h($sinf['sw_version']),
        'Uptime'      => h($sinf['uptime']),
        'Last Inform' => h($sinf['last_seen']),
        'Tags'        => implode(' ',array_map(function($t){return '<span class="bdg bpur">'.h($t).'</span>';},$sinf['tags'])),
    ];
    foreach($infoRows as $l=>$v):?>
    <div style="display:flex;gap:8px;padding:5px 0;border-bottom:1px solid var(--g100)">
        <div style="font-size:.67rem;font-weight:700;color:var(--g400);text-transform:uppercase;width:80px;flex-shrink:0;padding-top:1px"><?=$l?></div>
        <div style="font-size:.82rem;flex:1"><?=$v?></div>
    </div>
    <?php endforeach;?>

    <!-- Baris Redaman — live update via JS -->
    <div style="display:flex;gap:8px;padding:5px 0;border-bottom:1px solid var(--g100);align-items:center">
        <div style="font-size:.67rem;font-weight:700;color:var(--g400);text-transform:uppercase;width:80px;flex-shrink:0">Redaman</div>
        <div style="font-size:.82rem;flex:1;display:flex;align-items:center;gap:8px">
            <?php
            // Tampilkan nilai awal dari server-side
            $rxInit=$soptical['rx_power'];
            $rxStInit=$soptical['rx_status'];
            $rxColorInit=$rxStInit==='good'?'#16A34A':($rxStInit==='warning'?'#D97706':($rxStInit==='unknown'?'#94A3B8':'#DC2626'));
            $rxBgInit=$rxStInit==='good'?'rgba(22,163,74,.12)':($rxStInit==='warning'?'rgba(217,119,6,.12)':($rxStInit==='unknown'?'rgba(148,163,184,.12)':'rgba(220,38,38,.12)'));
            $rxIconInit=$rxStInit==='good'?'✅':($rxStInit==='warning'?'⚠️':($rxStInit==='unknown'?'📡':'🔴'));
            ?>
            <span id="rxPowerBadge" style="
                display:inline-flex;align-items:center;gap:5px;
                padding:3px 10px;border-radius:20px;font-weight:700;font-size:.8rem;
                background:<?=$rxBgInit?>;color:<?=$rxColorInit?>;
                transition:all .4s ease"
            >
                <span id="rxPowerIcon"><?=$rxIconInit?></span>
                <span id="rxPowerVal"><?=$rxInit!==null?number_format($rxInit,2).' dBm':'N/A'?></span>
            </span>
            <?php if($rxInit!==null):?>
            <span id="rxPowerLabel" style="font-size:.7rem;color:<?=$rxColorInit?>;font-weight:600;transition:color .4s">
                <?=$rxStInit==='good'?'Normal':($rxStInit==='warning'?'Lemah':'Kritis')?>
            </span>
            <?php else:?>
            <span id="rxPowerLabel" style="font-size:.7rem;color:#94A3B8">Belum ada data</span>
            <?php endif;?>
        </div>
    </div>

    </div>
</div>

<div class="card" style="margin:0">
    <div class="ch"><div class="ct">✏️ Edit WiFi (2.4G + 5G)</div></div>
    <div class="cb">
        <form method="POST">
            <input type="hidden" name="action" value="set_wifi">
            <input type="hidden" name="device_id" value="<?=h($sid)?>">
            <!-- SSID 2.4G -->
            <div class="fg"><label class="fl">📡 SSID 2.4 GHz</label>
                <input type="text" name="ssid_24" class="fc" value="<?=h($swifi['ssid24']??'')?>" placeholder="Nama WiFi 2.4G" maxlength="32"></div>
            <!-- SSID 5G -->
            <div class="fg"><label class="fl">📡 SSID 5 GHz</label>
                <input type="text" name="ssid_5g" class="fc" value="<?=h($swifi['ssid5g']??'')?>" placeholder="Nama WiFi 5G" maxlength="32"></div>
            <!-- Password TUNGGAL untuk kedua band -->
            <div class="fg">
                <label class="fl">🔑 Password WiFi <span style="font-weight:400;color:var(--g400)">(berlaku 2.4G + 5G)</span></label>
                <input type="text" name="key_24" id="wfPass" class="fc" value="<?=h($swifi['key24']??'')?>" placeholder="Min 8 karakter" maxlength="63">
                <div class="fhint">Password sama akan dikirim ke 2.4 GHz dan 5 GHz sekaligus</div>
            </div>
            <input type="hidden" name="same_pass" value="1">
            <button type="submit" class="btn bp" style="width:100%">📡 Kirim WiFi ke ONT</button>
        </form>
    </div>
</div>
</div>

<!-- TABS -->
<div class="tabnav">
    <button class="tab on" data-tab="wan" onclick="swTab('wan','d')">🌐 WAN (<?=count($swan)?>)</button>
    <button class="tab" data-tab="addwan" onclick="swTab('addwan','d')">➕ Add/Edit WAN</button>
    <button class="tab" data-tab="binding" onclick="swTab('binding','d')">🔗 Binding LAN/WiFi</button>
    <button class="tab" data-tab="optical" onclick="swTab('optical','d')">
        📡 Sinyal Optik
        <?php
        $rxSt=$soptical['rx_status']??'unknown';
        $rxBadge=$rxSt==='good'?'bon':($rxSt==='warning'?'borg':'boff');
        if($soptical['rx_power']!==null):?>
        <span class="bdg <?=$rxBadge?>" style="font-size:.6rem;margin-left:3px"><?=number_format($soptical['rx_power'],1)?> dBm</span>
        <?php endif;?>
    </button>
    <button class="tab" data-tab="clients" onclick="swTab('clients','d')">📱 Clients (<?=count($sclients)?>)</button>
    <button class="tab" data-tab="saved" onclick="swTab('saved','d')">💾 Config Tersimpan (<?=count($savedCfgs)?>)</button>
</div>

<!-- TAB: Sinyal Optik -->
<div class="tp" id="tp-optical" data-grp="d">
<div class="card">
    <div class="ch">
        <div class="ct">📡 Cek Redaman / Sinyal Optik ONT</div>
        <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="refresh_optical">
            <input type="hidden" name="device_id" value="<?=h($sid)?>">
            <button type="submit" class="btn bo bsm">🔄 Refresh Optik</button>
        </form>
    </div>
    <?php
    $rx=$soptical['rx_power']??null;
    $tx=$soptical['tx_power']??null;
    $rxStat=$soptical['rx_status']??'unknown';
    $rxColor=$rxStat==='good'?'#16A34A':($rxStat==='warning'?'#D97706':'#DC2626');
    $rxLabel=$rxStat==='good'?'Normal ✓':($rxStat==='warning'?'Lemah ⚠️':'Kritis ❌');
    // Gauge: RX range -5 (max) sampai -35 (min), tampilkan sebagai persentase
    $rxPct=($rx!==null)?max(0,min(100,round(($rx+35)/30*100))):0;
    ?>

    <?php if(!$soptical['has_data']??true):?>
    <div class="alert ainf" style="margin:16px">
        ℹ️ Data sinyal optik belum tersedia di cache GenieACS. Klik <strong>🔄 Refresh Optik</strong> untuk meminta ONT melaporkan data optik, lalu tunggu beberapa detik dan buka halaman ini kembali.
        <div style="margin-top:8px;font-size:.78rem;color:var(--g500)">
            📌 Pastikan ONT dalam keadaan <strong>Online</strong> agar perintah dapat diterima.
        </div>
    </div>
    <?php else:?>

    <div class="cb" style="padding:16px">
        <!-- RX Power Gauge -->
        <div style="background:linear-gradient(135deg,#0F172A,#1E293B);border-radius:14px;padding:20px;margin-bottom:16px;color:#fff">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
                <div>
                    <div style="font-size:.7rem;font-weight:700;color:#94A3B8;text-transform:uppercase;letter-spacing:.05em">RX Power (Redaman Optik)</div>
                    <div style="font-size:2.2rem;font-weight:800;color:<?=$rxColor?>;line-height:1.1;margin-top:4px">
                        <?=$rx!==null?number_format($rx,2).' <span style="font-size:1rem;font-weight:500">dBm</span>':'N/A'?>
                    </div>
                    <div style="margin-top:6px">
                        <span style="display:inline-block;padding:3px 10px;border-radius:20px;font-size:.72rem;font-weight:700;
                            background:<?=$rxStat==='good'?'rgba(22,163,74,.2)':($rxStat==='warning'?'rgba(217,119,6,.2)':'rgba(220,38,38,.2)')?>;color:<?=$rxColor?>">
                            <?=$rxLabel?>
                        </span>
                    </div>
                </div>
                <div style="font-size:3rem;opacity:.6">💡</div>
            </div>
            <!-- Progress bar -->
            <div style="background:rgba(255,255,255,.1);border-radius:8px;height:12px;overflow:hidden;position:relative">
                <div style="height:100%;width:<?=$rxPct?>%;border-radius:8px;transition:width .6s;
                    background:linear-gradient(90deg,<?=$rxColor?>,<?=$rxStat==='good'?'#4ADE80':($rxStat==='warning'?'#FCD34D':'#F87171')?>)"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:.65rem;color:#64748B;margin-top:4px">
                <span>-35 dBm (Kritis)</span>
                <span>-27 dBm (Batas Normal)</span>
                <span>-5 dBm (Maks)</span>
            </div>
        </div>

        <!-- Detail tabel -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px">

            <?php $optItems=[
                ['icon'=>'📡','label'=>'TX Power (Daya Kirim)','val'=>$tx!==null?number_format($tx,2).' dBm':'—','sub'=>'Normal: 2 s/d 7 dBm','color'=>'#3B82F6'],
                ['icon'=>'⚡','label'=>'Tegangan (Voltage)','val'=>$soptical['voltage']!==null?number_format($soptical['voltage'],3).' V':'—','sub'=>'Normal: 3.1 s/d 3.5 V','color'=>'#8B5CF6'],
                ['icon'=>'🌡️','label'=>'Suhu (Temperature)','val'=>$soptical['temp']!==null?number_format($soptical['temp'],1).' °C':'—','sub'=>'Normal: 0 s/d 70 °C','color'=>'#F97316'],
                ['icon'=>'🔋','label'=>'Bias Current','val'=>$soptical['bias']!==null?number_format($soptical['bias'],2).' mA':'—','sub'=>'Normal: 5 s/d 60 mA','color'=>'#10B981'],
            ];
            foreach($optItems as $oi):?>
            <div style="background:var(--g50);border:1px solid var(--g200);border-radius:12px;padding:14px;border-left:4px solid <?=$oi['color']?>">
                <div style="font-size:1.3rem;margin-bottom:6px"><?=$oi['icon']?></div>
                <div style="font-size:.67rem;font-weight:700;color:var(--g400);text-transform:uppercase;margin-bottom:4px"><?=$oi['label']?></div>
                <div style="font-size:1.1rem;font-weight:700;color:var(--g800)"><?=$oi['val']?></div>
                <div style="font-size:.68rem;color:var(--g400);margin-top:3px"><?=$oi['sub']?></div>
            </div>
            <?php endforeach;?>
        </div>

        <!-- Panduan referensi -->
        <div style="margin-top:14px;background:#FFF7ED;border:1px solid #FCD34D;border-radius:10px;padding:12px;font-size:.78rem">
            <div style="font-weight:700;color:#92400E;margin-bottom:6px">📋 Referensi Level Sinyal GPON</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px">
                <div style="background:#fff;border-radius:7px;padding:8px;border-left:3px solid #16A34A">
                    <div style="font-size:.68rem;font-weight:700;color:#16A34A">✓ Normal</div>
                    <div style="font-size:.72rem;color:#374151">RX: -8 s/d -27 dBm<br>Sinyal bagus, tidak ada masalah</div>
                </div>
                <div style="background:#fff;border-radius:7px;padding:8px;border-left:3px solid #D97706">
                    <div style="font-size:.68rem;font-weight:700;color:#D97706">⚠️ Lemah</div>
                    <div style="font-size:.72rem;color:#374151">RX: -27 s/d -30 dBm<br>Perlu cek konektor/splitter</div>
                </div>
                <div style="background:#fff;border-radius:7px;padding:8px;border-left:3px solid #DC2626">
                    <div style="font-size:.68rem;font-weight:700;color:#DC2626">❌ Kritis</div>
                    <div style="font-size:.72rem;color:#374151">RX: &lt; -30 dBm<br>Cek kabel fiber, bend, atau kotor</div>
                </div>
            </div>
        </div>

        <!-- Raw data (debug) -->
        <?php if($soptical['rx_raw']!==null||$soptical['tx_raw']!==null||$soptical['vp_rx']!==null):?>
        <details style="margin-top:10px">
            <summary style="font-size:.74rem;color:var(--g400);cursor:pointer">🔬 Raw Data (debug)</summary>
            <div style="font-family:'JetBrains Mono',monospace;font-size:.7rem;color:var(--g600);background:var(--g50);border-radius:7px;padding:10px;margin-top:6px;line-height:1.9">
                VP RXPower: <strong><?=h($soptical['vp_rx']??'—')?></strong> <span style="color:var(--g400)">(VirtualParameters.RXPower)</span><br>
                RX Raw: <strong><?=h($soptical['rx_raw']??'—')?></strong><br>
                TX Raw: <strong><?=h($soptical['tx_raw']??'—')?></strong><br>
                Brand: <strong><?=h($soptical['brand']??'—')?></strong>
            </div>
        </details>

        <?php endif;?>
    </div>
    <?php endif;?>
</div>
</div>

<!-- TAB: WAN List -->
<div class="tp on" id="tp-wan" data-grp="d">
<div class="card">
    <div class="ch"><div class="ct">🌐 WAN Connections</div><a href="?id=<?=urlencode($sid)?>" class="btn bo bsm">🔄 Refresh</a></div>
    <?php if(empty($swan)):?>
    <div class="empty"><div class="eico">🌐</div>Tidak ada data WAN — klik Refresh untuk memuat</div>
    <?php else:?>
    <div class="tw"><table class="dt">
        <thead><tr><th>Label</th><th>Nama</th><th>Conn Type</th><th>Status</th><th>IP WAN</th><th>Gateway</th><th>VLAN</th><th>NAT</th></tr></thead>
        <tbody>
        <?php foreach($swan as $w):?>
        <tr>
            <td><span class="bdg <?=$w['type']==='ppp'?'bpur':'bblue'?>"><?=h($w['label'])?></span></td>
            <td style="font-size:.82rem"><strong><?=h($w['name'])?></strong></td>
            <td><code style="font-size:.74rem;background:var(--g100);padding:1px 5px;border-radius:3px"><?=h($w['conn_type'])?></code></td>
            <td><span class="bdg <?=str_contains($w['status'],'Connect')?'bon':'boff'?>"><?=h($w['status'])?></span></td>
            <td class="ipm" style="font-size:.76rem"><?=h($w['ip'])?></td>
            <td class="ipm" style="font-size:.76rem"><?=h($w['gw'])?></td>
            <td><?=$w['vlan']!=='-'?'<span class="bdg borg">VLAN '.h($w['vlan']).'</span>':'-'?></td>
            <td><?=$w['nat']==='true'||$w['nat']==='1'?'<span class="bdg bon">ON</span>':'<span class="bdg bgray">OFF</span>'?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php endif;?>
</div>
</div>

<!-- TAB: Add/Edit WAN — SESUAI DOKUMEN -->
<div class="tp" id="tp-addwan" data-grp="d">
<div class="card">
    <div class="ch"><div class="ct">➕ Add WAN (addObject) / Edit WAN</div></div>
    <div class="cb">
        <!-- INFO BOX sesuai dokumen -->
        <div style="background:var(--g50);border:1px solid var(--g200);border-radius:10px;padding:14px;margin-bottom:16px;font-size:.8rem;color:var(--g700)">
            <div style="font-weight:700;color:var(--blue-d);margin-bottom:8px">📋 Panduan Add WAN (sesuai dokumen TR-069 ONT)</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
                <div style="background:#fff;border-radius:7px;padding:8px 10px;border-left:3px solid var(--orange)">
                    <div style="font-size:.68rem;font-weight:700;color:var(--orange);margin-bottom:3px">ADD = addObject dulu</div>
                    <div style="font-size:.73rem">Path: <strong>WANConnectionDevice.N.WANPPPConnection</strong><br>Lalu set parameter pada <strong>.1</strong></div>
                </div>
                <div style="background:#fff;border-radius:7px;padding:8px 10px;border-left:3px solid var(--blue)">
                    <div style="font-size:.68rem;font-weight:700;color:var(--blue-d);margin-bottom:3px">VLAN wajib disertakan</div>
                    <div style="font-size:.73rem">Centang VLAN dan isi VLAN ID saat Add WAN baru<br>Contoh: VLAN ID <strong>100</strong></div>
                </div>
                <div style="background:#fff;border-radius:7px;padding:8px 10px;border-left:3px solid var(--green)">
                    <div style="font-size:.68rem;font-weight:700;color:var(--green-d);margin-bottom:3px">Contoh parameter</div>
                    <div style="font-family:'JetBrains Mono',monospace;font-size:.66rem">WANDevice.1.<br>WANConnectionDevice.<strong>2</strong>.<br>WANPPPConnection</div>
                </div>
            </div>
        </div>

        <!-- Toggle Add vs Edit -->
        <div style="display:flex;gap:10px;margin-bottom:14px">
            <label style="flex:1;cursor:pointer">
                <input type="radio" name="wan_op_mode" id="opModeAdd" value="add" checked
                       onchange="updateWanOpMode(this.value)"
                       style="accent-color:var(--purple)">
                <span style="font-weight:700;color:var(--purple)">➕ Add WAN Baru</span>
                <div style="font-size:.72rem;color:var(--g400);margin-top:2px">Buat instance baru via addObject</div>
            </label>
            <label style="flex:1;cursor:pointer">
                <input type="radio" name="wan_op_mode" id="opModeEdit" value="edit"
                       onchange="updateWanOpMode(this.value)"
                       style="accent-color:var(--blue)">
                <span style="font-weight:700;color:var(--blue-d)">✏️ Edit WAN yang Ada</span>
                <div style="font-size:.72rem;color:var(--g400);margin-top:2px">Update parameter instance existing</div>
            </label>
        </div>

        <!-- FORM ADD WAN -->
        <form method="POST" id="wanAddForm">
            <input type="hidden" name="action" value="add_wan" id="wanFormAction">
            <input type="hidden" name="device_id" value="<?=h($sid)?>">

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px">
                <!-- WANConnectionDevice index (untuk Add WAN) -->
                <div class="fg" style="margin:0" id="wanCdField">
                    <label class="fl">WANConnectionDevice (N)</label>
                    <select name="wan_cd" class="fsel" id="wanCdSel" onchange="updateWanPath()">
                        <?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>"<?=$i===2?' selected':''?>><?=$i?></option><?php endfor;?>
                    </select>
                    <div class="fhint">Contoh: <code>WANConnectionDevice.<strong>2</strong>.WANPPPConnection</code></div>
                </div>
                <!-- Slot untuk Edit WAN -->
                <div class="fg" style="margin:0;display:none" id="wanSlotField">
                    <label class="fl">Slot WAN (Edit)</label>
                    <select name="wan_slot" class="fsel">
                        <?php for($i=1;$i<=5;$i++):?><option value="<?=$i?>"><?=$i?></option><?php endfor;?>
                    </select>
                    <div class="fhint">WANIPConnection.N atau WANPPPConnection.N</div>
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl">Mode Koneksi</label>
                    <select name="conn_mode" class="fsel" id="connMode" onchange="onModeChange(this)">
                        <option value="bridge_ppp" selected>WAN Bridge PPP (PPPoE_Bridged)</option>
                        <option value="bridge_ip">WAN Bridge IP (IP_Bridged)</option>
                        <option value="route">WAN Route / PPPoE (IP_Routed)</option>
                    </select>
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl">Service List</label>
                    <select name="service_list" class="fsel">
                        <option value="INTERNET">INTERNET</option>
                        <option value="Other">Other</option>
                        <option value="INTERNET_TR069">INTERNET_TR069</option>
                        <option value="VOIP">VOIP</option>
                        <option value="IPTV">IPTV</option>
                    </select>
                    <div class="fhint">Route=INTERNET, Bridge=INTERNET/Other</div>
                </div>
            </div>

            <div class="fg">
                <label class="fl">Nama WAN <span style="font-weight:400;color:var(--g400)">(opsional)</span></label>
                <input type="text" name="wan_name" class="fc" placeholder="Contoh: 2_INTERNET_R_VID_100">
            </div>

            <!-- VLAN Section — WAJIB untuk Add WAN -->
            <div style="background:#F0F4FF;border-radius:9px;padding:12px;margin-bottom:12px;border:2px solid var(--purple)">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                    <input type="checkbox" name="vlan_enable" id="vlanEn" value="1" checked style="width:15px;height:15px;accent-color:var(--purple)"
                           onchange="document.getElementById('vlanFields').style.display=this.checked?'grid':'none'">
                    <label for="vlanEn" style="font-weight:700;color:var(--purple);cursor:pointer;font-size:.85rem">🏷️ Aktifkan VLAN <span style="font-weight:400;font-size:.78rem;color:var(--g500)">(disarankan untuk Add WAN)</span></label>
                </div>
                <div id="vlanFields" style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div class="fg" style="margin:0">
                        <label class="fl">VLAN ID <span style="font-weight:400">(1-4094)</span></label>
                        <input type="number" name="vlan_id" class="fc" placeholder="100" min="1" max="4094" value="">
                    </div>
                    <div class="fg" style="margin:0">
                        <label class="fl">VLAN Priority <span style="font-weight:400">(0-7)</span></label>
                        <input type="number" name="vlan_priority" class="fc" placeholder="0" min="0" max="7" value="0">
                    </div>
                </div>
            </div>

            <!-- Route: Addressing -->
            <div id="routeFields" style="display:none;background:var(--g50);border-radius:9px;padding:12px;margin-bottom:12px;border-left:3px solid var(--green)">
                <div style="font-weight:700;color:var(--green-d);font-size:.8rem;margin-bottom:8px">🌐 WAN Route — Tipe Addressing</div>
                <div class="fg">
                    <label class="fl">Addressing Type</label>
                    <select name="addr_type" class="fsel" onchange="document.getElementById('staticFields').style.display=this.value==='static'?'grid':'none'">
                        <option value="dhcp">DHCP Otomatis</option>
                        <option value="static">Static / Manual</option>
                    </select>
                </div>
                <div id="staticFields" style="display:none;grid-template-columns:1fr 1fr;gap:10px">
                    <div class="fg" style="margin:0"><label class="fl">IP WAN</label><input type="text" name="static_ip" class="fc" placeholder="203.0.113.10"></div>
                    <div class="fg" style="margin:0"><label class="fl">Subnet Mask</label><input type="text" name="static_mask" class="fc" placeholder="255.255.255.252"></div>
                    <div class="fg" style="margin:0"><label class="fl">Gateway</label><input type="text" name="static_gw" class="fc" placeholder="203.0.113.1"></div>
                    <div class="fg" style="margin:0"><label class="fl">DNS 1</label><input type="text" name="dns1" class="fc" placeholder="8.8.8.8"></div>
                </div>
            </div>

            <!-- PPPoE Credentials: tampil untuk mode route (IP_Routed) dan bridge_ppp (PPPoE_Bridged) -->
            <div id="pppoeFields" style="display:none;background:#FFF7ED;border-radius:9px;padding:12px;margin-bottom:12px;border-left:3px solid var(--orange);border:1px solid #FCD9A0">
                <div style="font-weight:700;color:var(--orange);font-size:.8rem;margin-bottom:4px">🔑 PPPoE Credentials</div>
                <div id="pppoeHintRoute" style="display:none;font-size:.72rem;color:#92400E;margin-bottom:8px;background:#FEF3C7;padding:5px 8px;border-radius:6px">📌 Mode <strong>IP_Routed</strong>: ONT dial PPPoE sendiri ke ISP. Isi username &amp; password dari ISP.</div>
                <div id="pppoeHintBridge" style="display:none;font-size:.72rem;color:#7C3AED;margin-bottom:8px;background:#F5F3FF;padding:5px 8px;border-radius:6px">📌 Mode <strong>PPPoE_Bridged</strong>: PPPoE pass-through ke router. Credentials opsional.</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
                    <div class="fg" style="margin:0"><label class="fl">Username PPPoE</label><input type="text" name="pppoe_user" class="fc" placeholder="user@isp.net.id" autocomplete="off"></div>
                    <div class="fg" style="margin:0"><label class="fl">Password PPPoE</label><input type="text" name="pppoe_pass" class="fc" placeholder="password" autocomplete="off"></div>
                </div>
            </div>

            <!-- Path preview -->
            <div id="wanPathPreview" style="background:#0F172A;border-radius:9px;padding:10px 14px;margin-bottom:12px;font-family:'JetBrains Mono',monospace;font-size:.75rem;color:#94A3B8">
                <span style="color:#64748B;font-size:.65rem">addObject path:</span><br>
                <span id="wanPathTxt" style="color:#38BDF8">InternetGatewayDevice.WANDevice.1.WANConnectionDevice.2.WANPPPConnection</span>
            </div>

            <div class="alert ainf" style="margin-bottom:12px">
                ℹ️ <strong>Add WAN</strong>: kirim <code>addObject</code> ke parent path lalu <code>setParameterValues</code> ke instance baru.<br>
                <strong>Edit WAN</strong>: kirim <code>setParameterValues</code> langsung ke slot existing.
            </div>
            <button type="submit" class="btn bp" id="wanSubmitBtn" style="width:100%">➕ Add WAN ke ONT</button>
        </form>
    </div>
</div>
</div>

<!-- TAB: Binding LAN/WiFi — SESUAI DOKUMEN -->
<div class="tp" id="tp-binding" data-grp="d">
<div class="card">
    <div class="ch"><div class="ct">🔗 Binding WAN → LAN / WiFi</div></div>
    <div class="cb">
        <!-- Panduan dari dokumen -->
        <div style="background:var(--g50);border-radius:10px;padding:12px;margin-bottom:14px;font-size:.78rem;border:1px solid var(--g200)">
            <div style="font-weight:700;color:var(--blue-d);margin-bottom:6px">📋 Binding sesuai dokumen TR-069:</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:.68rem;color:var(--g600);line-height:1.9">
                LAN 1: <strong>InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1</strong><br>
                SSID1: <strong>InternetGatewayDevice.LANDevice.1.WLANConfiguration.1</strong> (2.4G)<br>
                SSID5: <strong>InternetGatewayDevice.LANDevice.1.WLANConfiguration.5</strong> (5G)<br>
                <span style="color:var(--orange)">ZTE F670L: tambahkan juga WAN Interface di kolom khusus</span>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="bind_wan">
            <input type="hidden" name="device_id" value="<?=h($sid)?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px">
                <div class="fg" style="margin:0">
                    <label class="fl">WAN Slot</label>
                    <select name="wan_slot" class="fsel">
                        <?php for($i=1;$i<=4;$i++):?><option value="<?=$i?>">Slot <?=$i?></option><?php endfor;?>
                    </select>
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl">Tipe WAN Slot</label>
                    <select name="wan_type" class="fsel">
                        <option value="ip">WAN IP (WANIPConnection.N)</option>
                        <option value="ppp">WAN PPP (WANPPPConnection.N)</option>
                    </select>
                </div>
            </div>

            <!-- Pilihan LAN Interface -->
            <div style="background:var(--g50);border-radius:9px;padding:12px;margin-bottom:10px;border:1px solid var(--g200)">
                <div style="font-weight:700;font-size:.78rem;color:var(--g700);margin-bottom:8px">🔌 Bind ke LAN Port (LANEthernetInterfaceConfig)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <?php foreach($lanPaths as $idx=>$lbl):?>
                    <label style="display:flex;align-items:center;gap:7px;padding:6px 8px;background:#fff;border-radius:7px;cursor:pointer;border:1px solid var(--g200);font-size:.8rem">
                        <input type="checkbox" name="bind_lan[]" value="<?=$idx?>" style="accent-color:var(--blue)">
                        <?=h($lbl)?>
                    </label>
                    <?php endforeach;?>
                </div>
            </div>

            <!-- Pilihan SSID Interface -->
            <div style="background:var(--g50);border-radius:9px;padding:12px;margin-bottom:10px;border:1px solid var(--g200)">
                <div style="font-weight:700;font-size:.78rem;color:var(--g700);margin-bottom:8px">📡 Bind ke SSID WiFi (WLANConfiguration)</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                    <?php foreach($ssidPaths as $idx=>$lbl):?>
                    <label style="display:flex;align-items:center;gap:7px;padding:6px 8px;background:#fff;border-radius:7px;cursor:pointer;border:1px solid var(--g200);font-size:.8rem">
                        <input type="checkbox" name="bind_ssid[]" value="<?=$idx?>" style="accent-color:var(--purple)">
                        <?=h($lbl)?>
                    </label>
                    <?php endforeach;?>
                </div>
            </div>

            <!-- Manual input (override) -->
            <div class="fg">
                <label class="fl">Input Manual <span style="font-weight:400;color:var(--g400)">(opsional, tambahkan atau override)</span></label>
                <input type="text" name="bind_manual" class="fc" placeholder="InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig.1,InternetGatewayDevice.LANDevice.1.WLANConfiguration.1">
                <div class="fhint">Pisahkan dengan koma (,) untuk lebih dari satu interface</div>
            </div>

            <!-- ZTE F670L WAN Interface -->
            <div style="background:#FEF3C7;border-radius:9px;padding:10px 12px;margin-bottom:12px;border:1px solid #FCD34D">
                <div style="font-weight:700;font-size:.78rem;color:#92400E;margin-bottom:6px">⚡ Khusus ZTE F670L — Wan Interface (opsional)</div>
                <input type="text" name="wan_interface" class="fc" placeholder="InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1">
                <div class="fhint" style="color:#92400E">Isi WAN interface path jika menggunakan ZTE F670L</div>
            </div>

            <div class="alert ainf" style="margin-bottom:12px">
                ℹ️ Centang port LAN dan SSID yang akan di-bind ke WAN ini. Setelah di-bind, device di port tersebut akan menggunakan WAN yang dipilih.
            </div>
            <button type="submit" class="btn bp" style="width:100%">🔗 Kirim Binding ke ONT</button>
        </form>
    </div>
</div>
</div>

<!-- TAB: Clients -->
<div class="tp" id="tp-clients" data-grp="d">
<div class="card">
    <div class="ch"><div class="ct">📱 Perangkat WiFi Terhubung (<?=count($sclients)?>)</div></div>
    <?php if(empty($sclients)):?>
    <div class="empty"><div class="eico">📱</div>Tidak ada client terhubung saat ini</div>
    <?php else:?>
    <div class="tw"><table class="dt">
        <thead><tr><th>MAC Address</th><th>IP</th><th>Hostname</th><th>Band</th><th>RSSI</th></tr></thead>
        <tbody>
        <?php foreach($sclients as $cl):?>
        <tr>
            <td><span class="code"><?=h($cl['mac'])?></span></td>
            <td class="ipm"><?=h($cl['ip'])?></td>
            <td style="font-size:.8rem"><?=h($cl['hostname'])?></td>
            <td><span class="bdg <?=$cl['band']==='5G'?'bpur':'bblue'?>"><?=h($cl['band'])?></span></td>
            <td style="font-size:.78rem"><?=h($cl['rssi'])?> dBm</td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php endif;?>
</div>
</div>

<!-- TAB: Config Tersimpan -->
<div class="tp" id="tp-saved" data-grp="d">
<div class="card">
    <div class="ch">
        <div class="ct">💾 Konfigurasi Tersimpan (<?=count($savedCfgs)?>)</div>
        <?php if(!empty($savedCfgs)):?>
        <form method="POST" onsubmit="return confirm('Push semua config ke ONT?')">
            <input type="hidden" name="action" value="push_all_saved">
            <input type="hidden" name="device_id" value="<?=h($sid)?>">
            <button type="submit" class="btn bpu bsm">⚡ Push Semua</button>
        </form>
        <?php endif;?>
    </div>
    <?php if(empty($savedCfgs)):?>
    <div class="empty"><div class="eico">💾</div>Belum ada konfigurasi tersimpan.<br>Lakukan konfigurasi WiFi/WAN/Binding terlebih dahulu — akan tersimpan otomatis.</div>
    <?php else:?>
    <div class="alert ainf" style="margin:12px 16px 0">ℹ️ Konfigurasi ini tersimpan otomatis setiap kali Anda set WiFi/WAN/Binding. Gunakan tombol ⚡ Push untuk mengirim ulang ke ONT setelah ONT reset.</div>
    <div class="tw"><table class="dt">
        <thead><tr><th>Tipe</th><th>Nama</th><th>Push Terakhir</th><th>Status</th><th>Push Count</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach($savedCfgs as $cfg):
            $data=json_decode($cfg['config_data'],true)??[];
        ?>
        <tr>
            <td><span class="bdg <?=$cfg['config_type']==='wifi'?'bblue':($cfg['config_type']==='wan'?'bon':'bpur')?>"><?=strtoupper($cfg['config_type'])?></span></td>
            <td>
                <strong style="font-size:.84rem"><?=h($cfg['config_name'])?></strong>
                <?php if($cfg['config_type']==='wifi'):?>
                <div style="font-size:.7rem;color:var(--g400)">SSID: <?=h($data['ssid_24']??'-')?> / <?=h($data['ssid_5g']??'-')?></div>
                <?php elseif($cfg['config_type']==='wan'):?>
                <div style="font-size:.7rem;color:var(--g400)">Mode: <?=h($data['conn_mode']??'-')?>, Slot: <?=h($data['wan_slot']??'-')?><?=$data['vlan_enable']?' VLAN'.$data['vlan_id']??'':''?></div>
                <?php elseif($cfg['config_type']==='binding'):?>
                <div style="font-size:.7rem;color:var(--g400);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h(substr($data['bind_str']??'-',0,60))?>...</div>
                <?php endif;?>
            </td>
            <td style="font-size:.76rem;color:var(--g400);white-space:nowrap"><?=date('d/m/y H:i',strtotime($cfg['last_pushed']))?></td>
            <td><span class="bdg <?=$cfg['push_status']==='success'?'bon':($cfg['push_status']==='failed'?'boff':'bgray')?>"><?=h($cfg['push_status'])?></span></td>
            <td style="text-align:center"><?=$cfg['push_count']?> kali</td>
            <td style="white-space:nowrap">
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="push_saved">
                    <input type="hidden" name="device_id" value="<?=h($sid)?>">
                    <input type="hidden" name="cfg_id" value="<?=$cfg['id']?>">
                    <button type="submit" class="btn bpu bxs">⚡ Push</button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus config &quot;<?=h(addslashes($cfg['config_name']))?>"&quot; permanen?')">
                    <input type="hidden" name="action" value="delete_saved">
                    <input type="hidden" name="device_id" value="<?=h($sid)?>">
                    <input type="hidden" name="cfg_id" value="<?=$cfg['id']?>">
                    <button type="submit" class="btn bd bxs">🗑</button>
                </form>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php endif;?>
</div>
</div>

<script>
function onModeChange(sel){
    const v=sel.value;
    // routeFields: hanya muncul untuk mode route (IP_Routed) — tipe addressing
    document.getElementById('routeFields').style.display=v==='route'?'block':'none';
    // pppoeFields: muncul untuk route (PPPoE IP_Routed) DAN bridge_ppp (PPPoE_Bridged)
    const needPppoe = (v==='route'||v==='bridge_ppp');
    document.getElementById('pppoeFields').style.display=needPppoe?'block':'none';
    // Hint berbeda per mode
    const hintR=document.getElementById('pppoeHintRoute');
    const hintB=document.getElementById('pppoeHintBridge');
    if(hintR) hintR.style.display = v==='route'?'block':'none';
    if(hintB) hintB.style.display = v==='bridge_ppp'?'block':'none';
    updateWanPath();
}

function updateWanPath(){
    const cdSel  = document.getElementById('wanCdSel');
    const modeSel= document.getElementById('connMode');
    const pathEl = document.getElementById('wanPathTxt');
    const preview= document.getElementById('wanPathPreview');
    if(!cdSel||!modeSel||!pathEl) return;
    const cd  = cdSel.value||'2';
    const mode= modeSel.value;
    // route & bridge_ppp = WANPPPConnection, bridge_ip = WANIPConnection
    const connType = (mode==='bridge_ppp'||mode==='route')?'WANPPPConnection':'WANIPConnection';
    const isAdd = document.getElementById('opModeAdd')?.checked;
    pathEl.textContent = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.'+cd+'.'+connType;
    if(preview){
        const lbl = preview.querySelector('span:first-child');
        if(lbl) lbl.textContent = isAdd?'addObject path:':'setParameterValues path (.1):';
    }
}

function updateWanOpMode(mode){
    const isAdd = mode==='add';
    // Toggle field CD vs Slot
    document.getElementById('wanCdField').style.display = isAdd?'':'none';
    document.getElementById('wanSlotField').style.display = isAdd?'none':'';
    // Toggle action
    document.getElementById('wanFormAction').value = isAdd?'add_wan':'set_wan';
    // Toggle tombol
    const btn=document.getElementById('wanSubmitBtn');
    if(btn) btn.textContent = isAdd?'\u2795 Add WAN ke ONT':'\u270f\ufe0f Update WAN ke ONT';
    // Toggle path label
    updateWanPath();
}

// ── Live Optical Polling ────────────────────────────────────────────────
(function(){
    const devId = '<?=addslashes($sid)?>';
    if(!devId) return;

    const badge  = document.getElementById('rxPowerBadge');
    const icon   = document.getElementById('rxPowerIcon');
    const val    = document.getElementById('rxPowerVal');
    const label  = document.getElementById('rxPowerLabel');
    const dot    = document.getElementById('optLiveDot');
    const txt    = document.getElementById('optLiveTxt');

    // Warna per status
    const colors = {
        good:    {color:'#16A34A', bg:'rgba(22,163,74,.12)',  icon:'✅', label:'Normal'},
        warning: {color:'#D97706', bg:'rgba(217,119,6,.12)', icon:'⚠️', label:'Lemah'},
        critical:{color:'#DC2626', bg:'rgba(220,38,38,.12)', icon:'🔴', label:'Kritis'},
        unknown: {color:'#94A3B8', bg:'rgba(148,163,184,.12)',icon:'📡', label:'—'},
    };

    // Animasi titik "live"
    let blinkOn=true;
    setInterval(()=>{
        if(dot){ dot.style.opacity = blinkOn?'1':'0.3'; blinkOn=!blinkOn; }
    },800);

    function applyOptical(data){
        const st  = data.rx_status||'unknown';
        const cfg = colors[st]||colors.unknown;
        const rx  = data.rx_power;
        const valStr = rx!==null && rx!==undefined ? rx.toFixed(2)+' dBm' : 'N/A';

        if(badge){
            badge.style.background = cfg.bg;
            badge.style.color      = cfg.color;
        }
        if(icon)  icon.textContent  = cfg.icon;
        if(val)   val.textContent   = valStr;
        if(label){
            label.style.color     = cfg.color;
            label.textContent     = cfg.label;
        }
        // Update tab sinyal juga jika ada
        const optTabBadge = document.querySelector('[data-tab="optical"] .bdg');
        if(optTabBadge && rx!==null){
            optTabBadge.textContent = rx.toFixed(1)+' dBm';
            optTabBadge.className = 'bdg '+(st==='good'?'bon':st==='warning'?'borg':'boff');
        }
    }

    function fetchOptical(){
        const url = '/admin/ont.php?ajax=optical&id='+encodeURIComponent(devId);
        if(dot) dot.style.background='#F59E0B'; // kuning saat fetching
        if(txt) txt.textContent='memperbarui...';

        fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}})
            .then(r=>r.json())
            .then(data=>{
                if(data.ok){
                    applyOptical(data);
                    if(dot) dot.style.background = data.rx_status==='good'?'#22C55E':data.rx_status==='warning'?'#F59E0B':'#EF4444';
                    const now = new Date();
                    const hm  = now.getHours().toString().padStart(2,'0')+':'+now.getMinutes().toString().padStart(2,'0');
                    if(txt) txt.textContent='update '+hm;
                } else {
                    if(dot) dot.style.background='#94A3B8';
                    if(txt) txt.textContent='gagal';
                }
            })
            .catch(()=>{
                if(dot) dot.style.background='#94A3B8';
                if(txt) txt.textContent='error';
            });
    }

    // Langsung fetch saat halaman dimuat (setelah 1.5 detik)
    setTimeout(fetchOptical, 1500);
    // Lalu tiap 30 detik
    setInterval(fetchOptical, 30000);
})();
</script>

<?php else: // ════════════════ LIST VIEW ════════════════ ?>

<div class="ph">
    <div><div class="ph-t">📶 Monitor ONT</div><div class="ph-s">Semua perangkat ONT yang terhubung ke GenieACS</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/admin/genie.php" class="btn bo bsm">⚙️ GenieACS</a>
        <a href="/admin/ont.php" class="btn bo bsm">🔄 Refresh</a>
    </div>
</div>

<div class="stats" style="grid-template-columns:repeat(3,1fr)">
    <div class="stat" style="--c:var(--blue)"><div class="stat-l">Total ONT</div><div class="stat-n"><?=$total?></div></div>
    <div class="stat" style="--c:#22C55E"><div class="stat-l">Online</div><div class="stat-n" style="color:#22C55E"><?=$online?></div></div>
    <div class="stat" style="--c:var(--red)"><div class="stat-l">Offline</div><div class="stat-n" style="color:var(--red)"><?=$offline?></div></div>
</div>

<!-- Search -->
<div class="card"><div class="cb" style="padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap">
        <div style="flex:1;min-width:180px"><label class="fl">🔍 Cari Serial / SSID / Tag</label><input type="text" name="q" class="fc" placeholder="Cari ONT..." value="<?=h($search)?>"></div>
        <button type="submit" class="btn bp">Cari</button>
        <?php if($search):?><a href="/admin/ont.php" class="btn bo">Reset</a><?php endif;?>
    </form>
</div></div>

<?php if(!$genieOk):?>
<div class="alert a<?=$genieErr?'err':'wrn'?>"><?=$genieErr?"❌ Error GenieACS: ".h($genieErr):"⚠️ GenieACS belum dikonfigurasi."?> <a href="/admin/genie.php">→ Setup</a></div>
<?php elseif(empty($allDevicesGrouped)):?>
<div class="empty"><div class="eico">📡</div>Tidak ada ONT ditemukan<?=$search?' untuk pencarian "'.h($search).'"':''?></div>
<?php else:?>

<?php foreach($allDevicesGrouped as $acsName => $devices): ?>
<div style="margin-bottom:24px;">
    <h3 style="margin-bottom:12px;font-size:1.1rem;font-weight:800;color:var(--g800);border-bottom:2px solid var(--blue);display:inline-block;padding-bottom:4px;">
        📡 Server: <?=h($acsName)?> <span class="bdg bblue" style="font-size:0.75rem;vertical-align:middle;margin-left:8px;"><?=count($devices)?> ONT</span>
    </h3>
    
    <?php if(empty($devices)): ?>
    <div class="empty" style="padding:16px;">Tidak ada ONT di server ini.</div>
    <?php else: ?>
    <div class="og">
    <?php foreach($devices as $item):
        $inf=$item['inf'];$wf=$item['wf'];$cn=$item['custName'];
        $br=$inf['brand'];
        $ic=$br==='FiberHome'?'🟠':($br==='CData'?'🔵':'⚪');
        $cls=$br==='FiberHome'?'bfh':($br==='CData'?'bcd':'bgen');
    ?>
    <div class="oc">
        <div class="och <?=$inf['online']?'on':'off'?>">
            <div class="oa <?=$cls?>"><?=$ic?></div>
            <div style="flex:1;min-width:0">
                <div style="font-weight:700;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?php $tag1=$inf['tags'][0]??'';echo $tag1?h($tag1):h($inf['serial']);?>
                </div>
                <div style="font-size:.7rem;color:var(--g400)"><?=$cn?:h($inf['serial'])?></div>
            </div>
            <div style="text-align:right;flex-shrink:0">
                <div class="dot <?=$inf['online']?'don':'doff'?>" style="display:block;margin:0 0 3px auto"></div>
                <span style="font-size:.65rem;font-weight:700;color:<?=$inf['online']?'#16A34A':'var(--red)'?>"><?=$inf['online']?'Online':'Offline'?></span>
            </div>
        </div>
        <div style="padding:6px 12px;font-size:.72rem;color:var(--g500);border-bottom:1px solid var(--g100);display:flex;justify-content:space-between;flex-wrap:wrap;gap:3px">
            <span>🏭 <?=h($br.' '.$inf['model'])?></span>
            <span class="code" style="font-size:.68rem"><?=h($inf['serial'])?></span>
            <span>🕐 <?=h($inf['last_seen'])?></span>
        </div>
        <?php if(!empty($inf['tags'])):?>
        <div style="padding:5px 12px;border-bottom:1px solid var(--g100);display:flex;gap:4px;flex-wrap:wrap">
            <?php foreach($inf['tags'] as $t):?><span class="bdg bpur" style="font-size:.65rem"><?=h($t)?></span><?php endforeach;?>
        </div>
        <?php endif;?>
        <?php if($wf['ssid24']||$wf['ssid5g']):?>
        <div style="padding:4px 12px;font-size:.72rem;color:var(--g500);border-bottom:1px solid var(--g100)">
            📶 <?=h($wf['ssid24']??'-')?><?php if($wf['ssid5g']):?> &bull; <?=h($wf['ssid5g'])?><?php endif;?>
        </div>
        <?php endif;?>
        <div style="padding:8px 12px;display:flex;gap:6px">
            <a href="/admin/ont.php?id=<?=urlencode($inf['id'])?>&genie_id=<?=$item['genie_id']?>" class="btn bo bsm">🔍 Detail & Config</a>
            <?php $ontWanIp=($inf['ip_wan']??'');$hasWan=$ontWanIp&&$ontWanIp!=='-'&&$ontWanIp!=='0.0.0.0';?>
            <button onclick="remoteONT('<?=h($ontWanIp)?>')"
                class="btn bsm remote-ont-btn"
                data-wan="<?=h($ontWanIp)?>"
                data-tooltip="<?=$hasWan?'WAN: '.h($ontWanIp):'Isi WAN IP manual'?>"
                style="background:<?=$hasWan?'#F97316':'#9CA3AF'?>;color:#fff;min-width:70px;transition:.3s">
                📡 Remote
            </button>
        </div>
    </div>
    <?php endforeach;?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php endif; // end list/detail ?>

<?php endif; // end list/detail ?>

<div class="mo" id="moRemote">
    <div class="md" style="max-width:460px">
        <div class="mh">
            <div class="mt">📡 Remote ONT — 10 Menit</div>
            <button class="mx" onclick="cM('moRemote')">✕</button>
        </div>
        <div class="mb">
            <div style="background:#FFF7ED;border:1px solid #FDBA74;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:.8rem;color:#92400E">
                ⚡ Akses via <strong>domain/IP publik</strong> router → ONT WAN IP → port 80<br>
                ⏱ Port forward <strong>otomatis terhapus</strong> dari Mikrotik setelah 10 menit
            </div>
            <form method="POST" action="/admin/port_forward.php" id="remoteOntForm">
                <input type="hidden" name="action" value="temp_ont">
                <input type="hidden" name="ext_port" id="remoteExtPort" value="">
                <div class="fg">
                    <label class="fl">Router</label>
                    <select name="router_id" class="fsel" required onchange="updateRemoteInfo(this)">
                        <option value="">— Pilih Router —</option>
                        <?php foreach($db->query("SELECT id,name,ip_public,domain_public FROM routers WHERE is_active=1 ORDER BY is_main DESC,name") as $r):
                            $domOrIp=$r['domain_public']?:$r['ip_public'];
                        ?>
                        <option value="<?=$r['id']?>" data-dom="<?=h($domOrIp)?>" data-ip="<?=h($r['ip_public'])?>">
                            <?=h($r['name'])?> — <?=h($domOrIp)?>
                        </option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div class="fg">
                    <label class="fl">WAN IP ONT</label>
                    <input type="text" name="wan_ip" id="remoteWanIp" class="fc"
                        style="font-family:'JetBrains Mono',monospace" placeholder="mis: 10.10.10.1 (WAN IP ONT di sisi Mikrotik)">
                </div>
                <div class="fg">
                    <label class="fl">Akses URL <span style="font-size:.72rem;color:var(--g400)">(auto-generate)</span></label>
                    <div id="remoteUrlPreview" style="padding:8px 12px;background:var(--g50);border-radius:8px;font-family:'JetBrains Mono',monospace;font-size:.82rem;color:var(--blue-d);border:1px solid var(--g200)">
                        Pilih router dulu...
                    </div>
                </div>
                <button type="button" onclick="submitRemoteONT()" class="btn" style="background:#F97316;color:#fff;width:100%;padding:11px;font-size:.9rem;margin-top:4px;font-weight:700">
                    🔗 Buat Akses Remote (10 Menit)
                </button>
            </form>
        </div>
    </div>
</div>
<script>
// ════ REMOTE ONT SYSTEM ════════════════════════════════════
// Store active remote sessions in sessionStorage
// Format: { wanIp: { url, port, expireAt (timestamp ms) } }
const REMOTE_STORE_KEY = 'snet_active_remotes';
const REMOTE_PORT_MIN=5000, REMOTE_PORT_MAX=9000;

function genRandomPort(){
    return Math.floor(Math.random()*(REMOTE_PORT_MAX-REMOTE_PORT_MIN+1))+REMOTE_PORT_MIN;
}

function getActiveRemotes(){
    try{return JSON.parse(sessionStorage.getItem(REMOTE_STORE_KEY)||'{}');}catch{return {};}
}

function saveActiveRemote(wanIp, url, port){
    const remotes=getActiveRemotes();
    remotes[wanIp]={url, port, expireAt:Date.now()+10*60*1000}; // 10 menit
    sessionStorage.setItem(REMOTE_STORE_KEY, JSON.stringify(remotes));
}

function getActiveRemote(wanIp){
    const remotes=getActiveRemotes();
    const r=remotes[wanIp];
    if(!r)return null;
    if(Date.now()>r.expireAt){
        delete remotes[wanIp];
        sessionStorage.setItem(REMOTE_STORE_KEY, JSON.stringify(remotes));
        return null;
    }
    return r;
}

// Check all buttons on page for active remote sessions
function refreshRemoteButtons(){
    document.querySelectorAll('.remote-ont-btn').forEach(btn=>{
        const wanIp=btn.dataset.wan;
        if(!wanIp)return;
        const active=getActiveRemote(wanIp);
        if(active){
            const remain=Math.ceil((active.expireAt-Date.now())/1000);
            const mm=Math.floor(remain/60),ss=remain%60;
            btn.style.background='#16A34A';
            btn.innerHTML=`🟢 ${mm}:${ss.toString().padStart(2,'0')}`;
            btn.title=`Aktif: ${active.url} (${mm}m ${ss}s lagi)`;
        } else {
            btn.style.background='#F97316';
            btn.innerHTML='📡 Remote';
            btn.title=btn.dataset.tooltip||'Remote ONT';
        }
    });
}

// Run refresh every second
setInterval(refreshRemoteButtons, 1000);

function updateRemoteInfo(sel){
    const opt=sel.selectedOptions[0];
    if(!opt||!opt.value){
        document.getElementById('remoteUrlPreview').textContent='Pilih router dulu...';
        document.getElementById('remoteExtPort').value='';
        return;
    }
    const dom=opt.dataset.dom||opt.dataset.ip;
    const port=document.getElementById('remoteExtPort').value||genRandomPort();
    document.getElementById('remoteExtPort').value=port;
    document.getElementById('remoteUrlPreview').textContent=`http://${dom}:${port}`;
}

function remoteONT(wanIp){
    document.getElementById('remoteWanIp').value=wanIp;
    // Check if there's already an active session for this WAN IP
    const active=getActiveRemote(wanIp);
    if(active){
        const remain=Math.ceil((active.expireAt-Date.now())/1000);
        const mm=Math.floor(remain/60),ss=remain%60;
        if(confirm(`Remote ONT ini masih AKTIF!

🌐 URL: ${active.url}
⏱ Sisa waktu: ${mm} menit ${ss} detik

Buka URL sekarang?`)){
            window.open(active.url,'_blank');
        }
        return; // Don't open modal if already active
    }
    // Generate new random port
    const port=genRandomPort();
    document.getElementById('remoteExtPort').value=port;
    // Trigger update URL if router already selected
    const sel=document.querySelector('#remoteOntForm [name="router_id"]');
    if(sel&&sel.value)updateRemoteInfo(sel);
    oM('moRemote');
}

function submitRemoteONT(){
    const wanIp=document.getElementById('remoteWanIp').value;
    const port=document.getElementById('remoteExtPort').value||genRandomPort();
    document.getElementById('remoteExtPort').value=port;
    const routerSel=document.querySelector('#remoteOntForm [name="router_id"]');
    const opt=routerSel?.selectedOptions[0];
    if(!routerSel?.value){alert('Pilih router terlebih dahulu!');return;}
    const dom=opt?.dataset?.dom||'?';
    const url=`http://${dom}:${port}`;
    if(confirm(`Buat akses remote ONT?

🌐 URL: ${url}
📡 WAN ONT: ${wanIp}
⏱ Otomatis terhapus dalam 10 menit.`)){
        // Save to sessionStorage BEFORE submit (so we can show it immediately after)
        saveActiveRemote(wanIp, url, port);
        document.getElementById('remoteOntForm').submit();
    }
}
</script>
<?php endPage();?>
