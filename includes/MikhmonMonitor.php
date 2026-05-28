<?php
// ================================================================
// MIKHMON MONITOR — Multi Router Monitoring
// Ambil data langsung dari Mikrotik API (ROS 7 compatible)
// ================================================================
if (!defined('IN_APP')) die('Direct access denied');

class MikhmonMonitor {

    /**
     * Ambil semua statistik dari satu router
     */
    public static function getRouterStats(array $router): array {
        $result=[
            'router_id'   =>$router['id'],
            'router_name' =>$router['name'],
            'router_ip'   =>$router['ip_public'],
            'connected'   =>false,'error'=>'',
            'identity'=>'-','resource'=>[],
            'hotspot' =>['active'=>[],'users'=>[],'profiles'=>[],'total_active'=>0,'total_users'=>0],
            'pppoe'   =>['active'=>[],'secrets'=>[],'total_active'=>0,'total_secrets'=>0],
            'revenue' =>['daily_hs'=>0,'daily_ppp'=>0,'daily_total'=>0,'monthly_hs'=>0,'monthly_ppp'=>0,'monthly_total'=>0],
        ];
        $api=MikrotikAPI::fromRouter($router);
        if(!$api->connect()){$result['error']=$api->error;return $result;}
        $result['connected']=true;
        $result['identity'] =$api->getIdentity();
        $result['resource'] =$api->getResourceUsage();

        // Hotspot
        $hsActive  =$api->getHotspotActive();
        $hsUsers   =$api->getHotspotUsers();
        $hsProfiles=$api->getHotspotProfiles();
        $result['hotspot']=[
            'active'  =>$hsActive,'users'=>$hsUsers,'profiles'=>$hsProfiles,
            'total_active'=>count($hsActive),'total_users'=>count($hsUsers),
        ];

        // PPPoE (filter hanya service pppoe/any, bukan l2tp/pptp)
        $pppActive  =$api->getPppActive();
        $pppSecrets =$api->getPppSecrets();
        // Filter PPPoE saja (exclude VPN services)
        $pppoeActive=array_filter($pppActive, fn($s)=>in_array($s['service']??'',['', ' ','pppoe','any'])||!isset($s['service']));
        $pppoeSecrets=array_filter($pppSecrets, fn($s)=>!in_array($s['service']??'',['l2tp','pptp']));
        $result['pppoe']=[
            'active'  =>array_values($pppoeActive),'secrets'=>array_values($pppoeSecrets),
            'total_active'=>count($pppoeActive),'total_secrets'=>count($pppoeSecrets),
        ];

        // Revenue
        $result['revenue']=self::calcRevenue($hsUsers,$pppoeSecrets,$hsActive,$pppoeActive);
        $api->close();
        return $result;
    }

    /**
     * Parse harga dari comment (format Mikhmon: Rp.5000, 5000, 5K, 5k, dll)
     */
    public static function parsePrice(string $comment): int {
        // Format Rp.5000 atau Rp 5000
        if(preg_match('/[Rr][Pp]\.?\s*([0-9][0-9.,]*[KkMm]?)/u',$comment,$m)){
            return self::parseNum($m[1]);
        }
        // Angka dengan K/M suffix
        if(preg_match('/\b(\d+(?:\.\d+)?)\s*[Kk]\b/',$comment,$m)) return (int)((float)$m[1]*1000);
        if(preg_match('/\b(\d+(?:\.\d+)?)\s*[Mm]\b/',$comment,$m)) return (int)((float)$m[1]*1000000);
        // Angka saja 4+ digit
        if(preg_match('/\b(\d{4,})\b/',$comment,$m)) return (int)$m[1];
        return 0;
    }

    private static function parseNum(string $s): int {
        $s=strtolower(str_replace([' ','.'],'',$s));
        if(str_ends_with($s,'k')) return (int)rtrim($s,'k')*1000;
        if(str_ends_with($s,'m')) return (int)rtrim($s,'m')*1000000;
        return (int)str_replace(',','',$s);
    }

    private static function calcRevenue(array $hsUsers,array $pppSecrets,array $hsActive,array $pppActive): array {
        // Map active by name/user
        $ahMap=[];
        foreach($hsActive as $s) $ahMap[$s['user']??$s['name']??'']=true;
        $apMap=[];
        foreach($pppActive as $s) $apMap[$s['name']??'']=true;

        $dHs=0;$dPpp=0;$mHs=0;$mPpp=0;
        foreach($hsUsers as $u){
            $price=self::parsePrice($u['comment']??'');
            if($price<=0) continue;
            $mHs+=$price;
            if(isset($ahMap[$u['name']??''])) $dHs+=$price;
        }
        foreach($pppSecrets as $s){
            $price=self::parsePrice($s['comment']??'');
            if($price<=0) continue;
            $mPpp+=$price;
            if(isset($apMap[$s['name']??''])) $dPpp+=$price;
        }
        return ['daily_hs'=>$dHs,'daily_ppp'=>$dPpp,'daily_total'=>$dHs+$dPpp,
                'monthly_hs'=>$mHs,'monthly_ppp'=>$mPpp,'monthly_total'=>$mHs+$mPpp];
    }

    public static function rp(int $n): string { return 'Rp '.number_format($n,0,',','.'); }

    public static function getResBar(array $res): array {
        $total=(int)($res['total-memory']??1); $free=(int)($res['free-memory']??0); $used=$total-$free;
        return [
            'cpu'      =>(int)($res['cpu-load']??0),
            'mem_pct'  =>$total>0?round($used/$total*100):0,
            'mem_used' =>round($used/1048576,1),
            'mem_total'=>round($total/1048576,1),
            'uptime'   =>$res['uptime']??'-',
            'version'  =>$res['version']??'-',
        ];
    }
}
