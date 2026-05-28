<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';

function pushNat(array $router,array $row):array{
    $api=MikrotikAPI::fromRouter($router);
    if(!$api->connect())return['ok'=>false,'err'=>$api->error,'id'=>null];
    // Gunakan wan_interface (in-interface) jika ada, supaya tidak konflik dengan dst-address program utama
    $iface=$router['wan_interface']??'';
    $r=$api->addDstNat($row['ip_public'],(int)$row['public_port'],$row['ip_lokal'],(int)$row['port_lokal'],$row['protocol'],$row['comment']??'',$row['rule_name']??'',$iface);
    $api->close();return['ok'=>(bool)$r,'err'=>'','id'=>($r&&$r!=='ok'?$r:null)];
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='add'){
        $rid=(int)($_POST['router_id']??0);$rname=trim($_POST['rule_name']??'');
        $ilok=trim($_POST['ip_lokal']??'');$plok=(int)($_POST['port_lokal']??0);
        $proto=$_POST['protocol']??'tcp';$cmt=trim($_POST['comment']??'');
        $dom=trim($_POST['domain_public']??'');$rand=isset($_POST['use_random']);$ppub=(int)($_POST['public_port']??0);
        if(!$rid||!$ilok||!$plok){$msg='Router, IP Lokal, Port Lokal wajib!';$mtype='err';}
        else{
            $router=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1");$router->execute([$rid]);$router=$router->fetch();
            if(!$router){$msg='Router tidak ditemukan!';$mtype='err';}
            else{
                if($rand||!$ppub){$used=array_column($db->query("SELECT public_port FROM port_forwardings WHERE router_id=$rid")->fetchAll(),'public_port');$ppub=generateRandomPort($used);}
                $ck=$db->prepare("SELECT id FROM port_forwardings WHERE router_id=? AND public_port=?");$ck->execute([$rid,$ppub]);
                if($ck->fetch()){$msg="Port $ppub sudah dipakai!";$mtype='err';}
                else{
                    $row=['ip_public'=>$router['ip_public'],'public_port'=>$ppub,'ip_lokal'=>$ilok,'port_lokal'=>$plok,'protocol'=>$proto,'comment'=>$cmt,'rule_name'=>$rname];
                    $push=pushNat($router,$row);
                    $db->prepare("INSERT INTO port_forwardings(router_id,rule_name,ip_public,domain_public,public_port,ip_lokal,port_lokal,protocol,comment,mikrotik_id,status,created_by)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$rid,$rname,$router['ip_public'],$dom,$ppub,$ilok,$plok,$proto,$cmt,$push['id'],$push['ok']?'active':'error',adminUser()['id']]);
                    auditLog('add_fwd',"$rid:$ppub");
                    $msg=$push['ok']?"✅ Forwarding berhasil! Port publik: <strong>$ppub</strong>":"⚠️ Tersimpan tapi gagal push ke Mikrotik: {$push['err']} (Port: $ppub)";$mtype=$push['ok']?'ok':'wrn';
                }
            }
        }
    }
    if($act==='delete'){
        // Hanya hapus dari database — TIDAK hapus dari Mikrotik
        $id=(int)($_POST['id']??0);
        $db->prepare("DELETE FROM port_forwardings WHERE id=?")->execute([$id]);
        auditLog('delete_fwd_db',"ID:$id");
        $msg='✅ Rule dihapus dari database (Mikrotik tidak terpengaruh).';$mtype='ok';
    }
    if($act==='delete_mikrotik'){
        // Hapus dari Mikrotik saja, DB tidak dihapus (set mikrotik_id=NULL)
        $id=(int)($_POST['id']??0);
        $row=$db->prepare("SELECT f.*,r.host,r.port rp,r.username ru,r.password rpw FROM port_forwardings f JOIN routers r ON f.router_id=r.id WHERE f.id=?");
        $row->execute([$id]);$row=$row->fetch();
        if($row&&$row['mikrotik_id']){
            $api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);
            if($api->connect()){$ok=$api->removeDstNat($row['mikrotik_id']);$api->close();
                if($ok){$db->prepare("UPDATE port_forwardings SET mikrotik_id=NULL,status='error',updated_at=NOW() WHERE id=?")->execute([$id]);$msg='✅ Rule dihapus dari Mikrotik.';$mtype='ok';}
                else{$msg='❌ Gagal hapus dari Mikrotik: '.$api->error;$mtype='err';}
            }else{$msg='❌ Gagal konek: '.$api->error;$mtype='err';}
        }else{$msg='Tidak ada mikrotik_id.';$mtype='wrn';}
    }
    if($act==='delete_both'){
        // Hapus dari DB dan Mikrotik sekaligus
        $id=(int)($_POST['id']??0);
        $row=$db->prepare("SELECT f.*,r.host,r.port rp,r.username ru,r.password rpw FROM port_forwardings f JOIN routers r ON f.router_id=r.id WHERE f.id=?");
        $row->execute([$id]);$row=$row->fetch();
        if($row){if($row['mikrotik_id']){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);if($api->connect()){$api->removeDstNat($row['mikrotik_id']);$api->close();}}
            $db->prepare("DELETE FROM port_forwardings WHERE id=?")->execute([$id]);
            auditLog('delete_fwd_both',"ID:$id");
            $msg='✅ Rule dihapus dari DB dan Mikrotik.';$mtype='ok';
        }
    }
    // Import rule live dari Mikrotik ke database
    if($act==='import_live'){
        $rid=(int)($_POST['router_id']??0);$mkid=trim($_POST['mikrotik_id']??'');
        $port=(int)($_POST['dst_port']??0);$toIp=trim($_POST['to_addresses']??'');
        $toPort=(int)($_POST['to_ports']??0);$proto=trim($_POST['protocol']??'tcp');
        $cmt=trim($_POST['comment']??'');$inIface=trim($_POST['in_interface']??'');
        $dstAddr=trim($_POST['dst_address']??'');
        $router=$db->prepare("SELECT * FROM routers WHERE id=?");$router->execute([$rid]);$router=$router->fetch();
        if($router&&$mkid&&$port&&$toIp&&$toPort){
            $ck=$db->prepare("SELECT id FROM port_forwardings WHERE router_id=? AND public_port=?");$ck->execute([$rid,$port]);
            if($ck->fetch()){$msg="⚠️ Port $port sudah ada di database!";$mtype='wrn';}
            else{
                $ipPub=$dstAddr?:$router['ip_public'];
                $db->prepare("INSERT INTO port_forwardings(router_id,rule_name,ip_public,public_port,ip_lokal,port_lokal,protocol,comment,mikrotik_id,status,created_by)VALUES(?,?,?,?,?,?,?,?,?,'active',?)")
                   ->execute([$rid,'[LIVE] '.($cmt?:"Port $port"),$ipPub,$port,$toIp,$toPort,$proto,$cmt,$mkid,adminUser()['id']]);
                auditLog('import_live_fwd',"$rid:$port:$mkid");
                $msg="✅ Rule port <strong>$port</strong> berhasil diimport ke database!";$mtype='ok';
            }
        }else{$msg='Data tidak lengkap untuk import.';$mtype='err';}
    }
    if($act==='repush'){
        $id=(int)($_POST['id']??0);$row=$db->prepare("SELECT f.*,r.host,r.port rp,r.username ru,r.password rpw,r.wan_interface FROM port_forwardings f JOIN routers r ON f.router_id=r.id WHERE f.id=?");$row->execute([$id]);$row=$row->fetch();
        if($row){$router=['host'=>$row['host'],'port'=>$row['rp'],'username'=>$row['ru'],'password'=>$row['rpw'],'wan_interface'=>$row['wan_interface']??''];
            if($row['mikrotik_id']){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);if($api->connect()){@$api->removeDstNat($row['mikrotik_id']);$api->close();}}
            $push=pushNat($router,$row);
            $db->prepare("UPDATE port_forwardings SET mikrotik_id=?,status=?,updated_at=NOW() WHERE id=?")->execute([$push['id'],$push['ok']?'active':'error',$id]);
            $msg=$push['ok']?'✅ Re-push berhasil!':'❌ Re-push gagal: '.$push['err'];$mtype=$push['ok']?'ok':'err';
        }
    }
    if($act==='repush_all'){
        $rows=$db->query("SELECT f.*,r.host,r.port rp,r.username ru,r.password rpw,r.wan_interface FROM port_forwardings f JOIN routers r ON f.router_id=r.id WHERE f.status='error' OR f.mikrotik_id IS NULL")->fetchAll();
        $ok=0;$fail=0;
        foreach($rows as $row){$router=['host'=>$row['host'],'port'=>$row['rp'],'username'=>$row['ru'],'password'=>$row['rpw'],'wan_interface'=>$row['wan_interface']??''];$push=pushNat($router,$row);$push['ok']?$ok++:$fail++;$db->prepare("UPDATE port_forwardings SET mikrotik_id=?,status=?,updated_at=NOW() WHERE id=?")->execute([$push['id'],$push['ok']?'active':'error',$row['id']]);}
        $msg="Re-push: <strong>$ok berhasil</strong>".($fail?" | $fail gagal":"");$mtype=$fail?'wrn':'ok';
    }
    if($act==='toggle'){$id=(int)($_POST['id']??0);$cur=$db->prepare("SELECT status FROM port_forwardings WHERE id=?");$cur->execute([$id]);$cur=$cur->fetchColumn();$new=$cur==='active'?'inactive':'active';$db->prepare("UPDATE port_forwardings SET status=?,updated_at=NOW() WHERE id=?")->execute([$new,$id]);$msg="Status → $new";$mtype='ok';}
    
    // ── Temporary Port Forward for ONT Remote (auto-delete 10 menit) ──
    if($act==='temp_ont'){
        $rid=(int)($_POST['router_id']??0);
        $wanIp=trim($_POST['wan_ip']??'');    // WAN IP ONT
        $extPort=(int)($_POST['ext_port']??8080); // Port eksternal
        $proto=$_POST['protocol']??'tcp';
        if(!$rid||!$wanIp){$msg='Router dan WAN IP ONT wajib!';$mtype='err';}
        else{
            $router=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1");$router->execute([$rid]);$router=$router->fetch();
            if(!$router){$msg='Router tidak ditemukan!';$mtype='err';}
            else{
                $api=MikrotikAPI::fromRouter($router);
                if(!$api->connect()){$msg='❌ Konek gagal: '.$api->error;$mtype='err';}
                else{
                    // Generate random port jika kosong
                    if(!$extPort){$used=[];for($i=0;$i<5;$i++){$p=rand(5000,9000);if(!in_array($p,$used)){$extPort=$p;break;}}}
                    $ruleName='TEMP-ONT-'.date('His');
                    $comment='TEMP-ONT-'.date('His');
                    // Gunakan wan_interface (in-interface) jika ada di router
                    $ifaceOnt=$router['wan_interface']??'';
                    $mikrotikId=$api->addDstNat($router['ip_public'],$extPort,$wanIp,80,$proto,$comment,$ruleName,$ifaceOnt);
                    
                    if($mikrotikId){
                        // Scheduler auto-delete menggunakan .id NAT rule langsung (LEBIH RELIABLE!)
                        // Tidak perlu quote comment - gunakan ID yang pasti unique
                        $schedName='del-'.$ruleName;
                        $runAt=time()+600; // +10 menit
                        // Format ROS date: lowercase e.g. "apr/06/2026"
                        $rosDate=strtolower(date('M/d/Y', $runAt));
                        $rosTime=date('H:i:s', $runAt);
                        // Script menggunakan .id langsung - tidak ada masalah quote!
                        $natId=$mikrotikId; // e.g. "*1E"
                        $onEvent='/ip firewall nat remove '.$natId.'; /system scheduler remove [find where name='.$schedName.']';
                        $api->talk(['/system/scheduler/add',
                            '=name='.$schedName,
                            '=on-event='.$onEvent,
                            '=start-date='.$rosDate,
                            '=start-time='.$rosTime,
                            '=interval=00:00:00',
                            '=comment=TEMP-NAT-10MIN',
                        ]);
                        $api->close();
                        $accessUrl='http://'.$router['ip_public'].':'.$extPort;
                        // Gunakan domain_public jika ada, fallback ke IP
                        $domOrIp=$router['domain_public']?:$router['ip_public'];
                        $accessUrl='http://'.$domOrIp.':'.$extPort;
                        auditLog('temp_port_forward',"ONT $wanIp → $extPort (10 min) via $domOrIp");
                        // Redirect ke halaman asal (ont.php) dengan sukses
                        $ref=$_SERVER['HTTP_REFERER']??'/admin/ont.php';
                        // Bersihkan referer dari temp params sebelumnya
                        $ref=preg_replace('/[&?]temp_ok=.*$/','',$ref);
                        $sep=str_contains($ref,'?')?'&':'?';
                        header("Location: {$ref}{$sep}temp_ok=1&url=".urlencode($accessUrl));exit;
                    }else{
                        $api->close();$msg='❌ Gagal buat rule: '.$api->error;$mtype='err';
                    }
                }
            }
        }
    }
}

