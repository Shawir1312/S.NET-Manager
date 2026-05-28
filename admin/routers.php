<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    $act=$_POST['action']??'';

    if($act==='add'){
        $name=trim($_POST['name']??'');$host=trim($_POST['host']??'');$port=(int)($_POST['port']??8728);
        $un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
        $ip=trim($_POST['ip_public']??'');$dom=trim($_POST['domain_public']??'');$ros=(int)($_POST['ros_version']??7);
        $useMK=(int)(!empty($_POST['use_mikhmon']));
        $wanIface=trim($_POST['wan_interface']??'');
        if(!$name||!$host||!$un||!$pw||!$ip){$msg='Semua field wajib diisi!';$mtype='err';}
        else{
            $db->prepare("INSERT INTO routers(name,host,port,username,password,ip_public,domain_public,ros_version,use_mikhmon,wan_interface)VALUES(?,?,?,?,?,?,?,?,?,?)")
               ->execute([$name,$host,$port,$un,$pw,$ip,$dom,$ros,$useMK,$wanIface]);
            auditLog('add_router',$name);$msg="✅ Router <strong>$name</strong> berhasil ditambahkan!";$mtype='ok';
        }
    }

    if($act==='edit'){
        $id=(int)($_POST['id']??0);$name=trim($_POST['name']??'');$host=trim($_POST['host']??'');
        $port=(int)($_POST['port']??8728);$un=trim($_POST['username']??'');$pw=trim($_POST['password']??'');
        $ip=trim($_POST['ip_public']??'');$dom=trim($_POST['domain_public']??'');$ros=(int)($_POST['ros_version']??7);
        $act2=(int)($_POST['is_active']??1);$useMK=(int)(!empty($_POST['use_mikhmon']));
        $wanIface=trim($_POST['wan_interface']??'');
        if(!$name||!$host||!$un||!$ip){$msg='Semua field wajib!';$mtype='err';}
        else{
            if($pw){
                $db->prepare("UPDATE routers SET name=?,host=?,port=?,username=?,password=?,ip_public=?,domain_public=?,ros_version=?,is_active=?,use_mikhmon=?,wan_interface=? WHERE id=?")
                   ->execute([$name,$host,$port,$un,$pw,$ip,$dom,$ros,$act2,$useMK,$wanIface,$id]);
            }else{
                $db->prepare("UPDATE routers SET name=?,host=?,port=?,username=?,ip_public=?,domain_public=?,ros_version=?,is_active=?,use_mikhmon=?,wan_interface=? WHERE id=?")
                   ->execute([$name,$host,$port,$un,$ip,$dom,$ros,$act2,$useMK,$wanIface,$id]);
            }
            auditLog('edit_router',"ID:$id");$msg='✅ Router diupdate!';$mtype='ok';
        }
    }

    if($act==='set_main'){
        $id=(int)($_POST['id']??0);
        $db->exec("UPDATE routers SET is_main=0");
        $db->prepare("UPDATE routers SET is_main=1 WHERE id=?")->execute([$id]);
        auditLog('set_main_router',"ID:$id");$msg='✅ Router utama berhasil diubah!';$mtype='ok';
    }

    if($act==='toggle_mikhmon'){
        $id=(int)($_POST['id']??0);$r=$db->prepare("SELECT use_mikhmon FROM routers WHERE id=?");
        $r->execute([$id]);$r=$r->fetch();
        if($r){$nv=$r['use_mikhmon']?0:1;$db->prepare("UPDATE routers SET use_mikhmon=? WHERE id=?")->execute([$nv,$id]);
        $msg='Monitoring Mikhmon '.($nv?'diaktifkan':'dinonaktifkan').'!';$mtype='ok';}
    }

    if($act==='delete'){
        $id=(int)($_POST['id']??0);
        $r=$db->prepare("SELECT name,is_main FROM routers WHERE id=?");$r->execute([$id]);$r=$r->fetch();
        if($r){
            if($r['is_main']){$msg='Tidak bisa menghapus router utama!';$mtype='err';}
            else{$db->prepare("DELETE FROM routers WHERE id=?")->execute([$id]);$msg='Router dihapus.';$mtype='ok';}
        }
    }

    if($act==='test'){
        $id=(int)($_POST['id']??0);$r=$db->prepare("SELECT * FROM routers WHERE id=?");$r->execute([$id]);$r=$r->fetch();
        if($r){$api=MikrotikAPI::fromRouter($r);if($api->connect()){
            $idn=$api->getIdentity();$res=$api->getResourceUsage();$api->close();
            $cpu=$res['cpu-load']??'-';$up=$res['uptime']??'-';
            $msg="✅ Konek berhasil! Identity: <strong>$idn</strong> | CPU: {$cpu}% | Uptime: $up";$mtype='ok';
        }else{$msg='❌ Gagal: '.$api->error;$mtype='err';}}
    }
}

