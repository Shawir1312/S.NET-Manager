<?php
if (!defined('IN_APP')) die('Direct access denied');

function startPage(string $title): void {
    $u=adminUser(); $cur=basename($_SERVER['PHP_SELF']); $ver=APP_VERSION;
    $logoPath='/assets/logo.png'; $logoExists=file_exists(APP_BASE.'/assets/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?> — S.NET v<?=$ver?></title>
<link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--red:#D42B2B;--red-d:#A51C1C;--red-l:#F23535;--blue:#1B3FA6;--blue-d:#122B7A;--blue-m:#1E4DBF;--blue-l:#2555D4;--green:#16A34A;--green-d:#15803D;--orange:#D97706;--purple:#7C3AED;--g50:#F8FAFF;--g100:#F0F3FA;--g200:#E0E6F5;--g400:#8A95B8;--g500:#6270A0;--g600:#5A6490;--g700:#3A4468;--g900:#1A2040;--sb:260px;--hh:60px}
body{font-family:'Exo 2',sans-serif;background:var(--g50);color:var(--g700);min-height:100vh;display:flex;flex-direction:column;font-size:14px}
.hdr{position:fixed;top:0;left:0;right:0;height:var(--hh);background:linear-gradient(135deg,var(--blue-d),var(--blue) 65%,#1B3FA6);display:flex;align-items:center;padding:0 16px;z-index:1100;gap:12px;box-shadow:0 2px 12px rgba(18,43,122,.4)}
.hdr::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:linear-gradient(90deg,var(--red),var(--red-l),var(--blue-l),var(--blue))}
.ham{display:none;flex-direction:column;gap:5px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:8px;padding:8px 10px;cursor:pointer;flex-shrink:0}
.ham span{display:block;width:20px;height:2px;background:#fff;border-radius:2px;transition:.3s}
.ham.open span:nth-child(1){transform:translateY(7px) rotate(45deg)}.ham.open span:nth-child(2){opacity:0}.ham.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg)}
.hdr-logo{height:40px;object-fit:contain;filter:drop-shadow(0 2px 6px rgba(0,0,0,.3));flex-shrink:0}
.hdr-title{color:rgba(255,255,255,.85);font-size:.8rem;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.hdr-r{display:flex;align-items:center;gap:10px;margin-left:auto;flex-shrink:0}
.hdr-badge{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;padding:4px 12px;border-radius:20px;font-size:.74rem;font-weight:600;white-space:nowrap}
.hdr-badge .role{background:var(--red);color:#fff;padding:1px 7px;border-radius:10px;font-size:.62rem;margin-left:5px}
.btn-lo{background:rgba(212,43,43,.18);border:1px solid rgba(212,43,43,.4);color:#FFB3B3;padding:5px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;text-decoration:none;transition:.2s;white-space:nowrap}
.btn-lo:hover{background:var(--red);color:#fff}
.ov{display:none;position:fixed;inset:0;background:rgba(12,28,90,.5);z-index:1050;backdrop-filter:blur(2px)}.ov.show{display:block}
.sb{width:var(--sb);background:#fff;border-right:1px solid var(--g200);position:fixed;top:var(--hh);left:0;bottom:0;overflow-y:auto;z-index:1060;transition:transform .3s cubic-bezier(.4,0,.2,1);box-shadow:2px 0 16px rgba(27,63,166,.05)}
.sb-ih{display:none;align-items:center;justify-content:space-between;padding:12px 14px 8px;border-bottom:1px solid var(--g100)}
.sb-close{background:var(--g100);border:none;border-radius:7px;padding:5px 12px;cursor:pointer;font-size:.8rem;color:var(--g600);font-family:'Exo 2',sans-serif;font-weight:600}
.sb-sec{padding:14px 12px 4px}.sb-st{font-size:.6rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:1.8px;padding:0 8px;margin-bottom:6px}
.na{display:flex;align-items:center;gap:10px;padding:9px 11px;border-radius:9px;color:var(--g600);text-decoration:none;font-size:.85rem;font-weight:500;transition:.2s;margin-bottom:2px}
.na:hover{background:var(--g100);color:var(--blue)}.na.on{background:linear-gradient(135deg,var(--blue),var(--blue-m));color:#fff;font-weight:600;box-shadow:0 3px 10px rgba(27,63,166,.25)}
.ni{width:28px;height:28px;display:flex;align-items:center;justify-content:center;border-radius:7px;background:rgba(27,63,166,.08);font-size:.85rem;flex-shrink:0}
.na.on .ni{background:rgba(255,255,255,.2)}.na.dng{color:var(--red)}.na.dng:hover{background:#FEE2E2;color:var(--red-d)}
.main{margin-left:var(--sb);margin-top:var(--hh);flex:1;padding:22px;overflow-x:hidden}
.ftr{margin-left:var(--sb);padding:12px 22px;border-top:1px solid var(--g200);background:#fff;font-size:.72rem;color:var(--g400);display:flex;justify-content:space-between;align-items:center}
.ph{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px}
.ph-t{font-size:1.35rem;font-weight:800;color:var(--g900)}.ph-s{color:var(--g400);font-size:.8rem;margin-top:2px}
.stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(155px,1fr));gap:12px;margin-bottom:20px}
.stat{background:#fff;border-radius:12px;padding:16px 18px;border:1px solid var(--g200);position:relative;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.stat::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--c,var(--blue))}
.stat-n{font-size:2rem;font-weight:900;color:var(--g900);line-height:1;margin:5px 0 2px;font-family:'JetBrains Mono',monospace}
.stat-l{font-size:.68rem;font-weight:700;color:var(--g400);text-transform:uppercase;letter-spacing:.8px}.stat-d{font-size:.72rem;color:var(--g400);margin-top:2px}
.card{background:#fff;border-radius:12px;border:1px solid var(--g200);box-shadow:0 1px 3px rgba(0,0,0,.06);overflow:hidden;margin-bottom:16px}
.ch{padding:14px 18px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between;gap:10px;background:linear-gradient(to right,#F8FAFF,#fff);flex-wrap:wrap}
.ct{font-size:.88rem;font-weight:700;color:var(--g900);display:flex;align-items:center;gap:7px}.cb{padding:18px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 15px;border-radius:8px;font-family:'Exo 2',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;border:none;transition:.2s;text-decoration:none;white-space:nowrap;line-height:1}
.bp{background:linear-gradient(135deg,var(--blue),var(--blue-d));color:#fff;box-shadow:0 3px 10px rgba(27,63,166,.28)}.bp:hover{transform:translateY(-1px);box-shadow:0 5px 14px rgba(27,63,166,.35)}
.bd{background:linear-gradient(135deg,var(--red),var(--red-d));color:#fff;box-shadow:0 3px 10px rgba(212,43,43,.28)}.bd:hover{transform:translateY(-1px)}
.bs{background:linear-gradient(135deg,var(--green),var(--green-d));color:#fff}.bw{background:linear-gradient(135deg,var(--orange),#B45309);color:#fff}
.bpu{background:linear-gradient(135deg,var(--purple),#5B21B6);color:#fff}
.bo{background:transparent;border:2px solid var(--g200);color:var(--g600)}.bo:hover{border-color:var(--blue);color:var(--blue);background:var(--g50)}
.bsm{padding:4px 10px;font-size:.73rem;border-radius:6px}.bxs{padding:2px 7px;font-size:.68rem;border-radius:5px}
.tw{overflow-x:auto;-webkit-overflow-scrolling:touch}
.dt{width:100%;border-collapse:collapse;font-size:.83rem}
.dt th{background:var(--g50);padding:9px 12px;text-align:left;font-size:.67rem;font-weight:800;color:var(--g400);text-transform:uppercase;letter-spacing:1px;border-bottom:2px solid var(--g200);white-space:nowrap}
.dt td{padding:10px 12px;border-bottom:1px solid var(--g100);vertical-align:middle}.dt tr:hover td{background:var(--g50)}.dt tr:last-child td{border-bottom:none}
.bdg{display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.4px}
.bon{background:#DCFCE7;color:#15803D}.boff{background:#FEE2E2;color:var(--red)}.bblue{background:#DBEAFE;color:#1D4ED8}.bgray{background:var(--g100);color:var(--g400)}.borg{background:#FEF3C7;color:#D97706}.bpur{background:#F3E8FF;color:var(--purple)}
.dot{width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0}.don{background:#22C55E;box-shadow:0 0 0 3px rgba(34,197,94,.2);animation:dp 2s infinite}.doff{background:#EF4444}
@keyframes dp{0%,100%{opacity:1}50%{opacity:.5}}
.fg{margin-bottom:13px}.fl{display:block;font-size:.68rem;font-weight:700;color:var(--g700);text-transform:uppercase;letter-spacing:1px;margin-bottom:4px}
.fc,.fsel{width:100%;padding:8px 12px;border:2px solid var(--g200);border-radius:8px;font-family:'Exo 2',sans-serif;font-size:.86rem;color:var(--g700);background:var(--g50);transition:.2s;outline:none}
.fc:focus,.fsel:focus{border-color:var(--blue);background:#fff;box-shadow:0 0 0 3px rgba(27,63,166,.08)}
textarea.fc{resize:vertical;min-height:70px}.fhint{font-size:.7rem;color:var(--g400);margin-top:3px}
.frow{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px}.frow2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;font-weight:500;margin-bottom:16px;display:flex;align-items:flex-start;gap:8px}
.aok{background:#DCFCE7;border-left:4px solid #16A34A;color:#15803D}.aerr{background:#FEE2E2;border-left:4px solid var(--red);color:var(--red-d)}.ainf{background:#DBEAFE;border-left:4px solid var(--blue);color:var(--blue-d)}.awrn{background:#FEF3C7;border-left:4px solid var(--orange);color:#92400E}
.mo{display:none;position:fixed;inset:0;background:rgba(18,43,122,.5);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(3px);padding:14px}.mo.show{display:flex}
.md{background:#fff;border-radius:14px;width:100%;max-width:540px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(18,43,122,.3);animation:mIn .22s ease}.md-lg{max-width:720px}
@keyframes mIn{from{opacity:0;transform:scale(.94) translateY(-14px)}to{opacity:1;transform:none}}
.mh{padding:16px 20px;border-bottom:1px solid var(--g200);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(to right,#F8FAFF,#fff)}
.mt{font-size:.95rem;font-weight:800;color:var(--g900)}.mx{width:28px;height:28px;border-radius:7px;border:none;background:var(--g100);color:var(--g600);cursor:pointer;font-size:.9rem;display:flex;align-items:center;justify-content:center;transition:.2s}.mx:hover{background:var(--g200);color:var(--red)}
.mb{padding:20px}.mf{padding:12px 20px;border-top:1px solid var(--g200);display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}
.code{font-family:'JetBrains Mono',monospace;font-size:.76rem;background:var(--g100);padding:1px 5px;border-radius:4px;color:var(--blue-d)}.ipm{font-family:'JetBrains Mono',monospace;font-size:.8rem;color:var(--blue-d)}
.empty{text-align:center;padding:40px 20px;color:var(--g400)}.eico{font-size:2.5rem;margin-bottom:10px}
.cbtn{background:none;border:1px solid var(--g200);border-radius:4px;padding:1px 6px;font-size:.66rem;cursor:pointer;color:var(--g400);transition:.2s}.cbtn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.sbox{background:#0D1B5E;border-radius:9px;padding:14px;font-family:'JetBrains Mono',monospace;font-size:.74rem;color:#A5D8FF;overflow-x:auto;white-space:pre;line-height:1.6}
.og{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:14px}
.oc{background:#fff;border-radius:12px;border:1px solid var(--g200);overflow:hidden;transition:.2s;box-shadow:0 1px 4px rgba(27,63,166,.06)}.oc:hover{box-shadow:0 6px 20px rgba(27,63,166,.12);transform:translateY(-1px)}
.och{padding:12px 14px;display:flex;align-items:center;gap:10px}.och.on{background:linear-gradient(to right,#F0FDF4,#fff)}.och.off{background:linear-gradient(to right,#FEF2F2,#fff)}
.oa{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.bfh{background:#FFF7ED;border:1px solid #FED7AA}.bcd{background:#EFF6FF;border:1px solid #BFDBFE}.bgen{background:var(--g100);border:1px solid var(--g200)}
.tabnav{display:flex;gap:2px;border-bottom:2px solid var(--g200);margin-bottom:16px;overflow-x:auto}
.tab{padding:8px 14px;border:none;background:none;border-radius:7px 7px 0 0;font-family:'Exo 2',sans-serif;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--g400);white-space:nowrap;border-bottom:3px solid transparent;margin-bottom:-2px;transition:.2s}
.tab.on{color:var(--blue);border-bottom-color:var(--blue);background:var(--g50)}.tp{display:none}.tp.on{display:block}
@media(max-width:900px){.ham{display:flex}.hdr-badge{display:none}.sb{transform:translateX(-260px)}.sb.open{transform:none;box-shadow:6px 0 24px rgba(18,43,122,.2)}.sb-ih{display:flex}.ov.show{display:block}.main,.ftr{margin-left:0;padding:14px}.ph-t{font-size:1.1rem}.stats{grid-template-columns:repeat(2,1fr);gap:9px}.frow,.frow2{grid-template-columns:1fr}.md{border-radius:10px}.og{grid-template-columns:1fr}}
@media(max-width:480px){.hdr{padding:0 10px;gap:8px}.hdr-logo{height:34px}.hdr-title{display:none}.stat-n{font-size:1.65rem}}
@keyframes sIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:none}}
</style>
</head>
<body>
<header class="hdr">
  <button class="ham" id="ham" onclick="tSB()"><span></span><span></span><span></span></button>
  <?php if($logoExists):?><img src="<?=$logoPath?>" alt="S.NET" class="hdr-logo"><?php else:?><div style="color:#fff;font-size:1.3rem;font-weight:900">S.NET</div><?php endif;?>
  <div style="width:1px;height:28px;background:rgba(255,255,255,.2);flex-shrink:0"></div>
  <div class="hdr-title">S.NET Manager v<?=$ver?></div>
  <div class="hdr-r">
    <div class="hdr-badge">👤 <?=h($u['full_name'])?><span class="role"><?=strtoupper($u['role'])?></span></div>
    <a href="/admin/logout.php" class="btn-lo">⏻ Keluar</a>
  </div>
</header>
<div class="ov" id="ov" onclick="cSB()"></div>
<div style="display:flex;margin-top:var(--hh)">
<nav class="sb" id="sb">
  <div class="sb-ih"><span style="font-size:.8rem;font-weight:700;color:var(--g600)">Menu</span><button class="sb-close" onclick="cSB()">✕ Tutup</button></div>
  <?php if(($u['role']??'')==='teknisi'): ?>
  <!-- MENU TEKNISI -->
  <div class="sb-sec">
    <div class="sb-st">Teknisi</div>
    <a href="/admin/teknisi_portal.php" class="na <?=$cur==='teknisi_portal.php'?'on':''?>"><div class="ni">🔧</div>Portal Teknisi</a>
    <a href="/admin/teknisi_laporan.php" class="na <?=$cur==='teknisi_laporan.php'?'on':''?>"><div class="ni">📈</div>Laporan Penjualan</a>
    <a href="/admin/penagihan.php" class="na <?=$cur==='penagihan.php'?'on':''?>"><div class="ni">💰</div>Laporan Penagihan</a>
  </div>
  <div class="sb-sec"><div class="sb-st">Akun</div>
    <a href="/admin/logout.php" class="na dng"><div class="ni">🚪</div>Logout</a>
  </div>
  <?php else: ?>
  <!-- MENU ADMIN/OPERATOR -->
  <div class="sb-sec">
    <div class="sb-st">Utama</div>
    <a href="/admin/dashboard.php" class="na <?=$cur==='dashboard.php'?'on':''?>"><div class="ni">🏠</div>Dashboard</a>
    <a href="/admin/ont.php"       class="na <?=$cur==='ont.php'?'on':''?>"><div class="ni">📶</div>Monitor ONT</a>
    <a href="/admin/customers.php" class="na <?=$cur==='customers.php'?'on':''?>"><div class="ni">👥</div>Pelanggan</a>
    <a href="/admin/pppoe.php"     class="na <?=in_array($cur,['pppoe.php','pppoe_detail.php'])?'on':''?>"><div class="ni">🏠</div>PPPoE Manager</a>
  </div>
  <div class="sb-sec">
    <div class="sb-st">Jaringan</div>
    <a href="/admin/port_forward.php" class="na <?=$cur==='port_forward.php'?'on':''?>"><div class="ni">🔀</div>Port Forwarding</a>
    <a href="/admin/vpn.php"          class="na <?=$cur==='vpn.php'?'on':''?>"><div class="ni">🔐</div>VPN L2TP</a>
    <a href="/admin/vpn_status.php"   class="na <?=$cur==='vpn_status.php'?'on':''?>"><div class="ni">📡</div>Status VPN</a>
    <a href="/admin/routers.php"      class="na <?=$cur==='routers.php'?'on':''?>"><div class="ni">🌐</div>Router Config</a>
  </div>
  <div class="sb-sec">
    <div class="sb-st">Monitoring</div>
    <a href="/admin/snetmonitoring.php" class="na <?=$cur==='snetmonitoring.php'?'on':''?>"><div class="ni">📡</div>S.NET Monitoring</a>
    <a href="/admin/reseller.php" class="na <?=$cur==='reseller.php'?'on':''?>"><div class="ni">🤝</div>Reseller & Drive</a>
    <a href="/admin/penagihan.php" class="na <?=$cur==='penagihan.php'?'on':''?>"><div class="ni">💰</div>Laporan Penagihan</a>
    <a href="/admin/laporan_arsip.php" class="na <?=$cur==='laporan_arsip.php'?'on':''?>"><div class="ni">📦</div>Arsip Laporan</a>
  </div>
  <div class="sb-sec">
    <div class="sb-st">Sistem</div>
    <a href="/admin/genie.php" class="na <?=$cur==='genie.php'?'on':''?>"><div class="ni">⚙️</div>Config GenieACS</a>
    <a href="/admin/maintenance.php" class="na <?=$cur==='maintenance.php'?'on':''?>" style="<?=$cur==='maintenance.php'?'':'color:inherit'?>">
        <div class="ni" style="<?php
            try{$db2=db();$hasMaint=$db2->query("SELECT COUNT(*) FROM announcements WHERE is_active=1 AND maintenance_mode=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())")->fetchColumn();}catch(Exception $e){$hasMaint=0;}
            echo $hasMaint>0?'background:rgba(212,43,43,.15);color:var(--red)':'';
        ?>">🔧</div>Maintenance
        <?php try{$db2=db();$hasMaint2=$db2->query("SELECT COUNT(*) FROM announcements WHERE is_active=1 AND maintenance_mode=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())")->fetchColumn();if($hasMaint2>0):?><span style="background:var(--red);color:#fff;border-radius:10px;font-size:.55rem;padding:1px 6px;font-weight:700;margin-left:auto">AKTIF</span><?php endif;}catch(Exception $e){}?>
    </a>
    <a href="/admin/users.php" class="na <?=$cur==='users.php'?'on':''?>"><div class="ni">🛡</div>User Admin</a>
    <a href="/admin/audit.php" class="na <?=$cur==='audit.php'?'on':''?>"><div class="ni">📋</div>Audit Log</a>
  </div>
  <div class="sb-sec"><div class="sb-st">Akun</div>
    <a href="/portal/login.php" class="na" target="_blank"><div class="ni">🌐</div>Portal Pelanggan</a>
    <a href="/admin/logout.php" class="na dng"><div class="ni">🚪</div>Logout</a>
  </div>
  <?php endif; ?>
</nav>
<main class="main">
<?php
}
function endPage(): void { $v=APP_VERSION; ?>
</main></div>
<footer class="ftr">
  <span>&copy; <?=date('Y')?> <strong style="color:var(--blue)">S.NET</strong> — PT Network Inovation Solutions</span>
  <span style="font-family:'JetBrains Mono',monospace">v<?=$v?></span>
</footer>
<script>
function tSB(){const s=document.getElementById('sb'),o=document.getElementById('ov'),h=document.getElementById('ham');const op=s.classList.toggle('open');o.classList.toggle('show',op);h.classList.toggle('open',op);document.body.style.overflow=op?'hidden':'';}
function cSB(){document.getElementById('sb').classList.remove('open');document.getElementById('ov').classList.remove('show');document.getElementById('ham').classList.remove('open');document.body.style.overflow='';}
document.querySelectorAll('.na').forEach(a=>a.addEventListener('click',()=>{if(window.innerWidth<=900)cSB();}));
window.addEventListener('resize',()=>{if(window.innerWidth>900)cSB();});
function oM(id){document.getElementById(id)?.classList.add('show')}
function cM(id){document.getElementById(id)?.classList.remove('show')}
function cDel(m){return confirm(m||'Yakin hapus?')}
function toast(msg,t='info'){const el=document.createElement('div');el.className=`alert a${t==='success'?'ok':t==='error'?'err':t==='warning'?'wrn':'inf'}`;el.style.cssText='position:fixed;top:68px;right:14px;z-index:9999;max-width:320px;box-shadow:0 6px 20px rgba(0,0,0,.15);animation:sIn .3s ease;min-width:200px';el.textContent=(t==='success'?'✅ ':t==='error'?'❌ ':t==='warning'?'⚠️ ':'ℹ️ ')+msg;document.body.appendChild(el);setTimeout(()=>el.remove(),4200);}
function cpTxt(t,b){navigator.clipboard.writeText(t).then(()=>{const o=b.textContent;b.textContent='✓';setTimeout(()=>b.textContent=o,1600);}).catch(()=>{const ta=document.createElement('textarea');ta.value=t;document.body.appendChild(ta);ta.select();document.execCommand('copy');ta.remove();});}
function swTab(id,grp){document.querySelectorAll('.tp[data-grp="'+grp+'"]').forEach(p=>p.classList.remove('on'));document.querySelectorAll('.tab[data-grp="'+grp+'"]').forEach(t=>t.classList.remove('on'));document.getElementById('tp-'+id)?.classList.add('on');document.querySelector('.tab[data-tab="'+id+'"][data-grp="'+grp+'"]')?.classList.add('on');}
document.addEventListener('click',e=>{document.querySelectorAll('.mo').forEach(m=>{if(e.target===m)m.classList.remove('show');});});
</script>
</body></html>
<?php
}
?>
