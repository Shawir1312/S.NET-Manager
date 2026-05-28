<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin('operator');
$db=db();
$msg='';$mtype='ok';

// ── Auto-create table jika belum ada ────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS announcements (
        id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        type         ENUM('maintenance','info','warning','success','release') NOT NULL DEFAULT 'info',
        title        VARCHAR(200) NOT NULL DEFAULT '',
        body         TEXT NOT NULL DEFAULT '',
        is_active    TINYINT(1) NOT NULL DEFAULT 1,
        show_on_login TINYINT(1) NOT NULL DEFAULT 1,
        maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
        start_at     DATETIME NULL DEFAULT NULL,
        end_at       DATETIME NULL DEFAULT NULL,
        created_by   INT UNSIGNED NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch(Exception $e){}

// ── POST handlers ────────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';

    if($act==='save_announcement'){
        $id     =(int)($_POST['ann_id']??0);
        $type   =$_POST['type']??'info';
        $title  =trim($_POST['title']??'');
        $body   =trim($_POST['body']??'');
        $isAct  =!empty($_POST['is_active'])?1:0;
        $showLog=!empty($_POST['show_on_login'])?1:0;
        $maint  =!empty($_POST['maintenance_mode'])?1:0;
        $startAt=trim($_POST['start_at']??'')?:null;
        $endAt  =trim($_POST['end_at']??'')?:null;

        if(!$title){$msg='❌ Judul tidak boleh kosong!';$mtype='err';}
        else {
            if($id){
                $db->prepare("UPDATE announcements SET type=?,title=?,body=?,is_active=?,show_on_login=?,maintenance_mode=?,start_at=?,end_at=? WHERE id=?")
                   ->execute([$type,$title,$body,$isAct,$showLog,$maint,$startAt,$endAt,$id]);
                $msg='✅ Pengumuman diperbarui!';
            } else {
                $db->prepare("INSERT INTO announcements(type,title,body,is_active,show_on_login,maintenance_mode,start_at,end_at,created_by)VALUES(?,?,?,?,?,?,?,?,?)")
                   ->execute([$type,$title,$body,$isAct,$showLog,$maint,$startAt,$endAt,adminUser()['id']??null]);
                $msg='✅ Pengumuman berhasil ditambahkan!';
            }
            auditLog('save_announcement','announcements',"type=$type,title=$title");
        }
    }

    if($act==='toggle_announcement'){
        $id=(int)($_POST['ann_id']??0);
        $db->prepare("UPDATE announcements SET is_active=NOT is_active WHERE id=?")->execute([$id]);
        $row=$db->prepare("SELECT is_active,title FROM announcements WHERE id=? LIMIT 1");
        $row->execute([$id]);$r=$row->fetch();
        $msg=($r['is_active']?'✅ Diaktifkan':'🔕 Dinonaktifkan').': '.h($r['title']??'');
    }

    if($act==='delete_announcement'){
        $id=(int)($_POST['ann_id']??0);
        $row=$db->prepare("SELECT title FROM announcements WHERE id=? LIMIT 1");
        $row->execute([$id]);$r=$row->fetch();
        $db->prepare("DELETE FROM announcements WHERE id=?")->execute([$id]);
        $msg='🗑️ Pengumuman "'.h($r['title']??'').'" dihapus.';
        auditLog('delete_announcement','announcements','ID='.$id);
    }
}

// ── Ambil semua pengumuman ──────────────────────────────────────────
$all=$db->query("SELECT * FROM announcements ORDER BY is_active DESC,updated_at DESC")->fetchAll();

// ── Edit mode ──────────────────────────────────────────────────────
$edit=null;
if(isset($_GET['edit'])){
    $s=$db->prepare("SELECT * FROM announcements WHERE id=? LIMIT 1");
    $s->execute([(int)$_GET['edit']]);$edit=$s->fetch();
}