$routers=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY name")->fetchAll();
$qf=trim($_GET['q']??'');$rf=(int)($_GET['rid']??0);$sf=$_GET['st']??'';
$wh=['1=1'];$pr=[];
if($qf){$wh[]='(f.rule_name LIKE ? OR f.ip_lokal LIKE ? OR f.comment LIKE ? OR f.domain_public LIKE ?)';$s="%$qf%";$pr=array_merge($pr,[$s,$s,$s,$s]);}
if($rf){$wh[]='f.router_id=?';$pr[]=$rf;}if($sf){$wh[]='f.status=?';$pr[]=$sf;}
$st=$db->prepare("SELECT f.*,r.name rn,r.ip_public rip,r.domain_public rdomain,r.wan_interface rwif,r.host rhost,r.port rport,r.username ruser,r.password rpass,u.full_name un FROM port_forwardings f JOIN routers r ON f.router_id=r.id LEFT JOIN users u ON f.created_by=u.id WHERE ".implode(' AND ',$wh)." ORDER BY f.created_at DESC");
$st->execute($pr);$fwds=$st->fetchAll();
$errCnt=$db->query("SELECT COUNT(*) FROM port_forwardings WHERE status='error' OR mikrotik_id IS NULL")->fetchColumn();

// ── UNIFIED VIEW: baca live dari router filter dan bandingkan dengan DB ──
$syncRid=(int)($_GET['sync_rid']??$rf??0);
$liveRules=[];$liveErr='';$syncRouter=null;
if($syncRid){
    $syncRouter=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1");$syncRouter->execute([$syncRid]);$syncRouter=$syncRouter->fetch();
    if($syncRouter){$api=MikrotikAPI::fromRouter($syncRouter);if($api->connect()){$liveRules=$api->listDstNat();$api->close();}else{$liveErr=$api->error;}}
}
// Index live rules by mikrotik_id for quick lookup
$liveById=[];foreach($liveRules as $lr)$liveById[$lr['mikrotik_id']]=$lr;
// Index DB rules by mikrotik_id
$dbByMkid=[];foreach($fwds as $f){if($f['mikrotik_id'])$dbByMkid[$f['mikrotik_id']]=$f;}

