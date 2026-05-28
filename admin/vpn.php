<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='add'){
        $rid=(int)($_POST['router_id']??0);$un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
        $svc=$_POST['service']??'l2tp';$prof=trim($_POST['profile']??'default-encryption');
        $loc=trim($_POST['local_address']??'');$rem=trim($_POST['remote_address']??'');
        $ipsec=trim($_POST['ipsec_secret']??'');$cmt=trim($_POST['comment']??'');
        if(!$rid||!$un||!$pw){$msg='Router, Username, Password wajib!';$mtype='err';}
        else{
            $dup=$db->prepare("SELECT id FROM vpn_accounts WHERE router_id=? AND username=?");$dup->execute([$rid,$un]);
            if($dup->fetch()){$msg="Username '$un' sudah ada di router ini!";$mtype='err';}
            else{
                $router=$db->prepare("SELECT * FROM routers WHERE id=? AND is_active=1");$router->execute([$rid]);$router=$router->fetch();
                if(!$router){$msg='Router tidak ditemukan!';$mtype='err';}
                else{
                    $api=MikrotikAPI::fromRouter($router);$mid=null;$st='active';
                    if($api->connect()){$r=$api->addPppSecret($un,$pw,$svc,$loc,$rem,$prof,$cmt);$mid=$r&&$r!=='ok'?$r:null;if(!$r){$st='error';$msg='⚠️ Gagal push ke Mikrotik: '.$api->error;$mtype='wrn';}$api->close();}
                    else{$st='error';$msg='⚠️ Tidak bisa konek Mikrotik: '.$api->error;$mtype='wrn';}
                    $db->prepare("INSERT INTO vpn_accounts(router_id,username,password,service,profile,local_address,remote_address,ipsec_secret,comment,mikrotik_id,status,created_by)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([$rid,$un,$pw,$svc,$prof,$loc,$rem,$ipsec,$cmt,$mid,$st,adminUser()['id']]);
                    auditLog('add_vpn',$un);if(!$msg){$msg="✅ VPN user <strong>$un</strong> berhasil ditambahkan!";$mtype='ok';}
                }
            }
        }
    }
    if($act==='delete'){
        $id=(int)($_POST['id']??0);$row=$db->prepare("SELECT v.*,r.host,r.port rp,r.username ru,r.password rpw FROM vpn_accounts v JOIN routers r ON v.router_id=r.id WHERE v.id=?");$row->execute([$id]);$row=$row->fetch();
        if($row){if($row['mikrotik_id']){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);if($api->connect()){$api->removePppSecret($row['mikrotik_id']);$api->close();}}$db->prepare("DELETE FROM vpn_accounts WHERE id=?")->execute([$id]);$msg='VPN user dihapus.';$mtype='ok';}
    }
    if($act==='toggle'){
        $id=(int)($_POST['id']??0);$row=$db->prepare("SELECT v.*,r.host,r.port rp,r.username ru,r.password rpw FROM vpn_accounts v JOIN routers r ON v.router_id=r.id WHERE v.id=?");$row->execute([$id]);$row=$row->fetch();
        if($row){$nd=$row['is_disabled']?0:1;$ns=$nd?'disabled':'active';if($row['mikrotik_id']){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);if($api->connect()){$api->updatePppSecret($row['mikrotik_id'],null,null,(bool)$nd);$api->close();}}$db->prepare("UPDATE vpn_accounts SET is_disabled=?,status=?,updated_at=NOW() WHERE id=?")->execute([$nd,$ns,$id]);$msg="Status → $ns";$mtype='ok';}
    }
    if($act==='update'){
        $id=(int)($_POST['id']??0);$np=trim($_POST['new_password']??'');$nc=trim($_POST['comment']??'');
        if(!$np){$msg='Password tidak boleh kosong!';$mtype='err';}
        else{$row=$db->prepare("SELECT v.*,r.host,r.port rp,r.username ru,r.password rpw FROM vpn_accounts v JOIN routers r ON v.router_id=r.id WHERE v.id=?");$row->execute([$id]);$row=$row->fetch();
            if($row){if($row['mikrotik_id']){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);if($api->connect()){$api->updatePppSecret($row['mikrotik_id'],$np,$nc?:null);$api->close();}}$db->prepare("UPDATE vpn_accounts SET password=?,comment=?,updated_at=NOW() WHERE id=?")->execute([$np,$nc,$id]);$msg='✅ Password VPN diupdate!';$mtype='ok';}}
    }
    if($act==='repush'){
        $id=(int)($_POST['id']??0);$row=$db->prepare("SELECT v.*,r.host,r.port rp,r.username ru,r.password rpw FROM vpn_accounts v JOIN routers r ON v.router_id=r.id WHERE v.id=?");$row->execute([$id]);$row=$row->fetch();
        if($row){$api=new MikrotikAPI($row['host'],(int)$row['rp'],$row['ru'],$row['rpw']);
            if($api->connect()){if($row['mikrotik_id'])@$api->removePppSecret($row['mikrotik_id']);$r=$api->addPppSecret($row['username'],$row['password'],$row['service'],$row['local_address'],$row['remote_address'],$row['profile'],$row['comment']);$api->close();if($r){$mid=$r!=='ok'?$r:null;$db->prepare("UPDATE vpn_accounts SET mikrotik_id=?,status='active',updated_at=NOW() WHERE id=?")->execute([$mid,$id]);$msg='✅ Re-push berhasil!';$mtype='ok';}else{$msg='❌ Re-push gagal: '.$api->error;$mtype='err';}}else{$msg='❌ Tidak bisa konek Mikrotik: '.$api->error;$mtype='err';}}
    }
    if($act==='server_toggle'){
        $rid=(int)($_POST['router_id']??0);$en=($_POST['enable']??'')===('1');$ipsec=trim($_POST['ipsec_secret']??'');
        $router=$db->prepare("SELECT * FROM routers WHERE id=?");$router->execute([$rid]);$router=$router->fetch();
        if($router){$api=MikrotikAPI::fromRouter($router);if($api->connect()){$ok=$en?$api->enableL2tp($ipsec):$api->disableL2tp();$api->close();$msg=$ok?($en?'✅ L2TP Server diaktifkan!':'✅ L2TP Server dinonaktifkan!'):'❌ Gagal';$mtype=$ok?'ok':'err';}else{$msg='❌ '.$api->error;$mtype='err';}}
    }
}

