<?php
// ================================================================
// MIKROTIK API — ROS 6 & 7 compatible
// ================================================================
if (!defined('IN_APP')) die('Direct access denied');

class MikrotikAPI {
    private $sock = null;
    public $error = '';
    private $host, $user, $pass;
    private $port;

    public function __construct(string $host, int $port, string $user, string $pass) {
        $this->host=$host; $this->port=$port; $this->user=$user; $this->pass=$pass;
    }
    public static function fromRouter(array $r): self {
        return new self($r['host'],(int)$r['port'],$r['username'],$r['password']);
    }

    public function connect(): bool {
        $this->sock = @fsockopen($this->host, $this->port, $en, $es, 10);
        if (!$this->sock) { $this->error="Gagal konek {$this->host}:{$this->port} — $es"; return false; }
        stream_set_timeout($this->sock, 120); // 120 detik — untuk router besar (x86 18k+ user)
        return $this->login();
    }
    private function login(): bool {
        $r=$this->talk(['/login','=name='.$this->user,'=password='.$this->pass]);
        if(in_array('!done',$r)) return true;
        foreach($r as $l) {
            if(preg_match('/=ret=([a-f0-9]+)/',$l,$m)) {
                $r2=$this->talk(['/login','=name='.$this->user,'=response=00'.md5("\x00".$this->pass.pack('H*',$m[1]))]);
                if(in_array('!done',$r2)) return true;
            }
        }
        $this->error='Login Mikrotik gagal'; return false;
    }
    public function talk(array $cmds): array {
        if(!$this->sock) return [];
        foreach($cmds as $w) $this->writeWord($w);
        $this->writeWord(''); // end of sentence
        return $this->readAll();
    }
    private function writeWord(string $w): void {
        $l=strlen($w);
        if($l<0x80)       fwrite($this->sock,chr($l));
        elseif($l<0x4000) fwrite($this->sock,chr(($l>>8)|0x80).chr($l&0xFF));
        elseif($l<0x200000) fwrite($this->sock,chr(($l>>16)|0xC0).chr(($l>>8)&0xFF).chr($l&0xFF));
        else fwrite($this->sock,chr(($l>>24)|0xE0).chr(($l>>16)&0xFF).chr(($l>>8)&0xFF).chr($l&0xFF));
        if($l>0) fwrite($this->sock,$w);
    }
    // Read response — robust untuk router x86 dengan banyak user
    private function readAll(): array {
        $out=[];
        $done=false;
        $maxLoops=500000; // safety limit — dinaikkan untuk router besar
        $loops=0;
        $emptyRetries=0;
        $maxEmptyRetries=200; // max retry saat TCP buffer kosong sementara
        while($loops++<$maxLoops){
            // Baca 1 byte dari socket
            $byte=@fread($this->sock,1);
            if($byte===false||$byte===''){
                if($done) break; // normal EOF setelah !done
                // Socket kosong sementara — cek apakah timeout atau TCP buffer kosong
                $meta=@stream_get_meta_data($this->sock);
                if($meta===false) break; // socket mati
                if($meta['timed_out']??false) break; // benar-benar timeout
                // TCP buffer mungkin kosong sementara (router masih kirim data)
                // Retry beberapa kali sebelum menyerah
                if(++$emptyRetries>$maxEmptyRetries) break;
                usleep(5000); // tunggu 5ms lalu coba lagi
                continue;
            }
            $emptyRetries=0; // reset retry counter saat dapat data
            $b=ord($byte);
            // Decode length (variable-length encoding)
            if($b&0x80){
                if(($b&0xC0)===0x80){
                    $len=(($b&0x3F)<<8)+ord(@fread($this->sock,1));
                } elseif(($b&0xE0)===0xC0){
                    $len=(($b&0x1F)<<8)+ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                } elseif(($b&0xF0)===0xE0){
                    $len=(($b&0x0F)<<8)+ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                } else {
                    // 4-byte length
                    $len=ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                    $len=($len<<8)+ord(@fread($this->sock,1));
                }
            } else {
                $len=$b;
            }
            if($len===0){
                // Empty word = end of sentence, skip but don't stop
                // Stop only when we've received !done AND no unread bytes
                $st=@stream_get_meta_data($this->sock);
                if($done&&($st===false||($st['unread_bytes']??0)===0)) break;
                continue;
            }
            // Read $len bytes
            $word='';
            $rem=$len;
            while($rem>0){
                $chunk=@fread($this->sock,$rem);
                if($chunk===false||$chunk==='') break;
                $word.=$chunk;
                $rem-=strlen($chunk);
            }
            $out[]=$word;
            if($word==='!done') $done=true;
            // On !trap or !fatal, drain remaining and stop
            if($word==='!trap'||$word==='!fatal'){
                // read remaining words in this sentence
                while(true){
                    $b2=@fread($this->sock,1);
                    if($b2===false||$b2==='') break;
                    $l2=ord($b2);
                    if($l2===0) break;
                    $w2='';$r2=$l2;
                    while($r2>0){$c2=@fread($this->sock,$r2);if(!$c2)break;$w2.=$c2;$r2-=strlen($c2);}
                    $out[]=$w2;
                }
                break;
            }
        }
        return $out;
    }
    public function parse(array $resp): array {
        $out=[];$cur=[];
        foreach($resp as $l){
            if($l==='!done'){if($cur)$out[]=$cur;break;}
            if($l==='!re'){if($cur)$out[]=$cur;$cur=[];continue;}
            if(str_starts_with($l,'=')){list($k,$v)=array_pad(explode('=',substr($l,1),2),2,'');$cur[$k]=$v;}
        }
        if($cur&&!in_array('!done',$resp))$out[]=$cur;
        return $out;
    }
    public function parseOne(array $resp): array {
        $out=[];
        foreach($resp as $l){
            if(in_array($l,['!done','!re','!trap','!fatal']))continue;
            if(str_starts_with($l,'=')){list($k,$v)=array_pad(explode('=',substr($l,1),2),2,'');$out[$k]=$v;}
        }
        return $out;
    }
    public function close(): void { if($this->sock){fclose($this->sock);$this->sock=null;} }

