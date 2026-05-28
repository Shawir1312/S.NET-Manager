<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';
$genieServers = $db->query("SELECT * FROM genie_config ORDER BY id ASC")->fetchAll();
$genie=GenieACS::fromDB();

if(isset($_GET['act'])&&$_GET['act']==='fetch_wifi'){
    header('Content-Type: application/json');
    $devId=trim($_GET['dev_id']??''); $genieId=(int)($_GET['genie_id']??0);
    $g = $genieId ? GenieACS::fromDB($genieId) : $genie;
    if(!$devId||!$g){echo json_encode(['ok'=>false,'msg'=>'Parameter salah']);exit;}
    $g->refresh($devId);usleep(400000);
    $dev=$g->getDevice($devId);
    if(!$dev){echo json_encode(['ok'=>false,'msg'=>'Perangkat tidak ditemukan']);exit;}
    echo json_encode(['ok'=>true,'wifi'=>$g->getWifi($dev),'info'=>$g->getInfo($dev)]);exit;
}

if(isset($_GET['act'])&&$_GET['act']==='search_device'){
    header('Content-Type: application/json');
    $serial=trim($_GET['serial']??'');$custId=(int)($_GET['cust_id']??0);
    $genieId=(int)($_GET['genie_id']??0);
    $g = $genieId ? GenieACS::fromDB($genieId) : $genie;
    if(!$serial||!$g){echo json_encode(['ok'=>false,'msg'=>'Parameter salah']);exit;}
    $devs=$g->searchDevices($serial);
    if(empty($devs)){echo json_encode(['ok'=>false,'msg'=>'Tidak ditemukan: '.$serial]);exit;}
    $devId=$devs[0]['_id']??'';
    if($devId&&$custId){$db->prepare("UPDATE customers SET genie_device_id=?,genie_id=?,updated_at=NOW() WHERE id=?")->execute([$devId,$genieId,$custId]);}
    echo json_encode(['ok'=>true,'device_id'=>$devId]);exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    $act=$_POST['action']??'';
    if($act==='set_wifi'){
        $devId=trim($_POST['genie_device_id']??'');$custId=(int)($_POST['customer_id']??0);
        $genieId=(int)($_POST['genie_id']??0);
        $g = $genieId ? GenieACS::fromDB($genieId) : $genie;
        // Satu SSID & password berlaku untuk 2.4G dan 5G sekaligus
        $ssid=trim($_POST['ssid']??'');$pass=trim($_POST['pass']??'');
        if(!$devId){$msg='❌ Device ACS belum terhubung.';$mtype='err';}
        elseif(!$g){$msg='❌ GenieACS tidak terhubung!';$mtype='err';}
        else{
            $dev=$g->getDevice($devId);
            if(!$dev){$msg='❌ Perangkat tidak ditemukan!';$mtype='err';}
            else{
                $ok=$g->setWifi($devId,$dev,($ssid?:null),($pass?:null),($ssid?:null),($pass?:null));
                $msg=$ok?'✅ WiFi berhasil diubah! ONT akan restart sebentar.':'❌ Gagal: '.$g->error;
                $mtype=$ok?'ok':'err';
                if($ok) auditLog('teknisi_set_wifi',"cust:$custId dev:$devId ssid:$ssid");
            }
        }
    }
    if($act==='edit_name'){
        $id=(int)($_POST['customer_id']??0);
        $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');
        if(!$name){$msg='Nama tidak boleh kosong!';$mtype='err';}
        else{$db->prepare("UPDATE customers SET full_name=?,phone=?,updated_at=NOW() WHERE id=?")->execute([$name,$phone,$id]);$msg='✅ Data berhasil diupdate!';}
    }
    if($act==='portal_cred'){
        $id=(int)($_POST['customer_id']??0);
        $newpass=trim($_POST['new_pass']??'');
        $newcid=trim($_POST['new_login_id']??'');
        $errors=[];
        // Edit Login ID jika diisi
        if($newcid!==''){
            // Cek duplikat
            $ck=$db->prepare("SELECT id FROM customers WHERE customer_id=? AND id!=? LIMIT 1");
            $ck->execute([$newcid,$id]);
            if($ck->fetchColumn()){$errors[]='Login ID sudah digunakan pelanggan lain!';}
            else{
                $db->prepare("UPDATE customers SET customer_id=?,updated_at=NOW() WHERE id=?")->execute([$newcid,$id]);
                auditLog('teknisi_edit_login_id',"cust:$id → $newcid");
            }
        }
        // Reset password jika diisi
        if($newpass!==''){
            if(strlen($newpass)<4){$errors[]='Password minimal 4 karakter!';}
            else{
                $db->prepare("UPDATE customers SET password=?,plain_password=?,updated_at=NOW() WHERE id=?")
                  ->execute([password_hash($newpass,PASSWORD_DEFAULT),$newpass,$id]);
                auditLog('teknisi_reset_portal_pass',"cust:$id");
            }
        }
        if($errors){$msg='❌ '.implode(' ',$errors);$mtype='err';}
        elseif($newcid===''&&$newpass===''){$msg='⚠️ Tidak ada perubahan.';$mtype='wrn';}
        else{$msg='✅ Kredensial portal berhasil diperbarui!';}
    }
}

