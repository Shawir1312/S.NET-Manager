<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
date_default_timezone_set('Asia/Jakarta');

require_once '/www/wwwroot/srv5.shaiwr.id/includes/MiniXlsx.php';

$pdo=new PDO(
    "mysql:unix_socket=/tmp/mysql.sock;dbname=snet1;charset=utf8mb4",
    'snet1','snet1',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);

$cnt=$pdo->query("SELECT COUNT(*) FROM laporan_penagihan")->fetchColumn();
echo "Total records: $cnt\n";
$bulans=$pdo->query("SELECT DISTINCT DATE_FORMAT(tanggal_penagihan,'%Y-%m') AS b FROM laporan_penagihan ORDER BY b")->fetchAll(PDO::FETCH_COLUMN);
echo "Bulan: ".implode(', ',$bulans)."\n";
$cabangs=$pdo->query("SELECT id,nama FROM cabang WHERE is_active=1")->fetchAll();
echo "Cabang: ".implode(', ',array_column($cabangs,'nama'))."\n\n";

$bulanId=['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni','07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$lapDir='/www/wwwroot/srv5.shaiwr.id/admin/laporan';
if(!is_dir($lapDir)) mkdir($lapDir,0755,true);

$rp=function($n){ return 'Rp '.number_format((int)$n,0,',','.'); };

foreach($bulans as $bln){
    $parts=explode('-',$bln);
    $nb=($bulanId[$parts[1]]??$parts[1]).' '.$parts[0];
    $slug=str_replace('-','_',$bln);

    // Per cabang
    foreach($cabangs as $cab){
        $rows=$pdo->prepare("SELECT lp.*,DATE_FORMAT(lp.tanggal_penagihan,'%d/%m/%Y %H:%i') AS tgl_fmt FROM laporan_penagihan lp WHERE lp.cabang_id=? AND DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m')=? ORDER BY lp.tanggal_penagihan ASC");
        $rows->execute([$cab['id'],$bln]);
        $data=$rows->fetchAll();

        $xlsx=new MiniXlsx();
        $sheet=[
            ["Laporan Penagihan - {$cab['nama']} - $nb"],
            ['Digenerate: '.date('d/m/Y H:i')],[],
            ['No','Tanggal','Reseller','Total','% Reseller','Bagian Reseller','Pendapatan Bersih','Voucher','Catatan','Teknisi'],
        ];
        $no=1;$sT=0;$sB=0;$sBr=0;$sV=0;
        foreach($data as $e){
            $sheet[]=[$no++,$e['tgl_fmt'],$e['reseller_nama'],$rp($e['total_pendapatan']),$e['persen_reseller'].'%',$rp($e['bagian_reseller']),$rp($e['pendapatan_bersih']),$e['voucher_terjual']?:0,$e['catatan']?:'-',$e['teknisi_nama']];
            $sT+=$e['total_pendapatan'];$sB+=$e['bagian_reseller'];$sBr+=$e['pendapatan_bersih'];$sV+=$e['voucher_terjual'];
        }
        if(empty($data)) $sheet[]=['(Tidak ada data)'];
        else $sheet[]=['TOTAL','',' ',$rp($sT),'',$rp($sB),$rp($sBr),$sV,'',''];
        $xlsx->add($nb,$sheet);

        $cSlug=preg_replace('/[^a-zA-Z0-9_-]/','_',$cab['nama']);
        $path="$lapDir/laporan_{$cSlug}_{$slug}.xlsx";
        $ok=$xlsx->save($path);
        echo ($ok?'OK':'FAIL')." $path (".count($data)." rows)\n";
    }

    // Semua cabang (multi-sheet)
    $xlsx2=new MiniXlsx();
    foreach($cabangs as $cab){
        $rows=$pdo->prepare("SELECT lp.*,DATE_FORMAT(lp.tanggal_penagihan,'%d/%m/%Y %H:%i') AS tgl_fmt FROM laporan_penagihan lp WHERE lp.cabang_id=? AND DATE_FORMAT(lp.tanggal_penagihan,'%Y-%m')=? ORDER BY lp.tanggal_penagihan ASC");
        $rows->execute([$cab['id'],$bln]);
        $data=$rows->fetchAll();
        $sheet=[
            ["Laporan - {$cab['nama']} - $nb"],['Digenerate: '.date('d/m/Y H:i')],[],
            ['No','Tanggal','Reseller','Total','% Reseller','Bagian Reseller','Pendapatan Bersih','Voucher','Catatan','Teknisi'],
        ];
        $no=1;$sT=0;$sB=0;$sBr=0;$sV=0;
        foreach($data as $e){
            $sheet[]=[$no++,$e['tgl_fmt'],$e['reseller_nama'],$rp($e['total_pendapatan']),$e['persen_reseller'].'%',$rp($e['bagian_reseller']),$rp($e['pendapatan_bersih']),$e['voucher_terjual']?:0,$e['catatan']?:'-',$e['teknisi_nama']];
            $sT+=$e['total_pendapatan'];$sB+=$e['bagian_reseller'];$sBr+=$e['pendapatan_bersih'];$sV+=$e['voucher_terjual'];
        }
        if(empty($data)) $sheet[]=['(Tidak ada data)'];
        else $sheet[]=['TOTAL','',' ',$rp($sT),'',$rp($sB),$rp($sBr),$sV,'',''];
        $xlsx2->add(substr($cab['nama'],0,28),$sheet);
    }
    $path2="$lapDir/laporan_SEMUA_{$slug}.xlsx";
    $ok2=$xlsx2->save($path2);
    echo ($ok2?'OK':'FAIL')." $path2\n\n";
}

echo "=== SELESAI ===\n";
echo "Total file: ".count(glob($lapDir.'/*.xlsx'))."\n";