$routers=$db->query("SELECT r.*, (SELECT COUNT(*) FROM port_forwardings WHERE router_id=r.id) fwd_count, (SELECT COUNT(*) FROM vpn_accounts WHERE router_id=r.id) vpn_count FROM routers r ORDER BY r.is_main DESC,r.created_at ASC")->fetchAll();
startPage('Router Config');
?>
<div class="ph">
    <div><div class="ph-t">🌐 Router Mikrotik</div><div class="ph-s">Kelola router, set Router Utama (⭐), aktifkan monitoring Mikhmon per router</div></div>
    <button class="btn bp" onclick="oM('mAdd')">➕ Tambah Router</button>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<div class="alert ainf" style="margin-bottom:14px">
    ⭐ <strong>Router Utama</strong> = digunakan untuk VPN L2TP dan Port Forwarding. Hanya satu yang bisa jadi utama.
    &nbsp;|&nbsp; 📊 Toggle <strong>Mikhmon ON/OFF</strong> langsung di kolom tabel. Jika tidak ada yang ON, semua router ditampilkan di monitoring.
</div>

<div class="card"><div class="ch"><div class="ct">📋 Daftar Router (<?=count($routers)?>)</div></div>
<div class="tw"><table class="dt">
<thead><tr><th>#</th><th>Nama</th><th>Host API</th><th>IP Publik / Domain</th><th>ROS</th><th>Fwd</th><th>VPN</th><th>Mikhmon</th><th>Utama</th><th>Status</th><th>Aksi</th></tr></thead>
<tbody>
<?php if(empty($routers)):?><tr><td colspan="11" class="empty">Belum ada router</td></tr><?php endif;?>
<?php foreach($routers as $i=>$r):?>
<tr style="<?=$r['is_main']?'background:rgba(27,63,166,.04);':''?>">
    <td style="color:var(--g400)"><?=$i+1?></td>
    <td><strong style="color:var(--blue-d)"><?=h($r['name'])?></strong></td>
    <td class="ipm" style="font-size:.8rem"><?=h($r['host'])?>:<?=$r['port']?></td>
    <td>
        <div class="ipm" style="font-weight:700;color:var(--red-d)"><?=h($r['ip_public'])?></div>
        <?php if($r['domain_public']):?><div style="font-size:.7rem;color:var(--g400)"><?=h($r['domain_public'])?></div><?php endif;?>
    </td>
    <td><span class="bdg bblue">v<?=$r['ros_version']?></span></td>
    <td><a href="/admin/port_forward.php?rid=<?=$r['id']?>" class="bdg bblue"><?=$r['fwd_count']?></a></td>
    <td><a href="/admin/vpn.php?rid=<?=$r['id']?>" class="bdg bpur"><?=$r['vpn_count']?></a></td>
    <td>
        <!-- Toggle Mikhmon langsung -->
        <form method="POST" style="display:inline"><?=csrfField()?>
            <input type="hidden" name="action" value="toggle_mikhmon">
            <input type="hidden" name="id" value="<?=$r['id']?>">
            <button type="submit" class="bdg <?=$r['use_mikhmon']?'bon':'bgray'?>" style="border:none;cursor:pointer;font-size:.72rem;padding:3px 10px" title="Toggle monitoring Mikhmon">
                <?=$r['use_mikhmon']?'✅ ON':'⭕ OFF'?>
            </button>
        </form>
    </td>
    <td>
        <?php if($r['is_main']):?>
            <span class="bdg bblue">⭐ UTAMA</span>
        <?php else:?>
            <form method="POST" style="display:inline"><?=csrfField()?>
                <input type="hidden" name="action" value="set_main">
                <input type="hidden" name="id" value="<?=$r['id']?>">
                <button type="submit" class="btn bo bxs" title="Jadikan Router Utama">⭐ Set</button>
            </form>
        <?php endif;?>
    </td>
    <td><span class="bdg <?=$r['is_active']?'bon':'boff'?>"><?=$r['is_active']?'Aktif':'Non'?></span></td>
    <td><div style="display:flex;gap:3px;flex-wrap:wrap">
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=$r['id']?>"><button type="submit" class="btn bs bxs" title="Test koneksi">🔌</button></form>
        <button class="btn bo bxs" onclick='editRouter(<?=json_encode($r)?>)'>✏️</button>
        <?php if(!$r['is_main']):?>
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus router? Semua rule forwarding & VPN akan ikut terhapus!')"><?=csrfField()?>
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$r['id']?>">
            <button type="submit" class="btn bd bxs">🗑️</button>
        </form>
        <?php endif;?>
    </div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- MODAL ADD -->
<div class="mo" id="mAdd"><div class="md md-lg">
<div class="mh"><div class="mt">➕ Tambah Router</div><button class="mx" onclick="cM('mAdd')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="add">
<div class="mb">
<div class="frow2">
    <div class="fg"><label class="fl">Nama Router *</label><input type="text" name="name" class="fc" required placeholder="Router Manado 01"></div>
    <div class="fg"><label class="fl">ROS Version</label><select name="ros_version" class="fsel"><option value="7" selected>RouterOS 7</option><option value="6">RouterOS 6</option></select></div>
