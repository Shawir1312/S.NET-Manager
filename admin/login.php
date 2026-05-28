<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
if(isAdmin()){
    $role=$_SESSION['admin_role']??'';
    header('Location: '.($role==='teknisi'?'/admin/teknisi_portal.php':'/admin/dashboard.php'));
    exit;
}

$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrfVerify();
    $u=trim($_POST['username']??'');$p=$_POST['password']??'';
    if($u&&$p){
        // Rate limiting
        if(!checkLoginRateLimit($u,'admin')){
            $left=LOGIN_LOCKOUT_MINS;
            $err="Terlalu banyak percobaan login. Coba lagi setelah $left menit.";
        } else {
            $s=db()->prepare("SELECT * FROM users WHERE username=? AND is_active=1 LIMIT 1");
            $s->execute([$u]);$row=$s->fetch();
            if($row&&password_verify($p,$row['password'])){
                clearLoginAttempts($u,'admin');
                // Fix session fixation
                session_regenerate_id(true);
                $_SESSION['admin_id']=$row['id'];$_SESSION['admin_user']=$row['username'];
                $_SESSION['admin_name']=$row['full_name'];$_SESSION['admin_role']=$row['role'];
                $dest=$row['role']==='teknisi'?'/admin/teknisi_portal.php':'/admin/dashboard.php';
                header('Location: '.$dest);exit;
            } else {
                recordLoginAttempt($u,'admin');
                $left=loginAttemptsLeft($u,'admin');
                $err='Username atau password salah!'.($left>0?" (Sisa $left percobaan)":" — Akun dikunci ".LOGIN_LOCKOUT_MINS." menit.");
            }
        }
    } else $err='Harap isi semua field!';
}
// Pre-generate CSRF token
$_csrf=csrfToken();
$logo=logoB64();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Login Admin — S.NET Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--blue:#1B3FA6;--blue-d:#122B7A;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g700:#3A4468}
body{font-family:'Exo 2',sans-serif;min-height:100vh;display:flex;overflow:hidden;background:#0D1B5E}
.L{width:55%;background:linear-gradient(135deg,#0D1B5E,var(--blue-d) 50%,#8B1414);display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;overflow:hidden}
.L::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' xmlns='http://www.w3.org/2000/svg'%3E%3Ccircle cx='30' cy='30' r='3' fill='%23ffffff' fill-opacity='0.03'/%3E%3C/svg%3E")}
.lc{position:relative;z-index:1;text-align:center;padding:40px}
.lc img{max-width:280px;filter:drop-shadow(0 10px 30px rgba(0,0,0,.4));animation:fl 6s ease-in-out infinite}
@keyframes fl{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.lc-t{color:#fff;font-size:1.3rem;font-weight:700;margin-top:22px;letter-spacing:3px;text-transform:uppercase;opacity:.9}
.lc-s{color:rgba(255,255,255,.55);font-size:.82rem;margin-top:6px}
.R{width:45%;background:#fff;display:flex;align-items:center;justify-content:center;padding:40px;position:relative}
.R::before{content:'';position:absolute;top:0;left:0;width:5px;height:100%;background:linear-gradient(to bottom,var(--red),var(--blue))}
.bx{width:100%;max-width:380px}
.bx-t{font-size:1.85rem;font-weight:800;color:var(--blue-d);line-height:1.1}
.bx-t span{color:var(--red)}
.dv{width:44px;height:4px;background:linear-gradient(to right,var(--red),var(--blue));border-radius:2px;margin:12px 0}
.bx-s{color:var(--g400);font-size:.84rem;margin-bottom:28px}
.fl{display:block;font-size:.7rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:6px}
.fc{width:100%;padding:12px 15px;border:2px solid var(--g200);border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.92rem;color:var(--g700);background:var(--g50);transition:.2s;outline:none}
.fc:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 4px rgba(27,63,166,.08)}
.fg{margin-bottom:18px}
.sbtn{width:100%;padding:13px;background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;border:none;border-radius:10px;font-family:'Exo 2',sans-serif;font-size:.95rem;font-weight:700;letter-spacing:1px;cursor:pointer;transition:.3s;margin-top:6px}
.sbtn:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(27,63,166,.4)}
.err{background:#FEE2E2;border-left:4px solid var(--red);color:#DC2626;padding:10px 14px;border-radius:8px;font-size:.84rem;margin-bottom:18px}
.ft{margin-top:28px;padding-top:18px;border-top:1px solid var(--g200);display:flex;justify-content:space-between;align-items:center;font-size:.73rem;color:var(--g400)}
.ft strong{color:var(--blue)}
@media(max-width:768px){.L{display:none}.R{width:100%}}
</style>
</head>
<body>
<div class="L">
    <?php if($logo):?><div class="lc"><img src="<?=$logo?>" alt="S.NET"><div class="lc-t">Manager v2</div><div class="lc-s">Network Management System</div></div><?php endif;?>
</div>
<div class="R">
    <div class="bx">
        <div class="bx-t">Login <span>Admin</span></div>
        <div class="dv"></div>
        <div class="bx-s">Masuk ke panel manajemen S.NET</div>
        <?php if($err):?><div class="err">⚠️ <?=h($err)?></div><?php endif;?>
        <form method="POST" id="loginForm">
            <?=csrfField()?>
            <div class="fg"><label class="fl">Username</label><input type="text" name="username" id="username" class="fc" required autofocus value="<?=h($_POST['username']??'')?>"></div>
            <div class="fg"><label class="fl">Password</label><input type="password" name="password" id="password" class="fc" required></div>
            <button type="submit" class="sbtn" id="submitBtn">🚀 Masuk ke Sistem</button>
        </form>
        <div class="ft"><span>&copy; <?=date('Y')?> <strong>S.NET</strong> — PT Network Inovation Solutions</span><span style="font-family:monospace;background:var(--g100);padding:2px 7px;border-radius:4px">v<?=APP_VERSION?></span></div>
    </div>
</div>
<script>
document.getElementById('loginForm').addEventListener('submit',function(){
  var btn=document.getElementById('submitBtn');
  btn.disabled=true;
  btn.textContent='⏳ Memproses...';
  btn.style.opacity='0.8';
});
</script>
</body>
</html>