    // DST NAT
    // $iface = nama interface WAN publik (misal: ether1, pppoe-out1).
    // Jika diisi, rule NAT menggunakan "in-interface" sehingga tidak konflik
    // dengan penggunaan dst-address di program utama.
    // Jika kosong, fallback ke "dst-address" untuk kompatibilitas mundur.
    public function addDstNat(string $ip,int $pub,string $lip,int $lp,string $proto,string $cmt='',string $name='',string $iface='') {
        $c=$name?"[$name] $cmt":$cmt;
        $matchParam = $iface ? "=in-interface=$iface" : "=dst-address=$ip";
        $add=function($p) use ($proto, $matchParam, $pub, $lip, $lp, $c){ return $this->talk(['/ip/firewall/nat/add','=chain=dstnat','=action=dst-nat',
            "=protocol=$p",$matchParam,"=dst-port=$pub","=to-addresses=$lip","=to-ports=$lp","=comment=$c"]); };
        $r=$add($proto==='both'?'tcp':$proto);
        if($proto==='both') $add('udp');
        foreach($r as $l) if(preg_match('/=ret=(.+)/',$l,$m)) return $m[1];
        return in_array('!done',$r)?'ok':null;
    }
    public function removeDstNat(string $id): bool { return in_array('!done',$this->talk(['/ip/firewall/nat/remove',"=.id=$id"])); }

    // Baca semua rule dstnat dari Mikrotik secara langsung (live)
    public function listDstNat(): array {
        $raw=$this->parse($this->talk(['/ip/firewall/nat/print','=.proplist=.id,chain,action,protocol,in-interface,dst-address,dst-port,to-addresses,to-ports,comment,disabled']));
        $out=[];
        foreach($raw as $r){
            if(($r['chain']??'')!=='dstnat') continue;
            if(($r['action']??'')!=='dst-nat') continue;
            $out[]=[
                'mikrotik_id' => $r['.id']??'',
                'protocol'    => $r['protocol']??'',
                'in_interface'=> $r['in-interface']??'',
                'dst_address' => $r['dst-address']??'',
                'dst_port'    => $r['dst-port']??'',
                'to_addresses'=> $r['to-addresses']??'',
                'to_ports'    => $r['to-ports']??'',
                'comment'     => $r['comment']??'',
                'disabled'    => ($r['disabled']??'false')==='true'||($r['disabled']??'false')==='yes',
            ];
        }
        return $out;
    }

