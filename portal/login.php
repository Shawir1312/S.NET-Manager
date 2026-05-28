<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
if(isCust()){header('Location: /portal/index.php');exit;}

// ── Baca pengumuman aktif dari DB ─────────────────────────────────
$announcements=[];$maintenanceMode=false;$maintenanceMsg=null;
try{
    $db=db();
    // Auto-create table jika belum ada
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type ENUM('maintenance','info','warning','success','release') NOT NULL DEFAULT 'info',
        title VARCHAR(200) NOT NULL DEFAULT '',
        body TEXT NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        show_on_login TINYINT(1) NOT NULL DEFAULT 1,
        maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
        start_at DATETIME NULL DEFAULT NULL,
        end_at DATETIME NULL DEFAULT NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $annRows=$db->query("SELECT * FROM announcements WHERE is_active=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW()) ORDER BY maintenance_mode DESC,created_at DESC")->fetchAll();
    foreach($annRows as $ann){
        if($ann['maintenance_mode']){$maintenanceMode=true;$maintenanceMsg=$ann;break;}
    }
    foreach($annRows as $ann){
        if(!$ann['maintenance_mode']&&$ann['show_on_login']) $announcements[]=$ann;
    }
}catch(Exception $e){}

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    // Blokir login saat maintenance
    if($maintenanceMode){$err='Portal sedang dalam maintenance. Silakan coba beberapa saat lagi.';}
    $cid=trim($_POST['customer_id']??'');$pw=$_POST['password']??'';
    if($cid&&$pw){
        // Rate limiting
        if(!checkLoginRateLimit($cid,'portal')){
            $left=LOGIN_LOCKOUT_MINS;
            $err="Terlalu banyak percobaan login. Coba lagi setelah $left menit.";
        } else {
            $s=db()->prepare("SELECT * FROM customers WHERE LOWER(customer_id)=LOWER(?) AND is_active=1 LIMIT 1");
            $s->execute([$cid]);$c=$s->fetch();
            if($c&&password_verify($pw,$c['password'])){
                clearLoginAttempts($cid,'portal');
                // Fix session fixation
                session_regenerate_id(true);
                $_SESSION['cust_id']=$c['id'];$_SESSION['cust_cid']=$c['customer_id'];
                $_SESSION['cust_name']=$c['full_name'];$_SESSION['cust_device_id']=$c['genie_device_id'];
                auditLog('portal_login',$c['customer_id'],'','customer');
                header('Location: /portal/index.php');exit;
            } else {
                recordLoginAttempt($cid,'portal');
                $left=loginAttemptsLeft($cid,'portal');
                $err='ID Pelanggan atau password salah!'.($left>0?" (Sisa $left percobaan)":" — Akun dikunci ".LOGIN_LOCKOUT_MINS." menit.");
            }
        }
    } else {$err='Harap isi ID dan password!';}
}
if(isset($_GET['err'])&&$_GET['err']==='disabled')$err='Akun Anda tidak aktif. Hubungi admin S.NET.';
$_csrf=csrfToken();
$logo=logoB64();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Portal Pelanggan — S.NET</title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--blue:#1B3FA6;--blue-d:#122B7A;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g700:#3A4468;--orange:#D97706;--purple:#7C3AED;--green:#16A34A}
body{font-family:'Exo 2',sans-serif;min-height:100vh;background:var(--g50);display:flex;flex-direction:column}
.bg{position:fixed;inset:0;background:linear-gradient(135deg,#0D1B5E 0%,var(--blue-d) 45%,#8B1414 100%);z-index:0}
.bg::before{content:'';position:absolute;top:-200px;right:-200px;width:600px;height:600px;border-radius:50%;border:1px solid rgba(255,255,255,.05)}
.bg::after{content:'';position:absolute;bottom:-200px;left:-200px;width:700px;height:700px;border-radius:50%;border:1px solid rgba(255,255,255,.05)}
.wrap{position:relative;z-index:1;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;gap:14px}
.card{background:#fff;border-radius:20px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(18,43,122,.35);overflow:hidden}
/* Maintenance full screen */
.maint-card{background:rgba(15,23,42,.85);border:1px solid rgba(220,38,38,.4);border-radius:20px;width:100%;max-width:460px;padding:32px 28px;text-align:center;backdrop-filter:blur(12px)}
.maint-icon{font-size:3.5rem;margin-bottom:14px;animation:pulse 2s ease-in-out infinite}
@keyframes pulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
.maint-title{color:#FCA5A5;font-size:1.3rem;font-weight:800;margin-bottom:8px}
.maint-body{color:rgba(255,255,255,.65);font-size:.86rem;line-height:1.6;margin-bottom:16px}
.maint-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(220,38,38,.2);border:1px solid rgba(220,38,38,.5);color:#FCA5A5;padding:5px 14px;border-radius:20px;font-size:.75rem;font-weight:700;letter-spacing:.5px}
/* Notifikasi cards di atas login */
.notif-wrap{width:100%;max-width:420px;display:flex;flex-direction:column;gap:8px}
.notif{backdrop-filter:blur(8px);border-radius:12px;padding:12px 14px;border:1px solid rgba(255,255,255,.15);display:flex;gap:10px;align-items:flex-start}
.notif.info{background:rgba(29,78,216,.18);border-color:rgba(29,78,216,.35)}
.notif.warning{background:rgba(217,119,6,.18);border-color:rgba(217,119,6,.35)}
.notif.success,.notif.release{background:rgba(22,163,74,.18);border-color:rgba(22,163,74,.35)}
.notif-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px}
.notif-title{color:#fff;font-weight:700;font-size:.83rem}
.notif-body{color:rgba(255,255,255,.65);font-size:.75rem;margin-top:3px;line-height:1.5}
/* Login card */
.hero{background:linear-gradient(135deg,var(--blue-d),var(--blue));padding:28px 28px 22px;text-align:center;position:relative}
.hero::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:20px;background:#fff;clip-path:ellipse(55% 100% at 50% 100%)}
.hero img{max-width:180px;max-height:56px;object-fit:contain;filter:drop-shadow(0 4px 12px rgba(0,0,0,.3));margin-bottom:10px}
.hero-t{color:rgba(255,255,255,.72);font-size:.78rem;letter-spacing:.5px}
.body{padding:28px}
.t1{font-size:1.3rem;font-weight:800;color:var(--blue-d);margin-bottom:3px}
.t2{color:var(--g400);font-size:.82rem;margin-bottom:22px}
.fl{display:block;font-size:.7rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:1px;margin-bottom:5px}
.fc{width:100%;padding:11px 14px;border:2px solid var(--g200);border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.92rem;color:var(--g700);background:var(--g50);transition:.2s;outline:none}
.fc:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(27,63,166,.08)}
.fc:disabled{opacity:.5;cursor:not-allowed}
input.cid{font-family:'JetBrains Mono',monospace;font-size:1rem;letter-spacing:2px;font-weight:600;color:var(--blue-d)}
.fg{margin-bottom:16px}
.sbtn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;border:none;border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.95rem;font-weight:700;letter-spacing:1px;cursor:pointer;transition:.3s;margin-top:4px}
.sbtn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(27,63,166,.4)}
.sbtn:disabled{opacity:.5;cursor:not-allowed;transform:none}
.err{background:#FEE2E2;border-left:4px solid var(--red);color:#DC2626;padding:10px 14px;border-radius:8px;font-size:.84rem;margin-bottom:16px}
.foot{text-align:center;padding:14px 28px 20px;font-size:.75rem;color:var(--g400);border-top:1px solid var(--g100)}
</style>
</head>
<body>
<div class="bg"></div>
<div class="wrap">

<?php if($maintenanceMode&&$maintenanceMsg):?>
<!-- ═══ MODE MAINTENANCE — Blokir seluruh portal ═══ -->
<div class="maint-card">
    <div class="maint-icon">🔧</div>
    <div class="maint-title"><?=h($maintenanceMsg['title'])?></div>
    <?php if($maintenanceMsg['body']):?>
    <div class="maint-body"><?=nl2br(h($maintenanceMsg['body']))?></div>
    <?php endif;?>
    <div class="maint-badge">⛔ Portal Sementara Tidak Tersedia</div>
    <div style="margin-top:18px;color:rgba(255,255,255,.4);font-size:.74rem">Hubungi admin atau teknisi S.NET untuk informasi lebih lanjut</div>
</div>
<?php else:?>

<!-- ═══ Notifikasi pengumuman (banner di atas login) ═══ -->
<?php if(!empty($announcements)):?>
<div class="notif-wrap">
    <?php foreach($announcements as $ann):
        $notifType=$ann['type']==='success'||$ann['type']==='release'?'success':$ann['type'];
        $icons=['info'=>'ℹ️','warning'=>'⚠️','success'=>'✅','release'=>'🚀'];
        $ico=$icons[$ann['type']]??'📢';
    ?>
    <div class="notif <?=$notifType?>">
        <span class="notif-icon"><?=$ico?></span>
        <div>
            <div class="notif-title"><?=h($ann['title'])?></div>
            <?php if($ann['body']):?><div class="notif-body"><?=nl2br(h($ann['body']))?></div><?php endif;?>
        </div>
    </div>
    <?php endforeach;?>
</div>
<?php endif;?>

<!-- ═══ Card Login ═══ -->
<div class="card">
    <div class="hero">
        <?php if($logo):?><img src="<?=$logo?>" alt="S.NET"><?php else:?><div style="color:#fff;font-size:2rem;font-weight:900;margin-bottom:8px">S.NET</div><?php endif;?>
        <div class="hero-t">Portal Pelanggan — Self-Service WiFi</div>
    </div>
    <div class="body">
        <div class="t1">Masuk Portal</div>
        <div class="t2">Gunakan ID Pelanggan dari teknisi S.NET Anda</div>
        <?php if($err):?><div class="err">⚠️ <?=h($err)?></div><?php endif;?>
        <form method="POST">
            <?=csrfField()?>
            <div class="fg"><label class="fl">ID Pelanggan</label>
                <input type="text" name="customer_id" id="customer_id" class="fc cid" placeholder="SNET-0001" required autocomplete="username" value="<?=h($_POST['customer_id']??'')?>">
            </div>
            <div class="fg"><label class="fl">Password</label>
                <input type="password" name="password" class="fc" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="sbtn">🚀 Masuk ke Portal</button>
        </form>
    </div>
    <div class="foot">Lupa password? Hubungi admin atau teknisi S.NET &bull; &copy; <?=date('Y')?> PT Network Inovation Solutions</div>
</div>

<?php endif;?>
</div>
</body>
</html>

