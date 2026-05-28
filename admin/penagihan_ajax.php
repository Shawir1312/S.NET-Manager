<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/MiniXlsx.php';

// Set header JSON lebih awal agar AJAX tidak dapat redirect HTML
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Auth check khusus AJAX — kembalikan JSON error bukan redirect
if(!isAdmin()){
    echo json_encode(['ok'=>false,'msg'=>'Sesi habis, silakan login ulang']);
    exit;
}

$db = db();
$u  = adminUser();
$isAdmin = in_array($u['role']??'',['admin','superadmin','operator']);

// PENTING: php://input hanya bisa dibaca sekali di PHP 7.0
$_rawInput = file_get_contents('php://input');
$_jsonBody  = json_decode($_rawInput, true);
$act  = $_GET['action'] ?? ($_POST['action'] ?? ($_jsonBody['action'] ?? ''));
$body = is_array($_jsonBody) ? $_jsonBody : [];

// ── Download File Excel Dinamis & Rebuild ──
if($act==='download_file'){
    $filename = $_GET['file'] ?? '';
    if(!$filename || !preg_match('/^laporan_(.+)_(\d{4}-\d{2})\.xlsx$/', $filename, $m)){
        die('File tidak valid');
    }
    
    $slug = $m[1];
    $bulan = $m[2];
    
    $lapDir = APP_BASE.'/admin/laporan';
    if(!is_dir($lapDir)) mkdir($lapDir, 0755, true);
    $path = $lapDir.'/'.$filename;
    
    // SELALU REBUILD sebelum download agar sinkron dan 100% update
    if($slug === 'SEMUA'){
        buildExcelSemua($db, $bulan, $path);
    } else {
        $cabangs = $db->query("SELECT id, nama FROM cabang")->fetchAll();
        $cid = 0;
        foreach($cabangs as $c){
            if(preg_replace('/[^a-zA-Z0-9]/', '_', $c['nama']) === $slug){
                $cid = $c['id']; break;
            }
        }
        if($cid){
            buildExcelCabang($db, $cid, $bulan, $path);
        }
    }
    
    if(!file_exists($path)){ echo 'Gagal menyiapkan file excel'; exit; }
    
    // Header anti-cache ketat
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.filesize($path));
    readfile($path); exit;
}

// ── Download Excel (legacy) ──
if($act==='download'){
    $cabang_id = (int)($_GET['cabang_id']??0);
    $bulan     = $_GET['bulan']??'';  // format YYYY-MM, kosong = semua

    $lapDir = APP_BASE.'/admin/laporan';
    if(!is_dir($lapDir)) mkdir($lapDir, 0755, true);

    if($cabang_id && $bulan){
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_',
            $db->prepare("SELECT nama FROM cabang WHERE id=? LIMIT 1")->execute([$cabang_id])
            ? ($db->query("SELECT nama FROM cabang WHERE id=$cabang_id LIMIT 1")->fetchColumn() ?: 'cabang')
            : 'cabang'
        );
        $path = "$lapDir/laporan_{$slug}_{$bulan}.xlsx";
        buildExcelCabang($db, $cabang_id, $bulan, $path); // Selalu rebuild
    } elseif($bulan) {
        $path = "$lapDir/laporan_SEMUA_{$bulan}.xlsx";
        buildExcelSemua($db, $bulan, $path); // Selalu rebuild
    } else {
        $bulan = date('Y-m');
        $path  = "$lapDir/laporan_SEMUA_{$bulan}.xlsx";
        buildExcelSemua($db, $bulan, $path); // Selalu rebuild
    }

    if(!file_exists($path)){ echo json_encode(['ok'=>false,'msg'=>'File belum ada']); exit; }
    $fname = basename($path);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Content-Length: '.filesize($path));
    readfile($path); exit;
}

// ── List File Arsip ──
if($act==='list_files'){
    if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit; }
    $lapDir = APP_BASE.'/admin/laporan';
    $files  = [];
    if(is_dir($lapDir)){
        foreach(glob($lapDir.'/laporan_*.xlsx') as $f){
            $files[] = [
                'name'    => basename($f),
                'size'    => filesize($f),
                'mtime'   => date('d/m/Y H:i', filemtime($f)),
                'url'     => '/admin/laporan/'.basename($f),
            ];
        }
        usort($files,function($a,$b){return strcmp($b['name'],$a['name']);});
    }
    echo json_encode(['ok'=>true,'files'=>$files]);
    exit;
}