startPage('Port Forwarding');
?>
<div class="ph">
    <div><div class="ph-t">🔀 Port Forwarding DST NAT</div><div class="ph-s">Kelola rule dstnat Mikrotik — database &amp; live Mikrotik dalam satu tampilan</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if($errCnt>0):?><form method="POST" style="display:inline" onsubmit="return cDel('Re-push semua rule error/missing?')"><?=csrfField()?><input type="hidden" name="action" value="repush_all"><button type="submit" class="btn bw">⚡ Re-push <?=$errCnt?> Error</button></form><?php endif;?>
        <button class="btn bp" onclick="oM('mAdd')">➕ Tambah Forwarding</button>
    </div>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>
<?php if($liveErr):?><div class="alert awrn">⚠️ Gagal baca live dari Mikrotik: <?=h($liveErr)?></div><?php endif;?>

<!-- FILTER -->
<div class="card"><div class="cb" style="padding:12px 16px">
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:160px"><label class="fl">🔍 Cari</label><input type="text" name="q" class="fc" placeholder="Nama, IP, domain, komentar..." value="<?=h($qf)?>"></div>
    <div style="min-width:180px"><label class="fl">Router <small style="font-weight:400;color:var(--g400)">(pilih untuk tampilkan live)</small></label>
    <select name="rid" class="fsel" onchange="this.form.submit()">
        <option value="">Semua (tanpa live)</option>
        <?php foreach($routers as $r):?><option value="<?=$r['id']?>" <?=$rf==$r['id']?'selected':''?>><?=h($r['name'])?> (<?=h($r['ip_public'])?>)</option><?php endforeach;?>
    </select></div>
    <?php if($syncRid):?>
    <a href="?rid=<?=$syncRid?>" class="btn bo">🔄 Refresh Live</a>
    <?php endif;?>
    <button type="submit" class="btn bp">Filter</button>
    <a href="/admin/port_forward.php" class="btn bo">Reset</a>