$routers=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY name")->fetchAll();
$qf=trim($_GET['q']??'');$rf=(int)($_GET['rid']??0);$sf=$_GET['st']??'';
$wh=['1=1'];$pr=[];
if($qf){$wh[]='(v.username LIKE ? OR v.comment LIKE ?)';$s="%$qf%";$pr=[$s,$s];}
if($rf){$wh[]='v.router_id=?';$pr[]=$rf;}if($sf){$wh[]='v.status=?';$pr[]=$sf;}
$st=$db->prepare("SELECT v.*,r.name rn,r.ip_public rip,r.domain_public rdomain,u.full_name un FROM vpn_accounts v JOIN routers r ON v.router_id=r.id LEFT JOIN users u ON v.created_by=u.id WHERE ".implode(' AND ',$wh)." ORDER BY v.created_at DESC");
$st->execute($pr);$vpns=$st->fetchAll();
$errCnt=$db->query("SELECT COUNT(*) FROM vpn_accounts WHERE status='error' OR mikrotik_id IS NULL")->fetchColumn();

startPage('VPN L2TP');
?>
<div class="ph">
    <div>
        <div class="ph-t">🔐 VPN L2TP Management</div>
        <div class="ph-s">Kelola akun VPN L2TP/IPSec untuk interkoneksi — ROS 6 & 7 compatible</div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/admin/vpn_status.php" class="btn bo bsm">📡 Status Live</a>
        <?php if($errCnt>0):?><form method="POST" style="display:inline" onsubmit="return cDel('Re-push semua VPN error?')"><?=csrfField()?><input type="hidden" name="action" value="repush_all_placeholder"><button type="button" class="btn bw" onclick="toast('Gunakan tombol ⚡ per baris','warning')">⚡ <?=$errCnt?> Error</button></form><?php endif;?>
        <button class="btn bp" onclick="oM('mAdd')">➕ Tambah VPN User</button>
    </div>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<!-- Server Control -->
