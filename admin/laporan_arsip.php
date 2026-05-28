<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin('operator');
$db=db();

// Scan file Excel di folder laporan
$lapDir=APP_BASE.'/admin/laporan';
if(!is_dir($lapDir)) mkdir($lapDir,0755,true);

$files=[];
foreach(glob($lapDir.'/laporan_*.xlsx') as $f){
    $base=basename($f,'.xlsx');
    if(preg_match('/^laporan_(.+)_(\d{4}-\d{2})$/',$base,$m)){
        $slug=$m[1]; $bulan=$m[2];
        list($y,$mo)=explode('-',$bulan);
        $bulanId=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
                  '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
                  '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
        $namaBulan=($bulanId[$mo] ?? $mo).' '.$y;
        $namaCabang=str_replace('_',' ',$slug);
        $isSemua=($slug==='SEMUA');
        $files[$bulan][]=[
            'path'       => $f,
            'name'       => basename($f),
            'slug'       => $slug,
            'cabang'     => $isSemua?'Semua Cabang':$namaCabang,
            'is_semua'   => $isSemua,
            'bulan'      => $bulan,
            'bulan_nama' => $namaBulan,
            'size'       => filesize($f),
            'mtime'      => date('d/m/Y H:i',filemtime($f)),
            'url'        => '/admin/laporan/'.basename($f),
        ];
    }
}
krsort($files);

// Ambil semua record dari DB, group per bulan
$records=$db->query(
    "SELECT lp.*,
            DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m') AS bln,
            DATE_FORMAT(lp.tanggal_penagihan,'%d/%m/%Y %H:%i') AS tgl_fmt,
            u.full_name AS teknisi_label
     FROM laporan_penagihan lp
     LEFT JOIN users u ON u.id=lp.teknisi_id
     ORDER BY lp.tanggal_penagihan DESC"
)->fetchAll();

$byBulan=[]; $statsBulan=[];
foreach($records as $r){
    $byBulan[$r['bln']][]=$r;
    $bln=$r['bln']; $cnm=$r['cabang_nama']?:'Pusat';
    if(!isset($statsBulan[$bln][$cnm])) $statsBulan[$bln][$cnm]=['jml'=>0,'total'=>0,'bersih'=>0];
    $statsBulan[$bln][$cnm]['jml']++;
    $statsBulan[$bln][$cnm]['total']+=$r['total_pendapatan'];
    $statsBulan[$bln][$cnm]['bersih']+=$r['pendapatan_bersih'];
}

$allBulans=array_unique(array_merge(array_keys($files),array_keys($byBulan)));
rsort($allBulans);

$bulanId=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
          '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

