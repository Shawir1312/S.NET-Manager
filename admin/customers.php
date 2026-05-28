<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';
// Auto-migrasi tabel genie_config jika belum ada
try{$db->query("SELECT id FROM genie_config LIMIT 1");}catch(Exception $e){try{$db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,url VARCHAR(255) NOT NULL,username VARCHAR(100) DEFAULT '',password VARCHAR(100) DEFAULT '',is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Exception $e2){}}
// Auto-migrasi kolom genie_id di tabel customers
try{$db->query("SELECT genie_id FROM customers LIMIT 1");}catch(Exception $e){try{$db->exec("ALTER TABLE customers ADD COLUMN genie_id INT NULL DEFAULT NULL");}catch(Exception $e2){}}
$genieServers = $db->query("SELECT * FROM genie_config ORDER BY id ASC")->fetchAll();
// Default genie for backward compatibility in some places if needed
$genie = GenieACS::fromDB();

// Normalisasi brand dari GenieACS ke ENUM database
function normalizeBrand(string $brand): string {
    $b=strtolower(trim($brand));
    if(str_contains($b,'huawei'))     return 'Huawei';
    if(str_contains($b,'fiberhome') || str_contains($b,'fiber home')) return 'FiberHome';
    if(str_contains($b,'zte'))        return 'ZTE';
    if(str_contains($b,'cdata') || str_contains($b,'c-data')) return 'CData';
    if($brand==='' || $brand==='Unknown') return 'Unknown';
    return 'Unknown';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    $act=$_POST['action']??'';

    if($act==='add'){
        $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');
        $addr=trim($_POST['address']??'');$brand=normalizeBrand((string)($_POST['device_brand']??''));
        $model=trim($_POST['device_model']??'');$serial=trim($_POST['device_serial']??'');
        $devId=trim($_POST['genie_device_id']??'');$tag=trim($_POST['ont_tag']??'');
        $rId=(int)($_POST['router_id']??0);$notes=trim($_POST['notes']??'');
        $pass=trim($_POST['portal_pass']??'');
        if(!$name){$msg='Nama wajib diisi!';$mtype='err';}
        else{
            $cid=generateCustomerId();
            $hash=password_hash($pass?:$cid,PASSWORD_DEFAULT);
            $db->prepare("INSERT INTO customers(customer_id,password,full_name,phone,address,genie_device_id,device_serial,device_brand,device_model,ont_tag,router_id,notes,created_by,genie_id)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$cid,$hash,$name,$phone,$addr,$devId,$serial,$brand,$model,$tag,$rId?:null,$notes,adminUser()['id'],(int)$_POST['genie_id']?:null]);
            if($devId&&$tag){$g=GenieACS::fromDB((int)$_POST['genie_id']); if($g)$g->addTag($devId,$tag);}
            auditLog('add_customer',$cid,"Name:$name,Brand:$brand");
            $msg="✅ Pelanggan <strong>$name</strong> dibuat. Login ID: <strong>$cid</strong> | Password: <strong>".($pass?h($pass):$cid)."</strong>";$mtype='ok';
        }
    }
    if($act==='edit'){
        $id=(int)($_POST['id']??0);$name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');
        $addr=trim($_POST['address']??'');$brand=normalizeBrand((string)($_POST['device_brand']??''));
        $model=trim($_POST['device_model']??'');$serial=trim($_POST['device_serial']??'');
        $devId=trim($_POST['genie_device_id']??'');$tag=trim($_POST['ont_tag']??'');
        $rId=(int)($_POST['router_id']??0);$notes=trim($_POST['notes']??'');
        $pass=trim($_POST['portal_pass']??'');$active=(int)($_POST['is_active']??1);
        $old=$db->prepare("SELECT * FROM customers WHERE id=?");$old->execute([$id]);$old=$old->fetch();
        $genieId=(int)($_POST['genie_id']??0); $genieId=$genieId?:null;
        $g = $old['genie_id'] ? GenieACS::fromDB($old['genie_id']) : null;
        if($g&&$old&&$old['genie_device_id']&&$old['ont_tag']!==$tag){$g->removeTag($old['genie_device_id'],$old['ont_tag']);}
        $gNew = $genieId ? GenieACS::fromDB($genieId) : null;
        if($gNew&&$devId&&$tag)$gNew->addTag($devId,$tag);
        $sql="UPDATE customers SET full_name=?,phone=?,address=?,device_brand=?,device_model=?,device_serial=?,genie_device_id=?,ont_tag=?,router_id=?,notes=?,is_active=?,genie_id=?,updated_at=NOW() WHERE id=?";
        $p=[$name,$phone,$addr,$brand,$model,$serial,$devId,$tag,$rId?:null,$notes,$active,$genieId,$id];
        if($pass){$sql="UPDATE customers SET full_name=?,phone=?,address=?,device_brand=?,device_model=?,device_serial=?,genie_device_id=?,ont_tag=?,router_id=?,notes=?,is_active=?,genie_id=?,password=?,updated_at=NOW() WHERE id=?";$p=[$name,$phone,$addr,$brand,$model,$serial,$devId,$tag,$rId?:null,$notes,$active,$genieId,password_hash($pass,PASSWORD_DEFAULT),$id];}
        $db->prepare($sql)->execute($p);
        auditLog('edit_customer',"ID:$id");$msg='✅ Data pelanggan berhasil diupdate!';$mtype='ok';
    }
    if($act==='delete'){
        $id=(int)($_POST['id']??0);$r=$db->prepare("SELECT * FROM customers WHERE id=?");$r->execute([$id]);$r=$r->fetch();
        if($r){$g=$r['genie_id']?GenieACS::fromDB($r['genie_id']):null; if($g&&$r['genie_device_id']&&$r['ont_tag'])$g->removeTag($r['genie_device_id'],$r['ont_tag']);
        $db->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);auditLog('del_customer',$r['customer_id']);$msg='Pelanggan dihapus.';$mtype='ok';}
    }
    if($act==='reset_pass'){
        $id=(int)($_POST['id']??0);$np=trim($_POST['new_pass']??'');
        if(strlen($np)<4){$msg='Min 4 karakter!';$mtype='err';}
        else{$db->prepare("UPDATE customers SET password=?,updated_at=NOW() WHERE id=?")->execute([password_hash($np,PASSWORD_DEFAULT),$id]);$msg='✅ Password portal direset!';$mtype='ok';}
    }
    if($act==='toggle'){$id=(int)($_POST['id']??0);$db->prepare("UPDATE customers SET is_active=NOT is_active,updated_at=NOW() WHERE id=?")->execute([$id]);$msg='Status diubah.';$mtype='ok';}
}