// ── Status summary ─────────────────────────────────────────────────
$activeMaint  = $db->query("SELECT COUNT(*) FROM announcements WHERE is_active=1 AND maintenance_mode=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())")->fetchColumn();
$activeNotif  = $db->query("SELECT COUNT(*) FROM announcements WHERE is_active=1 AND show_on_login=1 AND maintenance_mode=0 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW())")->fetchColumn();

startPage('Maintenance & Pengumuman');
?>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<div class="ph">
    <div>
        <div class="ph-t">🔧 Maintenance & Pengumuman</div>
        <div class="ph-s">Kelola mode maintenance portal pelanggan dan pesan pengumuman</div>
    </div>
    <button class="btn bp" onclick="oM('moAdd')" id="btnAdd">➕ Tambah Pengumuman</button>
</div>

<!-- Status Cards -->
<div class="stats" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px">
    <div class="stat" style="--c:<?=$activeMaint>0?'var(--red)':'var(--green)'?>">
        <div class="stat-l">Mode Maintenance</div>
        <div class="stat-n" style="font-size:1.4rem;color:<?=$activeMaint>0?'var(--red)':'var(--green)'?>">
            <?=$activeMaint>0?'🔴 AKTIF':'🟢 Normal'?>
        </div>
        <div class="stat-d"><?=$activeMaint>0?"$activeMaint pengumuman maintenance aktif":'Portal pelanggan berjalan normal'?></div>
    </div>
    <div class="stat" style="--c:var(--blue)">
        <div class="stat-l">Notifikasi Login</div>
        <div class="stat-n" style="font-size:1.8rem"><?=(int)$activeNotif?></div>
        <div class="stat-d">Pesan aktif di halaman login</div>
    </div>
    <div class="stat" style="--c:var(--orange)">
        <div class="stat-l">Total Pengumuman</div>
        <div class="stat-n" style="font-size:1.8rem"><?=count($all)?></div>
        <div class="stat-d">Semua pengumuman (aktif + nonaktif)</div>
    </div>
</div>

<!-- Info box maintenance aktif -->
<?php if($activeMaint>0):?>
<div class="alert" style="background:#FEE2E2;border-left:4px solid var(--red);color:var(--red-d);margin-bottom:16px;align-items:flex-start">
    <div style="font-size:1.4rem;flex-shrink:0">🚨</div>
    <div>
        <strong>Portal pelanggan dalam mode MAINTENANCE!</strong><br>
        <span style="font-size:.82rem">Pelanggan yang mencoba login akan melihat pesan maintenance dan tidak bisa masuk.</span>
        <?php
        $maintRows=$db->query("SELECT * FROM announcements WHERE is_active=1 AND maintenance_mode=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW()) LIMIT 3")->fetchAll();
        foreach($maintRows as $mr):?>
        <div style="margin-top:6px;font-size:.8rem;background:rgba(220,38,38,.1);padding:5px 8px;border-radius:6px">
            📌 <strong><?=h($mr['title'])?></strong>
        </div>
        <?php endforeach;?>
    </div>
</div>
<?php endif;?>

