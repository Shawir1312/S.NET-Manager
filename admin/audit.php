<?php
define('IN_APP',true);
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/layout.php';
requireAdmin();
$db=db();

$q=trim($_GET['q']??'');$actor=$_GET['actor']??'';$pg=max(1,(int)($_GET['pg']??1));$lim=50;$off=($pg-1)*$lim;
$wh=['1=1'];$pr=[];
if($q){$wh[]='(a.action LIKE ? OR a.target LIKE ? OR a.actor_name LIKE ? OR a.detail LIKE ?)';$s="%$q%";$pr=[$s,$s,$s,$s];}
if($actor){$wh[]='a.actor_type=?';$pr[]=$actor;}
$where=implode(' AND ',$wh);
$total=(int)$db->prepare("SELECT COUNT(*) FROM audit_log a WHERE $where")->execute($pr)?($db->prepare("SELECT COUNT(*) FROM audit_log a WHERE $where")&&0):0;
$cnt=$db->prepare("SELECT COUNT(*) FROM audit_log a WHERE $where");$cnt->execute($pr);$total=(int)$cnt->fetchColumn();
$pages=max(1,ceil($total/$lim));
$st=$db->prepare("SELECT * FROM audit_log a WHERE $where ORDER BY a.created_at DESC LIMIT $lim OFFSET $off");
$st->execute($pr);$logs=$st->fetchAll();

$actionColors=['add'=>'bon','edit'=>'bblue','delete'=>'boff','login'=>'bpur','portal_login'=>'borg','portal_change_wifi'=>'borg','portal_block_client'=>'boff','reboot'=>'boff'];

startPage('Audit Log');
?>
<div class="ph">
    <div><div class="ph-t">📋 Audit Log</div><div class="ph-s">Riwayat semua aktivitas admin dan portal pelanggan</div></div>
    <div style="font-size:.78rem;color:var(--g400)">Total: <?=$total?> entri</div>
</div>

<div class="card"><div class="cb" style="padding:12px 16px">
<form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
    <div style="flex:1;min-width:200px"><label class="fl">🔍 Cari</label><input type="text" name="q" class="fc" placeholder="Aksi, target, nama actor..." value="<?=h($q)?>"></div>
    <div style="min-width:130px"><label class="fl">Actor Type</label><select name="actor" class="fsel"><option value="">Semua</option><option value="admin" <?=$actor==='admin'?'selected':''?>>Admin</option><option value="customer" <?=$actor==='customer'?'selected':''?>>Pelanggan</option></select></div>
    <button type="submit" class="btn bp">Filter</button><?php if($q||$actor):?><a href="/admin/audit.php" class="btn bo">Reset</a><?php endif;?>
</form></div></div>

<div class="card"><div class="ch"><div class="ct">📋 Log (halaman <?=$pg?>/<?=$pages?>)</div></div>
<div class="tw"><table class="dt"><thead><tr><th>#</th><th>Waktu</th><th>Actor</th><th>Tipe</th><th>Aksi</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead><tbody>
<?php if(empty($logs)):?><tr><td colspan="8" class="empty">Tidak ada log</td></tr><?php endif;?>
<?php foreach($logs as $i=>$l):?>
<tr>
    <td style="color:var(--g400);font-size:.7rem"><?=$off+$i+1?></td>
    <td style="font-size:.72rem;white-space:nowrap"><strong style="color:var(--g700)"><?=date('d/m/y',strtotime($l['created_at']))?></strong><br><span style="color:var(--g400)"><?=date('H:i:s',strtotime($l['created_at']))?></span></td>
    <td><strong style="font-size:.82rem"><?=h($l['actor_name'])?></strong></td>
    <td><span class="bdg <?=$l['actor_type']==='admin'?'bblue':'borg'?>"><?=h($l['actor_type'])?></span></td>
    <td><span class="bdg <?=$actionColors[$l['action']]??'bgray'?>"><?=h($l['action'])?></span></td>
    <td style="font-size:.78rem;color:var(--blue-d)"><?=h($l['target']??'-')?></td>
    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.75rem;color:var(--g600)" title="<?=h($l['detail']??'')?>"><?=h($l['detail']?:'-')?></td>
    <td class="ipm" style="font-size:.7rem;color:var(--g400)"><?=h($l['ip_address']??'-')?></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>

<!-- Pagination -->
<?php if($pages>1):?>
<div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;padding:10px 0">
    <?php for($p=1;$p<=$pages;$p++):$url="?pg=$p".($q?"&q=".urlencode($q):'').($actor?"&actor=$actor":'');?>
    <a href="<?=$url?>" class="btn <?=$p===$pg?'bp':'bo'?> bsm"><?=$p?></a>
    <?php endfor;?>
</div>
<?php endif;?>
<?php endPage();?>