$q=trim($_GET['q']??'');$fb=$_GET['brand']??'';$fa=$_GET['active']??'';
$wh=['1=1'];$pr=[];
if($q){$wh[]='(c.full_name LIKE ? OR c.customer_id LIKE ? OR c.phone LIKE ? OR c.device_serial LIKE ?)';$s="%$q%";$pr=array_merge($pr,[$s,$s,$s,$s]);}
if($fb){$wh[]='c.device_brand=?';$pr[]=$fb;}
if($fa!==''){$wh[]='c.is_active=?';$pr[]=(int)$fa;}
$st=$db->prepare("SELECT c.*,r.name rn FROM customers c LEFT JOIN routers r ON c.router_id=r.id WHERE ".implode(' AND ',$wh)." ORDER BY c.created_at DESC");
$st->execute($pr);$custs=$st->fetchAll();
$routers=$db->query("SELECT * FROM routers WHERE is_active=1 ORDER BY name")->fetchAll();

// GenieACS devices for dropdown from ALL servers
$gdevs=[];
foreach($genieServers as $gs){
    $g = GenieACS::fromDB($gs['id']);
    if($g){
        try {
            $all=$g->getDevices('{}');
            foreach((array)$all as $d){
                $inf=$g->getInfo($d);$wf=$g->getWifi($d);
                $nb=normalizeBrand($inf['brand']??'');
                $gdevs[]=['server_id'=>$gs['id'],'server_name'=>$gs['name'],'id'=>$inf['id'],'serial'=>$inf['serial'],'brand'=>$nb,'model'=>$inf['model'],'ssid'=>$wf['ssid_24']??'','tags'=>$inf['tags'],'online'=>$inf['online']];
            }
        } catch(Exception $e) {}
    }
}