<!-- Daftar Pengumuman -->
<div class="card">
    <div class="ch"><div class="ct">📋 Daftar Pengumuman (<?=count($all)?>)</div></div>
    <?php if(empty($all)):?>
    <div class="empty"><div class="eico">📢</div>Belum ada pengumuman. Klik "➕ Tambah Pengumuman" untuk membuat.</div>
    <?php else:?>
    <div class="tw"><table class="dt">
        <thead><tr>
            <th>Tipe</th><th>Judul</th><th>Tampil di</th>
            <th>Jadwal</th><th>Status</th><th>Dibuat</th><th>Aksi</th>
        </tr></thead>
        <tbody>
        <?php foreach($all as $a):
            $typeInfo=[
                'maintenance'=>['🔧','boff','Maintenance'],
                'info'       =>['ℹ️','bblue','Info'],
                'warning'    =>['⚠️','borg','Warning'],
                'success'    =>['✅','bon','Rilis Baru'],
                'release'    =>['🚀','bpur','Feature Baru'],
            ];
            $ti=$typeInfo[$a['type']]??['📢','bgray',$a['type']];
            $isNowActive=$a['is_active']&&
                ($a['start_at']===null||strtotime($a['start_at'])<=time())&&
                ($a['end_at']===null||strtotime($a['end_at'])>=time());
        ?>
        <tr>
            <td><span class="bdg <?=$ti[1]?>"><?=$ti[0]?> <?=$ti[2]?></span></td>
            <td>
                <strong style="font-size:.84rem"><?=h($a['title'])?></strong>
                <?php if($a['body']):?>
                <div style="font-size:.7rem;color:var(--g400);margin-top:2px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h(strip_tags($a['body']))?></div>
                <?php endif;?>
                <?php if($a['maintenance_mode']):?><span class="bdg boff" style="margin-top:3px;display:inline-flex">🔒 Blokir Login</span><?php endif;?>
            </td>
            <td style="font-size:.75rem">
                <?=$a['show_on_login']?'<span class="bdg bblue">🔐 Halaman Login</span>':''?>
                <?=$a['maintenance_mode']?'<span class="bdg boff" style="margin-top:2px;display:inline-flex">⛔ Blokir Login</span>':''?>
            </td>
            <td style="font-size:.72rem;color:var(--g400)">
                <?php if($a['start_at']||$a['end_at']):?>
                <?=$a['start_at']?date('d/m/y H:i',strtotime($a['start_at'])):'—'?>
                →<br><?=$a['end_at']?date('d/m/y H:i',strtotime($a['end_at'])):'∞'?>
                <?php else:?>
                <span style="color:var(--green)">Selalu aktif</span>
                <?php endif;?>
            </td>
            <td>
                <?php if($isNowActive):?>
                <span class="bdg bon">● Aktif</span>
                <?php elseif($a['is_active']&&$a['start_at']&&strtotime($a['start_at'])>time()):?>
                <span class="bdg borg">⏰ Terjadwal</span>
                <?php elseif($a['is_active']&&$a['end_at']&&strtotime($a['end_at'])<time()):?>
                <span class="bdg bgray">⌛ Kadaluarsa</span>
                <?php else:?>
                <span class="bdg bgray">● Nonaktif</span>
                <?php endif;?>
            </td>
            <td style="font-size:.72rem;color:var(--g400);white-space:nowrap"><?=date('d/m/y H:i',strtotime($a['created_at']))?></td>
            <td style="white-space:nowrap">
                <!-- Toggle aktif/nonaktif -->
                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="toggle_announcement">
                    <input type="hidden" name="ann_id" value="<?=$a['id']?>">
                    <button type="submit" class="btn bxs <?=$a['is_active']?'bw':'bs'?>" title="<?=$a['is_active']?'Nonaktifkan':'Aktifkan'?>">
                        <?=$a['is_active']?'🔕':'▶'?>
                    </button>
                </form>
                <!-- Edit -->
                <a href="?edit=<?=$a['id']?>" class="btn bo bxs" title="Edit">✏️</a>
                <!-- Hapus -->
                <form method="POST" style="display:inline" onsubmit="return confirm('Hapus pengumuman ini permanen?')">
                    <input type="hidden" name="action" value="delete_announcement">
                    <input type="hidden" name="ann_id" value="<?=$a['id']?>">
                    <button type="submit" class="btn bd bxs" title="Hapus">🗑</button>
                </form>
            </td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
    <?php endif;?>
</div>

