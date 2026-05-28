<?php
if (!defined('IN_APP')) die('Direct access denied');
class GenieACS {
    private $base, $user, $pass;
    public $error = '';

    // TR-069 Root paths
    const IGD     = 'InternetGatewayDevice';
    const LAN_ETH = 'InternetGatewayDevice.LANDevice.1.LANEthernetInterfaceConfig';
    const WLAN    = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration';
    const WAN_IP  = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
    const WAN_PPP = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';

    // Brand detection: oui=prefix OUI, pid=product class substring
    // lan_bind = parameter name untuk binding LAN di WAN connection
    // vlan_id  = parameter untuk VLAN ID
    // vlan_en  = parameter untuk enable VLAN (jika ada)
    // svclist  = parameter service list
    // wifi_key = path WiFi password (PreSharedKey.1.KeyPassphrase vs KeyPassphrase)
    const BRANDS = [
        'FiberHome' => [
            'oui'      => ['000B82','ACDF00','48573A'],
            'pid'      => ['hg6145','h3-2s','h32s','xpon','fiberhome'],
            'mfr'      => ['FiberHome'],
            'lan_bind' => 'X_FH_LanInterface',
            'vlan_id'  => 'VLANID',
            'vlan_en'  => 'VLANEnable',
            'svclist'  => 'X_FH_ServiceList',
            'wifi_key' => 'PreSharedKey.1.KeyPassphrase',
            'wifi_key2'=> 'PreSharedKey.1.PreSharedKey',
        ],
        'ZTE' => [
            'oui'      => ['C8700A','A88090','ZCOMI0','C078BE'],
            'pid'      => ['f670','f660','f609','f6600','zte','zxhn'],
            'mfr'      => ['ZTE','ZXHN'],
            'rx_path'  => 'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            'lan_bind' => 'X_ZTE-COM_LanInterface',
            'vlan_id'  => 'X_ZTE-COM_VLANID',
            'vlan_en'  => 'X_ZTE-COM_VLANEnable',
            'svclist'  => 'X_ZTE-COM_ServiceList',
            'wifi_key' => 'PreSharedKey.1.KeyPassphrase',
        ],
        'Huawei' => [
            'oui'      => ['E8CD2D','D05FB8','00E0FC','4CB16C'],
            'pid'      => ['hg','hs','huawei','echolife'],
            'mfr'      => ['Huawei Technologies Co., Ltd','Huawei'],
            'hw_detect'=> 'InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber',
            'lan_bind' => 'X_HW_LANBIND',   // khusus: pakai enable per port
            'vlan_id'  => 'X_HW_VLAN',
            'vlan_en'  => null,
            'svclist'  => 'X_HW_SERVICELIST',
            'wifi_key' => 'PreSharedKey.1.KeyPassphrase',
        ],
        'CMCC' => [
            'oui'      => [],
            'pid'      => [],
            'mfr'      => [],
            'cmcc_detect' => 'InternetGatewayDevice.X_CMCC_UserInfo.ServiceName',
            'lan_bind' => 'X_CMCC_LanInterface',
            'vlan_id'  => 'X_CMCC_VLANIDMark',
            'vlan_en'  => 'X_CMCC_VLANMode',  // 2=enable, 1=disable
            'svclist'  => 'X_CMCC_ServiceList',
            'wifi_key' => 'PreSharedKey.1.KeyPassphrase',
        ],
        'CTCOM' => [
            'oui'      => [],
            'pid'      => [],
            'mfr'      => [],
            'ct_detect'=> 'InternetGatewayDevice.X_CT-COM_UserInfo.ServiceName',
            'lan_bind' => 'X_CT-COM_LanInterface',
            'vlan_id'  => 'X_CT-COM_VLANIDMark',
            'vlan_en'  => 'X_CT-COM_VLANMode',
            'svclist'  => 'X_CT-COM_ServiceList',
            'wifi_key' => 'PreSharedKey.1.KeyPassphrase',
        ],
        'CData' => [
            'oui'      => ['F04DA2','606B9C','A80720','84C230'],
            'pid'      => ['fd511','cdata','fd505'],
            'mfr'      => ['CData','C-Data'],
            'lan_bind' => 'X_CData_BindingLAN',
            'vlan_id'  => 'X_CData_VLANID',
            'vlan_en'  => null,
            'svclist'  => 'ServiceList',
            'wifi_key' => 'KeyPassphrase',
        ],
    ];

    // WiFi path per index SSID (index 1-4 = 2.4G, 5-8 = 5G untuk kebanyakan ONT)
    const WLAN_PATH = 'InternetGatewayDevice.LANDevice.1.WLANConfiguration';

    public function __construct(string $url, string $u='', string $p='') {
        $this->base=rtrim($url,'/'); $this->user=$u; $this->pass=$p;
    }

    private function req(string $method, string $path, $body=null) {
        if(!function_exists('curl_init')){$this->error='cURL not available';return null;}
        $ch=curl_init($this->base.$path);
        $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_CUSTOMREQUEST=>$method,
               CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json'],
               CURLOPT_SSL_VERIFYHOST=>(getenv('GENIE_SSL_VERIFY')==='true'?2:0),CURLOPT_SSL_VERIFYPEER=>(getenv('GENIE_SSL_VERIFY')==='true')];
        if($this->user) $opts[CURLOPT_USERPWD]="$this->user:$this->pass";
        if($body!==null){
            $opts[CURLOPT_POSTFIELDS]=is_string($body)?$body:json_encode($body,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch,$opts);
        $resp=curl_exec($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);
        curl_close($ch);
        if($err){$this->error=$err;return null;}
        if($code>=400){$this->error="HTTP $code: ".substr($resp??'',0,300);return null;}
        return $resp!==false?($resp?json_decode($resp,true)??true:true):null;
    }

