<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin('operator');
$db=db(); $msg=''; $mtype='ok';

// Auto-migrate
foreach(['cabang','resellers'] as $t){
    try{ $db->query("SELECT id FROM $t LIMIT 1"); }
    catch(Exception $e){
        if($t==='cabang') $db->exec("CREATE TABLE IF NOT EXISTS cabang(id INT AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(150) NOT NULL,keterangan TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        if($t==='resellers') $db->exec("CREATE TABLE IF NOT EXISTS resellers(id INT AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(150) NOT NULL,cabang_id INT DEFAULT 1,persen_keuntungan DECIMAL(5,2) DEFAULT 0,harga_voucher INT DEFAULT 0,catatan TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    }
}
// Pastikan cabang default ada
try{ $db->exec("INSERT IGNORE INTO cabang(id,nama,keterangan)VALUES(1,'Pusat','Cabang default')"); }catch(Exception $e){}
// Pastikan kolom cabang_id ada di resellers
try{ $db->query("SELECT cabang_id FROM resellers LIMIT 1"); }
catch(Exception $e){ try{ $db->exec("ALTER TABLE resellers ADD COLUMN cabang_id INT DEFAULT 1"); }catch(Exception $e2){} }
// Auto-migrasi kolom genie_id di tabel cabang
try{ $db->query("SELECT genie_id FROM cabang LIMIT 1"); }
catch(Exception $e){ try{ $db->exec("ALTER TABLE cabang ADD COLUMN genie_id INT NULL DEFAULT NULL"); }catch(Exception $e2){} }
// Auto-migrasi tabel genie_config jika belum ada
try{ $db->query("SELECT id FROM genie_config LIMIT 1"); }
catch(Exception $e){ try{ $db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) NOT NULL,url VARCHAR(255) NOT NULL,username VARCHAR(100) DEFAULT '',password VARCHAR(100) DEFAULT '',is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"); }catch(Exception $e2){} }

// ── POST Handlers ──
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    $act=$_POST['action']??'';

    // ─ Cabang CRUD ─
    if($act==='add_cabang'){
        $nama=trim($_POST['nama']??'');$ket=trim($_POST['keterangan']??'');
        $genieId=(int)($_POST['genie_id']??0);
        $genieId=($genieId>0)?$genieId:null;
        if(!$nama){$msg='Nama cabang tidak boleh kosong!';$mtype='err';}
        else{
            $db->prepare("INSERT INTO cabang(nama,keterangan,genie_id)VALUES(?,?,?)")->execute([$nama,$ket,$genieId]);
            auditLog('add_cabang',$nama);
            $msg='Cabang baru berhasil ditambah.';
        }
    }
    if($act==='edit_cabang'){
        $id=(int)$_POST['id'];
        $nama=trim($_POST['nama']??'');$ket=trim($_POST['keterangan']??'');$aktif=(int)($_POST['is_active']??1);
        $genieId=(int)($_POST['genie_id']??0);
        $genieId=($genieId>0)?$genieId:null;
        if(!$nama){$msg='Nama cabang tidak boleh kosong!';$mtype='err';}
        else{
            $db->prepare("UPDATE cabang SET nama=?,keterangan=?,is_active=?,genie_id=?,updated_at=NOW() WHERE id=?")->execute([$nama,$ket,$aktif,$genieId,$id]);
            auditLog('edit_cabang',"ID:$id");
            $msg='Data cabang berhasil diperbarui.';
        }
    }
    if($act==='delete_cabang'){
        $id=(int)($_POST['id']??0);
        if($id===1){$msg='Cabang Pusat tidak bisa dihapus!';$mtype='err';}
        else{
            $cek=$db->prepare("SELECT COUNT(*) FROM resellers WHERE cabang_id=?");
            $cek->execute([$id]); $cntRes=(int)$cek->fetchColumn();
            if($cntRes>0){$msg="Tidak bisa hapus cabang yang masih memiliki $cntRes reseller!";$mtype='err';}
            else{
                $c=$db->prepare("SELECT nama FROM cabang WHERE id=?");$c->execute([$id]);$c=$c->fetch();
                if($c){ $db->prepare("DELETE FROM cabang WHERE id=?")->execute([$id]); auditLog('delete_cabang',$c['nama']); $msg='Cabang dihapus.'; }
            }
        }
    }

    // ─ Reseller CRUD ─
    if($act==='add_reseller'){
        $nama=trim($_POST['nama']??'');
        $persen=(float)($_POST['persen_keuntungan']??0);
        $harga=(int)($_POST['harga_voucher']??0);
        $cat=trim($_POST['catatan']??'');
        $cid=(int)($_POST['cabang_id']??1);
        if(!$nama){$msg='Nama reseller tidak boleh kosong!';$mtype='err';}
        elseif($persen<0||$persen>100){$msg='Persentase harus 0–100!';$mtype='err';}
        else{
            $db->prepare("INSERT INTO resellers(nama,cabang_id,persen_keuntungan,harga_voucher,catatan)VALUES(?,?,?,?,?)")->execute([$nama,$cid,$persen,$harga,$cat]);
            auditLog('add_reseller',$nama);
            $msg="✅ Reseller <strong>".h($nama)."</strong> berhasil ditambahkan!";
        }
    }
    if($act==='edit_reseller'){
        $id=(int)($_POST['id']??0);
        $nama=trim($_POST['nama']??'');
        $persen=(float)($_POST['persen_keuntungan']??0);
        $harga=(int)($_POST['harga_voucher']??0);
        $cat=trim($_POST['catatan']??'');
        $aktif=(int)($_POST['is_active']??1);
        $cid=(int)($_POST['cabang_id']??1);
        if(!$nama){$msg='Nama tidak boleh kosong!';$mtype='err';}
        else{
            $db->prepare("UPDATE resellers SET nama=?,cabang_id=?,persen_keuntungan=?,harga_voucher=?,catatan=?,is_active=?,updated_at=NOW() WHERE id=?")->execute([$nama,$cid,$persen,$harga,$cat,$aktif,$id]);
            auditLog('edit_reseller',"ID:$id");
            $msg='✅ Reseller diperbarui!';
        }
    }
    if($act==='delete_reseller'){
        $id=(int)($_POST['id']??0);
        $r=$db->prepare("SELECT nama FROM resellers WHERE id=?");$r->execute([$id]);$r=$r->fetch();
        if($r){ $db->prepare("DELETE FROM resellers WHERE id=?")->execute([$id]); auditLog('delete_reseller',$r['nama']); $msg='Reseller dihapus.'; }
    }
}

// Load data
$cabangs=$db->query("SELECT c.*, g.name AS genie_name FROM cabang c LEFT JOIN genie_config g ON c.genie_id=g.id ORDER BY c.id ASC")->fetchAll();
$resellers=$db->query("SELECT r.*,c.nama AS cabang_nama FROM resellers r LEFT JOIN cabang c ON c.id=r.cabang_id ORDER BY r.cabang_id ASC, r.is_active DESC, r.nama ASC")->fetchAll();
$genieServers=$db->query("SELECT id, name FROM genie_config ORDER BY id ASC")->fetchAll();

// Group resellers by cabang
$byBranch=[];
foreach($resellers as $r) $byBranch[$r['cabang_id']??0][]=$r;

// File arsip info
$lapDir=APP_BASE.'/admin/laporan';
$xlsxFiles=is_dir($lapDir)?glob($lapDir.'/laporan_*.xlsx'):[];
$xlsxCount=count($xlsxFiles);

startPage('Kelola Reseller & Cabang');
?>
<style>
.tab-menu{display:flex;gap:4px;margin-bottom:18px;border-bottom:2px solid var(--g200)}
.tab-btn{padding:9px 18px;border:none;background:none;font-family:'Exo 2',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;color:var(--g400);border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s;border-radius:6px 6px 0 0}
.tab-btn.on{color:var(--blue);border-bottom-color:var(--blue);background:var(--g50)}
.tab-pane{display:none}.tab-pane.on{display:block}
.rs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.rs-card{background:#fff;border:1.5px solid var(--g200);border-radius:14px;padding:16px;transition:.2s;position:relative;overflow:hidden}
.rs-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--blue)}
.rs-card:hover{border-color:var(--blue);box-shadow:0 4px 18px rgba(27,63,166,.1)}
.rs-card.inactive{opacity:.55}.rs-card.inactive::before{background:var(--g400)}
.rs-name{font-weight:900;font-size:1rem;color:var(--g900);margin-bottom:4px}
.rs-pct{font-size:1.5rem;font-weight:900;background:linear-gradient(135deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1}
.rs-acts{display:flex;gap:6px;margin-top:12px;padding-top:10px;border-top:1px solid var(--g100)}
.cab-card{background:#fff;border:1.5px solid var(--g200);border-radius:12px;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;transition:.2s}
.cab-card:hover{border-color:var(--blue);box-shadow:0 2px 12px rgba(27,63,166,.08)}
.branch-hdr{display:flex;align-items:center;gap:10px;margin:20px 0 10px;padding:10px 14px;background:linear-gradient(135deg,var(--g50),#fff);border-radius:10px;border:1px solid var(--g200)}
.branch-lbl{font-weight:900;font-size:.92rem;color:var(--g900);flex:1}
</style>

<div class="ph">
  <div>
    <div class="ph-t">🤝 Kelola Reseller &amp; Cabang</div>
    <div class="ph-s">Manajemen cabang dan reseller per lokasi</div>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php if($xlsxCount>0):?>
    <a href="/admin/laporan_arsip.php" class="btn bpu bsm" style="font-weight:800">📦 Arsip (<?=$xlsxCount?> file)</a>
    <?php endif;?>
    <button class="btn bp" onclick="oM('mAddReseller')">➕ Tambah Reseller</button>
  </div>
</div>

<?php if($msg):?><div class="alert a<?=$mtype?>" style="font-weight:700"><?=$msg?></div><?php endif;?>

<!-- Tab Menu -->
<div class="tab-menu">
  <button class="tab-btn on" onclick="swTab('reseller','main')">🤝 Reseller (<?=count($resellers)?>)</button>
  <button class="tab-btn" onclick="swTab('cabang','main')">📍 Kelola Cabang (<?=count($cabangs)?>)</button>
  <button class="tab-btn" onclick="swTab('arsip','main')">📊 Info Laporan</button>
</div>

<!-- TAB: Reseller -->
<div class="tp on" id="tp-reseller" data-grp="main">
  <div style="margin-bottom:12px;display:flex;justify-content:flex-end">
    <button class="btn bp bsm" onclick="oM('mAddReseller')">➕ Tambah Reseller</button>
  </div>
  <?php if(empty($resellers)):?>
  <div class="empty"><div class="eico">🤝</div>Belum ada reseller. Tambah reseller terlebih dahulu.</div>
  <?php else:?>
  <?php foreach($cabangs as $cab):
    $cabRes=$byBranch[$cab['id']]??[];
    if(empty($cabRes)) continue;
  ?>
  <div class="branch-hdr">
    <span style="font-size:1.1rem">📍</span>
    <span class="branch-lbl"><?=h($cab['nama'])?></span>
    <span class="bdg bblue"><?=count($cabRes)?> reseller</span>
  </div>
  <div class="rs-grid" style="margin-bottom:8px">
  <?php foreach($cabRes as $r):?>
    <div class="rs-card <?=$r['is_active']?'':'inactive'?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
        <div>
          <div class="rs-name"><?=h($r['nama'])?></div>
          <?php if($r['catatan']):?><div style="font-size:.72rem;color:var(--g400);font-weight:600;margin-bottom:4px"><?=h($r['catatan'])?></div><?php endif;?>
        </div>
        <span class="bdg <?=$r['is_active']?'bon':'bgray'?>"><?=$r['is_active']?'Aktif':'Nonaktif'?></span>
      </div>
      <div style="margin-top:8px;padding:10px;background:var(--g50);border-radius:10px;text-align:center">
        <div class="rs-pct"><?=number_format($r['persen_keuntungan'],1)?>%</div>
        <div style="font-size:.65rem;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.5px">Keuntungan Reseller</div>
      </div>
      <?php if($r['harga_voucher']):?>
      <div style="font-size:.78rem;color:var(--g500);font-weight:600;margin-top:6px">🎫 Harga voucher: <strong>Rp <?=number_format($r['harga_voucher'],0,',','.')?></strong></div>
      <?php endif;?>
      <div class="rs-acts">
        <button class="btn bo bsm" style="flex:1;font-weight:700"
          onclick='editReseller(<?=json_encode($r)?> )'>✏️ Edit</button>
        <form method="POST" style="flex:1" onsubmit="return cDel('Hapus reseller <?=h(addslashes($r['nama']))?> ?')">
          <?=csrfField()?><input type="hidden" name="action" value="delete_reseller">
          <input type="hidden" name="id" value="<?=$r['id']?>">
          <button type="submit" class="btn bd bsm" style="width:100%;font-weight:700">🗑️ Hapus</button>
        </form>
      </div>
    </div>
  <?php endforeach;?>
  </div>
  <?php endforeach;?>
  <?php if(isset($byBranch[0]) && !empty($byBranch[0])):?>
  <div class="branch-hdr"><span>⚠️</span><span class="branch-lbl" style="color:var(--orange)">Reseller Tanpa Cabang</span><span class="bdg borg"><?=count($byBranch[0])?></span></div>
  <div class="rs-grid">
  <?php foreach($byBranch[0] as $r):?>
    <div class="rs-card inactive">
      <div class="rs-name"><?=h($r['nama'])?></div>
      <div style="font-size:.78rem;color:var(--orange);font-weight:700">⚠️ Belum dikategorikan ke cabang</div>
      <div class="rs-acts">
        <button class="btn bw bsm" style="flex:1;font-weight:700" onclick='editReseller(<?=json_encode($r)?> )'>✏️ Assign Cabang</button>
      </div>
    </div>
  <?php endforeach;?>
  </div>
  <?php endif;?>
  <?php endif;?>
</div>

<!-- TAB: Cabang -->
<div class="tp" id="tp-cabang" data-grp="main">
  <div style="margin-bottom:14px;display:flex;justify-content:flex-end">
    <button class="btn bp bsm" onclick="oM('mAddCabang')">➕ Tambah Cabang</button>
  </div>
  <?php if(empty($cabangs)):?>
  <div class="empty"><div class="eico">📍</div>Belum ada cabang.</div>
  <?php else:?>
  <div style="display:flex;flex-direction:column;gap:8px">
  <?php foreach($cabangs as $cab):
    $cntRes=count($byBranch[$cab['id']]??[]);
  ?>
  <div class="cab-card">
    <div>
      <div style="font-weight:700;color:var(--g900)"><?=$cab['id']===1?'🏠 ':''?><?=h($cab['nama'])?></div>
      <div style="font-size:.78rem;color:var(--g500);margin-top:2px"><?=h($cab['keterangan']?:'-')?></div>
      <?php if(!empty($cab['genie_name'])): ?>
      <div style="font-size:.78rem;color:var(--blue-d);margin-top:4px">📡 Server: <strong><?=h($cab['genie_name'])?></strong></div>
      <?php endif; ?>
      <div style="font-size:.75rem;color:var(--g500);margin-top:4px;font-weight:600">
        <span class="bdg bblue"><?=$cntRes?> reseller</span>
        <span class="bdg <?=$cab['is_active']?'bon':'bgray'?>" style="margin-left:4px"><?=$cab['is_active']?'Aktif':'Nonaktif'?></span>
      </div>
    </div>
    <div style="display:flex;gap:6px;flex-shrink:0">
      <button class="btn bo bsm" onclick='editCabang(<?=json_encode($cab)?> )'>✏️ Edit</button>
      <?php if($cab['id']!==1):?>
      <form method="POST" onsubmit="return cDel('Hapus cabang <?=h(addslashes($cab['nama']))?> ?')">
        <?=csrfField()?><input type="hidden" name="action" value="delete_cabang">
        <input type="hidden" name="id" value="<?=$cab['id']?>">
        <button type="submit" class="btn bd bsm">🗑️</button>
      </form>
      <?php endif;?>
    </div>
  </div>
  <?php endforeach;?>
  </div>
  <?php endif;?>
</div>

<!-- TAB: Info Laporan -->
<div class="tp" id="tp-arsip" data-grp="main">
  <div style="padding:16px 0">
    <div class="alert ainf" style="font-weight:700">
      📊 File Excel laporan tersimpan otomatis per cabang per bulan di folder <code>/admin/laporan/</code><br>
      <small>File tidak akan dihapus — setiap bulan baru file baru dibuat. Bisa lihat bulan-bulan sebelumnya di Arsip Laporan.</small>
    </div>
    <a href="/admin/laporan_arsip.php" class="btn bpu" style="font-weight:800;margin-top:10px">📦 Buka Halaman Arsip Laporan</a>
  </div>
</div>

<!-- MODAL ADD RESELLER -->
<div class="mo" id="mAddReseller"><div class="md">
<div class="mh"><div class="mt">➕ Tambah Reseller</div><button class="mx" onclick="cM('mAddReseller')">✕</button></div>
<form method="POST"><div class="mb">
<?=csrfField()?><input type="hidden" name="action" value="add_reseller">
<div class="fg"><label class="fl">Cabang *</label>
  <select name="cabang_id" class="fsel" style="font-weight:700" required>
    <?php foreach($cabangs as $c):?><option value="<?=$c['id']?>"><?=h($c['nama'])?></option><?php endforeach;?>
  </select>
</div>
<div class="fg"><label class="fl">Nama Reseller *</label>
  <input type="text" name="nama" class="fc" required placeholder="Nama Reseller / Toko" style="font-weight:700">
</div>
<div class="frow2">
  <div class="fg"><label class="fl">Persentase Keuntungan (%)</label>
    <input type="number" name="persen_keuntungan" class="fc" value="0" min="0" max="100" step="0.5" style="font-weight:700;font-size:1.1rem" oninput="previewCalc(this.value)">
    <div class="fhint">Contoh: 30 = reseller mendapat 30% dari total pendapatan</div>
  </div>
  <div class="fg"><label class="fl">Harga Per Voucher (Rp) <small style="text-transform:none;font-weight:400;color:var(--g400)">opsional</small></label>
    <input type="number" name="harga_voucher" class="fc" value="0" min="0" step="500" style="font-weight:700">
    <div class="fhint">Untuk hitung voucher terjual otomatis</div>
  </div>
</div>
<div id="calcPreview" style="display:none;background:linear-gradient(135deg,#EFF6FF,#F8FAFF);border:1.5px solid #BFDBFE;border-radius:10px;padding:12px;margin-bottom:12px">
  <div style="font-size:.72rem;font-weight:700;color:var(--blue-d);margin-bottom:6px">📊 Contoh Perhitungan (Total Rp 1.000.000)</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:.82rem;font-weight:700">
    <div>Bagian Reseller: <span id="pRseller" style="color:var(--red-d)">Rp 0</span></div>
    <div>Pendapatan Bersih: <span id="pBersih" style="color:var(--green)">Rp 1.000.000</span></div>
  </div>
</div>
<div class="fg"><label class="fl">Catatan <small style="text-transform:none;font-weight:400;color:var(--g400)">opsional</small></label>
  <input type="text" name="catatan" class="fc" placeholder="Lokasi, kontak, keterangan...">
</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mAddReseller')">Batal</button><button type="submit" class="btn bp" style="font-weight:800">💾 Simpan Reseller</button></div>
</form></div></div>

<!-- MODAL EDIT RESELLER -->
<div class="mo" id="mEditReseller"><div class="md">
<div class="mh"><div class="mt">✏️ Edit Reseller</div><button class="mx" onclick="cM('mEditReseller')">✕</button></div>
<form method="POST"><div class="mb">
<?=csrfField()?><input type="hidden" name="action" value="edit_reseller"><input type="hidden" name="id" id="eId">
<div class="fg"><label class="fl">Cabang *</label>
  <select name="cabang_id" id="eCid" class="fsel" style="font-weight:700">
    <?php foreach($cabangs as $c):?><option value="<?=$c['id']?>"><?=h($c['nama'])?></option><?php endforeach;?>
  </select>
</div>
<div class="fg"><label class="fl">Nama Reseller *</label><input type="text" name="nama" id="eNama" class="fc" required style="font-weight:700"></div>
<div class="frow2">
  <div class="fg"><label class="fl">Persentase Keuntungan (%)</label><input type="number" name="persen_keuntungan" id="ePct" class="fc" min="0" max="100" step="0.5" style="font-weight:700;font-size:1.1rem"></div>
  <div class="fg"><label class="fl">Harga Per Voucher (Rp)</label><input type="number" name="harga_voucher" id="eHarga" class="fc" min="0" step="500" style="font-weight:700"></div>
</div>
<div class="frow2">
  <div class="fg"><label class="fl">Catatan</label><input type="text" name="catatan" id="eCat" class="fc"></div>
  <div class="fg"><label class="fl">Status</label>
    <select name="is_active" id="eAktif" class="fsel">
      <option value="1">Aktif</option><option value="0">Nonaktif</option>
    </select>
  </div>
</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEditReseller')">Batal</button><button type="submit" class="btn bp" style="font-weight:800">💾 Simpan</button></div>
</form></div></div>

<!-- MODAL ADD CABANG -->
<div class="mo" id="mAddCabang"><div class="md">
<div class="mh"><div class="mt">📍 Tambah Cabang</div><button class="mx" onclick="cM('mAddCabang')">✕</button></div>
<form method="POST"><div class="mb">
<?=csrfField()?><input type="hidden" name="action" value="add_cabang">
<div class="fg"><label class="fl">Nama Cabang *</label>
  <input type="text" name="nama" class="fc" required placeholder="Contoh: Desa Pangeo, Desa Lusuo..." style="font-weight:700">
</div>
<div class="fg"><label class="fl">Keterangan <small style="text-transform:none;font-weight:400;color:var(--g400)">opsional</small></label>
  <input type="text" name="keterangan" class="fc" placeholder="Lokasi, wilayah, keterangan tambahan...">
</div>
<div class="fg"><label class="fl">Server GenieACS <small style="text-transform:none;font-weight:400;color:var(--g400)">opsional</small></label>
  <select name="genie_id" class="fsel">
    <option value="0">-- Tidak Ada (Pilih jika tidak pakai) --</option>
    <?php foreach($genieServers as $gs): ?>
      <option value="<?=$gs['id']?>"><?=h($gs['name'])?></option>
    <?php endforeach; ?>
  </select>
  <div class="fhint">Pilih server GenieACS khusus untuk cabang ini (untuk auto-detect ONT)</div>
</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mAddCabang')">Batal</button><button type="submit" class="btn bp" style="font-weight:800">💾 Simpan Cabang</button></div>
</form></div></div>

<!-- MODAL EDIT CABANG -->
<div class="mo" id="mEditCabang"><div class="md">
<div class="mh"><div class="mt">✏️ Edit Cabang</div><button class="mx" onclick="cM('mEditCabang')">✕</button></div>
<form method="POST"><div class="mb">
<?=csrfField()?><input type="hidden" name="action" value="edit_cabang"><input type="hidden" name="id" id="ecId">
<div class="fg"><label class="fl">Nama Cabang *</label><input type="text" name="nama" id="ecNama" class="fc" required style="font-weight:700"></div>
<div class="frow2">
  <div class="fg"><label class="fl">Keterangan</label><input type="text" name="keterangan" id="ecKet" class="fc"></div>
  <div class="fg"><label class="fl">Status</label>
    <select name="is_active" id="ecAktif" class="fsel"><option value="1">Aktif</option><option value="0">Nonaktif</option></select>
  </div>
</div>
<div class="fg"><label class="fl">Server GenieACS</label>
  <select name="genie_id" id="ecGenie" class="fsel">
    <option value="0">-- Tidak Ada --</option>
    <?php foreach($genieServers as $gs): ?>
      <option value="<?=$gs['id']?>"><?=h($gs['name'])?></option>
    <?php endforeach; ?>
  </select>
</div>
</div>
</div>
<div class="mf"><button type="button" class="btn bo" onclick="cM('mEditCabang')">Batal</button><button type="submit" class="btn bp" style="font-weight:800">💾 Simpan</button></div>
</form></div></div>

<script>
function previewCalc(pct){
  const p=parseFloat(pct)||0;
  const total=1000000, rs=Math.round(total*p/100);
  const wrap=document.getElementById('calcPreview');
  if(p>0){wrap.style.display='block';document.getElementById('pRseller').textContent='Rp '+rs.toLocaleString('id-ID');document.getElementById('pBersih').textContent='Rp '+(total-rs).toLocaleString('id-ID');}
  else{wrap.style.display='none';}
}
function editReseller(r){
  document.getElementById('eId').value=r.id;
  document.getElementById('eCid').value=r.cabang_id||1;
  document.getElementById('eNama').value=r.nama;
  document.getElementById('ePct').value=r.persen_keuntungan;
  document.getElementById('eHarga').value=r.harga_voucher||0;
  document.getElementById('eCat').value=r.catatan||'';
  document.getElementById('eAktif').value=r.is_active;
  oM('mEditReseller');
}
function editCabang(c){
  document.getElementById('ecId').value=c.id;
  document.getElementById('ecNama').value=c.nama;
  document.getElementById('ecKet').value=c.keterangan||'';
  document.getElementById('ecAktif').value=c.is_active;
  document.getElementById('ecGenie').value=c.genie_id||0;
  oM('mEditCabang');
}
</script>
<?php endPage();?>