</form>
</div></div>

<?php
// ── Build unified list ──
// DB rules: sudah di $fwds
// Live rules tidak ada di DB → "Live Only"
$liveOnlyRules=[];
foreach($liveRules as $lr){
    if(!isset($dbByMkid[$lr['mikrotik_id']])) $liveOnlyRules[]=$lr;
}
$cntSync=0;$cntDbOnly=0;$cntLiveOnly=count($liveOnlyRules);
foreach($fwds as $f){ if($f['mikrotik_id']&&isset($liveById[$f['mikrotik_id']]))$cntSync++; else $cntDbOnly++; }
?>
<?php if($syncRid&&!$liveErr):?>
<div class="alert ainf" style="padding:8px 14px;font-size:.8rem">
    🔴 <strong>Live Sync aktif</strong> — Router: <strong><?=h($syncRouter['name'])?></strong> &nbsp;|&nbsp;
    <span style="color:#15803D">✅ <?=$cntSync?> Sinkron</span> &nbsp;
    <span style="color:var(--red)">❌ <?=$cntDbOnly?> DB Only</span> &nbsp;
    <span style="color:var(--orange)">⚠️ <?=$cntLiveOnly?> Live Only (belum di DB)</span>
</div>
<?php endif;?>

<!-- TABEL UNIFIED: DB Rules -->
<div class="card"><div class="ch">
    <div class="ct">📋 Rule Database (<?=count($fwds)?>)</div>
    <?php if($errCnt>0):?><span class="bdg boff">⚠ <?=$errCnt?> perlu re-push</span><?php endif;?>