<?php if(!empty($routers)):?>
<div class="card"><div class="ch"><div class="ct">⚙️ Kontrol L2TP Server</div><span style="font-size:.76rem;color:var(--g400)">Enable/disable L2TP server per router (ROS 6 & 7)</span></div>
<div class="cb"><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">
<?php foreach($routers as $r):?>
<div style="background:var(--g50);border-radius:10px;padding:14px;border:1px solid var(--g200)">
    <div style="font-weight:700;margin-bottom:4px"><?=h($r['name'])?></div>
    <div style="font-size:.74rem;color:var(--g400);margin-bottom:10px;font-family:'JetBrains Mono',monospace"><?=h($r['host'])?> | <?=h($r['ip_public'])?></div>
    <form method="POST" style="display:flex;gap:8px;align-items:flex-end">
        <?=csrfField()?>
        <input type="hidden" name="action" value="server_toggle"><input type="hidden" name="router_id" value="<?=$r['id']?>">
        <div style="flex:1"><label class="fl">IPSec Pre-shared Key</label><input type="text" name="ipsec_secret" class="fc" placeholder="vpnsecretkey" style="padding:7px 10px;font-size:.82rem"></div>
        <button type="submit" name="enable" value="1" class="btn bs bsm" style="white-space:nowrap">▶ Enable</button>
        <button type="submit" name="enable" value="0" class="btn bd bsm">⏹</button>
    </form>
</div>
<?php endforeach;?>
</div></div></div>
<?php endif;?>

<!-- Filter -->
<div class="card"><div class="cb" style="padding:12px 16px">
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:160px"><label class="fl">🔍 Cari</label><input type="text" name="q" class="fc" placeholder="Username, komentar..." value="<?=h($qf)?>"></div>
    <div style="min-width:150px"><label class="fl">Router</label><select name="rid" class="fsel"><option value="">Semua</option><?php foreach($routers as $r):?><option value="<?=$r['id']?>" <?=$rf==$r['id']?'selected':''?>><?=h($r['name'])?></option><?php endforeach;?></select></div>
    <div style="min-width:110px"><label class="fl">Status</label><select name="st" class="fsel"><option value="">Semua</option><option value="active" <?=$sf==='active'?'selected':''?>>Aktif</option><option value="disabled" <?=$sf==='disabled'?'selected':''?>>Disabled</option><option value="error" <?=$sf==='error'?'selected':''?>>Error</option></select></div>
    <button type="submit" class="btn bp">Filter</button><a href="/admin/vpn.php" class="btn bo">Reset</a>
</form></div></div>