startPage('Pelanggan');
?>
<div class="ph">
    <div><div class="ph-t">👥 Manajemen Pelanggan</div><div class="ph-s">Kelola akun pelanggan & portal login</div></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/portal/login.php" class="btn bo bsm" target="_blank">🌐 Portal</a>
        <button class="btn bp" onclick="oM('mAdd')">➕ Tambah Pelanggan</button>
    </div>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<!-- Filter -->
<div class="card"><div class="cb" style="padding:12px 16px">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:160px"><label class="fl">🔍 Cari</label><input type="text" name="q" class="fc" placeholder="Nama, ID, HP, Serial..." value="<?=h($q)?>"></div>
        <div style="min-width:130px"><label class="fl">Brand ONT</label><select name="brand" class="fsel"><option value="">Semua</option><?php foreach(['FiberHome','CData','Huawei','ZTE','Unknown'] as $b):?><option value="<?=$b?>" <?=$fb===$b?'selected':''?>><?=$b?></option><?php endforeach;?></select></div>
        <div style="min-width:110px"><label class="fl">Status</label><select name="active" class="fsel"><option value="">Semua</option><option value="1" <?=$fa==='1'?'selected':''?>>Aktif</option><option value="0" <?=$fa==='0'?'selected':''?>>Non-aktif</option></select></div>
        <button type="submit" class="btn bp">Filter</button><a href="/admin/customers.php" class="btn bo">Reset</a>
    </form>
</div></div>

<div class="card"><div class="ch"><div class="ct">📋 Daftar Pelanggan (<?=count($custs)?>)</div></div>
<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Login ID</th><th>Nama</th><th>HP</th><th>Brand ONT</th><th>Model</th><th>Serial</th><th>Tag</th><th>Router</th><th>Status</th><th>Tgl</th><th>Aksi</th></tr></thead><tbody>
<?php if(empty($custs)):?><tr><td colspan="12" class="empty">Belum ada pelanggan — <button class="btn bp bsm" onclick="oM('mAdd')">Tambah Sekarang</button></td></tr><?php endif;?>
<?php foreach($custs as $i=>$c):?>
<tr>
    <td style="color:var(--g400);font-size:.76rem"><?=$i+1?></td>
    <td><span class="code"><?=h($c['customer_id'])?></span></td>
    <td><div style="font-weight:700;font-size:.85rem"><?=h($c['full_name'])?></div><?php if($c['address']):?><div style="font-size:.7rem;color:var(--g400);max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($c['address'])?></div><?php endif;?></td>
    <td style="font-size:.8rem"><?=h($c['phone']?:'-')?></td>
    <td><span class="bdg borg" style="font-size:.63rem"><?=h($c['device_brand'])?></span></td>
    <td style="font-size:.78rem"><?=h($c['device_model']?:'-')?></td>
    <td><span class="code" style="font-size:.68rem"><?=h($c['device_serial']?:'-')?></span></td>
    <td><?=$c['ont_tag']?'<span class="bdg bpur">🏷 '.h($c['ont_tag']).'</span>':'-'?></td>
    <td style="font-size:.76rem"><?=h($c['rn']?:'-')?></td>
    <td><span class="bdg <?=$c['is_active']?'bon':'boff'?>"><?=$c['is_active']?'Aktif':'Non'?></span></td>
    <td style="font-size:.72rem;color:var(--g400)"><?=date('d/m/y',strtotime($c['created_at']))?></td>
    <td><div style="display:flex;gap:3px;flex-wrap:wrap">
        <button class="btn bo bxs" onclick='editCust(<?=json_encode($c)?>)'>✏️</button>
        <button class="btn bo bxs" onclick='resetPass(<?=$c['id']?>,"<?=h($c['customer_id'])?>>")'>🔑</button>
        <?php if($c['genie_device_id']):?><a href="/admin/ont.php?id=<?=urlencode($c['genie_device_id'])?>" class="btn bs bxs" title="ONT">📶</a><?php endif;?>
        <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$c['id']?>"><button type="submit" class="btn bo bxs"><?=$c['is_active']?'⏸':'▶️'?></button></form>
        <form method="POST" style="display:inline" onsubmit="return cDel('Hapus pelanggan ini?')"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$c['id']?>"><button type="submit" class="btn bd bxs">🗑️</button></form>
    </div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- MODAL ADD -->