</div>
<div class="tw"><table class="dt"><thead><tr>
    <th>#</th><th>Nama Rule</th><th>Router</th><th>Publik:Port</th><th>→ Lokal:Port</th>
    <th>Proto</th><th>Komentar</th><th>Sync Status</th><th>Aksi</th>
</tr></thead><tbody>
<?php if(empty($fwds)):?><tr><td colspan="9" class="empty">Belum ada rule — <button class="btn bp bsm" onclick="oM('mAdd')">Tambah</button></td></tr><?php endif;?>
<?php foreach($fwds as $i=>$f):
    $inLive=$f['mikrotik_id']&&isset($liveById[$f['mikrotik_id']]);
    $syncBadge=$syncRid
        ? ($inLive ? '<span class="bdg bon" style="font-size:.6rem">✅ Sinkron</span>'
                   : ($f['mikrotik_id'] ? '<span class="bdg boff" style="font-size:.6rem">❌ Tdk di MK</span>'
                                        : '<span class="bdg bgray" style="font-size:.6rem">⬜ Blm Push</span>'))
        : '';
?>
<tr style="<?=(!$inLive&&$syncRid&&$f['mikrotik_id'])?'background:#FFF5F5':''?>">
    <td style="color:var(--g400);font-size:.76rem"><?=$i+1?></td>
    <td><strong style="color:var(--blue-d);font-size:.82rem"><?=h($f['rule_name']?:'-')?></strong></td>
    <td style="font-size:.75rem"><?=h($f['rn'])?></td>
    <td>
        <span class="ipm"><?=h($f['ip_public'])?>:<strong style="color:var(--red)"><?=$f['public_port']?></strong></span>
        <button class="cbtn" onclick="cpTxt('<?=h($f['ip_public'].':'.$f['public_port'])?>',this)">📋</button>
    </td>
    <td><span class="ipm" style="color:#15803D"><?=h($f['ip_lokal'])?>:<strong><?=$f['port_lokal']?></strong></span></td>
    <td><span class="bdg <?=$f['protocol']==='tcp'?'bblue':($f['protocol']==='udp'?'borg':'bpur')?>"><?=strtoupper($f['protocol'])?></span></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem;color:var(--g500)" title="<?=h($f['comment']??'')?>"><?=h($f['comment']?:'-')?></td>
    <td>
        <?=$syncBadge?>
        <span class="bdg <?=$f['status']==='active'?'bon':($f['status']==='inactive'?'bgray':'boff')?>" style="font-size:.6rem"><?=$f['status']?></span>
    </td>
    <td><div style="display:flex;gap:3px;flex-wrap:wrap">
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$f['id']?>"><button type="submit" class="btn bo bxs" title="Toggle"><?=$f['status']==='active'?'⏸':'▶️'?></button></form>
        <button class="btn bo bxs" onclick='showDetail(<?=json_encode($f)?>)' title="Detail">🔍</button>
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="repush"><input type="hidden" name="id" value="<?=$f['id']?>"><button type="submit" class="btn bw bxs" title="Re-push ke Mikrotik">⚡</button></form>
        <!-- Hapus hanya dari DB -->
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus dari DATABASE saja? (Mikrotik tidak terpengaruh)')"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$f['id']?>"><button type="submit" class="btn bo bxs" title="Hapus dari DB saja">🗂️</button></form>
        <!-- Hapus dari keduanya -->
        <?php if($f['mikrotik_id']):?>
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus dari DB DAN Mikrotik?')"><?=csrfField()?><input type="hidden" name="action" value="delete_both"><input type="hidden" name="id" value="<?=$f['id']?>"><button type="submit" class="btn bd bxs" title="Hapus dari DB + Mikrotik">🗑️</button></form>
        <?php endif;?>
    </div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- TABEL LIVE ONLY: rule di Mikrotik tapi belum di DB -->