    // Kirim task ke GenieACS NBI
    private function sendTask(string $devId, array $task): bool {
        $enc=rawurlencode($devId);
        $body=json_encode($task,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
        // Coba dengan connection_request dulu (ONT online)
        $r=$this->req('POST',"/devices/{$enc}/tasks?timeout=3000&connection_request",$body);
        if($r===null){
            $this->error='';
            // Fallback: queue task (ONT offline/delayed)
            $r=$this->req('POST',"/devices/{$enc}/tasks",$body);
        }
        return $r!==null;
    }

    public function getDevices(string $q='{}',string $proj=''): array {
        $qs='?query='.urlencode($q);
        if($proj)$qs.='&projection='.urlencode($proj);
        return (array)($this->req('GET','/devices'.$qs)??[]);
    }
    public function getDevice(string $id) {
        $r=$this->getDevices(json_encode(['_id'=>$id]));return $r[0]??null;
    }
    public function searchDevices(string $term): array {
        $s=addslashes($term);
        $q=json_encode(['$or'=>[
            ['_deviceId._SerialNumber'=>['$regex'=>$s,'$options'=>'i']],
            ['_tags'=>['$regex'=>$s,'$options'=>'i']],
            [self::WLAN_PATH.'.1.SSID._value'=>['$regex'=>$s,'$options'=>'i']],
            [self::WLAN_PATH.'.5.SSID._value'=>['$regex'=>$s,'$options'=>'i']],
            ['_deviceId._Manufacturer'=>['$regex'=>$s,'$options'=>'i']],
            ['_deviceId._ProductClass'=>['$regex'=>$s,'$options'=>'i']],
        ]]);
        return $this->getDevices($q);
    }

    // ── DETEKSI BRAND dari device data ──────────────────────────────
    public function detectBrand(array $dev): array {
        $oui=strtoupper(str_replace([':','-'],'',($dev['_deviceId']['_OUI']??'')));
        $pid=strtolower($dev['_deviceId']['_ProductClass']??'');
        $mfr=$dev['_deviceId']['_Manufacturer']??'';
        $id=strtolower($dev['_id']??'');

        // Cek field khusus di data device (dari summon)
        $hasHW=($this->dig($dev,'InternetGatewayDevice.DeviceInfo.X_HW_SerialNumber')!==null);
        $hasCMCC=($this->dig($dev,'InternetGatewayDevice.X_CMCC_UserInfo.ServiceName')!==null);
        $hasCT=($this->dig($dev,'InternetGatewayDevice.X_CT-COM_UserInfo.ServiceName')!==null)||
               ($this->dig($dev,'InternetGatewayDevice.X_CT-COM_UserInfo.UserName')!==null);
        $hasCU=($this->dig($dev,'InternetGatewayDevice.X_CU_UserInfo.UserName')!==null);
        $hasZTE=($this->dig($dev,'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower')!==null);

        if($hasZTE||str_contains(strtolower($mfr),'zte')) return self::BRANDS['ZTE'];
        if($hasHW||str_contains(strtolower($mfr),'huawei')) return self::BRANDS['Huawei'];
        if($hasCMCC) return self::BRANDS['CMCC'];
        if($hasCT) return self::BRANDS['CTCOM'];

        // OUI/PID match
        foreach(self::BRANDS as $b){
            if(!empty($b['oui'])){
                foreach($b['oui'] as $o){ if(str_starts_with($oui,strtoupper($o))) return $b; }
            }
            if(!empty($b['pid'])){
                foreach($b['pid'] as $k){ if(str_contains($pid,$k)||str_contains($id,$k)) return $b; }
            }
            if(!empty($b['mfr'])){
                foreach($b['mfr'] as $m){ if(str_contains(strtolower($mfr),strtolower($m))) return $b; }
            }
        }
        // Default FiberHome
        return self::BRANDS['FiberHome'];
    }

    // ── Helper: deteksi nama brand sebagai string ──────────────────
    public function detectBrandName(array $dev): string {
        $b = $this->detectBrand($dev);
        // Cocokkan array config ke nama brand
        foreach(self::BRANDS as $name=>$cfg){
            if($cfg===$b) return $name;
        }
        return 'FiberHome'; // default
    }

    // Legacy alias
    public function detectModel(array $dev): array {
        $b=$this->detectBrand($dev);
        $m=$dev['_deviceId']['_ProductClass']??'ONT';
        $mfr=$dev['_deviceId']['_Manufacturer']??'Unknown';
        $wk=$b['wifi_key']??'PreSharedKey.1.KeyPassphrase';
        return [
            'brand'=>$mfr,'model'=>$m,
            'ssid24'=>self::WLAN_PATH.'.1.SSID',
            'key24' =>self::WLAN_PATH.'.1.'.$wk,
            'ssid5g'=>self::WLAN_PATH.'.5.SSID',
            'key5g' =>self::WLAN_PATH.'.5.'.$wk,
            'sec24' =>self::WLAN_PATH.'.1.BeaconType',
            'sec5g' =>self::WLAN_PATH.'.5.BeaconType',
            'cli24' =>self::WLAN_PATH.'.1.AssociatedDevice',
            'cli5g' =>self::WLAN_PATH.'.5.AssociatedDevice',
        ];
    }

    // ── Baca daftar MAC filter dari data ONT (cached di GenieACS) ──────
    public function getMACFilter(array $dev): array {
        $slots=[];
        $count=(int)($this->dig($dev,'InternetGatewayDevice.X_FH_FireWall.MACFilterNumberOfEntries')??0);
        $max=$count>0?$count:16;
        for($i=1;$i<=$max+4;$i++){
            $base="InternetGatewayDevice.X_FH_FireWall.MACFilter.$i";
            $mac=$this->dig($dev,"$base.MAC");
            $en=$this->dig($dev,"$base.Enable");
            if($mac===null&&$en===null) continue; // slot mungkin ada tapi belum di-summon
            $slots[$i]=[
                'mac'      =>strtoupper(trim($mac??'')),
                'enable'   =>($en==='true'||$en==='1'),
                'timeStart'=>$this->dig($dev,"$base.TimeStart")??'00:00',
                'timeStop' =>$this->dig($dev,"$base.TimeStop")??'23:59',
            ];
        }
        return $slots;
    }

    // Jumlah entry MACFilter saat ini
    public function getMACFilterCount(array $dev): int {
        return (int)($this->dig($dev,'InternetGatewayDevice.X_FH_FireWall.MACFilterNumberOfEntries')??0);
    }

    // ── Refresh data MACFilter dari ONT ke GenieACS ──────────────────
    public function refreshMACFilter(string $devId): bool {
        return $this->sendTask($devId,['name'=>'getParameterValues',
            'parameterNames'=>[
                'InternetGatewayDevice.X_FH_FireWall.MACFilter.',
                'InternetGatewayDevice.X_FH_FireWall.MACFilterNumberOfEntries',
            ]]);
    }

    public function dig(array $d, string $path) {
        $cur=$d;
        foreach(explode('.',$path) as $k){
            if(!is_array($cur)||!array_key_exists($k,$cur))return null;
            $cur=$cur[$k];
        }
        return is_array($cur)?($cur['_value']??null):(is_string($cur)||is_numeric($cur)?(string)$cur:null);
    }

    public function getWifi(array $dev): array {
        $m=$this->detectModel($dev);
        $wk=$this->detectBrand($dev)['wifi_key']??'PreSharedKey.1.KeyPassphrase';
        // Coba PreSharedKey.1.KeyPassphrase dulu, fallback KeyPassphrase
        $k24=$this->dig($dev,self::WLAN_PATH.'.1.PreSharedKey.1.KeyPassphrase')
            ??$this->dig($dev,self::WLAN_PATH.'.1.KeyPassphrase')
            ??$this->dig($dev,self::WLAN_PATH.'.1.PreSharedKey.1.PreSharedKey');
        $k5g=$this->dig($dev,self::WLAN_PATH.'.5.PreSharedKey.1.KeyPassphrase')
            ??$this->dig($dev,self::WLAN_PATH.'.5.KeyPassphrase')
            ??$this->dig($dev,self::WLAN_PATH.'.5.PreSharedKey.1.PreSharedKey');
        return [
            'ssid_24'=>$this->dig($dev,self::WLAN_PATH.'.1.SSID'),
            'pass_24'=>$k24,
            'ssid_5g'=>$this->dig($dev,self::WLAN_PATH.'.5.SSID'),
            'pass_5g'=>$k5g,
            // legacy keys
            'ssid24'=>$this->dig($dev,self::WLAN_PATH.'.1.SSID'),
            'key24' =>$k24,
            'ssid5g'=>$this->dig($dev,self::WLAN_PATH.'.5.SSID'),
            'key5g' =>$k5g,
            'sec24' =>$this->dig($dev,self::WLAN_PATH.'.1.BeaconType'),
            'sec5g' =>$this->dig($dev,self::WLAN_PATH.'.5.BeaconType'),
            'model' =>$m,
        ];
    }

    public function getClients(array $dev): array {
        $out=[];
        foreach([1=>'2.4G',2=>'2.4G',5=>'5G',6=>'5G',7=>'5G',8=>'5G'] as $idx=>$band){
            $base=self::WLAN_PATH.".$idx.AssociatedDevice";
            $ena=$this->dig($dev,self::WLAN_PATH.".$idx.Enable");
            if($ena!==null&&$ena!=='true'&&$ena!=='1') continue;
            for($i=1;$i<=32;$i++){
                $mac=$this->dig($dev,"$base.$i.AssociatedDeviceMACAddress");
                if(!$mac) break;
                $ip=$this->dig($dev,"$base.$i.AssociatedDeviceIPAddress")
                   ??$this->dig($dev,"$base.$i.X_BROADCOM_COM_IPAddress")?:'-';
                $rssi=$this->dig($dev,"$base.$i.X_HW_RSSI")
                    ??$this->dig($dev,"$base.$i.AssociatedDeviceRssi")
                    ??$this->dig($dev,"$base.$i.X_BROADCOM_COM_RSSI")
                    ??$this->dig($dev,"$base.$i.SignalStrength")?:'-';
                $hn=$this->dig($dev,"$base.$i.X_HW_AssociatedDevicedescriptions")
                  ??$this->dig($dev,"$base.$i.X_ZTE-COM_AssociatedDeviceName")
                  ??$this->dig($dev,"$base.$i.X_BROADCOM_COM_Hostname")?:'-';
                $out[]=['mac'=>$mac,'band'=>$band,'ip'=>$ip,'rssi'=>$rssi,'hostname'=>$hn,'ssid_idx'=>$idx];
            }
        }
        return $out;
    }

    public function getWanList(array $dev): array {
        $out=[];
        $wanDevPath='InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
        for($cd=1;$cd<=5;$cd++){
            foreach(['WANIPConnection'=>'ip','WANPPPConnection'=>'ppp'] as $connType=>$tkey){
                $base="$wanDevPath.$cd.$connType";
                $name=$this->dig($dev,"$base.1.Name")?:$this->dig($dev,"$base.1.ConnectionType");
                if($name!==null){
                    $out["$tkey.$cd"]=[
                        'slot'=>$cd,'type'=>$tkey,
                        'label'=>($tkey==='ppp'?'WAN PPP':'WAN IP')." Slot $cd",
                        'name'=>$name,
                        'conn_type'=>$this->dig($dev,"$base.1.ConnectionType")?:'',
                        'status'=>$this->dig($dev,"$base.1.ConnectionStatus")?:'-',
                        'ip'=>$this->dig($dev,"$base.1.ExternalIPAddress")?:'-',
                        'gw'=>$this->dig($dev,"$base.1.DefaultGateway")?:'-',
                        'nat'=>$this->dig($dev,"$base.1.NATEnabled")?:'-',
                        'vlan'=>$this->dig($dev,"$base.1.X_HW_VLAN")
                              ??$this->dig($dev,"$base.1.X_ZTE-COM_VLANID")
                              ??$this->dig($dev,"$base.1.VLANID")
                              ??$this->dig($dev,"$base.1.X_CMCC_VLANIDMark")
                              ??$this->dig($dev,"$base.1.X_CT-COM_VLANIDMark")?:'-',
                    ];
                }
            }
        }
        return $out;
    }

    public function getInfo(array $dev): array {
        $last=$dev['_lastInform']??null;
        $diff=$last?(time()-strtotime($last))/60:PHP_INT_MAX;
        $m=$this->detectModel($dev);
        $mfr=$dev['_deviceId']['_Manufacturer']??'-';
        return [
            'id'         =>$dev['_id']??'-',
            'serial'     =>$dev['_deviceId']['_SerialNumber']??'-',
            'oui'        =>$dev['_deviceId']['_OUI']??'-',
            'manufacturer'=>$mfr,
            'product'    =>$dev['_deviceId']['_ProductClass']??'-',
            'sw_version' =>$this->dig($dev,'InternetGatewayDevice.DeviceInfo.SoftwareVersion')?:'-',
            'hw_version' =>$this->dig($dev,'InternetGatewayDevice.DeviceInfo.HardwareVersion')?:'-',
            'uptime'     =>$this->dig($dev,'InternetGatewayDevice.DeviceInfo.UpTime')?:'-',
            'ip_wan'     =>$this->dig($dev,'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANIPConnection.1.ExternalIPAddress')
                          ??$this->dig($dev,'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.ExternalIPAddress')?:'-',
            'last_inform'=>$last,
            'online'     =>$diff<10,
            'last_seen'  =>$diff<1?'Baru saja':($diff<60?round($diff).'m lalu':($last?date('d/m H:i',strtotime($last)):'-')),
            'tags'       =>$dev['_tags']??[],
            'brand'      =>$mfr,
            'model'      =>$m['model'],
        ];
    }

    // ── BACA DATA OPTIK (Redaman / Sinyal) ────────────────────────
    // Path dan formula sesuai virtual parameter GenieACS:
    //   val < 0  → sudah dBm, langsung pakai
    //   val > 0  → unit nW: db = 30 + (log10(val × 10⁻⁷) × 10)
    // Prioritas: VirtualParameters.RXPower → path brand spesifik
    public function getOptical(array $dev): array {
        $brand = $this->detectBrandName($dev);

        // Coba VirtualParameters dulu (jika sudah ada virtual param di GenieACS)
        $vpRx = $this->dig($dev, 'VirtualParameters.RXPower');

        // Candidate paths sesuai virtual parameter (urutan sesuai prioritas VP)
        $rxCandidates = [
            // ZTE
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.RXPower',
            // CMCC EPON
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.RXPower',
            // CMCC GPON
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.RXPower',
            // CT-COM EPON (GM220SE)
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.RXPower',
            // CT-COM GPON (GM220SG)
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.RXPower',
            // CU EPON (F477V2)
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.RXPower',
            // Huawei — "Interafce" adalah typo asli nama parameter ONT Huawei
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.RXPower',
            // FiberHome
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.RXPower',
            // Nokia/ALU
            'InternetGatewayDevice.X_ALU_OntOpticalParam.RXPower',
        ];
        $txCandidates = [
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.TXPower',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.TXPower',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.TXPower',
        ];
        $voltCandidates = [
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.Voltage',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.Voltage',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.Voltage',
        ];
        $tempCandidates = [
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.Temperature',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.Temperature',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.Temperature',
        ];
        $biasCandidates = [
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.OpticalTransceiver.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.BiasCurrent',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.BiasCurrent',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.BiasCurrent',
        ];

        $find = function(array $candidates) use ($dev) {
            foreach($candidates as $path){
                $v = $this->dig($dev, $path);
                if($v !== null && $v !== '' && $v !== '0' && $v !== 'N/A') return $v;
            }
            return null;
        };

        // VirtualParameters diutamakan jika bukan N/A
        $rxRaw = ($vpRx !== null && $vpRx !== 'N/A' && $vpRx !== '') ? $vpRx : $find($rxCandidates);
        $txRaw  = $find($txCandidates);
        $volt   = $find($voltCandidates);
        $temp   = $find($tempCandidates);
        $bias   = $find($biasCandidates);

        // ── Konversi ke dBm sesuai logika virtual parameter GenieACS ──────────
        //   val < 0  → sudah dBm, langsung pakai
        //   val > 0  → unit nW: db = 30 + (log10(val × 10⁻⁷) × 10)
        $convertDbm = function($raw) {
            if($raw === null || $raw === '' || $raw === 'N/A') return null;
            $v = (float)$raw;
            if($v == 0) return null;
            if($v < 0) return round($v, 2);
            // Konversi nW → dBm
            $db = 30 + (log10($v * 1e-7) * 10);
            return round(ceil($db * 100) / 100, 2);
        };

        $rx = $convertDbm($rxRaw);
        $tx = $convertDbm($txRaw);

        // Voltage: mV → V jika > 100
        $voltNorm = null;
        if($volt !== null && (float)$volt != 0){
            $vf = (float)$volt;
            $voltNorm = $vf > 100 ? round($vf / 1000, 3) : round($vf, 3);
        }

        // Bias: µA → mA jika > 1000
        $biasNorm = null;
        if($bias !== null && (float)$bias != 0){
            $bf = (float)$bias;
            $biasNorm = $bf > 1000 ? round($bf / 1000, 2) : round($bf, 2);
        }

        // Temperature: sudah °C
        $tempNorm = ($temp !== null && (float)$temp != 0) ? round((float)$temp, 1) : null;

        // Status RX (standar GPON ITU-T G.984)
        $rxStatus = 'unknown';
        if($rx !== null){
            if($rx >= -27)      $rxStatus = 'good';
            elseif($rx >= -30)  $rxStatus = 'warning';
            else                $rxStatus = 'critical';
        }

        return [
            'rx_power'  => $rx,
            'tx_power'  => $tx,
            'voltage'   => $voltNorm,
            'temp'      => $tempNorm,
            'bias'      => $biasNorm,
            'rx_status' => $rxStatus,
            'rx_raw'    => $rxRaw,
            'tx_raw'    => $txRaw,
            'vp_rx'     => $vpRx,
            'brand'     => $brand,
            'has_data'  => ($rx !== null || $tx !== null || $temp !== null),
        ];
    }

    // Helper: path optik untuk getParameterValues ke ONT
    public function getOpticalPaths(array $dev): array {
        return [
            'InternetGatewayDevice.WANDevice.1.X_ZTE-COM_WANPONInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_EponInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_CMCC_GponInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_EponInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_CU_WANEPONInterfaceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_GponInterafceConfig.',
            'InternetGatewayDevice.WANDevice.1.X_FH_GponInterfaceConfig.',
            'InternetGatewayDevice.X_ALU_OntOpticalParam.',
        ];
    }

    // ── SET WIFI — kirim SSID + password, task terpisah per band ──
    // Selalu kirim KEDUA parameter password (KeyPassphrase + PreSharedKey) agar
    // ONT yang hanya support WPA (PreSharedKey.1.PreSharedKey) MAUPUN
    // ONT yang hanya support WPA2/WPA+WPA2 (PreSharedKey.1.KeyPassphrase) sama-sama terkena update.
    // samePass=true: password 2.4G dikirim ke 5G juga.
    // Untuk 5GHz: coba index 5 (utama) dan 6 (alternatif beberapa ONT).
    public function setWifi(string $devId, array $dev, $s24, $k24, $s5g, $k5g, bool $samePass=false): bool {
        $k5use  = $samePass ? $k24 : $k5g;
        $base   = self::WLAN_PATH;
        $anyOk  = false;

        // ── Parameter password: SELALU kirim keduanya (WPA + WPA2) ──
        // PreSharedKey.1.KeyPassphrase = parameter WPA2/WPA+WPA2
        // PreSharedKey.1.PreSharedKey  = parameter WPA murni
        // Dengan mengirim keduanya, ONT yang punya salah satu atau keduanya akan berhasil.

        // ── 2.4 GHz (index 1) ──
        $p24=[];
        if($s24!==null&&$s24!=='') $p24[]=[$base.'.1.SSID',$s24,'xsd:string'];
        if($k24!==null&&$k24!==''){
            // Kirim KEDUANYA: KeyPassphrase (WPA2) dan PreSharedKey (WPA)
            $p24[]=[$base.'.1.PreSharedKey.1.KeyPassphrase',$k24,'xsd:string'];
            $p24[]=[$base.'.1.PreSharedKey.1.PreSharedKey', $k24,'xsd:string'];
            $p24[]=[$base.'.1.BeaconType','11i','xsd:string'];
        }
        if(!empty($p24)){
            $r=$this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$p24]);
            if($r) $anyOk=true; else $this->error='';
        }