startPage('Arsip Laporan Penagihan');
?>
<style>
.arsip-month{background:#fff;border:1.5px solid var(--g200);border-radius:14px;overflow:hidden;margin-bottom:16px}
.arsip-month-hdr{background:linear-gradient(135deg,var(--blue-d),var(--blue));color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;cursor:pointer;user-select:none}
.arsip-month-title{font-weight:900;font-size:1rem;letter-spacing:.5px}
.arsip-month-body{padding:14px 18px}
.arsip-file-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:14px}
.arsip-file{border:1.5px solid var(--g200);border-radius:10px;padding:12px 14px;display:flex;flex-direction:column;gap:6px;transition:.2s;background:var(--g50)}
.arsip-file:hover{border-color:var(--green);box-shadow:0 2px 10px rgba(22,163,74,.1);background:#fff}
.arsip-file.semua{border-color:var(--blue);background:linear-gradient(135deg,#EFF6FF,#F8FAFF)}
.arsip-file-nm{font-weight:800;font-size:.85rem;color:var(--g900)}
.arsip-file-sz{font-size:.72rem;color:var(--g400);font-weight:600}
.stat-inline{display:flex;gap:6px;flex-wrap:wrap;margin-top:4px}
.stat-chip{background:var(--g100);padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:700;color:var(--g600)}
.empty-month{color:var(--g400);font-size:.83rem;text-align:center;padding:14px}
/* Records table */
.rec-wrap{margin-top:10px;border:1.5px solid var(--g200);border-radius:10px;overflow:hidden}
.rec-hdr{background:var(--g50);padding:10px 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--g200)}
.rec-hdr-t{font-weight:800;font-size:.83rem;color:var(--g700)}
.rec-tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.rec-tbl th{background:var(--g100);font-weight:700;color:var(--g600);font-size:.7rem;text-transform:uppercase;letter-spacing:.5px;padding:8px 10px;text-align:left;border-bottom:1px solid var(--g200)}
.rec-tbl td{padding:8px 10px;border-bottom:1px solid var(--g100);vertical-align:middle}
.rec-tbl tr:last-child td{border-bottom:none}
.rec-tbl tr:hover td{background:#FAFBFF}
.rec-del{background:none;border:1.5px solid var(--red);color:var(--red);border-radius:6px;padding:3px 8px;font-size:.72rem;font-weight:700;cursor:pointer;transition:.2s}
.rec-del:hover{background:var(--red);color:#fff}
.toggle-arrow{transition:.3s;display:inline-block}
.collapsed .toggle-arrow{transform:rotate(-90deg)}
@media(max-width:600px){.arsip-file-grid{grid-template-columns:1fr}.rec-tbl{font-size:.72rem}.rec-tbl th,.rec-tbl td{padding:6px 7px}}
</style>

<div class="ph">
  <div>
    <div class="ph-t">📦 Arsip Laporan Penagihan</div>
    <div class="ph-s">File Excel &amp; data record per cabang per bulan — admin bisa hapus data yang salah</div>
  </div>
  <div style="display:flex;gap:6px;flex-wrap:wrap">
    <a href="/admin/reseller.php" class="btn bo bsm">← Kelola Reseller</a>
    <a href="/admin/penagihan.php" class="btn bp bsm">💰 Input Penagihan</a>
  </div>
</div>

<?php if(!empty($allBulans)): ?>
<div class="arsip-month" style="border-color: #FCA5A5; background: #FEF2F2;">
  <div class="arsip-month-hdr" style="background: linear-gradient(135deg, #DC2626, #EF4444); cursor: default;">
    <div class="arsip-month-title">🗑️ Hapus Masal Laporan Per Bulan</div>
  </div>
  <div class="arsip-month-body">
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <select id="selBulanHapus" style="width: auto; padding: 6px 10px; font-size: .85rem; border: 1.5px solid #FCA5A5; border-radius: 8px; font-family: inherit; outline: none; background: #fff;">
        <option value="">— Pilih Bulan —</option>
        <?php foreach($allBulans as $b): 
            list($y,$mo)=explode('-',$b);
            $namaBulan=($bulanId[$mo] ?? $mo).' '.$y;
        ?>
        <option value="<?=$b?>"><?=$namaBulan?></option>
        <?php endforeach;?>
      </select>
      <button class="btn bd bsm" onclick="hapusBulan()" style="font-weight:800; padding: 6px 12px; background: #EF4444; color: #fff; border: none; border-radius: 6px; cursor: pointer;">Hapus Permanen</button>
    </div>
    <div style="font-size: .75rem; color: #B91C1C; margin-top: 6px; font-weight: 700;">
      ⚠️ Peringatan: Tindakan ini akan menghapus permanen seluruh record penagihan dan file Excel terkait untuk bulan yang dipilih.
    </div>
  </div>
</div>
<?php endif; ?>

<?php if(empty($allBulans)):?>
<div class="empty"><div class="eico">📦</div>Belum ada laporan yang tersimpan.<br><small>File akan dibuat otomatis saat teknisi menyimpan penagihan pertama.</small></div>
<?php else:?>

<?php foreach($allBulans as $bln):
    list($y,$mo)=explode('-',$bln);
    $namaBulan=($bulanId[$mo] ?? $mo).' '.$y;
    $filesbulan=$files[$bln]??[];
    $statsbulan=$statsBulan[$bln]??[];
    $recsbulan=$byBulan[$bln]??[];
    $totAll=0; $bersihAll=0; $jmlAll=count($recsbulan);
    foreach($recsbulan as $r){ $totAll+=$r['total_pendapatan']; $bersihAll+=$r['pendapatan_bersih']; }
    $colId='col-'.$bln;
?>
<div class="arsip-month">
  <!-- Header (clickable toggle) -->
  <div class="arsip-month-hdr" onclick="toggleBulan('<?=$colId?>')">
    <div>
      <div class="arsip-month-title">
        <span class="toggle-arrow" id="arr-<?=$colId?>">▼</span>
        📅 <?=$namaBulan?>
      </div>
      <?php if($totAll>0):?>
      <div style="font-size:.75rem;opacity:.8;margin-top:2px">
        Total: <strong>Rp <?=number_format($totAll,0,',','.')?></strong> ·
        Bersih: <strong>Rp <?=number_format($bersihAll,0,',','.')?></strong> ·
        <?=$jmlAll?> transaksi
      </div>
      <?php endif;?>
    </div>
    <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
      <?php if($jmlAll>0):?>
      <span class="bdg bblue"><?=$jmlAll?> record</span>
      <?php endif;?>
      <span class="bdg bon"><?=count($filesbulan)?> file</span>
    </div>
  </div>

  <!-- Body (collapsible) -->
  <div class="arsip-month-body" id="<?=$colId?>">

    <!-- Ringkasan per cabang -->
    <?php if(!empty($statsbulan)):?>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
      <?php foreach($statsbulan as $cnm=>$st):?>
      <div style="background:var(--g50);border:1px solid var(--g200);border-radius:8px;padding:6px 10px;font-size:.78rem;font-weight:700">
        📍 <?=h($cnm?:'Pusat')?>:
        <span style="color:var(--blue-d)">Rp <?=number_format($st['total'],0,',','.')?></span>
        <span style="color:var(--g400);font-weight:500"> (<?=$st['jml']?> trx)</span>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>

    <!-- File Excel download -->
    <?php if(!empty($filesbulan)):?>
    <div class="arsip-file-grid">
      <?php
      usort($filesbulan,function($a,$b){$s=$a['is_semua']-$b['is_semua'];return $s?$s:strcmp($a['cabang'],$b['cabang']);});
      foreach($filesbulan as $fl):?>
      <div class="arsip-file <?=$fl['is_semua']?'semua':''?>">
        <div class="arsip-file-nm"><?=$fl['is_semua']?'🌐':'📍'?> <?=h($fl['cabang'])?></div>
        <div class="arsip-file-sz"><?=number_format($fl['size']/1024,1)?> KB · <?=$fl['mtime']?></div>
        <?php if(isset($statsbulan[$fl['cabang']])):$st=$statsbulan[$fl['cabang']];?>
        <div class="stat-inline">
          <span class="stat-chip"><?=$st['jml']?> trx</span>
          <span class="stat-chip">Rp <?=number_format($st['total'],0,',','.')?></span>
        </div>
        <?php endif;?>
        <a href="/admin/penagihan_ajax.php?action=download_file&file=<?=urlencode($fl['name'])?>" class="btn bs bsm" style="font-weight:800;text-align:center">⬇️ Download Excel</a>
        <button class="btn bd bsm" style="font-weight:800;margin-top:3px" onclick="hapusFile('<?=h(addslashes($fl['name']))?>','<?=h(addslashes($fl['cabang']))?>')">🗑️ Hapus File</button>
      </div>
      <?php endforeach;?>
    </div>
    <?php else:?>
    <div class="empty-month">⚠️ File Excel belum digenerate untuk bulan ini.</div>
    <?php endif;?>

    <!-- Tabel Detail Record (bisa hapus) -->
    <?php if(!empty($recsbulan)):?>
    <div class="rec-wrap">
      <div class="rec-hdr">
        <div class="rec-hdr-t">🗂️ Detail Record — <?=$jmlAll?> transaksi</div>
      </div>
      <div style="overflow-x:auto">
      <table class="rec-tbl">
        <thead>
          <tr>
            <th>Waktu</th>
            <th>Cabang</th>
            <th>Reseller</th>
            <th>Total</th>
            <th>Bag. Reseller</th>
            <th>Bersih</th>
            <th>Voucher</th>
            <th>Catatan</th>
            <th>Ditagih Oleh</th>
            <th>Hapus</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($recsbulan as $r):?>
        <tr id="row-<?=(int)$r['id']?>">
          <td style="white-space:nowrap;font-size:.75rem">
            <div style="font-weight:700"><?=date('d/m/Y',strtotime($r['tanggal_penagihan']))?></div>
            <div style="color:var(--g400)"><?=date('H:i',strtotime($r['tanggal_penagihan']))?></div>
          </td>
          <td><span class="bdg bpur" style="font-size:.65rem"><?=h($r['cabang_nama']?:'—')?></span></td>
          <td>
            <div style="font-weight:800"><?=h($r['reseller_nama'])?></div>
            <div style="font-size:.7rem;color:var(--g400)"><?=number_format($r['persen_reseller'],1)?>%</div>
          </td>
          <td style="font-weight:800;color:var(--blue-d)">Rp <?=number_format($r['total_pendapatan'],0,',','.')?></td>
          <td style="color:var(--red-d);font-weight:700">Rp <?=number_format($r['bagian_reseller'],0,',','.')?></td>
          <td style="color:var(--green);font-weight:800">Rp <?=number_format($r['pendapatan_bersih'],0,',','.')?></td>
          <td><?=$r['voucher_terjual']?'<span class="bdg bblue" style="font-size:.65rem">'.$r['voucher_terjual'].'</span>':'<span style="color:var(--g300)">—</span>'?></td>
          <td style="font-size:.75rem;color:var(--g500);max-width:120px;word-break:break-word"><?=h($r['catatan']?:'—')?></td>
          <td style="font-size:.75rem;font-weight:700;white-space:nowrap">
            <?=h($r['teknisi_label']??$r['teknisi_nama']??'—')?>
          </td>
          <td>
            <button class="rec-del" onclick="hapusRecord(<?=(int)$r['id']?>,'<?=h(addslashes($r['reseller_nama']))?>')" title="Hapus record ini">🗑️</button>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
      </div>
    </div>
    <?php endif;?>

  </div><!-- end body -->
</div>
<?php endforeach;?>

<?php endif;?>

<script>
function toggleBulan(id){
  var el=document.getElementById(id);
  var arr=document.getElementById('arr-'+id);
  if(el.style.display==='none'){
    el.style.display='block';
    arr.style.transform='';
  } else {
    el.style.display='none';
    arr.style.transform='rotate(-90deg)';
  }
}

function hapusBulan(){
  var sel = document.getElementById('selBulanHapus');
  var bln = sel.value;
  if(!bln){ alert('Silakan pilih bulan yang ingin dihapus.'); return; }
  var text = sel.options[sel.selectedIndex].text;
  
  if(!confirm('⚠️ PERINGATAN KRITIS!\n\nAnda yakin ingin menghapus SELURUH data penagihan dan file Excel untuk bulan '+text+'?\n\nData yang dihapus TIDAK DAPAT dikembalikan!')) return;
  
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'hapus_bulan', bulan: bln})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.ok){
      alert('🗑️ Seluruh data bulan '+text+' berhasil dihapus permanen!');
      location.reload();
    } else {
      alert('❌ Gagal hapus: '+(d.msg||'Error'));
    }
  })
  .catch(function(e){ alert('❌ '+e.message); });
}

