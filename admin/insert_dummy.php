<?php
error_reporting(E_ALL);
ini_set('display_errors',1);
date_default_timezone_set('Asia/Jakarta');

// Koneksi langsung tanpa config.php
$pdo=new PDO(
    "mysql:unix_socket=/tmp/mysql.sock;dbname=snet1;charset=utf8mb4",
    'snet1','snet1',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
);

// Ambil cabang
$cabangs=$pdo->query("SELECT id,nama FROM cabang WHERE is_active=1 ORDER BY id ASC")->fetchAll();
$resellers=$pdo->query("SELECT id,nama,cabang_id,persen_keuntungan,harga_voucher FROM resellers WHERE is_active=1")->fetchAll();

echo "Cabang: ".implode(', ',array_column($cabangs,'nama'))."\n";
echo "Reseller: ".implode(', ',array_column($resellers,'nama'))."\n\n";

if(empty($cabangs)){ echo "ERROR: Tidak ada cabang\n"; exit(1); }
if(empty($resellers)){ echo "ERROR: Tidak ada reseller\n"; exit(1); }

// Map reseller per cabang
$rByC=[];
foreach($resellers as $r) $rByC[$r['cabang_id']][]=$r;

// 20 data dummy Jan-Mei 2026
$dummy=[
    ['2026-01-05',0,800000],
    ['2026-01-12',1,650000],
    ['2026-01-20',0,920000],
    ['2026-01-28',1,500000],
    ['2026-02-03',0,750000],
    ['2026-02-10',1,880000],
    ['2026-02-18',0,1100000],
    ['2026-02-25',1,600000],
    ['2026-03-04',0,950000],
    ['2026-03-11',1,720000],
    ['2026-03-19',0,840000],
    ['2026-03-27',1,990000],
    ['2026-04-02',0,1050000],
    ['2026-04-09',1,780000],
    ['2026-04-16',0,670000],
    ['2026-04-23',1,1200000],
    ['2026-05-01',0,890000],
    ['2026-05-08',1,760000],
    ['2026-05-15',0,1000000],
    ['2026-05-22',1,550000],
];

$stmt=$pdo->prepare("INSERT INTO laporan_penagihan(teknisi_id,teknisi_nama,reseller_id,reseller_nama,persen_reseller,total_pendapatan,bagian_reseller,pendapatan_bersih,harga_voucher,voucher_terjual,catatan,cabang_id,cabang_nama,tanggal_penagihan) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

$n=0;
foreach($dummy as $i=>$d){
    $ci=$d[1]%count($cabangs);
    $cab=$cabangs[$ci];
    // Pilih reseller yang masuk ke cabang ini
    $rs=null;
    if(!empty($rByC[$cab['id']])) $rs=$rByC[$cab['id']][$i%count($rByC[$cab['id']])];
    else $rs=$resellers[$i%count($resellers)];

    $total=(int)$d[2];
    $pct=(float)($rs['persen_keuntungan']?:25);
    $bagian=(int)round($total*$pct/100);
    $bersih=$total-$bagian;
    $harga=(int)($rs['harga_voucher']?:5000);
    $vcr=$harga>0?(int)round($total/$harga):0;

    $stmt->execute([1,'Admin Dummy',$rs['id'],$rs['nama'],$pct,$total,$bagian,$bersih,$harga,$vcr,'Data dummy',$cab['id'],$cab['nama'],$d[0].' 09:00:00']);
    $n++;
    echo "[$n] {$d[0]} {$cab['nama']} - {$rs['nama']} Total:".number_format($total,0,',','.')." Bersih:".number_format($bersih,0,',','.')."\n";
}
echo "\n=== Berhasil insert $n records ===\n";
$total=$pdo->query("SELECT COUNT(*) FROM laporan_penagihan")->fetchColumn();
echo "Total semua records di DB: $total\n";