<!-- Preview portal login -->
<div class="card">
    <div class="ch"><div class="ct">👁️ Preview Tampilan Portal Pelanggan</div><a href="/portal/login.php" target="_blank" class="btn bo bsm">🔗 Buka Portal</a></div>
    <div class="cb" style="background:linear-gradient(135deg,#0D1B5E,#122B7A 45%,#8B1414);border-radius:0 0 10px 10px;padding:20px">
        <div style="max-width:380px;margin:0 auto">
            <?php
            // Ambil pesan aktif saat ini
            $activeAnns=$db->query("SELECT * FROM announcements WHERE is_active=1 AND (start_at IS NULL OR start_at<=NOW()) AND (end_at IS NULL OR end_at>=NOW()) ORDER BY maintenance_mode DESC,created_at DESC")->fetchAll();
            if($activeMaint>0):?>
            <div style="background:rgba(220,38,38,.15);border:1px solid rgba(220,38,38,.4);border-radius:12px;padding:16px;margin-bottom:12px;text-align:center">
                <div style="font-size:2rem;margin-bottom:8px">🔧</div>
                <div style="color:#FCA5A5;font-weight:700;font-size:.92rem;margin-bottom:4px"><?php foreach($activeAnns as $ann){ if($ann['maintenance_mode']){echo h($ann['title']);break;}}?></div>
                <?php foreach($activeAnns as $ann){ if($ann['maintenance_mode']&&$ann['body']):?>
                <div style="color:rgba(255,255,255,.7);font-size:.78rem;margin-top:6px"><?=h($ann['body'])?></div>
                <?php break;endif;}?>
            </div>
            <?php endif;?>
            <?php foreach($activeAnns as $ann):
                if($ann['maintenance_mode']||!$ann['show_on_login']) continue;
                $aColors=['info'=>['#DBEAFE','#1D4ED8','ℹ️'],'warning'=>['#FEF3C7','#D97706','⚠️'],'success'=>['#DCFCE7','#15803D','✅'],'release'=>['#F3E8FF','#7C3AED','🚀']];
                $ac=$aColors[$ann['type']]??['#F0F3FA','#6270A0','📢'];
            ?>
            <div style="background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:12px;margin-bottom:8px;backdrop-filter:blur(4px)">
                <div style="display:flex;gap:8px;align-items:flex-start">
                    <span style="font-size:1.1rem"><?=$ac[2]?></span>
                    <div>
                        <div style="color:#fff;font-weight:700;font-size:.82rem"><?=h($ann['title'])?></div>
                        <?php if($ann['body']):?><div style="color:rgba(255,255,255,.7);font-size:.75rem;margin-top:3px"><?=h($ann['body'])?></div><?php endif;?>
                    </div>
                </div>
            </div>
            <?php endforeach;?>
            <?php if(empty($activeAnns)):?>
            <div style="color:rgba(255,255,255,.4);text-align:center;font-size:.8rem;padding:16px">Tidak ada pesan aktif saat ini</div>
            <?php endif;?>
        </div>
    </div>
</div>