<?php if($syncRid&&!empty($liveOnlyRules)):?>
<div class="card" style="border-color:#FED7AA"><div class="ch" style="background:linear-gradient(to right,#FFF7ED,#fff)">
    <div class="ct" style="color:#D97706">⚠️ Live Only — Ada di Mikrotik, Belum di Database (<?=count($liveOnlyRules)?>)</div>
    <span class="bdg borg" style="font-size:.7rem">Klik Import untuk simpan ke DB</span>
</div>
<div class="tw"><table class="dt"><thead><tr>
    <th>MK ID</th><th>Proto</th><th>Match (in-interface / dst)</th><th>Dst Port</th><th>→ Lokal:Port</th><th>Komentar</th><th>Status</th><th>Aksi</th>
</tr></thead><tbody>
<?php foreach($liveOnlyRules as $lr):?>
<tr style="background:#FFFBEB;<?=$lr['disabled']?'opacity:.6':''?>">
    <td><span class="code" style="font-size:.64rem"><?=h($lr['mikrotik_id'])?></span></td>
    <td><span class="bdg bblue"><?=strtoupper(h($lr['protocol']))?></span></td>
    <td><?php if($lr['in_interface']):?><span class="bdg borg" style="font-size:.7rem">in: <?=h($lr['in_interface'])?></span><?php elseif($lr['dst_address']):?><span class="ipm" style="font-size:.75rem">dst: <?=h($lr['dst_address'])?></span><?php else:?><span style="color:var(--g300)">-</span><?php endif;?></td>
    <td><strong style="color:var(--red)"><?=h($lr['dst_port'])?></strong></td>
    <td><span class="ipm" style="color:#15803D"><?=h($lr['to_addresses'])?>:<strong><?=h($lr['to_ports'])?></strong></span></td>
    <td style="font-size:.75rem;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--g500)" title="<?=h($lr['comment'])?>"><?=h($lr['comment']?:'-')?></td>
    <td><span class="bdg <?=$lr['disabled']?'boff':'bon'?>"><?=$lr['disabled']?'Disabled':'Active'?></span></td>
    <td>
        <!-- Import ke DB -->
        <form method="POST" style="display:inline" onsubmit="return cDel('Import rule ini ke database?')"><?=csrfField()?>
            <input type="hidden" name="action" value="import_live">
            <input type="hidden" name="router_id" value="<?=$syncRid?>">
            <input type="hidden" name="mikrotik_id" value="<?=h($lr['mikrotik_id'])?>">
            <input type="hidden" name="dst_port" value="<?=h($lr['dst_port'])?>">
            <input type="hidden" name="to_addresses" value="<?=h($lr['to_addresses'])?>">
            <input type="hidden" name="to_ports" value="<?=h($lr['to_ports'])?>">
            <input type="hidden" name="protocol" value="<?=h($lr['protocol'])?>">
            <input type="hidden" name="comment" value="<?=h($lr['comment'])?>">
            <input type="hidden" name="in_interface" value="<?=h($lr['in_interface'])?>">
            <input type="hidden" name="dst_address" value="<?=h($lr['dst_address'])?>">
            <button type="submit" class="btn bs bsm">📥 Import ke DB</button>
        </form>
    </td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