<div class="card"><div class="ch"><div class="ct">📋 VPN Users (<?=count($vpns)?>)</div></div>
<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Username</th><th>Password</th><th>Router</th><th>Service</th><th>IP Lokal</th><th>IP Remote</th><th>IPSec Key</th><th>Komentar</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
<?php if(empty($vpns)):?><tr><td colspan="12" class="empty">Belum ada VPN user</td></tr><?php endif;?>
<?php foreach($vpns as $i=>$v):?>
<tr>
    <td style="color:var(--g400);font-size:.76rem"><?=$i+1?></td>
    <td><strong style="color:var(--blue-d)"><?=h($v['username'])?></strong></td>
    <td>
        <span id="pw<?=$v['id']?>" data-pw="<?=h($v['password'])?>" style="font-family:'JetBrains Mono',monospace;font-size:.76rem;letter-spacing:2px">••••••••</span>
        <button class="cbtn" onclick="tPw('pw<?=$v['id']?>')">👁</button>
    </td>
    <td style="font-size:.78rem"><?=h($v['rn'])?></td>
    <td><span class="bdg bblue"><?=strtoupper($v['service'])?></span></td>
    <td class="ipm" style="font-size:.76rem"><?=h($v['local_address']?:'-')?></td>
    <td class="ipm" style="font-size:.76rem"><?=h($v['remote_address']?:'-')?></td>
    <td style="font-size:.76rem;color:var(--g600)"><?=h($v['ipsec_secret']?:'-')?></td>
    <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.78rem;color:var(--g600)"><?=h($v['comment']?:'-')?></td>
    <td><span class="bdg <?=$v['status']==='active'?'bon':($v['status']==='disabled'?'bgray':'boff')?>"><?=$v['status']?></span></td>
    <td style="font-size:.72rem;color:var(--g400);white-space:nowrap"><?=date('d/m/y',strtotime($v['created_at']))?></td>
    <td><div style="display:flex;gap:3px;flex-wrap:wrap">
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$v['id']?>"><button type="submit" class="btn bo bxs"><?=$v['is_disabled']?'▶️':'⏸'?></button></form>
        <button class="btn bo bxs" onclick='editVpn(<?=json_encode($v)?>)'>✏️</button>
        <button class="btn bpu bxs" onclick='showScript(<?=json_encode($v)?>)'>📜</button>
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="repush"><input type="hidden" name="id" value="<?=$v['id']?>"><button type="submit" class="btn bw bxs">⚡</button></form>
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus VPN user ini?')"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$v['id']?>"><button type="submit" class="btn bd bxs">🗑️</button></form>
    </div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- MODAL ADD -->
<div class="mo" id="mAdd"><div class="md md-lg">
<div class="mh"><div class="mt">🔐 Tambah VPN L2TP User</div><button class="mx" onclick="cM('mAdd')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="add">
<div class="mb">
<?php if(empty($routers)):?><div class="alert awrn">Belum ada router! <a href="/admin/routers.php">Tambah router</a>.</div><?php else:?>
<div class="fg"><label class="fl">Router *</label><select name="router_id" class="fsel" required><option value="">-- Pilih Router --</option><?php foreach($routers as $r):?><option value="<?=$r['id']?>"><?=h($r['name'])?> — <?=h($r['ip_public'])?></option><?php endforeach;?></select></div>
<div class="frow2">
    <div class="fg"><label class="fl">Username VPN *</label><input type="text" name="username" class="fc" required placeholder="vpnuser01"></div>
    <div class="fg"><label class="fl">Password *</label><input type="text" name="password" class="fc" required placeholder="P@ssw0rd!"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Service</label><select name="service" class="fsel"><option value="l2tp">L2TP</option><option value="pptp">PPTP</option><option value="any">Any</option></select></div>
    <div class="fg"><label class="fl">Profile PPP</label><input type="text" name="profile" class="fc" value="default-encryption" placeholder="default-encryption"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">IP Lokal Tunnel</label><input type="text" name="local_address" class="fc" placeholder="10.0.0.1"><div class="fhint">IP sisi server tunnel (opsional)</div></div>
    <div class="fg"><label class="fl">IP Remote Client</label><input type="text" name="remote_address" class="fc" placeholder="10.0.0.2"><div class="fhint">IP diberikan ke client (opsional)</div></div>
</div>
<div class="fg"><label class="fl">IPSec Pre-shared Key</label><input type="text" name="ipsec_secret" class="fc" placeholder="Samakan dengan IPSec secret L2TP server"><div class="fhint">Kosongkan jika tidak pakai IPSec</div></div>
<div class="fg"><label class="fl">Komentar</label><textarea name="comment" class="fc" rows="2" placeholder="Misal: VPN interkoneksi kantor cabang Surabaya..."></textarea></div>
<?php endif;?>
</div>
<?php if(!empty($routers)):?><div class="mf"><button type="button" class="btn bo" onclick="cM('mAdd')">Batal</button><button type="submit" class="btn bp">🔐 Buat VPN User</button></div><?php endif;?>
</form></div></div>