<div class="mo" id="mAdd"><div class="md md-lg">
<div class="mh"><div class="mt">➕ Tambah Pelanggan</div><button class="mx" onclick="cM('mAdd')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="add">
<div class="mb">
<div class="frow2">
    <div class="fg"><label class="fl">Nama Lengkap *</label><input type="text" name="full_name" class="fc" required placeholder="Ahmad Budi..."></div>
    <div class="fg"><label class="fl">No. HP</label><input type="text" name="phone" class="fc" placeholder="08123456789"></div>
</div>
<div class="fg"><label class="fl">Alamat</label><textarea name="address" class="fc" rows="2" placeholder="Jl. Merdeka No.1..."></textarea></div>
<div style="background:var(--g50);border-radius:9px;padding:14px;margin-bottom:12px;border:1px solid var(--g200)">
    <div style="font-weight:700;color:var(--blue-d);margin-bottom:10px;font-size:.82rem">📡 Perangkat ONT dari GenieACS</div>
    <?php if(!empty($gdevs)):?>
    <div class="fg" style="margin-bottom:8px">
        <label class="fl">🔍 Cari & Pilih ONT (Serial / Tag / SSID)</label>
        <input type="text" id="aOntSearch" class="fc" placeholder="Ketik serial, tag, atau SSID untuk filter..." oninput="filterOntList(this.value)" autocomplete="off">
    </div>
    <div class="fg">
        <label class="fl">ONT dari ACS <span style="color:var(--red)">*</span></label>
        <select name="genie_device_id" class="fsel" id="aGenieSel" size="4" style="height:auto" onchange="onGenieSel(this)" required>
            <option value="">-- Pilih ONT --</option>
            <?php foreach($gdevs as $gd):?>
            <option value="<?=h($gd['id'])?>" data-genie="<?=h($gd['server_id'])?>" data-serial="<?=h($gd['serial'])?>" data-brand="<?=h($gd['brand'])?>" data-model="<?=h($gd['model'])?>" data-ssid="<?=h($gd['ssid'])?>" data-tags="<?=h(implode(',',$gd['tags']))?>"
                data-search="<?=strtolower(h($gd['server_name'].' '.$gd['serial'].' '.$gd['ssid'].' '.implode(' ',$gd['tags'])))?>">
                [<?=h($gd['server_name'])?>] <?=$gd['online']?'🟢':'🔴'?> [<?=h($gd['brand'])?>] <?=h($gd['serial'])?><?=$gd['ssid']?' — '.h($gd['ssid']):''?><?=!empty($gd['tags'])?' 🏷'.h($gd['tags'][0]):'';?>
            </option>
            <?php endforeach;?>
        </select>
        <div class="fhint">Pilih ONT yang sudah terdaftar di GenieACS. Data brand, model, serial otomatis terisi.</div>
    </div>
    <!-- Info ONT terpilih -->
    <div id="aOntInfo" style="display:none;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;padding:10px 12px;margin-top:8px;font-size:.8rem">
        <div style="font-weight:700;color:var(--blue-d);margin-bottom:6px">📶 ONT Terpilih:</div>
        <div id="aOntInfoText" style="display:grid;grid-template-columns:1fr 1fr;gap:4px"></div>
    </div>
    <!-- Hidden fields yang terisi otomatis dari ACS -->
    <input type="hidden" name="genie_id" id="aGenieId">
    <input type="hidden" name="device_brand" id="aBrand">
    <input type="hidden" name="device_model" id="aModel">
    <input type="hidden" name="device_serial" id="aSerial">
    <?php else:?>
    <div class="alert awrn" style="margin:0">⚠️ GenieACS belum terhubung. <a href="/admin/genie.php">Setup GenieACS</a> untuk pilih ONT otomatis.</div>
    <input type="hidden" name="genie_device_id" value="">
    <input type="hidden" name="genie_id" value="0">
    <div class="frow2" style="margin-top:10px">
        <div class="fg"><label class="fl">Brand ONT</label><select name="device_brand" class="fsel"><option>FiberHome</option><option>CData</option><option>Huawei</option><option>ZTE</option><option>Unknown</option></select></div>
        <div class="fg"><label class="fl">Model</label><input type="text" name="device_model" class="fc" placeholder="HG6145D2"></div>
    </div>
    <div class="fg"><label class="fl">Serial Number</label><input type="text" name="device_serial" class="fc" placeholder="FHTT12345678"></div>
    <?php endif;?>
    <div class="fg" style="margin-top:8px"><label class="fl">Tag GenieACS <span style="font-weight:400;color:var(--g400)">(auto atau isi manual)</span></label><input type="text" name="ont_tag" class="fc" placeholder="cust-SNET-0001" id="aTag"><div class="fhint">Tag unik untuk identifikasi di GenieACS (bisa diisi otomatis setelah simpan)</div></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Router</label>
        <select name="router_id" class="fsel"><option value="">-- Tidak dipilih --</option><?php foreach($routers as $r):?><option value="<?=$r['id']?>"><?=h($r['name'])?></option><?php endforeach;?></select>
    </div>
    <div class="fg"><label class="fl">Password Portal <span style="font-weight:400;color:var(--g400)">(kosong=ID)</span></label><input type="text" name="portal_pass" class="fc" placeholder="Password login portal"><div class="fhint">Default: Login ID pelanggan (SNET-XXXX)</div></div>
