<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();

// Auto-migrate cabang system
foreach(['cabang','resellers','laporan_penagihan'] as $t){
    try{ $db->query("SELECT 1 FROM $t LIMIT 1"); }
    catch(Exception $e){
        if($t==='cabang') $db->exec("CREATE TABLE IF NOT EXISTS cabang(id INT AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(150) NOT NULL,keterangan TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
        if($t==='resellers') $db->exec("CREATE TABLE IF NOT EXISTS resellers(id INT AUTO_INCREMENT PRIMARY KEY,nama VARCHAR(150) NOT NULL,cabang_id INT DEFAULT 1,persen_keuntungan DECIMAL(5,2) DEFAULT 0,harga_voucher INT DEFAULT 0,catatan TEXT,is_active TINYINT(1) DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
        if($t==='laporan_penagihan') $db->exec("CREATE TABLE IF NOT EXISTS laporan_penagihan(id INT AUTO_INCREMENT PRIMARY KEY,teknisi_id INT DEFAULT NULL,teknisi_nama VARCHAR(150) DEFAULT '',reseller_id INT DEFAULT NULL,reseller_nama VARCHAR(150) DEFAULT '',persen_reseller DECIMAL(5,2) DEFAULT 0,total_pendapatan BIGINT DEFAULT 0,bagian_reseller BIGINT DEFAULT 0,pendapatan_bersih BIGINT DEFAULT 0,harga_voucher INT DEFAULT 0,voucher_terjual INT DEFAULT 0,catatan TEXT,cabang_id INT DEFAULT 1,cabang_nama VARCHAR(150) DEFAULT '',tanggal_penagihan DATETIME DEFAULT CURRENT_TIMESTAMP,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    }
}
// Pastikan cabang default ada
try{ $db->exec("INSERT IGNORE INTO cabang(id,nama,keterangan)VALUES(1,'Pusat','Cabang default')"); }catch(Exception $e){}
// Pastikan index ada untuk performa query
try{ $db->exec("ALTER TABLE laporan_penagihan ADD INDEX idx_tgl(tanggal_penagihan)"); }catch(Exception $e){}
try{ $db->exec("ALTER TABLE laporan_penagihan ADD INDEX idx_cab(cabang_id)"); }catch(Exception $e){}

$u=adminUser();
$isAdmin=in_array($u['role']??'',['admin','superadmin','operator']);

// Ambil semua cabang aktif
$cabangs=$db->query("SELECT * FROM cabang WHERE is_active=1 ORDER BY nama ASC")->fetchAll();

// Ambil reseller per cabang (JSON untuk JS)
$resellers=$db->query("SELECT r.*,c.nama AS cabang_nama_txt FROM resellers r LEFT JOIN cabang c ON c.id=r.cabang_id WHERE r.is_active=1 ORDER BY r.cabang_id ASC,r.nama ASC")->fetchAll();

// Filter riwayat — semua role bisa lihat semua data
$filterCabang = (int)($_GET['cabang']??0);
$filterBulan  = $_GET['bulan']??'';

// Teknisi dan admin sama-sama bisa lihat semua riwayat
$where='1=1'; $params=[];
if($filterCabang){ $where.=' AND lp.cabang_id=?'; $params[]=$filterCabang; }
if($filterBulan)  { $where.=" AND DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m')=?"; $params[]=$filterBulan; }
$limit = $isAdmin ? 200 : 100;
$q=$db->prepare("SELECT lp.*,u.full_name AS teknisi_label FROM laporan_penagihan lp LEFT JOIN users u ON u.id=lp.teknisi_id WHERE $where ORDER BY lp.tanggal_penagihan DESC LIMIT $limit");
$q->execute($params); $riwayat=$q->fetchAll();

// Statistik ringkas per cabang — filter rentang tanggal
$statDari   = $_GET['stat_dari']??'';   // format YYYY-MM-DD
$statSampai = $_GET['stat_sampai']??''; // format YYYY-MM-DD
$statsCabang=[];
$statWhere='1=1'; $statParams=[];
if($statDari)   { $statWhere.=" AND DATE(lp.tanggal_penagihan)>=?"; $statParams[]=$statDari; }
if($statSampai) { $statWhere.=" AND DATE(lp.tanggal_penagihan)<=?"; $statParams[]=$statSampai; }
$scq=$db->prepare("SELECT lp.cabang_id,lp.cabang_nama,COUNT(*) AS jml,SUM(lp.total_pendapatan) AS total,SUM(lp.pendapatan_bersih) AS bersih FROM laporan_penagihan lp WHERE $statWhere GROUP BY lp.cabang_id,lp.cabang_nama ORDER BY bersih DESC");
$scq->execute($statParams); $statsCabang=$scq->fetchAll();

// Laporan dir
$lapDir=APP_BASE.'/admin/laporan';
if(!is_dir($lapDir)) mkdir($lapDir,0755,true);

header('Cache-Control: no-store,no-cache,must-revalidate');
startPage('Laporan Penagihan');
?>
<style>
.pen-card{background:linear-gradient(135deg,#EFF6FF,#F8FAFF);border:2px solid #BFDBFE;border-radius:16px;padding:20px;margin-bottom:16px}
.pen-card.purple{background:linear-gradient(135deg,#F5F3FF,#EDE9FE);border-color:#DDD6FE}
.pen-card.green{background:linear-gradient(135deg,#F0FDF4,#DCFCE7);border-color:#BBF7D0}
.pen-lbl{font-weight:900;font-size:.82rem;color:var(--blue-d);margin-bottom:12px;display:flex;align-items:center;gap:8px}
.pen-lbl.purple{color:var(--purple)}.pen-lbl.green{color:var(--green-d)}
.stepnum{width:26px;height:26px;background:var(--blue);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:900;flex-shrink:0}
.stepnum.purple{background:var(--purple)}.stepnum.green{background:var(--green)}
.calc-box{background:linear-gradient(135deg,#0F2266,#1B3FA6);border-radius:14px;padding:16px;color:#fff;margin-top:14px}
.calc-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.1);font-size:.84rem}
.calc-row:last-child{border-bottom:none}
.calc-lbl{font-weight:600;opacity:.8}.calc-val{font-weight:900}
/* Stats cabang */
.cab-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:18px}
.cab-stat{background:#fff;border:1.5px solid var(--g200);border-radius:12px;padding:14px 16px;position:relative;overflow:hidden}
.cab-stat::before{content:'';position:absolute;left:0;top:0;width:4px;height:100%;background:var(--blue)}
.cab-stat-nm{font-weight:800;font-size:.88rem;color:var(--g900);margin-bottom:4px}
.cab-stat-v{font-size:1.1rem;font-weight:900;color:var(--blue-d)}
.cab-stat-s{font-size:.72rem;color:var(--g400);margin-top:2px}
/* Mobile card view */
@media(max-width:768px){
  .dt-wrap table,.dt-wrap thead,.dt-wrap tbody,.dt-wrap th,.dt-wrap td,.dt-wrap tr{display:block}
  .dt-wrap thead tr{position:absolute;top:-9999px;left:-9999px}
  .dt-wrap tr{border:1.5px solid var(--g200);border-radius:10px;margin-bottom:10px;padding:10px 12px;background:#fff;box-shadow:0 1px 4px rgba(27,63,166,.06)}
  .dt-wrap td{border:none!important;padding:5px 0;display:flex;justify-content:space-between;align-items:flex-start;font-size:.82rem;gap:8px}
  .dt-wrap td::before{content:attr(data-label);font-weight:700;color:var(--g500);font-size:.72rem;text-transform:uppercase;letter-spacing:.5px;flex-shrink:0;min-width:110px}
  .dt-wrap td:last-child{padding-top:8px;border-top:1px solid var(--g100)!important;margin-top:4px}
}
</style>

<div class="ph">
  <div>
    <div class="ph-t">💰 Laporan Penagihan</div>
    <div class="ph-s">Input pendapatan tagihan reseller per cabang — sistem hitung otomatis</div>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <?php if($isAdmin):?>
    <a href="/admin/laporan_arsip.php" class="btn bpu bsm" style="font-weight:800">📦 Arsip Laporan</a>
    <?php endif;?>
    <a href="<?=$isAdmin?'/admin/dashboard.php':'/admin/teknisi_portal.php'?>" class="btn bo bsm" style="font-weight:700">← <?=$isAdmin?'Dashboard':'Portal'?></a>
  </div>
</div>

<?php if(!empty($statsCabang)):
  $grandTotal=0; $grandBersih=0; $grandJml=0;
  foreach($statsCabang as $sc){ $grandTotal+=$sc['total']; $grandBersih+=$sc['bersih']; $grandJml+=$sc['jml']; }
?>
<!-- Statistik per cabang — tampil untuk semua role -->
<div style="margin-bottom:14px">
  <!-- Filter Rentang Bulan -->
  <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;padding:10px 14px;background:var(--g50);border-radius:10px;border:1px solid var(--g200)">
    <span style="font-size:.75rem;font-weight:800;color:var(--g600)">📊 Statistik Per Cabang:</span>
    <label style="font-size:.75rem;font-weight:700;color:var(--g500)">Dari</label>
    <input type="date" name="stat_dari" value="<?=h($statDari)?>"
      style="padding:5px 8px;font-size:.8rem;border:1.5px solid var(--g200);border-radius:7px;font-family:inherit">
    <label style="font-size:.75rem;font-weight:700;color:var(--g500)">s/d</label>
    <input type="date" name="stat_sampai" value="<?=h($statSampai)?>"
      style="padding:5px 8px;font-size:.8rem;border:1.5px solid var(--g200);border-radius:7px;font-family:inherit">
    <!-- preserve filter riwayat -->
    <?php if($filterCabang):?><input type="hidden" name="cabang" value="<?=(int)$filterCabang?>"><?php endif;?>
    <?php if($filterBulan):?><input type="hidden" name="bulan" value="<?=h($filterBulan)?>"><?php endif;?>
    <button type="submit" class="btn bp bsm" style="font-weight:700;padding:5px 12px">🔍 Tampilkan</button>
    <?php if($statDari||$statSampai):?>
    <a href="/admin/penagihan.php<?=$filterCabang||$filterBulan?'?cabang='.$filterCabang.'&bulan='.urlencode($filterBulan):'';?>" class="btn bo bsm" style="font-size:.75rem">✕ Reset</a>
    <?php endif;?>
    <span style="font-size:.72rem;color:var(--g400);font-weight:600">
      <?php if($statDari||$statSampai): echo '📅 '.($statDari?:'…').' s/d '.($statSampai?:'sekarang'); else: echo 'Semua waktu'; endif;?>
    </span>
  </form>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:10px">
  <?php foreach($statsCabang as $sc):?>
  <div style="background:#fff;border:1.5px solid var(--g200);border-radius:12px;padding:14px;position:relative;overflow:hidden;cursor:pointer;transition:.2s"
       onclick="document.getElementById('fCabang').value='<?=(int)$sc['cabang_id']?>'; document.getElementById('filterForm').submit()"
       title="Klik untuk filter cabang <?=h($sc['cabang_nama']?:'Tanpa Cabang')?>">
    <div style="position:absolute;top:0;left:0;width:4px;height:100%;background:linear-gradient(180deg,var(--blue),var(--purple))"></div>
    <div style="font-size:.75rem;font-weight:800;color:var(--g500);margin-bottom:4px;padding-left:6px">📍 <?=h($sc['cabang_nama']?:'Tanpa Cabang')?></div>
    <div style="font-size:1.2rem;font-weight:900;color:var(--green);padding-left:6px;line-height:1">
      Rp <?=number_format($sc['bersih'],0,',','.')?>
    </div>
    <div style="font-size:.68rem;color:var(--g400);font-weight:600;margin-top:4px;padding-left:6px">
      Bersih dari Rp <?=number_format($sc['total'],0,',','.')?> · <?=$sc['jml']?> transaksi
    </div>
  </div>
  <?php endforeach;?>
  <!-- Grand Total -->
  <div style="background:linear-gradient(135deg,var(--blue-d),var(--blue));border-radius:12px;padding:14px;color:#fff">
    <div style="font-size:.72rem;font-weight:800;opacity:.8;margin-bottom:4px">🏆 TOTAL SEMUA CABANG</div>
    <div style="font-size:1.2rem;font-weight:900;line-height:1">Rp <?=number_format($grandBersih,0,',','.')?></div>
    <div style="font-size:.68rem;opacity:.75;margin-top:4px">
      Bersih dari Rp <?=number_format($grandTotal,0,',','.')?> · <?=$grandJml?> transaksi
    </div>
  </div>
  </div>
</div>
<?php endif;?>


<!-- Data JSON untuk JS -->
<script>
const RS=<?=json_encode(array_map(function($r){return['id'=>(int)$r['id'],'nama'=>$r['nama'],'pct'=>(float)$r['persen_keuntungan'],'harga'=>(int)$r['harga_voucher'],'cabang_id'=>(int)$r['cabang_id'],'cabang_nama'=>isset($r['cabang_nama_txt'])?$r['cabang_nama_txt']:''];}, $resellers))?>;
const CABANGS=<?=json_encode(array_map(function($c){return['id'=>(int)$c['id'],'nama'=>$c['nama']];}, $cabangs))?>;
function rp(n){return 'Rp '+Number(Math.round(n)).toLocaleString('id-ID');}
</script>

<!-- Form Input Penagihan -->
<div class="card">
  <div class="ch"><div class="ct">📝 Input Penagihan Baru</div></div>
  <div class="cb">
  <?php if(empty($cabangs)||empty($resellers)):?>
    <div class="alert awrn" style="font-weight:700">⚠️ <?=empty($cabangs)?'Belum ada cabang':'Belum ada reseller aktif'?>. Silakan atur di menu <strong>Reseller &amp; Drive</strong>.</div>
  <?php else:?>

    <!-- Step 1: Pilih Cabang -->
    <div class="pen-card green">
      <div class="pen-lbl green"><div class="stepnum green">1</div> Pilih Cabang</div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">Nama Cabang *</label>
        <select id="selCabang" class="fsel" onchange="onCabangChange()" style="font-weight:700;font-size:.95rem">
          <option value="">— Pilih Cabang —</option>
          <?php foreach($cabangs as $c):?>
          <option value="<?=(int)$c['id']?>"><?=h($c['nama'])?></option>
          <?php endforeach;?>
        </select>
      </div>
    </div>

    <!-- Step 2: Pilih Reseller (terfilter per cabang) -->
    <div class="pen-card" id="stepReseller" style="display:none">
      <div class="pen-lbl"><div class="stepnum">2</div> Pilih Reseller</div>
      <div class="fg" style="margin-bottom:0">
        <label class="fl">Nama Reseller *</label>
        <select id="selReseller" class="fsel" onchange="onRsChange()" style="font-weight:700;font-size:.95rem">
          <option value="">— Pilih Reseller —</option>
        </select>
      </div>
      <div id="rsInfo" style="display:none;margin-top:10px;padding:10px 14px;background:#fff;border:1.5px solid var(--green);border-radius:10px;font-size:.83rem;font-weight:700;color:var(--green-d)"></div>
    </div>

    <!-- Step 3: Input Pendapatan -->
    <div class="pen-card purple" id="stepInput" style="display:none">
      <div class="pen-lbl purple"><div class="stepnum purple">3</div> Isi Total Pendapatan</div>
      <div class="frow2">
        <div class="fg">
          <label class="fl">Total Pendapatan (Rp) *</label>
          <input type="number" id="inpTotal" class="fc" placeholder="Contoh: 500000"
                 min="0" step="1000" oninput="hitungOtomatis()"
                 style="font-size:1.15rem;font-weight:900;color:var(--g900)">
        </div>
        <div class="fg">
          <label class="fl">Catatan <small style="text-transform:none;font-weight:400;color:var(--g400)">opsional</small></label>
          <input type="text" id="inpCatatan" class="fc" placeholder="Keterangan penagihan...">
        </div>
      </div>
    </div>

    <!-- Hasil Perhitungan -->
    <div id="hasilCalc" style="display:none">
      <div class="calc-box">
        <div style="font-size:.65rem;font-weight:700;opacity:.6;text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px">📊 Hasil Perhitungan Otomatis</div>
        <div class="calc-row"><span class="calc-lbl">💰 Total Pendapatan</span><span class="calc-val" id="cTotal">Rp 0</span></div>
        <div class="calc-row"><span class="calc-lbl">🤝 Bagian Reseller (<span id="cPct">0</span>%)</span><span class="calc-val" style="color:#FCA5A5" id="cBagian">Rp 0</span></div>
        <div class="calc-row" style="border-top:1.5px solid rgba(255,255,255,.2);margin-top:4px">
          <span class="calc-lbl" style="font-weight:900;opacity:1">✅ Pendapatan Bersih</span>
          <span class="calc-val" style="color:#FCD34D;font-size:1.05rem" id="cBersih">Rp 0</span>
        </div>
        <div id="rowVcr" class="calc-row" style="display:none">
          <span class="calc-lbl">🎫 Estimasi Voucher Terjual</span>
          <span class="calc-val" style="color:#6EE7B7" id="cVcr">0</span>
        </div>
      </div>
      <button type="button" id="btnKirim" class="btn bp"
        style="width:100%;margin-top:14px;padding:13px;font-weight:900;font-size:.92rem"
        onclick="kirimPenagihan()">
        🚀 KIRIM LAPORAN &amp; Update Excel
      </button>
    </div>

  <?php endif;?>
  </div>
</div>

<!-- Filter + Riwayat -->
<div class="card">
  <div class="ch">
    <div class="ct">📋 Semua Riwayat Penagihan
      <?php if(!$isAdmin):?>
      <span class="bdg bblue" style="font-size:.65rem;font-weight:700;margin-left:6px">Semua Teknisi</span>
      <?php endif;?>
    </div>
    <a href="/admin/laporan_arsip.php" class="btn bpu bsm" style="font-weight:800">📦 Arsip</a>
  </div>

  <!-- Filter Bar -->
  <div style="padding:12px 18px;border-bottom:1px solid var(--g100);display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <form method="GET" id="filterForm" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;width:100%">
      <select name="cabang" id="fCabang" class="fsel" style="width:auto;min-width:140px;font-size:.83rem;padding:6px 10px">
        <option value="">Semua Cabang</option>
        <?php foreach($cabangs as $c):?>
        <option value="<?=$c['id']?>" <?=$filterCabang==$c['id']?'selected':''?>><?=h($c['nama'])?></option>
        <?php endforeach;?>
      </select>
      <input type="month" name="bulan" class="fc" value="<?=h($filterBulan)?>"
             style="width:auto;padding:6px 10px;font-size:.83rem">
      <button type="submit" class="btn bp bsm" style="font-weight:700">🔍 Filter</button>
      <?php if($filterCabang||$filterBulan):?>
      <a href="/admin/penagihan.php" class="btn bo bsm">✕ Reset</a>
      <?php endif;?>
    </form>
  </div>

  <?php if(empty($riwayat)):?>
  <div class="empty"><div class="eico">📋</div>Belum ada riwayat penagihan<?=($filterCabang||$filterBulan)?' untuk filter ini':''?>.</div>
  <?php else:?>
  <div class="dt-wrap" style="padding:14px">
  <table class="dt" style="width:100%">
    <thead><tr>
      <th>Waktu</th><th>Cabang</th><th>Reseller</th>
      <th>Total</th><th>Bagian Reseller</th><th>Bersih</th><th>Voucher</th>
      <th>Ditagih Oleh</th>
      <th>Aksi</th>
    </tr></thead>
    <tbody>
    <?php foreach($riwayat as $lp):
      $bisaEdit=$isAdmin||(int)$lp['teknisi_id']===(int)$u['id'];
    ?>
    <tr>
      <td data-label="Waktu">
        <div style="font-weight:700;font-size:.8rem"><?=date('d/m/Y',strtotime($lp['tanggal_penagihan']))?></div>
        <div style="font-size:.7rem;color:var(--g400)"><?=date('H:i',strtotime($lp['tanggal_penagihan']))?></div>
      </td>
      <td data-label="Cabang"><span class="bdg bpur"><?=h($lp['cabang_nama']?:'—')?></span></td>
      <td data-label="Reseller"><strong><?=h($lp['reseller_nama'])?></strong><div style="font-size:.7rem;color:var(--g400)"><?=number_format($lp['persen_reseller'],1)?>%</div></td>
      <td data-label="Total" style="font-weight:800">Rp <?=number_format($lp['total_pendapatan'],0,',','.')?></td>
      <td data-label="Bag. Reseller" style="color:var(--red-d);font-weight:700">Rp <?=number_format($lp['bagian_reseller'],0,',','.')?></td>
      <td data-label="Bersih" style="color:var(--green);font-weight:800">Rp <?=number_format($lp['pendapatan_bersih'],0,',','.')?></td>
      <td data-label="Voucher"><?=$lp['voucher_terjual']?'<span class="bdg bblue">'.$lp['voucher_terjual'].'</span>':'—'?></td>
      <td data-label="Ditagih Oleh">
        <span style="font-size:.78rem;font-weight:700;color:var(--g700)">
          <?=h($lp['teknisi_label']??$lp['teknisi_nama']??'—')?>
        </span>
        <?php if((int)$lp['teknisi_id']===(int)$u['id']):?>
        <span class="bdg bon" style="font-size:.6rem;margin-left:3px">Saya</span>
        <?php endif;?>
      </td>
      <td data-label="Aksi" style="white-space:nowrap">
        <?php if($bisaEdit):?>
        <button class="btn bo bxs" style="font-weight:700" onclick='bukaEditPenagihan(<?=json_encode(['id'=>(int)$lp['id'],'reseller_id'=>(int)$lp['reseller_id'],'reseller_nama'=>$lp['reseller_nama'],'persen'=>(float)$lp['persen_reseller'],'total'=>(int)$lp['total_pendapatan'],'harga_voucher'=>(int)$lp['harga_voucher'],'voucher_terjual'=>(int)$lp['voucher_terjual'],'catatan'=>$lp['catatan'],'cabang_id'=>(int)$lp['cabang_id'],'cabang_nama'=>$lp['cabang_nama']])?>)'>✏️</button>
        <?php endif;?>
        <?php if($isAdmin):?>
        <button class="btn bd bxs" style="font-weight:700;margin-left:2px" onclick="hapusPenagihan(<?=(int)$lp['id']?>,'<?=h(addslashes($lp['reseller_nama']))?>')">🗑️</button>
        <?php endif;?>
      </td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  </div>
  <?php endif;?>
</div>

<!-- Modal Edit Penagihan -->
<div class="mo" id="mEditPen"><div class="md">
  <div class="mh"><div class="mt">✏️ Edit Data Penagihan</div><button class="mx" onclick="cM('mEditPen')">✕</button></div>
  <div class="mb">
    <input type="hidden" id="epId"><input type="hidden" id="epRid">
    <input type="hidden" id="epPct"><input type="hidden" id="epHarga">
    <div class="fg"><label class="fl">Cabang &amp; Reseller</label>
      <div id="epRnm" style="font-weight:800;padding:8px 12px;background:var(--g50);border:2px solid var(--g200);border-radius:8px"></div>
    </div>
    <div class="frow2">
      <div class="fg"><label class="fl">Total Pendapatan (Rp) *</label>
        <input type="number" id="epTotal" class="fc" min="0" step="1000" oninput="hitungEditOtomatis()" style="font-size:1.1rem;font-weight:900">
      </div>
      <div class="fg"><label class="fl">Catatan</label>
        <input type="text" id="epCat" class="fc" placeholder="Keterangan...">
      </div>
    </div>
    <div style="background:linear-gradient(135deg,#0F2266,#1B3FA6);border-radius:12px;padding:14px;color:#fff;font-size:.83rem">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;font-weight:700">
        <div>Bagian Reseller<br><span id="epBagian" style="color:#FCA5A5">Rp 0</span></div>
        <div>Pendapatan Bersih<br><span id="epBersih" style="color:#FCD34D">Rp 0</span></div>
        <div>Est. Voucher<br><span id="epVcr" style="color:#6EE7B7">—</span></div>
      </div>
    </div>
  </div>
  <div class="mf">
    <button type="button" class="btn bo" onclick="cM('mEditPen')">Batal</button>
    <button type="button" id="btnSimpanEdit" class="btn bp" style="font-weight:800" onclick="simpanEditPenagihan()">💾 Simpan</button>
  </div>
</div></div>

<script>
let selCabang=null, selRs=null;

function onCabangChange(){
  const sel=document.getElementById('selCabang');
  const id=parseInt(sel.value)||0;
  selCabang=id?CABANGS.find(c=>c.id===id):null;
  // Reset reseller
  selRs=null;
  document.getElementById('rsInfo').style.display='none';
  document.getElementById('hasilCalc').style.display='none';

  const rsSel=document.getElementById('selReseller');
  rsSel.innerHTML='<option value="">— Pilih Reseller —</option>';
  if(id){
    const filtered=RS.filter(r=>r.cabang_id===id);
    filtered.forEach(r=>{
      const o=document.createElement('option');
      o.value=r.id; o.dataset.pct=r.pct; o.dataset.harga=r.harga;
      o.dataset.nama=r.nama;
      o.textContent=r.nama+' ('+r.pct+'%)';
      rsSel.appendChild(o);
    });
    document.getElementById('stepReseller').style.display='block';
    document.getElementById('stepInput').style.display='none';
  } else {
    document.getElementById('stepReseller').style.display='none';
    document.getElementById('stepInput').style.display='none';
  }
}

function onRsChange(){
  const sel=document.getElementById('selReseller');
  const opt=sel.options[sel.selectedIndex];
  const id=parseInt(sel.value)||0;
  if(!id){selRs=null;document.getElementById('rsInfo').style.display='none';document.getElementById('hasilCalc').style.display='none';document.getElementById('stepInput').style.display='none';return;}
  const pct=parseFloat(opt.dataset.pct)||0;
  const harga=parseInt(opt.dataset.harga)||0;
  const nama=opt.dataset.nama||opt.text;
  selRs={id,nama,pct,harga};
  document.getElementById('rsInfo').style.display='block';
  document.getElementById('rsInfo').innerHTML='✅ <strong>'+nama+'</strong> — Keuntungan: <strong>'+pct+'%</strong>'+(harga?' · Rp '+harga.toLocaleString('id-ID')+' /voucher':'');
  document.getElementById('stepInput').style.display='block';
  hitungOtomatis();
}

function hitungOtomatis(){
  if(!selRs) return;
  const total=parseInt(document.getElementById('inpTotal').value)||0;
  if(total<=0){document.getElementById('hasilCalc').style.display='none';return;}
  const bagian=Math.round(total*selRs.pct/100);
  const bersih=total-bagian;
  const vcr=selRs.harga>0?Math.floor(total/selRs.harga):0;
  document.getElementById('hasilCalc').style.display='block';
  document.getElementById('cTotal').textContent=rp(total);
  document.getElementById('cPct').textContent=selRs.pct;
  document.getElementById('cBagian').textContent=rp(bagian);
  document.getElementById('cBersih').textContent=rp(bersih);
  if(vcr>0){document.getElementById('rowVcr').style.display='flex';document.getElementById('cVcr').textContent=vcr+' voucher';}
  else document.getElementById('rowVcr').style.display='none';
}

function kirimPenagihan(){
  if(!selCabang){alert('Pilih cabang terlebih dahulu!');return;}
  if(!selRs){alert('Pilih reseller terlebih dahulu!');return;}
  const total=parseInt(document.getElementById('inpTotal').value)||0;
  if(!total){alert('Isi total pendapatan!');return;}
  const bagian=Math.round(total*selRs.pct/100);
  const bersih=total-bagian;
  const vcr=selRs.harga>0?Math.floor(total/selRs.harga):0;
  const cat=document.getElementById('inpCatatan').value.trim();
  if(!confirm('Konfirmasi:\nCabang: '+selCabang.nama+'\nReseller: '+selRs.nama+'\nTotal: '+rp(total)+'\nBagian Reseller: '+rp(bagian)+'\nBersih: '+rp(bersih)+'\n\nSimpan?')) return;
  const btn=document.getElementById('btnKirim');
  btn.disabled=true; btn.textContent='⏳ Menyimpan...';
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'simpan',cabang_id:selCabang.id,cabang_nama:selCabang.nama,reseller_id:selRs.id,reseller_nama:selRs.nama,persen:selRs.pct,total,harga_voucher:selRs.harga,voucher_terjual:vcr,catatan:cat})
  }).then(r=>r.json()).then(d=>{
    if(d.ok){alert('✅ Laporan berhasil disimpan!');location.reload();}
    else alert('❌ Gagal: '+(d.msg||'Error'));
  }).catch(e=>alert('❌ '+e.message))
  .finally(()=>{btn.disabled=false;btn.textContent='🚀 Simpan Laporan Penagihan & Update Excel';});
}

// Edit
let _ep={};
function bukaEditPenagihan(d){
  _ep=d;
  document.getElementById('epId').value=d.id;
  document.getElementById('epRid').value=d.reseller_id;
  document.getElementById('epPct').value=d.persen;
  document.getElementById('epHarga').value=d.harga_voucher;
  document.getElementById('epRnm').textContent=(d.cabang_nama?d.cabang_nama+' › ':'')+d.reseller_nama+' ('+d.persen+'%)';
  document.getElementById('epTotal').value=d.total;
  document.getElementById('epCat').value=d.catatan||'';
  hitungEditOtomatis(); oM('mEditPen');
}
function hitungEditOtomatis(){
  const total=parseInt(document.getElementById('epTotal').value)||0;
  const pct=parseFloat(document.getElementById('epPct').value)||0;
  const harga=parseInt(document.getElementById('epHarga').value)||0;
  const bagian=Math.round(total*pct/100);
  document.getElementById('epBagian').textContent=rp(bagian);
  document.getElementById('epBersih').textContent=rp(total-bagian);
  const vcr=harga>0?Math.floor(total/harga):0;
  document.getElementById('epVcr').textContent=vcr>0?vcr+' voucher':'—';
}
function simpanEditPenagihan(){
  const id=parseInt(document.getElementById('epId').value)||0;
  const total=parseInt(document.getElementById('epTotal').value)||0;
  if(!total){alert('Isi total pendapatan!');return;}
  if(!confirm('Simpan perubahan?')) return;
  const btn=document.getElementById('btnSimpanEdit');
  btn.disabled=true; btn.textContent='⏳ Menyimpan...';
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'edit',id,reseller_id:parseInt(document.getElementById('epRid').value)||0,reseller_nama:_ep.reseller_nama,persen:parseFloat(document.getElementById('epPct').value)||0,total,harga_voucher:parseInt(document.getElementById('epHarga').value)||0,voucher_terjual:Math.floor(total/(parseInt(document.getElementById('epHarga').value)||1)),catatan:document.getElementById('epCat').value.trim()})
  }).then(r=>r.json()).then(d=>{
    if(d.ok){alert('✅ Data diperbarui!');location.reload();}
    else alert('❌ Gagal: '+(d.msg||'Error'));
  }).catch(e=>alert('❌ '+e.message))
  .finally(()=>{btn.disabled=false;btn.textContent='💾 Simpan';});
}
function hapusPenagihan(id,nama){
  if(!confirm('Hapus data penagihan reseller "'+nama+'"?\nData akan dihapus permanen!')) return;
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'hapus',id})
  }).then(r=>r.json()).then(d=>{
    if(d.ok){alert('🗑️ Data dihapus!');location.reload();}
    else alert('❌ Gagal: '+(d.msg||'Error'));
  }).catch(e=>alert('❌ '+e.message));
}
</script>
<?php endPage();?>
