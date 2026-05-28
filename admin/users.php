<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin('admin');
$db=db();$msg='';$mtype='ok';

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='add'){
        $un=trim($_POST['username']??'');$fn=trim($_POST['full_name']??'');$pw=trim($_POST['password']??'');$role=$_POST['role']??'operator';
        if(!$un||!$fn||!$pw){$msg='Semua field wajib!';$mtype='err';}
        else{$dup=$db->prepare("SELECT id FROM users WHERE username=?");$dup->execute([$un]);if($dup->fetch()){$msg="Username '$un' sudah ada!";$mtype='err';}
        else{$db->prepare("INSERT INTO users(username,password,full_name,role)VALUES(?,?,?,?)")->execute([$un,password_hash($pw,PASSWORD_DEFAULT),$fn,$role]);auditLog('add_user',$un);$msg="✅ User <strong>$fn</strong> berhasil dibuat!";$mtype='ok';}}
    }
    if($act==='edit'){
        $id=(int)($_POST['id']??0);$fn=trim($_POST['full_name']??'');$role=$_POST['role']??'operator';$pw=trim($_POST['password']??'');$act2=(int)($_POST['is_active']??1);
        if($id===1&&adminUser()['id']!==1){$msg='Tidak bisa mengedit superadmin!';$mtype='err';}
        else{
            if($pw){$db->prepare("UPDATE users SET full_name=?,role=?,password=?,is_active=? WHERE id=?")->execute([$fn,$role,password_hash($pw,PASSWORD_DEFAULT),$act2,$id]);}
            else{$db->prepare("UPDATE users SET full_name=?,role=?,is_active=? WHERE id=?")->execute([$fn,$role,$act2,$id]);}
            $msg='✅ User diupdate!';$mtype='ok';
        }
    }
    if($act==='delete'){$id=(int)($_POST['id']??0);if($id===adminUser()['id']){$msg='Tidak bisa hapus akun sendiri!';$mtype='err';}else{$db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);$msg='User dihapus.';$mtype='ok';}}
}

$users=$db->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
startPage('User Admin');
?>
<div class="ph">
    <div><div class="ph-t">🛡 User Admin</div><div class="ph-s">Kelola akun administrator & operator S.NET Manager</div></div>
    <button class="btn bp" onclick="oM('mAdd')">➕ Tambah User</button>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<div class="card"><div class="ch"><div class="ct">👤 Daftar Admin User (<?=count($users)?>)</div></div>
<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Username</th><th>Nama Lengkap</th><th>Role</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead><tbody>
<?php foreach($users as $i=>$u):?>
<tr>
    <td style="color:var(--g400)"><?=$i+1?></td>
    <td><strong style="font-family:'JetBrains Mono',monospace;color:var(--blue-d)"><?=h($u['username'])?></strong><?=$u['id']===adminUser()['id']?'<span class="bdg bblue" style="margin-left:5px;font-size:.6rem">ANDA</span>':''?></td>
    <td><?=h($u['full_name'])?></td>
    <td>
        <?php $rc=['superadmin'=>'bpur','admin'=>'bblue','operator'=>'bgray','teknisi'=>'borg'][$u['role']]??'bgray';?>
        <span class="bdg <?=$rc?>"><?=strtoupper($u['role'])?></span>
    </td>
    <td><span class="bdg <?=$u['is_active']?'bon':'boff'?>"><?=$u['is_active']?'Aktif':'Non'?></span></td>
    <td style="font-size:.72rem;color:var(--g400)"><?=date('d/m/Y',strtotime($u['created_at']))?></td>
    <td><div style="display:flex;gap:4px">
        <button class="btn bo bxs" onclick='editUser(<?=json_encode($u)?>)'>✏️</button>
        <?php if($u['id']!==adminUser()['id']):?>
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus user ini?')"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$u['id']?>"><button type="submit" class="btn bd bxs">🗑️</button></form>
        <?php endif;?>
    </div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- INFO ROLES -->
<div class="card"><div class="ch"><div class="ct">📋 Level Akses</div></div><div class="cb">
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;font-size:.83rem">
    <?php foreach([['🛡','Superadmin','Akses penuh semua fitur, kelola user admin','bpur'],['🔑','Admin','Akses semua fitur kecuali kelola user','bblue'],['👷','Operator','Akses read + tambah pelanggan/forwarding/vpn, tidak bisa hapus/config','bgray']] as [$ic,$r,$d,$cl]):?>
    <div style="background:var(--g50);border-radius:9px;padding:12px;border:1px solid var(--g200)">
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:5px"><?=$ic?> <span class="bdg <?=$cl?>"><?=strtoupper($r)?></span></div>
        <div style="color:var(--g600);font-size:.78rem"><?=$d?></div>
    </div>
    <?php endforeach;?>
</div>
</div></div>

<!-- MODAL ADD -->
<div class="mo" id="mAdd"><div class="md">
<div class="mh"><div class="mt">➕ Tambah Admin User</div><button class="mx" onclick="cM('mAdd')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="add">
<div class="mb">
    <div class="fg"><label class="fl">Username *</label><input type="text" name="username" class="fc" required placeholder="operator1" autocomplete="off"></div>
    <div class="fg"><label class="fl">Nama Lengkap *</label><input type="text" name="full_name" class="fc" required placeholder="Ahmad Operator"></div>
    <div class="fg"><label class="fl">Password *</label><input type="text" name="password" class="fc" required placeholder="Min 6 karakter" minlength="6" autocomplete="off"></div>
    <div class="fg"><label class="fl">Role</label><select name="role" class="fsel"><option value="teknisi">🔧 Teknisi</option><option value="operator">Operator</option><option value="admin">Admin</option><option value="superadmin">Superadmin</option></select></div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mAdd')">Batal</button><button type="submit" class="btn bp">💾 Buat User</button></div>
</form></div></div>

<!-- MODAL EDIT -->
<div class="mo" id="mEdit"><div class="md">
<div class="mh"><div class="mt">✏️ Edit User</div><button class="mx" onclick="cM('mEdit')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
<div class="mb">
    <div style="font-size:.75rem;color:var(--g400);margin-bottom:12px">Username: <strong id="eUn" style="font-family:'JetBrains Mono',monospace;color:var(--blue-d)"></strong></div>
    <div class="fg"><label class="fl">Nama Lengkap *</label><input type="text" name="full_name" id="eFn" class="fc" required></div>
    <div class="fg"><label class="fl">Password Baru <span style="font-weight:400;color:var(--g400)">(kosong=tidak berubah)</span></label><input type="text" name="password" class="fc" placeholder="Password baru..." autocomplete="off"></div>
    <div class="frow2">
        <div class="fg"><label class="fl">Role</label><select name="role" id="eRole" class="fsel"><option value="teknisi">🔧 Teknisi</option><option value="operator">Operator</option><option value="admin">Admin</option><option value="superadmin">Superadmin</option></select></div>
        <div class="fg"><label class="fl">Status</label><select name="is_active" id="eActive" class="fsel"><option value="1">Aktif</option><option value="0">Non-aktif</option></select></div>
    </div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEdit')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
</form></div></div>

<script>
function editUser(u){document.getElementById('eId').value=u.id;document.getElementById('eUn').textContent=u.username;document.getElementById('eFn').value=u.full_name;document.getElementById('eRole').value=u.role;document.getElementById('eActive').value=u.is_active;oM('mEdit');}
</script>
<?php endPage();?>