        // ── 5 GHz — index 5 (utama) ──
        $p5g=[];
        if($s5g!==null&&$s5g!=='') $p5g[]=[$base.'.5.SSID',$s5g,'xsd:string'];
        if($k5use!==null&&$k5use!==''){
            // Kirim KEDUANYA: KeyPassphrase (WPA2) dan PreSharedKey (WPA)
            $p5g[]=[$base.'.5.PreSharedKey.1.KeyPassphrase',$k5use,'xsd:string'];
            $p5g[]=[$base.'.5.PreSharedKey.1.PreSharedKey', $k5use,'xsd:string'];
            $p5g[]=[$base.'.5.BeaconType','11i','xsd:string'];
        }
        if(!empty($p5g)){
            $r=$this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$p5g]);
            if($r) $anyOk=true; else $this->error='';
        }

        // ── 5 GHz — index 6 (alternatif beberapa ONT e.g. ZTE F670L, FiberHome HG6145) ──
        // Cek apakah SSID index 6 ada di cache; jika ada, kirim juga ke sana
        $ssid6 = $this->dig($dev, $base.'.6.SSID');
        if($ssid6 !== null && ($s5g!==null||$k5use!==null)){
            $p5g6=[];
            if($s5g!==null&&$s5g!=='') $p5g6[]=[$base.'.6.SSID',$s5g,'xsd:string'];
            if($k5use!==null&&$k5use!==''){
                $p5g6[]=[$base.'.6.PreSharedKey.1.KeyPassphrase',$k5use,'xsd:string'];
                $p5g6[]=[$base.'.6.PreSharedKey.1.PreSharedKey', $k5use,'xsd:string'];
                $p5g6[]=[$base.'.6.BeaconType','11i','xsd:string'];
            }
            if(!empty($p5g6)){
                $r6=$this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$p5g6]);
                if($r6) $anyOk=true; else $this->error='';
            }
        }

        if(!$anyOk&&empty($p24)&&empty($p5g)){
            $this->error='Tidak ada parameter WiFi yang diisi';return false;
        }
        if(!$anyOk) $this->error='Gagal mengirim parameter WiFi ke ONT';
        return $anyOk;
    }

    // ── ADD WAN — buat WAN baru via addObject + setParameterValues + VLAN ─
    // Alur:
    //   1. Cek apakah WANConnectionDevice.N sudah ada; jika belum, addObject dulu
    //   2. addObject ke WANPPPConnection / WANIPConnection (buat instance koneksi baru)
    //   3. setParameterValues ke instance baru (.1 karena per WANConnectionDevice)
    // connMode: route=WANPPPConnection/IP_Routed | bridge_ip=WANIPConnection/IP_Bridged | bridge_ppp=WANPPPConnection/PPPoE_Bridged
    public function addWan(string $devId, array $dev, array $cfg): bool {
        $brand    = $this->detectBrand($dev);
        $wanCd    = max(1,(int)($cfg['wan_cd']??$cfg['wan_slot']??1));   // WANConnectionDevice index
        $connMode = $cfg['conn_mode']??'route';
        $svcList  = $cfg['service_list']??'INTERNET';
        $vlanEn   = !empty($cfg['vlan_enable']) && !empty($cfg['vlan_id']);
        $vlanId   = (int)($cfg['vlan_id']??0);
        $vlanPri  = (int)($cfg['vlan_priority']??0);
        $wanName  = trim($cfg['wan_name']??'');
        $wanBase  = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';

        // ── Tentukan tipe koneksi dan connection type ──
        // route   = WANPPPConnection dengan IP_Routed (PPPoE ke router/ISP)
        // bridge_ppp = WANPPPConnection dengan PPPoE_Bridged (PPPoE pass-through)
        // bridge_ip  = WANIPConnection dengan IP_Bridged
        if($connMode==='route'){
            $connObjPath = "$wanBase.$wanCd.WANPPPConnection"; // PPPoE IP_Routed
            $connType    = 'IP_Routed';
        } elseif($connMode==='bridge_ppp'){
            $connObjPath = "$wanBase.$wanCd.WANPPPConnection"; // PPPoE_Bridged
            $connType    = 'PPPoE_Bridged';
        } else {
            $connObjPath = "$wanBase.$wanCd.WANIPConnection";  // IP_Bridged
            $connType    = 'IP_Bridged';
        }

        // ── Step 1: Cek apakah WANConnectionDevice.N slot sudah ada ──
        // Jika slot belum ada (tidak ada entry di cache GenieACS), addObject dulu
        $cdSlotExists = false;
        // Periksa apakah ada data WANPPPConnection atau WANIPConnection di slot ini
        $checkPpp = $this->dig($dev, "$wanBase.$wanCd.WANPPPConnection.1.Enable");
        $checkIp  = $this->dig($dev, "$wanBase.$wanCd.WANIPConnection.1.Enable");
        $checkName = $this->dig($dev, "$wanBase.$wanCd.WANPPPConnection.1.Name")
                   ??$this->dig($dev, "$wanBase.$wanCd.WANIPConnection.1.Name");
        $cdSlotExists = ($checkPpp !== null || $checkIp !== null || $checkName !== null);

        if(!$cdSlotExists){
            // Slot WANConnectionDevice.N belum ada — buat dulu
            $this->sendTask($devId, [
                'name'       => 'addObject',
                'objectName' => "$wanBase",
            ]);
            $this->error = '';
            // Tunggu sebentar agar task dikirim berurutan (GenieACS queue)
            usleep(200000);
        }

        // ── Step 2: addObject ke connection type (buat instance koneksi baru) ──
        $this->sendTask($devId, [
            'name'       => 'addObject',
            'objectName' => $connObjPath,
        ]);
        // addObject bisa error jika instance sudah ada, lanjutkan saja
        $this->error = '';

        // Instance baru selalu .1 dalam satu WANConnectionDevice
        $base = "$connObjPath.1";

        // ── Step 3: setParameterValues parameter inti ──
        $svcParam = $brand['svclist']??'ServiceList';
        $core = [
            [$base.'.Enable',         'true',    'xsd:boolean'],
            [$base.'.ConnectionType', $connType, 'xsd:string'],
            [$base.'.'.$svcParam,     $svcList,  'xsd:string'],
        ];
        if($wanName) $core[] = [$base.'.Name', $wanName, 'xsd:string'];

        // NAT: aktifkan HANYA untuk route (IP_Routed), NONAKTIFKAN untuk semua bridge
        if($connMode==='route'){
            $core[] = [$base.'.NATEnabled', 'true',  'xsd:boolean'];
        } else {
            // bridge_ppp dan bridge_ip: NAT harus false
            $core[] = [$base.'.NATEnabled', 'false', 'xsd:boolean'];
        }

        // ── Step 4: VLAN — WAJIB jika diminta (dikirim bersama core) ──
        if($vlanEn && $vlanId > 0){
            // Generic VLAN (hampir semua ONT support)
            $core[] = [$base.'.VLANEnable', 'true',          'xsd:boolean'];
            $core[] = [$base.'.VLANID',     (string)$vlanId, 'xsd:unsignedInt'];
            if($vlanPri > 0)
                $core[] = [$base.'.VLANPriority', (string)$vlanPri, 'xsd:unsignedInt'];
            // Brand-specific VLAN
            $vid = $brand['vlan_id']??null;
            $ven = $brand['vlan_en']??null;
            if($vid && $vid !== 'VLANID') $core[] = [$base.'.'.$vid, (string)$vlanId, 'xsd:unsignedInt'];
            if($ven){
                $venVal  = in_array($ven,['X_CMCC_VLANMode','X_CT-COM_VLANMode']) ? '2' : 'true';
                $venType = in_array($ven,['X_CMCC_VLANMode','X_CT-COM_VLANMode']) ? 'xsd:int' : 'xsd:boolean';
                $core[]  = [$base.'.'.$ven, $venVal, $venType];
            }
        }

        $ok = $this->sendTask($devId, [
            'name'            => 'setParameterValues',
            'parameterValues' => $core,
        ]);
        if(!$ok) return false;

        // ── Addressing (route mode — PPPoE IP_Routed) ──
        if($connMode==='route'){
            $addrParams = [];
            $addrType   = $cfg['addr_type']??'dhcp';
            if($addrType==='static'){
                $addrParams[] = [$base.'.AddressingType','Static','xsd:string'];
                if(!empty($cfg['static_ip']))   $addrParams[] = [$base.'.ExternalIPAddress',$cfg['static_ip'],'xsd:string'];
                if(!empty($cfg['static_mask'])) $addrParams[] = [$base.'.SubnetMask',$cfg['static_mask'],'xsd:string'];
                if(!empty($cfg['static_gw']))   $addrParams[] = [$base.'.DefaultGateway',$cfg['static_gw'],'xsd:string'];
                if(!empty($cfg['dns1'])){
                    $dns = $cfg['dns1'].(!empty($cfg['dns2'])?','.$cfg['dns2']:'');
                    $addrParams[] = [$base.'.DNSServers',$dns,'xsd:string'];
                }
            } else {
                $addrParams[] = [$base.'.AddressingType','DHCP','xsd:string'];
            }
            // PPPoE credentials untuk route mode
            if(!empty($cfg['pppoe_user'])) $addrParams[] = [$base.'.Username',$cfg['pppoe_user'],'xsd:string'];
            if(!empty($cfg['pppoe_pass'])) $addrParams[] = [$base.'.Password',$cfg['pppoe_pass'],'xsd:string'];
            if(!empty($addrParams)){
                $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$addrParams]);
                $this->error='';
            }
        }

        // PPPoE credentials (bridge_ppp mode)
        if($connMode==='bridge_ppp' && (!empty($cfg['pppoe_user'])||!empty($cfg['pppoe_pass']))){
            $pppParams=[];
            if(!empty($cfg['pppoe_user'])) $pppParams[]=[$base.'.Username',$cfg['pppoe_user'],'xsd:string'];
            if(!empty($cfg['pppoe_pass'])) $pppParams[]=[$base.'.Password',$cfg['pppoe_pass'],'xsd:string'];
            $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$pppParams]);
            $this->error='';
        }

        return true;
    }

    // ── SET WAN — edit parameter WAN yang SUDAH ADA ───────────────
    // Tidak melakukan addObject, langsung setParameterValues ke slot yang ada
    // connMode: route=IP_Routed | bridge_ip=IP_Bridged | bridge_ppp=PPPoE_Bridged
    public function setWan(string $devId, array $dev, array $cfg): bool {
        $brand    = $this->detectBrand($dev);
        $slot     = max(1,(int)($cfg['wan_slot']??1));
        $connMode = $cfg['conn_mode']??'route';
        $svcList  = $cfg['service_list']??'INTERNET';
        $vlanEn   = !empty($cfg['vlan_enable']) && !empty($cfg['vlan_id']);
        $vlanId   = (int)($cfg['vlan_id']??0);
        $vlanPri  = (int)($cfg['vlan_priority']??0);
        $wanName  = trim($cfg['wan_name']??'');
        $wanBase  = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';

        // ── Tentukan base path dan connection type ──
        if($connMode==='bridge_ppp'){
            $base     = "$wanBase.$slot.WANPPPConnection.1";
            $connType = 'PPPoE_Bridged';
        } elseif($connMode==='bridge_ip'){
            $base     = "$wanBase.$slot.WANIPConnection.1";
            $connType = 'IP_Bridged';
        } else {
            $base     = "$wanBase.$slot.WANIPConnection.1";
            $connType = 'IP_Routed';
        }

        // ── Parameter INTI ──
        $svcParam = $brand['svclist']??'ServiceList';
        $core = [
            [$base.'.Enable',         'true',     'xsd:boolean'],
            [$base.'.ConnectionType', $connType,  'xsd:string'],
            [$base.'.'.$svcParam,     $svcList,   'xsd:string'],
        ];
        if($wanName) $core[] = [$base.'.Name', $wanName, 'xsd:string'];
        if($connMode==='route'){
            $core[] = [$base.'.NATEnabled', 'true',  'xsd:boolean'];
        } elseif($connMode==='bridge_ip'){
            $core[] = [$base.'.NATEnabled', 'false', 'xsd:boolean'];
        }

        // VLAN — kirim bersama core jika diminta
        if($vlanEn && $vlanId > 0){
            $core[] = [$base.'.VLANEnable', 'true',          'xsd:boolean'];
            $core[] = [$base.'.VLANID',     (string)$vlanId, 'xsd:unsignedInt'];
            if($vlanPri > 0)
                $core[] = [$base.'.VLANPriority', (string)$vlanPri, 'xsd:unsignedInt'];
            $vid = $brand['vlan_id']??null;
            $ven = $brand['vlan_en']??null;
            if($vid && $vid !== 'VLANID') $core[] = [$base.'.'.$vid, (string)$vlanId, 'xsd:unsignedInt'];
            if($ven){
                $venVal  = in_array($ven,['X_CMCC_VLANMode','X_CT-COM_VLANMode']) ? '2' : 'true';
                $venType = in_array($ven,['X_CMCC_VLANMode','X_CT-COM_VLANMode']) ? 'xsd:int' : 'xsd:boolean';
                $core[]  = [$base.'.'.$ven, $venVal, $venType];
            }
        }

        $ok = $this->sendTask($devId, [
            'name'=>'setParameterValues',
            'parameterValues'=>$core
        ]);
        if(!$ok) return false;

        // ── Parameter addressing (route mode) ──
        if($connMode==='route'){
            $addrParams = [];
            $addrType = $cfg['addr_type']??'dhcp';
            if($addrType==='static'){
                $addrParams[] = [$base.'.AddressingType','Static','xsd:string'];
                if(!empty($cfg['static_ip']))   $addrParams[]=[$base.'.ExternalIPAddress',$cfg['static_ip'],'xsd:string'];
                if(!empty($cfg['static_mask'])) $addrParams[]=[$base.'.SubnetMask',$cfg['static_mask'],'xsd:string'];
                if(!empty($cfg['static_gw']))   $addrParams[]=[$base.'.DefaultGateway',$cfg['static_gw'],'xsd:string'];
                if(!empty($cfg['dns1'])){
                    $dns=$cfg['dns1'].(!empty($cfg['dns2'])?','.$cfg['dns2']:'');
                    $addrParams[]=[$base.'.DNSServers',$dns,'xsd:string'];
                }
            } else {
                $addrParams[]=[$base.'.AddressingType','DHCP','xsd:string'];
            }
            if(!empty($cfg['pppoe_user'])) $addrParams[]=[$base.'.Username',$cfg['pppoe_user'],'xsd:string'];
            if(!empty($cfg['pppoe_pass'])) $addrParams[]=[$base.'.Password',$cfg['pppoe_pass'],'xsd:string'];
            if(!empty($addrParams)){
                $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$addrParams]);
                $this->error='';
            }
        }

        // PPPoE bridge credentials
        if($connMode==='bridge_ppp' && (!empty($cfg['pppoe_user'])||!empty($cfg['pppoe_pass']))){
            $pppParams=[];
            if(!empty($cfg['pppoe_user'])) $pppParams[]=[$base.'.Username',$cfg['pppoe_user'],'xsd:string'];
            if(!empty($cfg['pppoe_pass'])) $pppParams[]=[$base.'.Password',$cfg['pppoe_pass'],'xsd:string'];
            $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$pppParams]);
            $this->error='';
        }

        return true;
    }

    // ── BINDING WAN → LAN/WLAN ────────────────────────────────────
    // Kirim dalam task terpisah: 1 task untuk brand utama, tidak kirim semua brand sekaligus
    // Ini menghindari error 9003 dari parameter yang tidak dikenal ONT
    public function bindWan(string $devId, array $dev, array $cfg): bool {
        $brand    = $this->detectBrand($dev);
        $bindStr  = trim($cfg['bind_str']??'');
        if(!$bindStr){$this->error='Pilih minimal satu interface untuk di-bind';return false;}

        $slot     = max(1,(int)($cfg['wan_slot']??1));
        $wanType  = $cfg['wan_type']??'ip';
        $wanBase  = 'InternetGatewayDevice.WANDevice.1.WANConnectionDevice';
        $connType = ($wanType==='ppp')?'WANPPPConnection':'WANIPConnection';
        $base     = "$wanBase.$slot.$connType.1";
        $bindParam= $brand['lan_bind']??'X_FH_LanInterface';

        // ── Huawei: X_HW_LANBIND — set per port boolean ──
        if($bindParam==='X_HW_LANBIND'){
            $params=[];
            // Reset semua
            for($i=1;$i<=4;$i++)  $params[]=[$base.".X_HW_LANBIND.Lan{$i}Enable",'false','xsd:boolean'];
            for($i=1;$i<=8;$i++)  $params[]=[$base.".X_HW_LANBIND.SSID{$i}Enable",'false','xsd:boolean'];
            // Set yang dipilih ke true
            foreach(array_map('trim',explode(',',$bindStr)) as $p){
                if(preg_match('/LANEthernetInterfaceConfig\.(\d+)$/',$p,$m))
                    $params[]=[$base.".X_HW_LANBIND.Lan{$m[1]}Enable",'true','xsd:boolean'];
                if(preg_match('/WLANConfiguration\.(\d+)$/',$p,$m))
                    $params[]=[$base.".X_HW_LANBIND.SSID{$m[1]}Enable",'true','xsd:boolean'];
            }
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── Non-Huawei: kirim binding string ke parameter brand-specific ──
        // Kirim HANYA parameter brand yang terdeteksi, bukan semua brand
        $ok = $this->sendTask($devId,[
            'name'=>'setParameterValues',
            'parameterValues'=>[[$base.'.'.$bindParam, $bindStr, 'xsd:string']]
        ]);

        // Jika brand-specific gagal, coba generic fallback satu per satu
        if(!$ok){
            $this->error='';
            // Fallback: coba parameter umum lainnya
            $fallbacks=['X_FH_LanInterface','X_ZTE-COM_LanInterface','X_CMCC_LanInterface',
                        'X_CT-COM_LanInterface','X_CU_LanInterface'];
            foreach($fallbacks as $fb){
                if($fb===$bindParam) continue;
                $this->error='';
                $r=$this->sendTask($devId,['name'=>'setParameterValues',
                    'parameterValues'=>[[$base.'.'.$fb,$bindStr,'xsd:string']]]);
                if($r){$ok=true;break;}
            }
        }

        // ── ZTE F670L: binding via X_ZTE-COM_PortBinding table ──
        $wanInterface=trim($cfg['wan_interface']??'');
        if($wanInterface){
            $this->error='';
            $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>[
                ['InternetGatewayDevice.X_ZTE-COM_PortBinding.1.WANInterface',$wanInterface,'xsd:string'],
                ['InternetGatewayDevice.X_ZTE-COM_PortBinding.1.LANInterface',$bindStr,'xsd:string'],
            ]]);
            $this->error=''; // Jangan error jika ZTE-COM_PortBinding tidak ada
        }

        if(!$ok) $this->error="Binding gagal: parameter tidak didukung ONT ini. Coba ubah WAN Slot atau Tipe WAN.";
        return $ok;
    }

    // ── BLOKIR CLIENT — MAC address filter ────────────────────────
    // FiberHome: WLANConfiguration.{i}.AccessControl (MACAddressControlEnabled + MACAddressControlList)
    // ZTE      : WLANConfiguration.{i}.MACAddressControlEnabled + AllowedMACAddresses
    // Huawei   : X_HW_MACAddressFilterMode + X_HW_MACAddressFilter
    public function blockClient(string $devId, array $dev, string $mac): bool {
        // Fix: detectBrand() mengembalikan array config, bukan string nama
        // Gunakan detectBrandName() untuk mendapat nama brand sebagai string
        $brand = $this->detectBrandName($dev);
        $mac   = strtoupper(trim($mac));

        // ── FiberHome: X_FH_FireWall.MACFilter ──
        // Cara BENAR:
        //   BLOKIR : addObject 'InternetGatewayDevice.X_FH_FireWall.MACFilter'
        //            lalu setParameterValues .Enable .MAC .TimeStart .TimeStop
        //   UNBLOKIR: deleteObject 'InternetGatewayDevice.X_FH_FireWall.MACFilter.N.'
        if($brand==='FiberHome'){
            // 1. Cek apakah MAC sudah ada di filter (enable saja, jangan duplikat)
            $existSlots=$this->getMACFilter($dev);
            foreach($existSlots as $idx=>$slot){
                if($slot['mac']===$mac){
                    // Sudah ada — enable saja
                    return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>[
                        ["InternetGatewayDevice.X_FH_FireWall.MACFilter.$idx.Enable",'true','xsd:boolean'],
                    ]]);
                }
            }

            // 2. Prediksi index baru: MACFilterNumberOfEntries + 1
            $count=$this->getMACFilterCount($dev);
            $newIdx=$count+1;

            // 3. addObject → buat entry baru (tanpa trailing dot untuk FiberHome)
            $this->sendTask($devId,[
                'name'      =>'addObject',
                'objectName'=>'InternetGatewayDevice.X_FH_FireWall.MACFilter',
            ]);
            $this->error='';

            // 4. Set parameter pada entry baru (index diprediksi dari count+1)
            //    GenieACS async: task dikirim berurutan, addObject dulu baru set
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>[
                ["InternetGatewayDevice.X_FH_FireWall.MACFilter.$newIdx.Enable",'true','xsd:boolean'],
                ["InternetGatewayDevice.X_FH_FireWall.MACFilter.$newIdx.MAC",$mac,'xsd:string'],
                ["InternetGatewayDevice.X_FH_FireWall.MACFilter.$newIdx.TimeStart",'00:00','xsd:string'],
                ["InternetGatewayDevice.X_FH_FireWall.MACFilter.$newIdx.TimeStop",'23:59','xsd:string'],
            ]]);
        }

        // ── ZTE ──
        if($brand==='ZTE'){
            $params=[];
            foreach([1,5] as $idx){
                $base=self::WLAN_PATH.".$idx";
                $params[]=[$base.'.MACAddressControlEnabled','true','xsd:boolean'];
                $params[]=[$base.'.AllowedMACAddresses',$mac,'xsd:string'];
            }
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── Huawei ──
        if($brand==='Huawei'){
            $params=[
                [self::WLAN_PATH.'.1.X_HW_MACAddressFilterMode','Deny','xsd:string'],
                [self::WLAN_PATH.'.1.X_HW_MACAddressFilter',$mac,'xsd:string'],
                [self::WLAN_PATH.'.5.X_HW_MACAddressFilterMode','Deny','xsd:string'],
                [self::WLAN_PATH.'.5.X_HW_MACAddressFilter',$mac,'xsd:string'],
            ];
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── CData ──
        if($brand==='CData'){
            $params=[];
            foreach([1,5] as $idx){
                $base=self::WLAN_PATH.".$idx";
                $params[]=[$base.'.MACAddressControlEnabled','true','xsd:boolean'];
                $params[]=[$base.'.MACAddressControlList',$mac,'xsd:string'];
            }
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── Generic / fallback ──
        $params=[];
        foreach([1,2,5,6] as $idx){
            $base=self::WLAN_PATH.".$idx";
            $params[]=[$base.'.MACAddressControlEnabled','true','xsd:boolean'];
            $params[]=[$base.'.MACAddressControlList',$mac,'xsd:string'];
        }
        return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
    }

    // Unblock: nonaktifkan MAC filter di semua band
    public function unblockClient(string $devId, array $dev, string $mac): bool {
        $brand = $this->detectBrandName($dev);
        $mac   = strtoupper(trim($mac));

        // ── FiberHome: deleteObject pada slot yang cocok ──
        if($brand==='FiberHome'){
            $slots=$this->getMACFilter($dev);
            $found=false;
            foreach($slots as $idx=>$slot){
                if($slot['mac']===$mac){
                    // deleteObject hapus entry (tanpa trailing dot untuk FiberHome)
                    $this->sendTask($devId,[
                        'name'      =>'deleteObject',
                        'objectName'=>"InternetGatewayDevice.X_FH_FireWall.MACFilter.$idx",
                    ]);
                    $found=true;
                    $this->error='';
                }
            }
            if(!$found){
                // Coba disable saja jika MAC tidak ketemu (data mungkin stale)
                $this->error='';
                $this->refreshMACFilter($devId);
            }
            return true;
        }

        // ── ZTE ──
        if($brand==='ZTE'){
            $params=[];
            foreach([1,5] as $idx){
                $params[]=[self::WLAN_PATH.".$idx.MACAddressControlEnabled",'false','xsd:boolean'];
                $params[]=[self::WLAN_PATH.".$idx.AllowedMACAddresses",'','xsd:string'];
            }
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── Huawei ──
        if($brand==='Huawei'){
            $params=[
                [self::WLAN_PATH.'.1.X_HW_MACAddressFilterMode','Off','xsd:string'],
                [self::WLAN_PATH.'.5.X_HW_MACAddressFilterMode','Off','xsd:string'],
            ];
            return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
        }

        // ── Generic / fallback ──
        $params=[];
        foreach([1,2,5,6] as $idx){
            $base=self::WLAN_PATH.".$idx";
            $params[]=[$base.'.MACAddressControlEnabled','false','xsd:boolean'];
            $params[]=[$base.'.MACAddressControlList','','xsd:string'];
        }
        return $this->sendTask($devId,['name'=>'setParameterValues','parameterValues'=>$params]);
    }

    public function refresh(string $devId, array $paths=[]): bool {
        $p=$paths?:[
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.',
            'InternetGatewayDevice.WANDevice.1.',
            'InternetGatewayDevice.DeviceInfo.',
        ];
        return $this->sendTask($devId,['name'=>'getParameterValues','parameterNames'=>$p]);
    }
    public function reboot(string $devId): bool {
        return $this->sendTask($devId,['name'=>'reboot']);
    }
    public function addTag(string $id,string $t): bool {
        return $this->req('POST','/devices/'.rawurlencode($id).'/tags/'.rawurlencode($t))!==null;
    }
    public function removeTag(string $id,string $t): bool {
        return $this->req('DELETE','/devices/'.rawurlencode($id).'/tags/'.rawurlencode($t))!==null;
    }
    public function task(string $devId, array $task): bool {
        return $this->sendTask($devId,$task);
    }
    public static function fromDB($id = null) {
        try{
            if($id) {
                $stmt = db()->prepare("SELECT * FROM genie_config WHERE id=?");
                $stmt->execute([$id]);
                $c = $stmt->fetch();
            } else {
                $c=db()->query("SELECT * FROM genie_config WHERE is_active=1 LIMIT 1")->fetch();
            }
            return $c?new self($c['url'],$c['username'],$c['password']):null;
        }catch(Exception $e){return null;}
    }
}