<!-- MODAL: Tambah/Edit Pengumuman -->
<div class="mo <?=$edit?'show':''?>" id="moAdd">
<div class="md md-lg">
    <div class="mh">
        <div class="mt"><?=$edit?'✏️ Edit Pengumuman':'➕ Tambah Pengumuman'?></div>
        <button class="mx" onclick="cM('moAdd')">✕</button>
    </div>
    <form method="POST">
        <div class="mb">
            <input type="hidden" name="action" value="save_announcement">
            <input type="hidden" name="ann_id" value="<?=$edit?$edit['id']:0?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:13px">
                <div class="fg" style="margin:0">
                    <label class="fl">Tipe Pengumuman</label>
                    <select name="type" class="fsel" id="annType" onchange="updateTypeHint()">
                        <?php foreach(['maintenance'=>'🔧 Maintenance','info'=>'ℹ️ Info Umum','warning'=>'⚠️ Peringatan','success'=>'✅ Rilis Baru','release'=>'🚀 Fitur Baru'] as $v=>$l):?>
                        <option value="<?=$v?>"<?=$edit&&$edit['type']===$v?' selected':''?>><?=$l?></option>
                        <?php endforeach;?>
                    </select>
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl">Status</label>
                    <div style="display:flex;gap:16px;margin-top:8px">
                        <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer">
                            <input type="checkbox" name="is_active" value="1" <?=$edit===null||$edit['is_active']?'checked':''?> style="accent-color:var(--blue)"> Aktifkan
                        </label>
                        <label style="display:flex;align-items:center;gap:6px;font-size:.84rem;cursor:pointer">
                            <input type="checkbox" name="show_on_login" value="1" <?=$edit===null||$edit['show_on_login']?'checked':''?> style="accent-color:var(--purple)"> Tampil di Login
                        </label>
                    </div>
                </div>
            </div>

            <!-- Maintenance mode toggle -->
            <div id="maintBox" style="background:#FEE2E2;border:2px solid var(--red);border-radius:10px;padding:12px;margin-bottom:13px;<?=$edit&&$edit['type']==='maintenance'?'':'display:none'?>">
                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer">
                    <input type="checkbox" name="maintenance_mode" value="1" <?=$edit&&$edit['maintenance_mode']?'checked':''?> style="width:16px;height:16px;accent-color:var(--red);margin-top:2px">
                    <div>
                        <div style="font-weight:700;color:var(--red-d);font-size:.86rem">🔒 Aktifkan Mode Maintenance (Blokir Login)</div>
                        <div style="font-size:.74rem;color:var(--red-d);margin-top:3px">Jika dicentang, pelanggan <strong>tidak bisa login</strong> dan akan melihat pesan maintenance ini.</div>
                    </div>
                </label>
            </div>

            <div class="fg">
                <label class="fl">Judul / Headline <span style="font-weight:400;color:var(--red)">*</span></label>
                <input type="text" name="title" class="fc" placeholder="Contoh: Maintenance Jaringan — Minggu 14 Mei 2026" required value="<?=$edit?h($edit['title']):''?>">
            </div>

            <div class="fg">
                <label class="fl">Pesan / Keterangan <span style="font-weight:400;color:var(--g400)">(opsional)</span></label>
                <textarea name="body" class="fc" placeholder="Contoh: Kami sedang melakukan upgrade jaringan. Estimasi selesai pukul 10.00 WIB. Mohon maaf atas ketidaknyamanannya." rows="3"><?=$edit?h($edit['body']):''?></textarea>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                <div class="fg" style="margin:0">
                    <label class="fl">Mulai Tampil <span style="font-weight:400;color:var(--g400)">(kosongkan = langsung)</span></label>
                    <input type="datetime-local" name="start_at" class="fc" value="<?=$edit&&$edit['start_at']?date('Y-m-d\TH:i',strtotime($edit['start_at'])):''?>">
                </div>
                <div class="fg" style="margin:0">
                    <label class="fl">Selesai Tampil <span style="font-weight:400;color:var(--g400)">(kosongkan = permanen)</span></label>
                    <input type="datetime-local" name="end_at" class="fc" value="<?=$edit&&$edit['end_at']?date('Y-m-d\TH:i',strtotime($edit['end_at'])):''?>">
                </div>
            </div>
        </div>
        <div class="mf">
            <button type="button" class="btn bo" onclick="cM('moAdd')">Batal</button>
            <button type="submit" class="btn bp">💾 Simpan Pengumuman</button>
        </div>
    </form>
</div>
</div>

<script>
function updateTypeHint(){
    const v=document.getElementById('annType').value;
    document.getElementById('maintBox').style.display=v==='maintenance'?'block':'none';
    if(v!=='maintenance'){
        const cb=document.querySelector('[name="maintenance_mode"]');
        if(cb) cb.checked=false;
    }
}
<?php if($edit):?>
// Buka modal edit langsung
document.addEventListener('DOMContentLoaded',()=>oM('moAdd'));
<?php endif;?>
</script>
<?php endPage();?>