</div>
<div class="fg"><label class="fl">Catatan</label><textarea name="notes" class="fc" rows="2" placeholder="Catatan tambahan..."></textarea></div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mAdd')">Batal</button><button type="submit" class="btn bp">💾 Simpan Pelanggan</button></div>
</form></div></div>

<!-- MODAL EDIT -->
<div class="mo" id="mEdit"><div class="md md-lg">
<div class="mh"><div class="mt">✏️ Edit Pelanggan</div><button class="mx" onclick="cM('mEdit')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="eId">
<div class="mb">
<div style="background:#DBEAFE;border-radius:8px;padding:9px 12px;margin-bottom:12px;font-size:.82rem">Login ID: <strong id="eCid" style="font-family:'JetBrains Mono',monospace;color:var(--blue-d)"></strong></div>
<div class="frow2">
    <div class="fg"><label class="fl">Nama *</label><input type="text" name="full_name" id="eName" class="fc" required></div>
    <div class="fg"><label class="fl">No. HP</label><input type="text" name="phone" id="ePhone" class="fc"></div>
</div>
<div class="fg"><label class="fl">Alamat</label><textarea name="address" id="eAddr" class="fc" rows="2"></textarea></div>
<div class="frow2">
    <div class="fg"><label class="fl">Brand ONT</label><select name="device_brand" id="eBrand" class="fsel"><option value="FiberHome">FiberHome</option><option value="CData">C-Data</option><option value="Huawei">Huawei</option><option value="ZTE">ZTE</option><option value="Unknown">Lainnya</option></select></div>
    <div class="fg"><label class="fl">Model</label><input type="text" name="device_model" id="eModel" class="fc" placeholder="HG6145D2"></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Serial</label><input type="text" name="device_serial" id="eSerial" class="fc"></div>
    <div class="fg"><label class="fl">GenieACS Device ID</label><input type="text" name="genie_device_id" id="eDevId" class="fc"></div>