<?php endif;?>

<!-- MODAL ADD -->
<div class="mo" id="mAdd"><div class="md md-lg">
<div class="mh"><div class="mt">➕ Tambah Port Forwarding</div><button class="mx" onclick="cM('mAdd')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="add">
<div class="mb">
<?php if(empty($routers)):?><div class="alert awrn">⚠️ Belum ada router! <a href="/admin/routers.php">Tambah router dulu</a>.</div><?php else:?>
<div class="fg"><label class="fl">Router *</label><select name="router_id" class="fsel" required id="rSel" onchange="onRouterSel(this)"><option value="">-- Pilih Router --</option><?php foreach($routers as $r):?><option value="<?=$r['id']?>" data-ip="<?=h($r['ip_public'])?>" data-domain="<?=h($r['domain_public'])?>"><?=h($r['name'])?> (<?=h($r['ip_public'])?>)</option><?php endforeach;?></select></div>
<div class="frow2">
    <div class="fg"><label class="fl">IP Publik (otomatis)</label><input type="text" id="showIp" class="fc" readonly placeholder="Pilih router..." style="background:var(--g100);color:var(--blue-d);font-family:'JetBrains Mono',monospace;font-weight:700"></div>
    <div class="fg"><label class="fl">Domain Publik <span style="font-weight:400;color:var(--g400)">(opsional)</span></label><input type="text" name="domain_public" id="showDomain" class="fc" placeholder="vpn.snet.co.id"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">IP Lokal *</label><input type="text" name="ip_lokal" class="fc" placeholder="192.168.1.100" required></div>
    <div class="fg"><label class="fl">Port Lokal *</label><input type="number" name="port_lokal" class="fc" placeholder="80" min="1" max="65535" required></div>
</div>
<div style="background:var(--g50);border-radius:9px;padding:12px;margin-bottom:12px;border:1px solid var(--g200)">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
        <input type="checkbox" name="use_random" id="useRand" checked style="width:15px;height:15px;accent-color:var(--blue)" onchange="document.getElementById('manPort').style.display=this.checked?'none':'block'">
        <label for="useRand" style="font-weight:700;cursor:pointer;font-size:.85rem">🎲 Generate Port Publik Otomatis (Random)</label>
    </div>
    <div id="manPort" style="display:none"><div class="fg" style="margin:0"><label class="fl">Port Publik Manual</label><input type="number" name="public_port" class="fc" placeholder="10000-65000" min="1024" max="65535"></div></div>
    <div style="font-size:.75rem;color:var(--g400)">Range: 10.000 – 65.000</div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Nama Rule</label><input type="text" name="rule_name" class="fc" placeholder="Web Server Budi"></div>
    <div class="fg"><label class="fl">Protokol</label><select name="protocol" class="fsel"><option value="tcp">TCP</option><option value="udp">UDP</option><option value="both">TCP + UDP</option></select></div>
</div>
<div class="fg"><label class="fl">Komentar / Deskripsi</label><textarea name="comment" class="fc" rows="2" placeholder="Misal: Web server pelanggan SNET-0001..."></textarea></div>
<?php endif;?>
</div>
<?php if(!empty($routers)):?><div class="mf"><button type="button" class="btn bo" onclick="cM('mAdd')">Batal</button><button type="submit" class="btn bp">🚀 Simpan &amp; Push ke Mikrotik</button></div><?php endif;?>
</form></div></div>