<!-- MODAL EDIT -->
<div class="mo" id="mEdit"><div class="md">
<div class="mh"><div class="mt">✏️ Edit VPN User</div><button class="mx" onclick="cM('mEdit')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="eId">
<div class="mb">
<div class="alert ainf" id="eInfo"></div>
<div class="fg"><label class="fl">Password Baru *</label><input type="text" name="new_password" id="ePw" class="fc" required></div>
<div class="fg"><label class="fl">Komentar</label><textarea name="comment" id="eCmt" class="fc" rows="2"></textarea></div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEdit')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
</form></div></div>

<!-- MODAL SCRIPT -->
<div class="mo" id="mScript"><div class="md md-lg">
<div class="mh"><div class="mt">📜 Script Mikrotik — VPN L2TP</div><button class="mx" onclick="cM('mScript')">✕</button></div>
<div class="mb" id="mScriptBody"></div>
</div></div>

<script>
function tPw(id){const el=document.getElementById(id);el.textContent=el.textContent.includes('•')?el.dataset.pw:'••••••••';}
function editVpn(v){document.getElementById('eId').value=v.id;document.getElementById('ePw').value=v.password;document.getElementById('eCmt').value=v.comment||'';document.getElementById('eInfo').textContent='Edit: '+v.username+' @ '+v.rn;oM('mEdit');}
function showScript(v){
    const srvIp=v.rip||'IP_PUBLIK';
    const s1=`/interface l2tp-server server set \\\n  enabled=yes \\\n  use-ipsec=yes \\\n  ipsec-secret=${v.ipsec_secret||'vpnsecretkey'} \\\n  authentication=mschap2,mschap1 \\\n  default-profile=default-encryption`;
    const s2=`/ppp secret add \\\n  name="${v.username}" \\\n  password="${v.password}" \\\n  service=${v.service||'l2tp'} \\\n  profile=${v.profile||'default-encryption'}${v.local_address?'\\\n  local-address='+v.local_address:''}${v.remote_address?'\\\n  remote-address='+v.remote_address:''}${v.comment?'\\\n  comment="'+v.comment+'"':''}`;
    const s3=`/interface l2tp-client add \\\n  name="l2tp-${v.username}" \\\n  connect-to=${srvIp} \\\n  user="${v.username}" \\\n  password="${v.password}" \\\n  use-ipsec=yes \\\n  ipsec-secret=${v.ipsec_secret||'vpnsecretkey'} \\\n  profile=default-encryption \\\n  disabled=no`;
    document.getElementById('mScriptBody').innerHTML=`
    <div style="display:grid;gap:12px">
        <div style="background:var(--g50);border-radius:9px;padding:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem">
            <div><span style="color:var(--g400);font-size:.66rem;text-transform:uppercase">Username</span><br><strong style="color:var(--blue-d)">${v.username}</strong></div>
            <div><span style="color:var(--g400);font-size:.66rem;text-transform:uppercase">Server IP</span><br><strong style="color:var(--red)">${srvIp}</strong></div>
        </div>
        ${mkScript('① Enable L2TP Server','s1',s1)}
        ${mkScript('② Tambah PPP Secret','s2',s2)}
        ${mkScript('③ L2TP Client (Router↔Router)','s3',s3)}
        <button class="btn bo" style="width:100%" onclick="cpTxt(document.getElementById('s1').textContent+'\\n\\n'+document.getElementById('s2').textContent+'\\n\\n'+document.getElementById('s3').textContent,this)">📋 Salin Semua Script</button>
    </div>`;
    oM('mScript');
}
function mkScript(title,id,code){return`<div><div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px"><div style="font-size:.8rem;font-weight:700;color:var(--g700)">${title}</div><button class="cbtn" style="padding:3px 10px" onclick="cpTxt(document.getElementById('${id}').textContent,this)">📋 Salin</button></div><div class="sbox" id="${id}">${code}</div></div>`;}
</script>
<?php endPage();?>