</div>
<div style="background:var(--g50);border-radius:9px;padding:12px;margin-bottom:12px;border:1px solid var(--g200)">
    <div style="font-weight:700;color:var(--blue-d);font-size:.78rem;margin-bottom:8px">🔌 API Mikrotik</div>
    <div class="frow2">
        <div class="fg"><label class="fl">Host/IP *</label><input type="text" name="host" class="fc" required placeholder="192.168.1.1"></div>
        <div class="fg"><label class="fl">Port</label><input type="number" name="port" class="fc" value="8728"></div>
    </div>
    <div class="frow2">
        <div class="fg"><label class="fl">Username *</label><input type="text" name="username" class="fc" required placeholder="admin"></div>
        <div class="fg"><label class="fl">Password *</label><input type="password" name="password" class="fc" required></div>
    </div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">IP Publik *</label><input type="text" name="ip_public" class="fc" required placeholder="203.0.113.1"></div>
    <div class="fg"><label class="fl">Domain Publik <small style="font-weight:400;color:var(--g400)">(opsional)</small></label><input type="text" name="domain_public" class="fc" placeholder="isp.co.id"></div>
</div>
<div class="fg">
    <label class="fl">Interface WAN Publik <small style="font-weight:400;color:var(--g400)">(untuk Port Forwarding / Remote ONT)</small></label>
    <input type="text" name="wan_interface" class="fc" placeholder="ether1 atau pppoe-out1">
    <div class="fhint">Nama interface Mikrotik yang terhubung ke internet (dipakai sebagai <code>in-interface</code> pada rule NAT, bukan dst-address)</div>
</div>
<label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:10px;background:#F0FDF4;border-radius:8px;border:1px solid #BBF7D0;font-weight:700;font-size:.85rem;color:var(--green-d)">
    <input type="checkbox" name="use_mikhmon" value="1" style="width:15px;height:15px;accent-color:var(--green)">
    📊 Aktifkan Monitoring Mikhmon untuk router ini
</label>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mAdd')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
</form></div></div>

<!-- MODAL EDIT -->
<div class="mo" id="mEdit"><div class="md md-lg">
<div class="mh"><div class="mt">✏️ Edit Router</div><button class="mx" onclick="cM('mEdit')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
<div class="mb">
<div class="frow2">
    <div class="fg"><label class="fl">Nama *</label><input type="text" name="name" id="eName" class="fc" required></div>
    <div class="fg"><label class="fl">ROS Version</label><select name="ros_version" id="eRos" class="fsel"><option value="7">RouterOS 7</option><option value="6">RouterOS 6</option></select></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Host/IP *</label><input type="text" name="host" id="eHost" class="fc" required></div>
    <div class="fg"><label class="fl">Port</label><input type="number" name="port" id="ePort" class="fc"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Username *</label><input type="text" name="username" id="eUser" class="fc" required></div>
    <div class="fg"><label class="fl">Password <small style="font-weight:400;color:var(--g400)">(kosong=tidak berubah)</small></label><input type="password" name="password" class="fc"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">IP Publik *</label><input type="text" name="ip_public" id="eIp" class="fc" required></div>
    <div class="fg"><label class="fl">Domain Publik</label><input type="text" name="domain_public" id="eDomain" class="fc"></div>
</div>
<div class="fg">
    <label class="fl">Interface WAN Publik <small style="font-weight:400;color:var(--g400)">(untuk Port Forwarding / Remote ONT)</small></label>
    <input type="text" name="wan_interface" id="eWanIface" class="fc" placeholder="ether1 atau pppoe-out1">
    <div class="fhint">Nama interface Mikrotik ke internet. Dipakai sebagai <code>in-interface</code> pada rule NAT DST-NAT.</div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Status</label><select name="is_active" id="eActive" class="fsel"><option value="1">Aktif</option><option value="0">Non-aktif</option></select></div>
    <div class="fg" style="display:flex;align-items:flex-end;padding-bottom:4px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:700;font-size:.84rem;color:var(--green-d)">
            <input type="checkbox" name="use_mikhmon" id="eMK" value="1" style="width:15px;height:15px;accent-color:var(--green)">
            📊 Mikhmon Monitor
        </label>
    </div>
</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEdit')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
</form></div></div>

<script>
function editRouter(r){
    document.getElementById('eId').value=r.id;
    document.getElementById('eName').value=r.name;
    document.getElementById('eHost').value=r.host;
    document.getElementById('ePort').value=r.port;
    document.getElementById('eUser').value=r.username;
    document.getElementById('eIp').value=r.ip_public;
    document.getElementById('eDomain').value=r.domain_public||'';
    document.getElementById('eWanIface').value=r.wan_interface||'';
    document.getElementById('eRos').value=r.ros_version;
    document.getElementById('eActive').value=r.is_active;
    document.getElementById('eMK').checked=parseInt(r.use_mikhmon)===1;
    oM('mEdit');
}
</script>
<?php endPage();?>