    // PPP
    public function addPppSecret(string $n,string $p,string $svc='l2tp',string $loc='',string $rem='',string $prof='default-encryption',string $cmt='') {
        $c=['/ppp/secret/add',"=name=$n","=password=$p","=service=$svc","=profile=$prof"];
        if($loc)$c[]="=local-address=$loc"; if($rem)$c[]="=remote-address=$rem"; if($cmt)$c[]="=comment=$cmt";
        $r=$this->talk($c);
        foreach($r as $l) if(preg_match('/=ret=(.+)/',$l,$m)) return $m[1];
        return in_array('!done',$r)?'ok':null;
    }
    public function removePppSecret(string $id): bool { return in_array('!done',$this->talk(['/ppp/secret/remove',"=.id=$id"])); }
    public function updatePppSecret(string $id,$pass=null,$cmt=null,$dis=null): bool {
        $c=['/ppp/secret/set',"=.id=$id"];
        if($pass!==null)$c[]="=password=$pass"; if($cmt!==null)$c[]="=comment=$cmt";
        if($dis!==null)$c[]='=disabled='.($dis?'yes':'no');
        return in_array('!done',$this->talk($c));
    }
    public function listActivePpp(): array { return $this->parse($this->talk(['/ppp/active/print'])); }
    public function listPppSecrets(): array { return $this->parse($this->talk(['/ppp/secret/print'])); }
    public function disconnectPpp(string $id): bool { return in_array('!done',$this->talk(['/ppp/active/remove',"=.id=$id"])); }

    // L2TP Server (ROS 6 & 7)
    public function enableL2tp(string $ipsec=''): bool {
        $c=['/interface/l2tp-server/server/set','=enabled=yes','=use-ipsec=yes','=authentication=mschap2,mschap1','=default-profile=default-encryption'];
        if($ipsec)$c[]="=ipsec-secret=$ipsec";
        if(in_array('!done',$this->talk($c))) return true;
        $c2=['/interface/l2tp-server/set','=enabled=yes','=use-ipsec=yes','=authentication=mschap2'];
        if($ipsec)$c2[]="=ipsec-secret=$ipsec";
        return in_array('!done',$this->talk($c2));
    }
    public function disableL2tp(): bool {
        $r=$this->talk(['/interface/l2tp-server/server/set','=enabled=no']);
        return in_array('!done',$r)?:in_array('!done',$this->talk(['/interface/l2tp-server/set','=enabled=no']));
    }
    public function getL2tpStatus(): array {
        // ROS 7: /interface/l2tp-server/server/print
        // ROS 6: /interface/l2tp-server/print
        $i=$this->parseOne($this->talk(['/interface/l2tp-server/server/print']));
        if(empty($i)||!isset($i['disabled']))
            $i=$this->parseOne($this->talk(['/interface/l2tp-server/print']));
        // ROS 7 uses 'disabled'='false' → enabled, 'disabled'='true' → disabled
        if(!isset($i['enabled'])){
            if(isset($i['disabled'])){
                // 'false' = NOT disabled = ON; 'true' = disabled = OFF
                $i['enabled']=($i['disabled']==='false'||$i['disabled']==='no')?'yes':'no';
            } else {
                $i['enabled']='no';
            }
        }
        return $i;
    }

    // Hotspot
    public function getHotspotActive(): array {
        $r=$this->parse($this->talk(['/ip/hotspot/active/print','=.proplist=.id,user,address,mac-address,uptime,bytes-in,bytes-out']));
        if(empty($r)) $r=$this->parse($this->talk(['/ip/hotspot/active/print']));
        return $r;
    }
    public function getHotspotUsers(): array  { return $this->parse($this->talk(['/ip/hotspot/user/print','=.proplist=name,profile,comment,uptime,bytes-in,bytes-out,disabled,mac-address'])); }
    public function getHotspotProfiles(): array { return $this->parse($this->talk(['/ip/hotspot/user/profile/print'])); }
    public function disconnectHotspot(string $id): bool { return in_array('!done',$this->talk(['/ip/hotspot/active/remove',"=.id=$id"])); }

    // PPPoE / PPP
    public function getPppActive(): array {
        // Proplist untuk performa lebih baik di router besar (x86)
        $r=$this->parse($this->talk(['/ppp/active/print','=.proplist=.id,name,address,uptime,service,caller-id']));
        if(empty($r)) $r=$this->parse($this->talk(['/ppp/active/print']));
        return $r;
    }
    public function getPppActiveCount(): int {
        // count-only lebih efisien untuk router dengan ribuan PPPoE users
        $raw=$this->talk(['/ppp/active/print','=count-only=']);
        foreach($raw as $w){
            if(preg_match('/=ret=(\d+)/',$w,$m)) return (int)$m[1];
            if(is_numeric(trim($w))) return (int)trim($w);
        }
        return count($this->getPppActive()); // fallback
    }
    public function getPppSecrets(): array { return $this->parse($this->talk(['/ppp/secret/print'])); }

    // System
    public function getResourceUsage(): array { return $this->parseOne($this->talk(['/system/resource/print'])); }
    public function getIdentity(): string     { $r=$this->parseOne($this->talk(['/system/identity/print'])); return $r['name']??'-'; }
}