// ── Simpan Penagihan ──
if($act==='simpan'){
    $rid     = (int)($body['reseller_id']??0);
    $rnm     = trim($body['reseller_nama']??'');
    $pct     = (float)($body['persen']??0);
    $total   = (int)($body['total']??0);
    $harga   = (int)($body['harga_voucher']??0);
    $vcr     = (int)($body['voucher_terjual']??0);
    $cat     = trim($body['catatan']??'');
    $cid     = (int)($body['cabang_id']??1);
    $cnm     = trim($body['cabang_nama']??'Pusat');

    if(!$rid||!$total){ echo json_encode(['ok'=>false,'msg'=>'Data tidak lengkap']); exit; }

    $bagian = (int)round($total * $pct / 100);
    $bersih = $total - $bagian;

    try {
        $db->prepare("INSERT INTO laporan_penagihan(teknisi_id,teknisi_nama,reseller_id,reseller_nama,persen_reseller,total_pendapatan,bagian_reseller,pendapatan_bersih,harga_voucher,voucher_terjual,catatan,cabang_id,cabang_nama,tanggal_penagihan)VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
           ->execute([$u['id'],$u['full_name'],$rid,$rnm,$pct,$total,$bagian,$bersih,$harga,$vcr,$cat,$cid,$cnm]);
    } catch(Exception $e){ echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }

    auditLog('penagihan',"cabang:$cnm reseller:$rnm total:$total");
    buildExcelAuto($db, $cid, date('Y-m'));
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Edit Penagihan ──
if($act==='edit'){
    $id    = (int)($body['id']??0);
    $total = (int)($body['total']??0);
    $cat   = trim($body['catatan']??'');
    $pct   = (float)($body['persen']??0);
    $rid   = (int)($body['reseller_id']??0);
    $rnm   = trim($body['reseller_nama']??'');
    $harga = (int)($body['harga_voucher']??0);
    $vcr   = (int)($body['voucher_terjual']??0);

    if(!$id||!$total){ echo json_encode(['ok'=>false,'msg'=>'Data tidak lengkap']); exit; }

    $row = $db->prepare("SELECT * FROM laporan_penagihan WHERE id=? LIMIT 1");
    $row->execute([$id]); $row = $row->fetch();
    if(!$row){ echo json_encode(['ok'=>false,'msg'=>'Data tidak ditemukan']); exit; }
    if(!$isAdmin && (int)$row['teknisi_id'] !== (int)$u['id']){
        echo json_encode(['ok'=>false,'msg'=>'Tidak boleh edit data milik teknisi lain']); exit;
    }

    $bagian = (int)round($total * $pct / 100);
    $bersih = $total - $bagian;

    try {
        $db->prepare("UPDATE laporan_penagihan SET
            reseller_id=?,reseller_nama=?,persen_reseller=?,
            total_pendapatan=?,bagian_reseller=?,pendapatan_bersih=?,
            harga_voucher=?,voucher_terjual=?,catatan=?
            WHERE id=?")
           ->execute([$rid,$rnm,$pct,$total,$bagian,$bersih,$harga,$vcr,$cat,$id]);
    } catch(Exception $e){ echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }

    auditLog('edit_penagihan',"id:$id total:$total");
    $bulanData = date('Y-m', strtotime($row['tanggal_penagihan']));
    buildExcelAuto($db, (int)($row['cabang_id']??1), $bulanData);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Hapus Penagihan (admin only) ──
if($act==='hapus'){
    if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit; }
    $id = (int)($body['id']??0);
    if(!$id){ echo json_encode(['ok'=>false,'msg'=>'ID tidak valid']); exit; }

    $row = $db->prepare("SELECT * FROM laporan_penagihan WHERE id=? LIMIT 1");
    $row->execute([$id]); $row = $row->fetch();
    if(!$row){ echo json_encode(['ok'=>false,'msg'=>'Data tidak ditemukan']); exit; }

    try {
        $db->prepare("DELETE FROM laporan_penagihan WHERE id=?")->execute([$id]);
    } catch(Exception $e){ echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }

    auditLog('hapus_penagihan',"id:$id");
    $bulanData = date('Y-m', strtotime($row['tanggal_penagihan']));
    buildExcelAuto($db, (int)($row['cabang_id']??1), $bulanData);
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Hapus Penagihan Per Bulan (admin only) ──
if($act==='hapus_bulan'){
    if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit; }
    $bulan = trim($body['bulan']??'');
    if(!$bulan || !preg_match('/^\d{4}-\d{2}$/', $bulan)){ echo json_encode(['ok'=>false,'msg'=>'Bulan tidak valid']); exit; }

    try {
        $db->prepare("DELETE FROM laporan_penagihan WHERE DATE_FORMAT(tanggal_penagihan,'%Y-%m')=?")->execute([$bulan]);
        
        // Hapus file Excel terkait bulan tersebut
        $lapDir = APP_BASE.'/admin/laporan';
        foreach(glob($lapDir.'/laporan_*_'.$bulan.'.xlsx') as $f){ @unlink($f); }
        foreach(glob($lapDir.'/laporan_*_'.str_replace('-', '_', $bulan).'.xlsx') as $f){ @unlink($f); }
    } catch(Exception $e){ echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]); exit; }

    auditLog('hapus_penagihan_bulan',"bulan:$bulan");
    echo json_encode(['ok'=>true]);
    exit;
}

// ── Hapus File Excel (admin only) ──
if($act==='hapus_file'){
    if(!$isAdmin){ echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit; }

    $filename = basename($body['filename']??'');
    // Validasi ketat: hanya file laporan_*.xlsx yang boleh dihapus
    if(!$filename || !preg_match('/^laporan_.+\.xlsx$/',$filename)){
        echo json_encode(['ok'=>false,'msg'=>'Nama file tidak valid']); exit;
    }

    $lapDir = APP_BASE.'/admin/laporan';
    $fullPath = $lapDir.'/'.$filename;

    // Pastikan path tidak keluar dari folder laporan (anti path traversal)
    if(strpos(realpath($lapDir),realpath(dirname($fullPath)))===false && realpath($fullPath)){
        // path traversal attempt
        echo json_encode(['ok'=>false,'msg'=>'Akses ditolak']); exit;
    }

    if(!file_exists($fullPath)){
        echo json_encode(['ok'=>false,'msg'=>'File tidak ditemukan']); exit;
    }

    if(unlink($fullPath)){
        auditLog('hapus_file_excel',$filename);
        echo json_encode(['ok'=>true]);
    } else {
        echo json_encode(['ok'=>false,'msg'=>'Gagal menghapus file — periksa permission folder']);
    }
    exit;
}

echo json_encode(['ok'=>false,'msg'=>'Action tidak dikenal']);

// ═══════════════════════════════════════════════════════
// Helpers nama bulan Indonesia
// ═══════════════════════════════════════════════════════
function namaBulanId(string $ym): string {
    list($y,$m) = explode('-',$ym);
    $bl=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April',
         '05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus',
         '09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
    return ($bl[$m]??$m).' '.$y;
}

// ═══════════════════════════════════════════════════════
// Auto-build Excel: generate file per cabang + per bulan
// File TIDAK ditimpa jika sudah ada di bulan sebelumnya
// ═══════════════════════════════════════════════════════
function buildExcelAuto(PDO $db, int $cabangId, string $bulan): void {
    $lapDir = APP_BASE.'/admin/laporan';
    if(!is_dir($lapDir)) mkdir($lapDir, 0755, true);

    // Ambil nama cabang
    $cabangNama = $db->prepare("SELECT nama FROM cabang WHERE id=? LIMIT 1");
    $cabangNama->execute([$cabangId]);
    $cabangNama = $cabangNama->fetchColumn() ?: 'Cabang';
    $slug = preg_replace('/[^a-zA-Z0-9]/', '_', $cabangNama);

    // Build file per-cabang per-bulan
    $pathCabang = "$lapDir/laporan_{$slug}_{$bulan}.xlsx";
    buildExcelCabang($db, $cabangId, $bulan, $pathCabang);

    // Build file SEMUA cabang bulan ini
    $pathSemua = "$lapDir/laporan_SEMUA_{$bulan}.xlsx";
    buildExcelSemua($db, $bulan, $pathSemua);
}

// ═══════════════════════════════════════════════════════
// Build Excel untuk 1 cabang 1 bulan
// ═══════════════════════════════════════════════════════
function buildExcelCabang(PDO $db, int $cabangId, string $bulan, string $path): void {
    $rows = $db->prepare(
        "SELECT lp.*, DATE_FORMAT(lp.tanggal_penagihan,'%d/%m/%Y %H:%i') AS tgl_fmt
         FROM laporan_penagihan lp
         WHERE lp.cabang_id=?
           AND DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m')=?
         ORDER BY lp.tanggal_penagihan ASC"
    );
    $rows->execute([$cabangId, $bulan]);
    $rows = $rows->fetchAll();

    // Nama cabang
    $s = $db->prepare("SELECT nama FROM cabang WHERE id=? LIMIT 1");
    $s->execute([$cabangId]);
    $cabangNama = $s->fetchColumn() ?: 'Cabang';

    $xlsx = new MiniXlsx();
    $rp = function($n){ return 'Rp '.number_format($n,0,',','.'); };
    $nb   = namaBulanId($bulan);

    $sheetRows = [];
    $sheetRows[] = ["Laporan Penagihan — $cabangNama — $nb"];
    $sheetRows[] = ['Digenerate: '.date('d/m/Y H:i')];
    $sheetRows[] = [];
    $sheetRows[] = ['No','Tanggal','Reseller','Total Pendapatan','% Reseller','Bagian Reseller','Pendapatan Bersih','Voucher','Catatan','Teknisi'];

    $no=1; $sT=0; $sB=0; $sBr=0; $sV=0;
    foreach($rows as $e){
        $sheetRows[] = [
            $no++, $e['tgl_fmt'], $e['reseller_nama'],
            $rp($e['total_pendapatan']), $e['persen_reseller'].'%',
            $rp($e['bagian_reseller']), $rp($e['pendapatan_bersih']),
            $e['voucher_terjual']?:'-', $e['catatan']?:'-', $e['teknisi_nama'],
        ];
        $sT+=$e['total_pendapatan']; $sB+=$e['bagian_reseller'];
        $sBr+=$e['pendapatan_bersih']; $sV+=$e['voucher_terjual'];
    }
    $sheetRows[] = [];
    $sheetRows[] = ['TOTAL','',' ',$rp($sT),'',$rp($sB),$rp($sBr),$sV?:'-','',''];

    if(empty($rows)) $sheetRows[] = ['(Belum ada data penagihan bulan ini)'];

    $xlsx->add($nb, $sheetRows);
    $xlsx->save($path);
}

// ═══════════════════════════════════════════════════════
// Build Excel semua cabang untuk 1 bulan (per-sheet cabang)
// ═══════════════════════════════════════════════════════
function buildExcelSemua(PDO $db, string $bulan, string $path): void {
    $cabangs = $db->query("SELECT id,nama FROM cabang WHERE is_active=1 ORDER BY nama ASC")->fetchAll();
    $xlsx    = new MiniXlsx();
    $rp = function($n){ return 'Rp '.number_format($n,0,',','.'); };
    $nb      = namaBulanId($bulan);
    $hasData = false;

    foreach($cabangs as $cab){
        $rows = $db->prepare(
            "SELECT lp.*, DATE_FORMAT(lp.tanggal_penagihan,'%d/%m/%Y %H:%i') AS tgl_fmt
             FROM laporan_penagihan lp
             WHERE lp.cabang_id=? AND DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m')=?
             ORDER BY lp.tanggal_penagihan ASC"
        );
        $rows->execute([$cab['id'], $bulan]);
        $rows = $rows->fetchAll();

        $sheetRows = [];
        $sheetRows[] = ["Laporan Penagihan — {$cab['nama']} — $nb"];
        $sheetRows[] = ['Digenerate: '.date('d/m/Y H:i')];
        $sheetRows[] = [];
        $sheetRows[] = ['No','Tanggal','Reseller','Total Pendapatan','% Reseller','Bagian Reseller','Pendapatan Bersih','Voucher','Catatan','Teknisi'];

        $no=1; $sT=0; $sB=0; $sBr=0; $sV=0;
        foreach($rows as $e){
            $sheetRows[] = [
                $no++, $e['tgl_fmt'], $e['reseller_nama'],
                $rp($e['total_pendapatan']), $e['persen_reseller'].'%',
                $rp($e['bagian_reseller']), $rp($e['pendapatan_bersih']),
                $e['voucher_terjual']?:'-', $e['catatan']?:'-', $e['teknisi_nama'],
            ];
            $sT+=$e['total_pendapatan']; $sB+=$e['bagian_reseller'];
            $sBr+=$e['pendapatan_bersih']; $sV+=$e['voucher_terjual'];
            $hasData = true;
        }
        if(!empty($rows)){
            $sheetRows[] = [];
            $sheetRows[] = ['TOTAL','',' ',$rp($sT),'',$rp($sB),$rp($sBr),$sV?:'-','',''];
        } else {
            $sheetRows[] = ['(Belum ada data)'];
        }

        $sheetName = substr($cab['nama'],0,28); // Excel sheet name max 31 chars
        $xlsx->add($sheetName, $sheetRows);
    }

    if(!$hasData && empty($cabangs)){
        $xlsx->add('Laporan', [['Belum ada data penagihan']]);
    }

    $xlsx->save($path);
}