$q=trim($_GET['q']??'');$wh=['1=1'];$pr=[];
if($q){$wh[]='(c.full_name LIKE ? OR c.customer_id LIKE ? OR c.phone LIKE ? OR c.device_serial LIKE ?)';$s="%$q%";$pr=[$s,$s,$s,$s];}
// Cek kolom plain_password, auto-migrasi jika belum ada
try{$db->query("SELECT plain_password FROM customers LIMIT 1");$hasPW=true;}catch(Exception $e){$hasPW=false;}
if(!$hasPW){try{$db->exec("ALTER TABLE customers ADD COLUMN plain_password VARCHAR(255) DEFAULT '' COMMENT 'Password plaintext untuk referensi teknisi'");$hasPW=true;}catch(Exception $e){}}
// Auto-migrasi kolom genie_id di tabel customers
try{$db->query("SELECT genie_id FROM customers LIMIT 1");}catch(Exception $e){try{$db->exec("ALTER TABLE customers ADD COLUMN genie_id INT NULL DEFAULT NULL");}catch(Exception $e2){}}
// Auto-migrasi tabel genie_config jika belum ada
try{$db->query("SELECT id FROM genie_config LIMIT 1");}catch(Exception $e){try{$db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,url VARCHAR(255) NOT NULL,username VARCHAR(100) DEFAULT '',password VARCHAR(100) DEFAULT '',is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");}catch(Exception $e2){}}
$stmt=$db->prepare("SELECT c.id,c.customer_id,c.full_name,c.phone,c.address,c.device_brand,c.device_model,c.device_serial,c.genie_device_id,c.genie_id,c.is_active,r.name rn".($hasPW?',c.plain_password':'')."
  FROM customers c LEFT JOIN routers r ON c.router_id=r.id WHERE ".implode(' AND ',$wh)." ORDER BY c.full_name ASC");
$stmt->execute($pr);$custs=$stmt->fetchAll();

header('Cache-Control: no-store,no-cache,must-revalidate');
startPage('Portal Teknisi');
?>
<style>
/* ── Base ── */
body{font-weight:500}
.ph-t{font-size:1.4rem;font-weight:900}
.ph-s{font-size:.85rem;font-weight:600;color:var(--g400)}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Month/filter tab buttons (used inside laporan detail) ── */
.tk-mth{padding:5px 12px;border-radius:20px;border:1.5px solid var(--g200);background:#fff;font-size:.74rem;font-weight:700;cursor:pointer;color:var(--g600);transition:.2s;font-family:inherit}
.tk-mth.active,.tk-mth:hover{background:var(--blue);color:#fff;border-color:var(--blue)}

/* ── Search ── */
.srch-wrap{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.srch-wrap>div{flex:1;min-width:180px}

/* ── Cards daftar pelanggan ── */
.clist{display:flex;flex-direction:column;gap:10px;padding:16px}
.ccard{background:#fff;border:1.5px solid var(--g200);border-radius:14px;padding:14px 16px;display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;transition:.2s;box-shadow:0 1px 4px rgba(27,63,166,.05)}
.ccard:hover{border-color:var(--blue);box-shadow:0 4px 16px rgba(27,63,166,.1)}
.ccard-main{min-width:0}
.cname{font-size:1rem;font-weight:800;color:var(--g900);margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cmeta{display:flex;flex-wrap:wrap;gap:5px;align-items:center;font-size:.78rem;font-weight:600;color:var(--g500)}
.cmeta .sep{color:var(--g200)}
.cid{font-family:'JetBrains Mono',monospace;font-size:.73rem;color:var(--blue-d);background:var(--g100);padding:1px 7px;border-radius:6px;font-weight:700}
.caksi{display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end}
.caksi .btn{font-size:.78rem;padding:7px 13px;font-weight:700}
.cempty{text-align:center;padding:40px 20px;color:var(--g400);font-size:.9rem;font-weight:600}

/* ── Status badges ── */
.sbdg{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.3px}
.son{background:#DCFCE7;color:#15803D}
.soff{background:#FEE2E2;color:var(--red)}
.swrn{background:#FEF3C7;color:#92400E}

/* ── WiFi Modal ── */
.wband{background:var(--g50);border-radius:12px;padding:14px;margin-bottom:12px;border:1.5px solid var(--g200)}
.wband-hd{font-weight:800;font-size:.85rem;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.pwd-rel{position:relative}
.pwd-rel input{padding-right:38px}
.pwd-copy-btn{position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:.85rem;color:var(--g400);padding:2px}

/* ── Mobile ── */
@media(max-width:640px){
  .main{padding:12px}
  .ccard{padding:12px;border-radius:12px}
  .cname{font-size:.95rem}
  .caksi{width:100%;margin-top:4px}
  .caksi .btn{flex:1;justify-content:center}
  .ccard{grid-template-columns:1fr;gap:6px}
  .md{margin:8px;border-radius:12px;max-height:96vh}
  .mb{padding:14px}
  .mf{padding:10px 14px;flex-wrap:wrap}
  .mf .btn{flex:1;justify-content:center}
  .frow2{grid-template-columns:1fr!important}
}
@media(max-width:400px){
  .ph-t{font-size:1.1rem}
  .srch-wrap{flex-direction:column}
  .srch-wrap>div{width:100%}
}

/* ── Laporan Revenue ── */
.lp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-bottom:14px}
.lp-stat{background:#fff;border:1.5px solid var(--g200);border-radius:12px;padding:12px 14px;text-align:center;transition:.2s}
.lp-stat-lbl{font-size:.72rem;font-weight:700;color:var(--g500);margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px}
.lp-stat-val{font-size:.92rem;font-weight:900;color:var(--g900);margin-bottom:2px;word-break:break-all}
.lp-stat-sub{font-size:.68rem;color:var(--g400);font-weight:600}
.rcard{background:#fff;border:1.5px solid var(--g200);border-radius:12px;padding:12px 14px;margin-bottom:8px;cursor:pointer;transition:.2s;display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center}
.rcard:hover{border-color:var(--blue);box-shadow:0 3px 12px rgba(27,63,166,.1)}
.rcard-name{font-weight:800;font-size:.88rem;color:var(--g900);margin-bottom:3px}
.rcard-meta{font-size:.73rem;color:var(--g400);font-weight:600}
.rcard-rev{text-align:right}
.rcard-daily{font-size:.75rem;font-weight:700;color:var(--orange)}
.rcard-monthly{font-size:.7rem;font-weight:600;color:var(--purple)}
.rcard-off{opacity:.55}
.rcard-status{display:inline-flex;align-items:center;gap:3px;font-size:.65rem;font-weight:800;padding:2px 7px;border-radius:10px}
.rs-on{background:#DCFCE7;color:#15803D}
.rs-off{background:#FEE2E2;color:var(--red)}
.lp-detail{background:var(--g50);border:1.5px solid var(--g200);border-radius:12px;padding:14px;margin-top:10px;display:none}
.tk-mth-btn{padding:5px 12px;border-radius:20px;border:1.5px solid var(--g200);background:#fff;font-size:.74rem;font-weight:700;cursor:pointer;color:var(--g600);transition:.2s;font-family:inherit}
.tk-mth-btn.on,.tk-mth-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
@media(max-width:600px){
  .lp-grid{grid-template-columns:1fr 1fr}
  .rcard{grid-template-columns:1fr}
  .rcard-rev{text-align:left}
}
/* ── WiFi gabungan grid ── */
.wifi-grid{display:grid;grid-template-columns:1fr 1px 1fr;gap:0 16px;margin-top:10px}
.wifi-divider{background:var(--g200);width:1px;align-self:stretch}
.wifi-band-label{font-size:.8rem;font-weight:900;text-transform:uppercase;letter-spacing:.8px;margin-bottom:10px;padding-bottom:6px;border-bottom:2px solid var(--g200)}
.wifi-col{padding:0 2px}
@media(max-width:560px){
  .wifi-grid{grid-template-columns:1fr;gap:14px 0}
  .wifi-divider{height:1px;width:100%}
  .wifi-band-label{border-bottom:none;margin-bottom:6px}
}
</style>

<?php $u=adminUser();?>
<div class="ph">
  <div>
    <div class="ph-t">🔧 Portal Teknisi</div>
    <div class="ph-s">Manajemen & Konfigurasi WiFi ONT Pelanggan</div>
  </div>
</div>
<!-- Welcome card teknisi (tampil di mobile & desktop) -->
<div style="background:linear-gradient(135deg,var(--blue-d),var(--blue));border-radius:14px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;color:#fff;box-shadow:0 4px 16px rgba(27,63,166,.25)">
  <div style="width:44px;height:44px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.4rem;flex-shrink:0">👷</div>
  <div>
    <div style="font-weight:900;font-size:1rem"><?=h($u['full_name']??'Teknisi')?></div>
    <div style="font-size:.78rem;opacity:.85;font-weight:600">Teknisi · Sesi aktif sekarang</div>
  </div>
  <div style="margin-left:auto;display:flex;flex-direction:column;align-items:flex-end;gap:6px">
    <div style="text-align:right;font-size:.75rem;opacity:.8;font-weight:600"><?=date('d M Y')?><br><?=date('H:i')?> WIT</div>
    <!-- <button onclick="openLaporan()" class="btn" style="background:rgba(255,255,255,.22);color:#fff;border:1.5px solid rgba(255,255,255,.4);font-size:.76rem;font-weight:800;padding:5px 12px;border-radius:8px;cursor:pointer">📈 Laporan Penjualan</button> -->
  </div>
</div> 

<?php if($msg):?><div class="alert a<?=$mtype?>" style="font-weight:700"><?=$msg?></div><?php endif;?>
<?php if(empty($genieServers)):?><div class="alert awrn" style="font-weight:700">⚠️ <strong>GenieACS tidak terhubung.</strong> Fitur konfigurasi WiFi tidak tersedia.</div><?php endif;?>

<div class="card">
  <div class="cb" style="padding:14px 16px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
      <div style="font-weight:900;font-size:1.05rem;color:var(--blue-d)">🔍 Cari & Kelola Pelanggan</div>
      <a href="/admin/pppoe.php" class="btn bp" style="font-weight:800;border-radius:20px;padding:8px 16px;box-shadow:0 4px 10px rgba(27,63,166,.2)">➕ Tambah Pelanggan Rumahan</a>
    </div>
    <form method="GET" class="srch-wrap">
      <div>
        <label class="fl">🔍 Cari Pelanggan</label>
        <input type="text" name="q" class="fc" placeholder="Nama, ID, No. HP, Serial..." value="<?=h($q)?>" autofocus>
      </div>
      <button type="submit" class="btn bp" style="font-weight:800">Cari</button>
      <a href="/admin/teknisi_portal.php" class="btn bo" style="font-weight:700">Reset</a>
    </form>
  </div>
</div>

<div class="card">
  <div class="ch">
    <div class="ct" style="font-weight:900;font-size:.95rem">👥 Daftar Pelanggan <span style="color:var(--blue)">(<?=count($custs)?>)</span></div>
  </div>
  <?php if(empty($custs)):?>
  <div class="cempty">📋 Tidak ada pelanggan ditemukan<?=$q?' untuk pencarian "'.h($q).'"':''?>.</div>
  <?php else:?>
  <div class="clist">
  <?php foreach($custs as $c):
    $hasACS=!empty($c['genie_device_id']);
    $sn=(string)($c['device_serial']??'');
    $di=(int)$c['id'];
    $dcid=h($c['customer_id']??'');
    $dname=h($c['full_name']??'');
    $dphone=h($c['phone']??'');
    $dbrand=h($c['device_brand']??'');
    $dmodel=h($c['device_model']??'');
    $dserial=h($sn);
    $dgenie=h($c['genie_device_id']??'');
    $dgenie_id=h($c['genie_id']??'0');
  ?>
  <div class="ccard">
    <div class="ccard-main">
      <div class="cname"><?=h($c['full_name']??'')?></div>
      <div class="cmeta">
        <span class="cid"><?=$dcid?></span>
        <?php if($c['phone']??''):?><span class="sep">|</span><span>📞 <?=h($c['phone'])?></span><?php endif;?>
        <?php if($sn):?><span class="sep">|</span><span>SN: <code style="font-size:.7rem"><?=$dserial?></code></span><?php endif;?>
        <span class="sep">|</span>
        <?php if($hasACS):?>
          <span class="sbdg son">✅ ACS Link</span>
        <?php elseif($sn):?>
          <span class="sbdg swrn">⚠️ Belum Link</span>
        <?php else:?>
          <span class="sbdg soff">❌ No SN</span>
        <?php endif;?>
        <span class="sep">|</span>
        <?php $actcls=$c['is_active']?'son':'soff'; $actlbl=$c['is_active']?'Aktif':'Non-Aktif';?>
        <span class="sbdg <?=$actcls?>"><?=$actlbl?></span>
      </div>
    </div>
    <div class="caksi">
      <?php if(!empty($genieServers)):?>
      <button class="btn bp bsm btn-wifi"
        data-id="<?=$di?>" data-cid="<?=$dcid?>" data-name="<?=$dname?>"
        data-phone="<?=$dphone?>" data-brand="<?=$dbrand?>" data-model="<?=$dmodel?>"
        data-serial="<?=$dserial?>" data-genie="<?=$dgenie?>" data-genie_id="<?=$dgenie_id?>">📶 WiFi</button>
      <?php else:?>
      <button class="btn bo bsm" disabled style="opacity:.4">📶 WiFi</button>
      <?php endif;?>
      <button class="btn bo bsm btn-edit"
        data-id="<?=$di?>" data-name="<?=$dname?>" data-phone="<?=$dphone?>">✏️ Edit</button>
      <button class="btn bo bsm btn-cred" title="ID &amp; Password Portal Pelanggan"
        data-id="<?=$di?>" data-cid="<?=$dcid?>" data-name="<?=$dname?>"
        data-pw="<?=h($c['plain_password']??'')?>">🔑 Login</button>
    </div>
  </div>
  <?php endforeach;?>
  </div>
  <?php endif;?>
</div>

<!-- MODAL WiFi -->
<div class="mo" id="mWifi"><div class="md md-lg">
<div class="mh">
  <div class="mt" style="font-weight:900">📶 Konfigurasi WiFi ONT</div>
  <button class="mx" onclick="cM('mWifi')">✕</button>
</div>
<form method="POST" id="frmWifi"><div class="mb">
<?=csrfField()?>
<input type="hidden" name="action" value="set_wifi">
<input type="hidden" name="customer_id" id="wCustId">
<input type="hidden" name="genie_device_id" id="wDevId"><input type="hidden" name="genie_id" id="wGenieId">

<!-- Info -->
<div style="background:linear-gradient(135deg,#EFF6FF,#F8FAFF);border:1.5px solid #BFDBFE;border-radius:12px;padding:12px 14px;margin-bottom:14px">
  <div style="font-weight:900;font-size:.8rem;color:var(--blue-d);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">📡 Info Perangkat</div>
  <div id="wInfoGrid" style="display:grid;grid-template-columns:1fr 1fr;gap:5px;font-size:.82rem;font-weight:600;color:var(--g700)"></div>
</div>

<div id="wACSStatus" style="margin-bottom:10px"></div>
<div id="wPrefillBar" style="display:none;background:#EFF6FF;border-radius:8px;padding:8px 12px;margin-bottom:10px;border:1px solid #BFDBFE;font-size:.82rem;font-weight:700;color:var(--blue-d);align-items:center;gap:8px">
  <span>⏳</span><span>Mengambil data WiFi dari ONT...</span>
</div>

<!-- Form WiFi (1 SSID + 1 Password untuk 2.4G & 5G) -->
<div id="wPanel" style="display:none">
  <div class="wband">
    <div class="wband-hd" style="justify-content:space-between">
      <span style="font-weight:900;font-size:.92rem;color:var(--g900)">📶 Konfigurasi WiFi</span>
      <span id="wBadge" style="display:none;font-size:.62rem;background:#DCFCE7;color:#15803D;padding:2px 10px;border-radius:10px;font-weight:800">✅ Data dari ONT</span>
    </div>
    <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap">
      <span style="background:#DBEAFE;color:#1D4ED8;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:800">📡 2.4 GHz</span>
      <span style="color:var(--g400);font-size:.75rem;align-self:center">+</span>
      <span style="background:#F3E8FF;color:var(--purple);padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:800">📡 5 GHz</span>
      <span style="color:var(--g500);font-size:.72rem;font-weight:700;align-self:center">— Berlaku untuk kedua band</span>
    </div>
    <div class="frow2">
      <div class="fg"><label class="fl">SSID (Nama WiFi)</label>
        <input type="text" name="ssid" id="wSSID" class="fc" placeholder="Nama WiFi baru..." maxlength="32" style="font-weight:700;font-size:.9rem">
      </div>
      <div class="fg"><label class="fl">Password WiFi</label>
        <div class="pwd-rel">
          <input type="text" name="pass" id="wPASS" class="fc" placeholder="Min 8 karakter" maxlength="63" style="font-weight:700;font-size:.9rem">
          <button type="button" class="pwd-copy-btn" onclick="cpPwd('wPASS')" title="Copy">📋</button>
        </div>
      </div>
    </div>
  </div>
  <div class="alert ainf" style="margin:0;font-weight:700">ℹ️ SSID & Password yang diisi akan diterapkan ke <strong>2.4 GHz dan 5 GHz</strong> sekaligus. Kosongkan jika tidak ingin diubah.</div>
</div>

<!-- Belum link ACS -->
<div id="wNoACS" style="display:none">
  <div class="alert awrn" style="flex-direction:column;gap:8px;font-weight:700">
    <div>⚠️ <strong>Perangkat belum terhubung ke ACS.</strong></div>
    <div style="font-size:.82rem">Klik tombol di bawah untuk mencari perangkat berdasarkan serial number.</div>
    <button type="button" id="btnCariACS" class="btn bw" onclick="cariACS()" style="align-self:flex-start;font-weight:800">🔍 Cari di ACS</button>
    <div id="srchResult" style="font-size:.82rem;font-weight:700"></div>
  </div>
</div>

<!-- Tidak ada serial -->
<div id="wNoSN" style="display:none">
  <div class="alert aerr" style="font-weight:700">❌ Pelanggan belum memiliki serial number ONT.</div>
</div>

</div>
<div class="mf">
  <button type="button" class="btn bo" onclick="cM('mWifi')" style="font-weight:700">Batal</button>
  <button type="button" class="btn bo" id="btnRef" onclick="doFetch()" style="display:none;font-weight:700">🔄 Refresh</button>
  <button type="submit" class="btn bp" id="btnKirim" style="display:none;font-weight:800" onclick="return validateWifi()">🚀 Kirim ke ONT</button>
</div>
</form></div></div>

<!-- MODAL Edit -->
<div class="mo" id="mEdit"><div class="md">
<div class="mh"><div class="mt" style="font-weight:900">✏️ Edit Data Pelanggan</div><button class="mx" onclick="cM('mEdit')">✕</button></div>
<form method="POST"><div class="mb">
<?=csrfField()?><input type="hidden" name="action" value="edit_name">
<input type="hidden" name="customer_id" id="eCustId">
<div class="fg"><label class="fl">Nama Lengkap *</label><input type="text" name="full_name" id="eName" class="fc" required style="font-weight:700"></div>
<div class="fg"><label class="fl">No. HP</label><input type="text" name="phone" id="ePhone" class="fc" style="font-weight:700"></div>
<div class="alert ainf" style="margin:0;font-weight:700">ℹ️ Teknisi hanya dapat mengubah nama dan nomor HP.</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEdit')" style="font-weight:700">Batal</button><button type="submit" class="btn bp" style="font-weight:800">💾 Simpan</button></div>
</form></div></div>

<!-- MODAL Login Portal -->
<div class="mo" id="mCred"><div class="md">
<div class="mh"><div class="mt" style="font-weight:900">🔑 Kredensial Portal Pelanggan</div><button class="mx" onclick="cM('mCred')">✕</button></div>
<div class="mb">

  <!-- Info Login Saat Ini -->
  <div style="background:linear-gradient(135deg,#EFF6FF,#DBEAFE);border:1.5px solid #93C5FD;border-radius:12px;padding:14px 16px;margin-bottom:16px">
    <div style="font-weight:900;font-size:.75rem;color:var(--blue-d);margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px">📋 Info Login Saat Ini</div>
    <div style="display:grid;grid-template-columns:auto 1fr;gap:8px 14px;font-size:.84rem;align-items:center">
      <span style="font-weight:700;color:var(--g600);white-space:nowrap">Pelanggan:</span>
      <span id="crName" style="font-weight:800;color:var(--g900)"></span>
      <span style="font-weight:700;color:var(--g600);white-space:nowrap">Login ID:</span>
      <span style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
        <code id="crCid" style="font-size:.88rem;font-weight:900;color:var(--blue-d);background:#fff;border:1.5px solid #BFDBFE;padding:3px 10px;border-radius:8px"></code>
        <button type="button" onclick="cpTxt('crCid')" title="Salin Login ID" style="background:none;border:none;cursor:pointer;font-size:.82rem;color:var(--g400)" title="Salin">📋</button>
      </span>
      <span style="font-weight:700;color:var(--g600);white-space:nowrap">Password:</span>
      <span style="display:flex;align-items:center;gap:7px;flex-wrap:wrap">
        <code id="crPwShow" style="font-size:.88rem;font-weight:900;color:var(--g900);background:#fff;border:1.5px solid var(--g200);padding:3px 10px;border-radius:8px;min-width:80px"></code>
        <button type="button" id="btnTogglePw" onclick="togglePw()" title="Tampilkan/Sembunyikan" style="background:none;border:none;cursor:pointer;font-size:.82rem;color:var(--g400)">👁️</button>
        <button type="button" onclick="cpPwShow()" title="Salin Password" style="background:none;border:none;cursor:pointer;font-size:.82rem;color:var(--g400)">📋</button>
      </span>
      <span style="font-weight:700;color:var(--g600);white-space:nowrap">URL Portal:</span>
      <a href="/portal/login.php" target="_blank" style="font-size:.78rem;color:var(--blue-d);font-weight:700;text-decoration:none">/portal/login.php ↗</a>
    </div>
  </div>

  <form method="POST" id="frmCred" onsubmit="return confirmReset()">
    <?=csrfField()?>
    <input type="hidden" name="action" value="portal_cred">
    <input type="hidden" name="customer_id" id="crCustId">

    <!-- Edit Login ID -->
    <div style="background:var(--g50);border:1.5px solid var(--g200);border-radius:12px;padding:14px 16px;margin-bottom:12px">
      <div style="font-weight:900;font-size:.8rem;color:var(--g700);margin-bottom:10px">✏️ Ubah Login ID</div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">Login ID Baru <span style="font-size:.65rem;color:var(--g400);font-weight:600;text-transform:none;letter-spacing:0">(Kosongkan jika tidak ingin diubah)</span></label>
        <input type="text" name="new_login_id" id="crNewCid" class="fc" placeholder="Contoh: SNET-0001" style="font-weight:700;font-size:.9rem;letter-spacing:.3px">
        <div class="fhint">Login ID harus unik. Pelanggan akan login menggunakan ID ini.</div>
      </div>
    </div>

    <!-- Reset Password -->
    <div style="background:var(--g50);border:1.5px solid var(--g200);border-radius:12px;padding:14px 16px;margin-bottom:14px">
      <div style="font-weight:900;font-size:.8rem;color:var(--g700);margin-bottom:10px">🔐 Ubah Password</div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">Password Baru <span style="font-size:.65rem;color:var(--g400);font-weight:600;text-transform:none;letter-spacing:0">(Kosongkan jika tidak ingin diubah)</span></label>
        <div style="display:flex;gap:8px">
          <div style="flex:1;position:relative">
            <input type="text" name="new_pass" id="crPass" class="fc" placeholder="Min 4 karakter" style="font-weight:700;font-size:.9rem;padding-right:38px">
            <button type="button" onclick="genPass()" title="Generate password acak" style="position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:.88rem;color:var(--blue)">✨</button>
          </div>
          <button type="button" class="btn bo" onclick="cpField('crPass')" title="Salin" style="padding:8px 12px;flex-shrink:0">📋</button>
        </div>
        <div class="fhint">Pelanggan harus login ulang setelah password diubah. Password akan tersimpan untuk referensi teknisi.</div>
      </div>
    </div>

    <div style="display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap">
      <button type="button" class="btn bo" onclick="cM('mCred')" style="font-weight:700">Batal</button>
      <button type="submit" class="btn bp" style="font-weight:800">💾 Simpan Perubahan</button>
    </div>
  </form>
</div>
</div></div>

<!-- MODAL Laporan Penjualan Multi-Router -->
<div class="mo" id="mLaporan"><div class="md md-lg" style="max-width:720px">
<div class="mh">
  <div class="mt" style="font-weight:900">📈 Laporan Penjualan — Semua Router</div>
  <button class="mx" onclick="cM('mLaporan')">✕</button>
</div>
<div class="mb" id="mLaporanBody">
  <div style="text-align:center;padding:30px;color:var(--g400)">
    <span style="font-size:1.8rem;display:block;margin-bottom:8px">⏳</span>
    <span style="font-weight:700">Memuat data dari semua router...</span>
  </div>
</div>
<div class="mf">
  <button type="button" class="btn bo" onclick="cM('mLaporan')" style="font-weight:700">Tutup</button>
  <button type="button" class="btn bp" onclick="openLaporan()" style="font-weight:800" id="btnRefLaporan">🔄 Refresh</button>
</div>
</div></div>

<script>
let wCust=null;
document.addEventListener('click',function(e){
  const bw=e.target.closest('.btn-wifi');
  if(bw){openWifi({id:+bw.dataset.id,customer_id:bw.dataset.cid,full_name:bw.dataset.name,phone:bw.dataset.phone,device_brand:bw.dataset.brand,device_model:bw.dataset.model,device_serial:bw.dataset.serial,genie_device_id:bw.dataset.genie,genie_id:bw.dataset.genie_id});return;}
  const be=e.target.closest('.btn-edit');
  if(be){openEdit({id:+be.dataset.id,full_name:be.dataset.name,phone:be.dataset.phone});return;}
  const bc=e.target.closest('.btn-cred');
  if(bc){openCred({id:+bc.dataset.id,customer_id:bc.dataset.cid,full_name:bc.dataset.name,pw:bc.dataset.pw||''});return;}
});

function openWifi(c){
  wCust=c;
  document.getElementById('wCustId').value=c.id;
  document.getElementById('wDevId').value=c.genie_device_id||'';
  document.getElementById('wGenieId').value=c.genie_id||'0';
  ['wPanel','wNoACS','wNoSN'].forEach(x=>el(x).style.display='none');
  ['btnKirim','btnRef'].forEach(x=>el(x).style.display='none');
  el('wACSStatus').innerHTML='';el('wPrefillBar').style.display='none';
  el('srchResult').innerHTML='';el('wBadge').style.display='none';
  el('wSSID').value='';el('wPASS').value='';
  el('wInfoGrid').innerHTML=`
    <div><b>Nama:</b> ${x(c.full_name)}</div>
    <div><b>ID:</b> ${x(c.customer_id)}</div>
    <div><b>Brand:</b> ${x(c.device_brand)}</div>
    <div><b>Serial:</b> <code style="font-size:.7rem">${x(c.device_serial||'-')}</code></div>
    <div><b>ACS ID:</b> <span style="font-size:.72rem;color:var(--blue-d)">${x(c.genie_device_id||'Belum link')}</span></div>`;
  oM('mWifi');
  if(c.genie_device_id){showPanel();doFetch();}
  else if(c.device_serial){
    el('wNoACS').style.display='block';
    if(el('wServerSel')) el('wServerSel').style.display='block';
  }
  else{el('wNoSN').style.display='block';}
}

function showPanel(){
  el('wPanel').style.display='block';
  el('btnKirim').style.display='';
  el('btnRef').style.display='';
}

function doFetch(){
  const devId=el('wDevId').value; const genieId=el('wGenieId').value;
  if(!devId)return;
  showPanel();
  el('wPrefillBar').style.display='flex';
  setSt('info','⏳ Mengambil data WiFi dari ONT...');
  fetch(`/admin/teknisi_portal.php?act=fetch_wifi&dev_id=${encodeURIComponent(devId)}&genie_id=${encodeURIComponent(genieId)}`)
    .then(r=>r.json()).then(d=>{
      el('wPrefillBar').style.display='none';
      if(d.ok){
        const w=d.wifi,inf=d.info;
        // Pre-fill dari 2.4G (prioritas), fallback ke 5G
        if(w.ssid_24||w.ssid_5g) el('wSSID').value=w.ssid_24||w.ssid_5g;
        if(w.pass_24||w.pass_5g) el('wPASS').value=w.pass_24||w.pass_5g;
        el('wBadge').style.display='';
        setSt('ok',`${inf.online?'🟢':'🔴'} ONT ${inf.online?'Online':'Offline'} — ${x(inf.last_seen||'-')} | WAN: ${x(inf.ip_wan||'-')}`);
      } else {setSt('wrn',`⚠️ Pre-fill gagal: ${x(d.msg)}. Isi manual.`);}
    }).catch(e=>{el('wPrefillBar').style.display='none';setSt('wrn',`⚠️ ${x(e.message)}. Isi manual.`);});
}

function cariACS(){
  const serial=wCust?.device_serial,custId=wCust?.id;
  if(!serial){el('srchResult').innerHTML='<span style="color:var(--red)">Serial tidak ada!</span>';return;}
  const selGenie=el('wSelGenieId');
  const genieIdToSearch = selGenie ? selGenie.value : '0';
  const btn=el('btnCariACS');btn.disabled=true;btn.textContent='⏳ Mencari...';
  el('srchResult').innerHTML=`<span style="color:var(--g400)">Mencari <b>${x(serial)}</b>...</span>`;
  fetch(`/admin/teknisi_portal.php?act=search_device&serial=${encodeURIComponent(serial)}&cust_id=${custId}&genie_id=${encodeURIComponent(genieIdToSearch)}`)
    .then(r=>r.json()).then(d=>{
      btn.disabled=false;btn.textContent='🔍 Cari di ACS';
      if(d.ok){
        el('wDevId').value=d.device_id;
        el('wGenieId').value=genieIdToSearch;
        wCust={...wCust,genie_device_id:d.device_id,genie_id:genieIdToSearch};
        el('srchResult').innerHTML=`<span style="color:var(--green)">✅ Ditemukan: <code>${x(d.device_id)}</code></span>`;
        el('wNoACS').style.display='none';
        if(el('wServerSel')) el('wServerSel').style.display='none';
        showPanel();doFetch();
      } else {el('srchResult').innerHTML=`<span style="color:var(--red)">❌ ${x(d.msg)}</span>`;}
    }).catch(e=>{btn.disabled=false;btn.textContent='🔍 Cari di ACS';el('srchResult').innerHTML=`<span style="color:var(--red)">❌ ${x(e.message)}</span>`;});
}

function setSt(t,m){
  const cl=t==='ok'?'aok':t==='err'?'aerr':t==='wrn'?'awrn':'ainf';
  el('wACSStatus').innerHTML=`<div class="alert ${cl}" style="margin:0;font-weight:700">${m}</div>`;
}
function validateWifi(){
  const ssid=el('wSSID').value.trim(),pass=el('wPASS').value.trim();
  if(!ssid&&!pass){alert('Isi minimal SSID atau Password!');return false;}
  if(pass&&pass.length<8){alert('Password minimal 8 karakter!');return false;}
  return confirm(`Kirim perubahan WiFi ke ONT?\nSSID: ${ssid||'(tidak diubah)'}\nPassword: ${pass?'••••••••':'(tidak diubah)'}\n\nBerlaku untuk 2.4GHz & 5GHz. ONT akan restart.`);
}
function cpPwd(id){const v=el(id).value;if(!v){alert('Field kosong!');return;}navigator.clipboard.writeText(v).then(()=>toast('Password disalin!','success')).catch(()=>{});}
function openEdit(c){el('eCustId').value=c.id;el('eName').value=c.full_name;el('ePhone').value=c.phone||'';oM('mEdit');}
// State password visibility
let pwVisible=false, crRawPw='', crRawPwLabel='••••••••';

function openCred(c){
  el('crCustId').value=c.id;
  el('crName').textContent=c.full_name;
  el('crCid').textContent=c.customer_id;
  el('crNewCid').value='';
  el('crPass').value='';
  // Tampilkan password (jika ada data plaintext)
  crRawPw=c.pw||'';
  pwVisible=false;
  el('crPwShow').textContent=crRawPw?'••••••••':'(tidak tersimpan)';
  el('crPwShow').style.color=crRawPw?'var(--g900)':'var(--g400)';
  el('crPwShow').style.fontStyle=crRawPw?'normal':'italic';
  oM('mCred');
}
function togglePw(){
  if(!crRawPw){toast('Password belum tersimpan. Reset terlebih dahulu.','warning');return;}
  pwVisible=!pwVisible;
  el('crPwShow').textContent=pwVisible?crRawPw:'••••••••';
  el('btnTogglePw').title=pwVisible?'Sembunyikan':'Tampilkan';
  el('btnTogglePw').textContent=pwVisible?'🙈':'👁️';
}
function cpPwShow(){
  if(!crRawPw){toast('Password belum tersimpan.','warning');return;}
  navigator.clipboard.writeText(crRawPw).then(()=>toast('Password disalin!','success')).catch(()=>{});
}
function genPass(){
  const chars='abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789@#!';
  let p='';for(let i=0;i<10;i++)p+=chars[Math.floor(Math.random()*chars.length)];
  el('crPass').value=p;
}
function cpField(id){
  const v=el(id).value;
  if(!v){toast('Field kosong!','warning');return;}
  navigator.clipboard.writeText(v).then(()=>toast('Disalin!','success')).catch(()=>{});
}
function cpTxt(id){
  const t=el(id).textContent||el(id).value;
  navigator.clipboard.writeText(t.trim()).then(()=>toast('Disalin!','success')).catch(()=>{});
}
function confirmReset(){
  const nm=el('crName').textContent;
  const cid=el('crNewCid').value.trim()||el('crCid').textContent;
  const pw=el('crPass').value.trim();
  const newCid=el('crNewCid').value.trim();
  if(!pw&&!newCid){alert('Isi minimal Login ID baru atau Password baru!');return false;}
  if(pw&&pw.length<4){alert('Password minimal 4 karakter!');return false;}
  let info=`Pelanggan: ${nm}`;
  if(newCid) info+=`\nLogin ID baru: ${newCid}`;
  if(pw) info+=`\nPassword baru: ${pw}`;
  return confirm(`Simpan perubahan kredensial portal?\n\n${info}\n\nPelanggan harus login ulang.`);
}
function el(id){return document.getElementById(id);}
function x(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

/* ════ LAPORAN PENJUALAN MULTI-ROUTER ════
   Stage 1: Ringkasan semua router (pilih router)
   Stage 2: Laporan detail per router yang dipilih
══════════════════════════════════════════════ */
function rp(n){return 'Rp '+Number(n).toLocaleString('id-ID');}
let _lpData=null; // cache data all_revenue

function openLaporan(){
  oM('mLaporan');
  _showLoading('Menghubungi semua router MikroTik...','Mungkin butuh 10–30 detik');
  fetch('/admin/teknisi_laporan_ajax.php?action=all_revenue')
    .then(r=>r.json())
    .then(d=>{_lpData=d; renderRouterList(d);})
    .catch(e=>{ el('mLaporanBody').innerHTML=`<div class="alert aerr" style="margin:0">❌ Gagal memuat: ${x(e.message)}</div>`; });
}

function _showLoading(msg,sub){
  el('mLaporanBody').innerHTML=`
    <div style="text-align:center;padding:40px 20px;color:var(--g400)">
      <span style="font-size:2rem;display:inline-block;animation:spin 1s linear infinite">⏳</span>
      <div style="font-weight:700;margin-top:10px">${x(msg)}</div>
      ${sub?`<div style="font-size:.75rem;margin-top:4px;color:var(--g400)">${x(sub)}</div>`:''}
    </div>`;
}

/* ── Stage 1: Daftar Router ── */
function renderRouterList(d){
  if(d.error){el('mLaporanBody').innerHTML=`<div class="alert aerr" style="margin:0">❌ ${x(d.error)}</div>`;return;}
  const routers=d.routers||[];
  const onlineCount=routers.filter(r=>r.online).length;
  let html=`
    <!-- Total aggregate -->
    <div style="background:linear-gradient(135deg,#0F2266,#1B3FA6);border-radius:14px;padding:14px 18px;margin-bottom:14px;color:#fff">
      <div style="font-size:.7rem;font-weight:700;opacity:.7;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📊 Total Semua Router — ${x(d.date||'')}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;text-align:center">
        <div>
          <div style="font-size:.65rem;opacity:.75;font-weight:700">HARI INI</div>
          <div style="font-size:1rem;font-weight:900;color:#FCD34D">${rp(d.total_daily)}</div>
          <div style="font-size:.65rem;opacity:.7">${d.total_daily_count} transaksi</div>
        </div>
        <div>
          <div style="font-size:.65rem;opacity:.75;font-weight:700">BULAN INI</div>
          <div style="font-size:1rem;font-weight:900;color:#A78BFA">${rp(d.total_monthly)}</div>
          <div style="font-size:.65rem;opacity:.7">${d.total_monthly_count} transaksi</div>
        </div>
        <div>
          <div style="font-size:.65rem;opacity:.75;font-weight:700">ROUTER</div>
          <div style="font-size:1rem;font-weight:900">${onlineCount}<span style="font-size:.7rem;opacity:.6">/${routers.length}</span></div>
          <div style="font-size:.65rem;opacity:.7">online</div>
        </div>
      </div>
    </div>
    <!-- Pilih Router -->
    <div style="font-size:.75rem;font-weight:800;color:var(--g600);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🌐 Pilih Router untuk Laporan Detail</div>`;

  if(!routers.length){
    html+=`<div class="alert awrn" style="margin:0">⚠️ Tidak ada router aktif ditemukan.</div>`;
  } else {
    routers.forEach(r=>{
      const online=r.online;
      const badgeCls=online?'rs-on':'rs-off';
      const badgeTxt=online?'🟢 Online':'🔴 Offline';
      const err=(!online&&r.error)?`<div style="font-size:.68rem;color:var(--red);margin-top:2px">⚠️ ${x(r.error)}</div>`:'';
      const clickable=online?`onclick="loadRouterDetail(${r.id},${JSON.stringify(x(r.name))})" style="cursor:pointer"`:
                               `style="opacity:.55;cursor:not-allowed"`;
      html+=`
      <div class="rcard" ${clickable}>
        <div>
          <div class="rcard-name">${x(r.name)} <span class="rcard-status ${badgeCls}">${badgeTxt}</span></div>
          <div class="rcard-meta">${x(r.ip||'')}${err}</div>
        </div>
        <div class="rcard-rev">
          <div class="rcard-daily">📅 ${rp(r.rev_daily)}<span style="font-weight:400;color:var(--g400);font-size:.68rem"> (${r.rev_daily_count} vcr)</span></div>
          <div class="rcard-monthly">📆 ${rp(r.rev_monthly)}<span style="font-weight:400;color:var(--g400);font-size:.68rem"> (${r.rev_monthly_count} vcr)</span></div>
          ${online?`<div style="font-size:.62rem;color:var(--blue-d);font-weight:800;margin-top:3px">Lihat Detail →</div>`:''}
        </div>
      </div>`;
    });
  }
  el('mLaporanBody').innerHTML=html;
}

/* ── Stage 2: Laporan Detail Per Router ── */
let _currentRid=null;
function loadRouterDetail(rid,rname){
  _currentRid=rid;
  _showLoading(`Memuat laporan ${rname||'router'}...`,'');
  _doLoadDetail(rid,'','');
}

function _doLoadDetail(rid,idbl,reseller){
  let url=`/admin/teknisi_laporan_ajax.php?action=report&rid=${rid}`;
  if(idbl) url+=`&idbl=${encodeURIComponent(idbl)}`;
  if(reseller) url+=`&reseller=${encodeURIComponent(reseller)}`;
  fetch(url)
    .then(r=>r.text())
    .then(html=>{
      el('mLaporanBody').innerHTML=`
        <!-- Back button -->
        <div style="margin-bottom:12px">
          <button class="btn bo bsm" onclick="renderRouterList(_lpData)" style="font-weight:800">← Kembali ke Semua Router</button>
        </div>
        <!-- Detail content -->
        <div id="lpDetailContent" style="font-size:.85rem">${html}</div>`;
    })
    .catch(e=>{
      el('mLaporanBody').innerHTML=`
        <div style="margin-bottom:10px">
          <button class="btn bo bsm" onclick="renderRouterList(_lpData)" style="font-weight:800">← Kembali</button>
        </div>
        <div class="alert aerr" style="margin:0">❌ Gagal: ${x(e.message)}</div>`;
    });
}

/* tkRpt: dipanggil dari HTML laporan detail (tombol bulan/filter profil) */
function tkRpt(rid,idbl,reseller){
  const wrap=el('lpDetailContent');
  if(wrap) wrap.innerHTML=`<div style="text-align:center;padding:20px;color:var(--g400)"><span style="animation:spin 1s linear infinite;display:inline-block">⏳</span></div>`;
  let url=`/admin/teknisi_laporan_ajax.php?action=report&rid=${rid}&idbl=${encodeURIComponent(idbl)}`;
  if(reseller) url+=`&reseller=${encodeURIComponent(reseller)}`;
  fetch(url).then(r=>r.text()).then(html=>{
    if(wrap) wrap.innerHTML=html;
  }).catch(e=>{
    if(wrap) wrap.innerHTML=`<div class="alert aerr" style="margin:0">❌ Gagal: ${x(e.message)}</div>`;
  });
}
</script>
<?php endPage();?>
