<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();$msg='';$mtype='ok';

// Ensure genie_config exists
try{$db->query("SELECT 1 FROM genie_config LIMIT 1");}catch(Exception $e){
    $db->exec("CREATE TABLE IF NOT EXISTS genie_config(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(100) DEFAULT 'GenieACS',url VARCHAR(255) DEFAULT 'http://localhost:7557',username VARCHAR(100) DEFAULT '',password VARCHAR(255) DEFAULT '',is_active TINYINT(1) DEFAULT 1,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $db->exec("INSERT IGNORE INTO genie_config(id,name,url)VALUES(1,'GenieACS Pusat','http://localhost:7557')");
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['action']??'';
    if($act==='save'){
        $id=(int)($_POST['id']??0);
        $url=rtrim(trim($_POST['url']??''),'/');$name=trim($_POST['name']??'');$un=trim($_POST['username']??'');$pw=$_POST['password']??'';
        
        if($id > 0){
            if(!empty($pw)){
                $db->prepare("UPDATE genie_config SET name=?,url=?,username=?,password=?,updated_at=NOW() WHERE id=?")->execute([$name,$url,$un,$pw,$id]);
            } else {
                $db->prepare("UPDATE genie_config SET name=?,url=?,username=?,updated_at=NOW() WHERE id=?")->execute([$name,$url,$un,$id]);
            }
            $msg='✅ Konfigurasi GenieACS diperbarui!';$mtype='ok';
        } else {
            $db->prepare("INSERT INTO genie_config (name,url,username,password) VALUES (?,?,?,?)")->execute([$name,$url,$un,$pw]);
            $msg='✅ Server GenieACS baru ditambahkan!';$mtype='ok';
        }
    }
    if($act==='delete'){
        $id=(int)($_POST['id']??0);
        $db->prepare("DELETE FROM genie_config WHERE id=?")->execute([$id]);
        // Update branches that used this genie_id
        try { $db->prepare("UPDATE cabang SET genie_id=NULL WHERE genie_id=?")->execute([$id]); } catch(Exception $e){}
        try { $db->prepare("UPDATE customers SET genie_id=NULL WHERE genie_id=?")->execute([$id]); } catch(Exception $e){}
        $msg='🗑️ Server GenieACS dihapus.';$mtype='ok';
    }
    if($act==='test'){
        $id=(int)($_POST['id']??0);
        $c=$db->prepare("SELECT * FROM genie_config WHERE id=?"); $c->execute([$id]); $c=$c->fetch();
        if($c){$g=new GenieACS($c['url'],$c['username'],$c['password']);$d=$g->getDevices('{}','_id');
            if(is_array($d)){$msg="✅ Koneksi ke <strong>{$c['name']}</strong> berhasil! Ditemukan <strong>".count($d)."</strong> perangkat ONT.";$mtype='ok';}
            else{$msg="❌ Gagal konek ke <strong>{$c['name']}</strong>: ".$g->error;$mtype='err';}
        }else{$msg='❌ Konfigurasi belum tersimpan atau tidak ditemukan.';$mtype='err';}
    }
}

$servers=$db->query("SELECT * FROM genie_config ORDER BY id ASC")->fetchAll();
startPage('Config GenieACS');
?>
<div class="ph">
    <div><div class="ph-t">⚙️ Konfigurasi GenieACS</div><div class="ph-s">Integrasi multi-server GenieACS NBI untuk manajemen ONT via TR-069</div></div>
    <div>
        <button class="btn bp" onclick="addServer()">➕ Tambah Server</button>
        <a href="/admin/ont.php" class="btn bo" style="margin-left:6px">📶 Monitor ONT</a>
    </div>
</div>
<?php if($msg):?><div class="alert a<?=$mtype?>"><?=$msg?></div><?php endif;?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;align-items:start">
<div style="display:flex;flex-direction:column;gap:14px">
    <?php if(empty($servers)): ?>
        <div class="alert awrn">⚠️ Belum ada server GenieACS. Silakan tambah server baru.</div>
    <?php else: ?>
        <?php foreach($servers as $s): ?>
        <div class="card" style="margin:0">
            <div class="ch">
                <div class="ct">🔗 <?=$s['name']?></div>
            </div>
            <div class="cb" style="font-size:.85rem">
                <div style="margin-bottom:6px"><strong>URL NBI:</strong> <span class="code"><?=$s['url']?></span></div>
                <div style="margin-bottom:12px"><strong>Username:</strong> <?=$s['username']?:'-'?></div>
                <div style="display:flex;gap:8px">
                    <button class="btn bo bsm" onclick='editServer(<?=json_encode($s)?>)'>✏️ Edit</button>
                    <form method="POST" style="display:inline"><?=csrfField()?><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn bw bsm">🔌 Test</button></form>
                    <form method="POST" style="display:inline;margin-left:auto" onsubmit="return confirm('Hapus server ini?')"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$s['id']?>"><button type="submit" class="btn bd bsm">🗑️ Hapus</button></form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div style="display:grid;gap:14px">
<div class="card" style="margin:0">
    <div class="ch"><div class="ct">📡 ONT yang Didukung</div></div>
    <div class="cb" style="font-size:.83rem;color:var(--g600);line-height:1.9">
        <?php foreach([
            ['🟠','FiberHome HG6145D2','WLANConfiguration.1/.5, PreSharedKey.1.KeyPassphrase'],
            ['🟠','FiberHome H3-2S XPON','WLANConfiguration.1/.5, PreSharedKey.1.KeyPassphrase'],
            ['🔵','C-Data FD511GD','WLANConfiguration.1/.5, KeyPassphrase (direct)'],
        ] as $item): list($ic,$n,$p) = $item; ?>
        <div style="padding:8px 12px;border-radius:8px;background:var(--g50);border:1px solid var(--g200);margin-bottom:8px">
            <div style="font-weight:700;color:var(--g900)"><?=$ic?> <?=$n?></div>
            <div style="font-size:.72rem;font-family:'JetBrains Mono',monospace;color:var(--g500)"><?=$p?></div>
        </div>
        <?php endforeach;?>
    </div>
</div>

<div class="card" style="margin:0">
    <div class="ch"><div class="ct">⚙️ Setup ACS di ONT</div></div>
    <div class="cb" style="font-size:.83rem;line-height:1.8">
        <div style="display:grid;gap:6px">
            <div><span class="code">ACS URL</span> → <span style="color:var(--blue-d);font-weight:600">http://SERVER_IP:7547</span></div>
            <div><span class="code">Periodic Inform</span> → <span style="color:var(--blue-d);font-weight:600">Enable</span></div>
            <div><span class="code">Inform Interval</span> → <span style="color:var(--blue-d);font-weight:600">300 detik</span></div>
            <div><span class="code">Connection Req Auth</span> → <span style="color:var(--blue-d);font-weight:600">Enable</span></div>
        </div>
        <div class="alert ainf" style="margin-top:12px;margin-bottom:0">
            Port: <span class="code">7547</span> = NBI Device Interface | <span class="code">7557</span> = API Management
        </div>
    </div>
</div>
</div>
</div>

<!-- Modal Form -->
<div class="mo" id="mForm"><div class="md">
    <div class="mh"><div class="mt" id="fTitle">⚙️ Server GenieACS</div><button class="mx" onclick="cM('mForm')">✕</button></div>
    <form method="POST"><div class="mb">
        <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="fId" value="0">
        <div class="fg"><label class="fl">Nama Server</label><input type="text" name="name" id="fName" class="fc" required placeholder="Contoh: GenieACS Pusat"></div>
        <div class="fg"><label class="fl">URL NBI *</label>
            <input type="text" name="url" id="fUrl" class="fc" required placeholder="http://192.168.1.10:7557">
            <div class="fhint">Port NBI default GenieACS: <span class="code">7557</span></div>
        </div>
        <div class="frow2">
            <div class="fg"><label class="fl">Username <span style="font-weight:400;color:var(--g400)">(opsional)</span></label><input type="text" name="username" id="fUn" class="fc"></div>
            <div class="fg"><label class="fl">Password</label><input type="password" name="password" id="fPw" class="fc" placeholder="Biarkan kosong jika tidak diubah"></div>
        </div>
    </div>
    <div class="mf"><button type="button" class="btn bo" onclick="cM('mForm')">Batal</button><button type="submit" class="btn bp">💾 Simpan</button></div>
    </form>
</div></div>

<script>
function addServer(){
    document.getElementById('fId').value='0';
    document.getElementById('fName').value='';
    document.getElementById('fUrl').value='http://';
    document.getElementById('fUn').value='';
    document.getElementById('fTitle').textContent='➕ Tambah Server GenieACS';
    oM('mForm');
}
function editServer(s){
    document.getElementById('fId').value=s.id;
    document.getElementById('fName').value=s.name;
    document.getElementById('fUrl').value=s.url;
    document.getElementById('fUn').value=s.username||'';
    document.getElementById('fTitle').textContent='✏️ Edit Server GenieACS';
    oM('mForm');
}
</script>
<?php endPage();?>