</div>
<div class="fg"><label class="fl">Server GenieACS</label>
    <select name="genie_id" id="eGenieId" class="fsel">
        <option value="0">-- Tidak Ada --</option>
        <?php foreach($genieServers as $gs): ?>
            <option value="<?=$gs['id']?>"><?=h($gs['name'])?></option>
        <?php endforeach; ?>
    </select>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Tag GenieACS</label><input type="text" name="ont_tag" id="eTag" class="fc"></div>
    <div class="fg"><label class="fl">Router</label><select name="router_id" id="eRouter" class="fsel"><option value="">-- Tidak dipilih --</option><?php foreach($routers as $r):?><option value="<?=$r['id']?>"><?=h($r['name'])?></option><?php endforeach;?></select></div>
</div>
<div class="frow2">
    <div class="fg"><label class="fl">Status</label><select name="is_active" id="eActive" class="fsel"><option value="1">Aktif</option><option value="0">Non-aktif</option></select></div>
    <div class="fg"><label class="fl">Password Portal <span style="font-weight:400;color:var(--g400)">(kosong=tidak berubah)</span></label><input type="text" name="portal_pass" class="fc" placeholder="Password baru..."></div>
</div>
<div class="fg"><label class="fl">Catatan</label><textarea name="notes" id="eNotes" class="fc" rows="2"></textarea></div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEdit')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
</form></div></div>

<!-- MODAL RESET PASS -->
<div class="mo" id="mReset"><div class="md">
<div class="mh"><div class="mt">🔑 Reset Password Portal</div><button class="mx" onclick="cM('mReset')">✕</button></div>
<form method="POST"><?=csrfField()?><input type="hidden" name="action" value="reset_pass"><input type="hidden" name="id" id="rpId">
<div class="mb">
<div class="alert ainf">Reset password portal untuk: <strong id="rpCid"></strong></div>
<div class="fg"><label class="fl">Password Baru (min 4 karakter)</label><input type="text" name="new_pass" class="fc" required minlength="4"></div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mReset')">Batal</button><button type="submit" class="btn bd">🔑 Reset</button></div>
</form></div></div>

<script>
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
    if(!o||!o.value){
        document.getElementById('aOntInfo').style.display='none';
        return;
    }
    const serial=o.dataset.serial||'',brand=o.dataset.brand||'',model=o.dataset.model||'',tags=o.dataset.tags||'',gid=o.dataset.genie||'0';
    // Isi hidden fields
    const setV=(id,v)=>{const el=document.getElementById(id);if(el)el.value=v;};
    setV('aBrand',brand);setV('aModel',model);setV('aSerial',serial);setV('aGenieId',gid);
    // Auto-isi tag jika kosong
    const tagEl=document.getElementById('aTag');
    if(tagEl&&!tagEl.value&&tags)tagEl.value=tags.split(',')[0];
    // Tampilkan info ONT
    const info=document.getElementById('aOntInfo');
    const txt=document.getElementById('aOntInfoText');
    if(info&&txt){
        info.style.display='block';
        txt.innerHTML=`
            <div><b>Brand:</b> ${esc(brand)}</div>
            <div><b>Model:</b> ${esc(model)}</div>
            <div><b>Serial:</b> ${esc(serial)}</div>
            <div><b>Tag:</b> ${esc(tags||'-')}</div>`;
    }
}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
function editCust(c){
    document.getElementById('eId').value=c.id;document.getElementById('eCid').textContent=c.customer_id;
    document.getElementById('eName').value=c.full_name;document.getElementById('ePhone').value=c.phone||'';
    document.getElementById('eAddr').value=c.address||'';document.getElementById('eBrand').value=c.device_brand;
    document.getElementById('eSerial').value=c.device_serial||'';
    document.getElementById('eDevId').value=c.genie_device_id||'';document.getElementById('eTag').value=c.ont_tag||'';
    document.getElementById('eGenieId').value=c.genie_id||0;
    document.getElementById('eActive').value=c.is_active;document.getElementById('eNotes').value=c.notes||'';
    const er=document.getElementById('eRouter');if(er)er.value=c.router_id||'';
    oM('mEdit');
}
function resetPass(id,cid){document.getElementById('rpId').value=id;document.getElementById('rpCid').textContent=cid;oM('mReset');}
</script>
<?php endPage();?>
