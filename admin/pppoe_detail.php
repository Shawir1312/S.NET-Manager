<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();

$cid=(int)($_GET['cid']??0);
$rid=(int)($_GET['rid']??0);
if(!$cid){header('Location: /admin/pppoe.php');exit;}

$cust=$db->prepare("SELECT pc.*,r.name router_name,r.host,r.port,r.username,r.password 
    FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.id=?");
$cust->execute([$cid]);$cust=$cust->fetch();
if(!$cust){header('Location: /admin/pppoe.php');exit;}

$settings=[];
foreach($db->query("SELECT setting_key,setting_value FROM pppoe_settings") as $s)$settings[$s['setting_key']]=$s['setting_value'];

$msg='';$mtype='ok';$act=trim($_POST['action']??'');

if($act==='edit'){
    $name=trim($_POST['full_name']??'');$phone=trim($_POST['phone']??'');
    $address=trim($_POST['address']??'');$price=(int)($_POST['monthly_price']??0);
    $due=(int)($_POST['due_day']??1);$profile=trim($_POST['profile']??'');$notes=trim($_POST['notes']??'');
    $db->prepare("UPDATE pppoe_customers SET full_name=?,phone=?,address=?,monthly_price=?,due_day=?,profile=?,notes=? WHERE id=?")
       ->execute([$name,$phone,$address,$price,$due,$profile,$notes,$cid]);
    // Update profile di MikroTik jika berubah dan status aktif
    if($profile&&$cust['status']==='active'){
        try{
            $api=MikrotikAPI::fromRouter($cust);if($api->connect()){
                $api->talk(['/ppp/secret/set','?name='.$cust['pppoe_username'],'=profile='.$profile]);$api->close();
            }
        }catch(\Exception $e){}
    }
    $msg='✅ Data pelanggan diperbarui!';
    header("Location: /admin/pppoe_detail.php?cid=$cid&rid=$rid&ok=1");exit;
}

if($act==='change_password'){
    $newpass=trim($_POST['new_password']??'');
    if($newpass){
        try{
            $api=MikrotikAPI::fromRouter($cust);if($api->connect()){
                $api->talk(['/ppp/secret/set','?name='.$cust['pppoe_username'],'=password='.$newpass]);$api->close();
            }
            $msg='✅ Password berhasil diubah di MikroTik!';
        }catch(\Exception $e){$msg='❌ Gagal ubah password: '.$e->getMessage();$mtype='err';}
    }
}

if($act==='bayar'){
    $amount=(int)($_POST['amount']??0);$month=(int)($_POST['month']??date('n'));
    $year=(int)($_POST['year']??date('Y'));$notes2=trim($_POST['notes']??'');
    if($amount>0){
        $db->prepare("INSERT INTO pppoe_payments(customer_id,amount,payment_method,period_month,period_year,notes,created_by) VALUES(?,?,'cash',?,?,?,?)")
           ->execute([$cid,$amount,$month,$year,$notes2,$_SESSION['admin_id']??null]);
        $db->prepare("UPDATE pppoe_customers SET last_paid_at=CURDATE(),last_paid_amount=? WHERE id=?")->execute([$amount,$cid]);
        // Auto reaktivasi jika isolated
        if($cust['status']==='isolated'){
            $profile=$cust['profile']?:'default';
            try{
                $api=MikrotikAPI::fromRouter($cust);if($api->connect()){
                    $api->talk(['/ppp/secret/set','?name='.$cust['pppoe_username'],'=profile='.$profile]);
                    $api->close();
                }
            }catch(\Exception $e){}
            $db->prepare("UPDATE pppoe_customers SET status='active',isolated_at=NULL,isolated_reason='' WHERE id=?")->execute([$cid]);
        }
        $msg='✅ Pembayaran Rp '.number_format($amount,0,',','.').' dicatat! '.($cust['status']==='isolated'?'Pelanggan otomatis diaktifkan kembali.':'');
    }
}

$stmt=$db->prepare("SELECT pc.*,r.name router_name,r.host,r.port,r.username,r.password FROM pppoe_customers pc JOIN routers r ON pc.router_id=r.id WHERE pc.id=?");
$stmt->execute([$cid]);$cust=$stmt->fetch();

$payments=$db->prepare("SELECT * FROM pppoe_payments WHERE customer_id=? ORDER BY paid_at DESC LIMIT 24");
$payments->execute([$cid]);$payments=$payments->fetchAll();

// Get live session from MikroTik
$liveSession=null;
try{
    $api=MikrotikAPI::fromRouter($cust);
    if($api->connect()){
        $s=$api->parse($api->talk(['/ppp/active/print','?name='.$cust['pppoe_username']]));
        if(!empty($s))$liveSession=$s[0];
        $api->close();
    }
}catch(\Exception $e){}

startPage('Detail Pelanggan PPPoE');
?>
<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px">
    <a href="/admin/pppoe.php?rid=<?=$rid?>" class="btn bo">← Kembali</a>
    <div>
        <div class="ph-t">👤 <?=h($cust['full_name'])?></div>
        <div style="font-size:.75rem;color:var(--g400)"><?=h($cust['pppoe_username'])?> · <?=h($cust['router_name'])?></div>
    </div>
    <div style="margin-left:auto">
        <?php if($cust['status']==='active'):?>
        <span style="background:#DCFCE7;color:#15803D;padding:4px 12px;border-radius:10px;font-size:.8rem;font-weight:700">✅ Aktif</span>
        <?php else:?>
        <span style="background:#FEE2E2;color:#DC2626;padding:4px 12px;border-radius:10px;font-size:.8rem;font-weight:700">🔴 Diisolir</span>
        <?php endif;?>
    </div>
</div>

<?php if($msg):?><div class="alert a<?=$mtype?>" style="margin-bottom:14px"><?=$msg?></div><?php endif;?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
<!-- Info & Edit -->
<div class="card">
    <div class="ch"><div class="ct">📋 Informasi Pelanggan</div></div>
    <div class="cb">
        <form method="POST">
            <input type="hidden" name="action" value="edit">
            <div class="fg"><label class="fl">Nama Lengkap</label><input type="text" name="full_name" class="fc" value="<?=h($cust['full_name'])?>" required></div>
            <div class="f2">
                <div class="fg"><label class="fl">No. HP/WA</label><input type="text" name="phone" class="fc" value="<?=h($cust['phone'])?>"></div>
                <div class="fg"><label class="fl">Profil PPPoE</label><input type="text" name="profile" class="fc" value="<?=h($cust['profile'])?>"></div>
            </div>
            <div class="f2">
                <div class="fg"><label class="fl">Harga/Bulan</label><input type="number" name="monthly_price" class="fc" value="<?=$cust['monthly_price']?>" min="0"></div>
                <div class="fg"><label class="fl">Tanggal Jatuh Tempo</label><input type="number" name="due_day" class="fc" value="<?=$cust['due_day']?>" min="1" max="28"><div class="fhint">Tgl jatuh tempo tiap bulan</div></div>
            </div>
            <div class="fg"><label class="fl">Alamat</label><textarea name="address" class="fc" rows="2"><?=h($cust['address']??'')?></textarea></div>
            <div class="fg"><label class="fl">Catatan</label><textarea name="notes" class="fc" rows="2"><?=h($cust['notes']??'')?></textarea></div>
            <button type="submit" class="btn bp" style="width:100%">💾 Simpan Perubahan</button>
        </form>
    </div>
</div>

<!-- Status & Actions -->
<div style="display:flex;flex-direction:column;gap:14px">
<!-- Live Session -->
<div class="card">
    <div class="ch"><div class="ct">🔌 Sesi Online</div></div>
    <div class="cb">
    <?php if($liveSession):?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:.82rem">
            <?php foreach(['IP'=>$liveSession['address']??'-','Uptime'=>$liveSession['uptime']??'-','Caller ID'=>$liveSession['caller-id']??'-','Service'=>$liveSession['service']??'-'] as $l=>$v):?>
            <div><div style="font-size:.65rem;color:var(--g400);text-transform:uppercase"><?=$l?></div><strong><?=$v?></strong></div>
            <?php endforeach;?>
        </div>
    <?php else:?>
        <div style="color:var(--g400);text-align:center;padding:12px">📴 Tidak ada sesi aktif</div>
    <?php endif;?>
    </div>
</div>

<!-- Ganti Password -->
<div class="card">
    <div class="ch"><div class="ct">🔑 Ganti Password</div></div>
    <div class="cb">
        <form method="POST">
            <input type="hidden" name="action" value="change_password">
            <div class="fg"><input type="text" name="new_password" class="fc" placeholder="Password baru..." required></div>
            <button type="submit" class="btn bo" style="width:100%" onclick="return confirm('Ganti password PPPoE?')">🔑 Ganti Password</button>
        </form>
    </div>
</div>

<!-- Catat Bayar -->
<div class="card">
    <div class="ch"><div class="ct">💰 Catat Pembayaran Cash</div></div>
    <div class="cb">
        <form method="POST">
            <input type="hidden" name="action" value="bayar">
            <div class="fg"><label class="fl">Jumlah (Rp)</label><input type="number" name="amount" class="fc" value="<?=$cust['monthly_price']?>" min="0"></div>
            <div class="f2">
                <div class="fg"><label class="fl">Bulan</label>
                    <select name="month" class="fsel">
                        <?php for($m=1;$m<=12;$m++):?>
                        <option value="<?=$m?>" <?=$m==date('n')?'selected':''?>><?=date('M',mktime(0,0,0,$m,1))?></option>
                        <?php endfor;?>
                    </select>
                </div>
                <div class="fg"><label class="fl">Tahun</label><input type="number" name="year" class="fc" value="<?=date('Y')?>"></div>
            </div>
            <div class="fg"><input type="text" name="notes" class="fc" placeholder="Catatan..."></div>
            <button type="submit" class="btn bp" style="width:100%">💰 Simpan Pembayaran</button>
        </form>
    </div>
</div>
</div>
</div>

<!-- Riwayat Bayar -->
<?php if(!empty($payments)):?>
<div class="card" style="margin-top:14px">
    <div class="ch"><div class="ct">📋 Riwayat Pembayaran</div></div>
    <div class="tw">
    <table class="dt">
        <thead><tr><th>Periode</th><th>Jumlah</th><th>Metode</th><th>Status</th><th>Tanggal</th><th>Catatan</th></tr></thead>
        <tbody>
        <?php foreach($payments as $p):
            $isPaid=$p['midtrans_status']!=='pending'||$p['payment_method']==='cash';?>
        <tr>
            <td><?=date('M Y',mktime(0,0,0,$p['period_month'],1,$p['period_year']))?></td>
            <td><strong>Rp <?=number_format($p['amount'],0,',','.')?></strong></td>
            <td><span class="bdg bblue" style="font-size:.7rem"><?=strtoupper($p['payment_method'])?></span></td>
            <td><span class="bdg <?=$isPaid?'bon':'boff'?>" style="font-size:.7rem"><?=$isPaid?'✅ Lunas':'⏳ Pending'?></span></td>
            <td style="font-size:.75rem"><?=date('d/m/Y H:i',strtotime($p['paid_at']))?></td>
            <td style="font-size:.75rem;color:var(--g400)"><?=h($p['notes']??'')?></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table>
    </div>
</div>
<?php endif;?>

<?php endPage();?>