<!-- MODAL DETAIL -->
<div class="mo" id="mDetail"><div class="md md-lg"><div class="mh"><div class="mt">🔍 Detail &amp; Script</div><button class="mx" onclick="cM('mDetail')">✕</button></div><div class="mb" id="mDetailBody"></div></div></div>

<script>
function onRouterSel(sel){const o=sel.options[sel.selectedIndex];document.getElementById('showIp').value=o.dataset.ip||'';document.getElementById('showDomain').value=o.dataset.domain||'';}
function showDetail(f){
    const dom=f.domain_public||f.rdomain||'';
    const iface=f.rwif||f.wan_interface||'';
    const matchLine=iface?`  in-interface=${iface} \\`:`  dst-address=${f.ip_public} \\`;
    const script=`# Rule: ${f.rule_name||f.ip_lokal+':'+f.port_lokal}\n/ip firewall nat add \\\n  chain=dstnat action=dst-nat \\\n  protocol=${f.protocol==='both'?'tcp':f.protocol} \\\n${matchLine}\n  dst-port=${f.public_port} \\\n  to-addresses=${f.ip_lokal} \\\n  to-ports=${f.port_lokal} \\\n  comment="${(f.rule_name?'['+f.rule_name+'] ':'')+f.comment}"${f.protocol==='both'?'\n\n# UDP\n/ip firewall nat add \\\n  chain=dstnat action=dst-nat \\\n  protocol=udp \\\n'+matchLine+'\n  dst-port='+f.public_port+' \\\n  to-addresses='+f.ip_lokal+' \\\n  to-ports='+f.port_lokal:''}`;
    document.getElementById('mDetailBody').innerHTML=`
    <div style="display:grid;gap:12px">
        <div style="background:var(--g50);border-radius:10px;padding:14px">
            <div style="font-size:.68rem;font-weight:700;color:var(--g400);text-transform:uppercase;margin-bottom:10px">Rute Forwarding</div>
            <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <div style="background:#fff;border-radius:8px;padding:8px 12px;border:1px solid var(--g200)">
                    <div style="font-size:.6rem;color:var(--g400);font-weight:700;margin-bottom:3px">IP PUBLIK:PORT</div>
                    <span class="ipm">${esc(f.ip_public)}:<strong style="color:var(--red)">${f.public_port}</strong></span>
                    <button class="cbtn" onclick="cpTxt('${esc(f.ip_public+':'+f.public_port)}',this)">📋</button>
                </div>
                ${dom?`<div style="background:#fff;border-radius:8px;padding:8px 12px;border:1px solid #DDD6FE">
                    <div style="font-size:.6rem;color:var(--purple);font-weight:700;margin-bottom:3px">DOMAIN:PORT</div>
                    <span class="ipm" style="color:var(--purple)">${esc(dom)}:<strong style="color:var(--red)">${f.public_port}</strong></span>
                    <button class="cbtn" onclick="cpTxt('${esc(dom+':'+f.public_port)}',this)">📋</button>
                </div>`:''}
                <div style="font-size:1.3rem;color:var(--red)">→</div>
                <div style="background:#fff;border-radius:8px;padding:8px 12px;border:1px solid #BBF7D0">
                    <div style="font-size:.6rem;color:#15803D;font-weight:700;margin-bottom:3px">IP LOKAL:PORT</div>
                    <span class="ipm" style="color:#15803D">${esc(f.ip_lokal)}:<strong>${f.port_lokal}</strong></span>
                </div>
            </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between">
            <div style="font-size:.82rem;font-weight:700;color:var(--g700)">📜 Script Mikrotik</div>
            <button class="cbtn" style="padding:4px 12px" onclick="cpTxt(document.getElementById('scriptPre').textContent,this)">📋 Salin Script</button>
        </div>
        <div class="sbox" id="scriptPre">${esc(script)}</div>
    </div>`;
    oM('mDetail');
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;')}
function confirmTempOnt(){
    const wan=document.querySelector('[name="wan_ip"]').value;
    const port=document.querySelector('[name="ext_port"]').value;
    return confirm('Buat akses sementara ke ONT '+wan+' via port '+port+'?\nRule akan otomatis terhapus setelah 10 menit.');
}
</script>
<?php endPage();?>