function hapusRecord(id, nama){
  if(!confirm('Hapus record penagihan reseller "'+nama+'"?\n\nData akan dihapus permanen dan file Excel bulan ini akan diperbarui.')) return;
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'hapus',id:id})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.ok){
      var row=document.getElementById('row-'+id);
      if(row){
        row.style.background='#FEE2E2';
        row.style.transition='opacity .4s';
        setTimeout(function(){ row.style.opacity='0'; },200);
        setTimeout(function(){ row.remove(); location.reload(); },700);
      }
    } else {
      alert('❌ Gagal hapus: '+(d.msg||'Error tidak diketahui'));
    }
  })
  .catch(function(e){ alert('❌ '+e.message); });
}
function hapusFile(nama, cabang){
  if(!confirm('Hapus file Excel "'+nama+'"?\n\nFile akan dihapus permanen dari server.')) return;
  fetch('/admin/penagihan_ajax.php',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({action:'hapus_file',filename:nama})
  })
  .then(function(r){ return r.json(); })
  .then(function(d){
    if(d.ok){
      alert('🗑️ File "'+nama+'" berhasil dihapus!');
      location.reload();
    } else {
      alert('❌ Gagal hapus file: '+(d.msg||'Error'));
    }
  })
  .catch(function(e){ alert('❌ '+e.message); });
}
</script>
<?php endPage();?>
