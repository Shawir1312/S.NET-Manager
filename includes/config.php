<?php
// ── PHP 7.4 Polyfills (str_contains, str_starts_with, str_ends_with tersedia di PHP 8.0+) ──
if (!function_exists('str_contains')) {
    function str_contains(string $h, string $n): bool { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $h, string $n): bool { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $h, string $n): bool { return $n === '' || substr($h, -strlen($n)) === $n; }
}

// ================================================================
// S.NET Manager v2.1 — Core Config & Security
// ================================================================
define('APP_NAME',    'S.NET Manager');
define('APP_VERSION', '3.0.0');
define('APP_BASE',    dirname(__DIR__));
define('ADMIN_URL',   '/admin');
define('PORTAL_URL',  '/portal');

// ── Load .env ───────────────────────────────────────────────────
// (function(){
//     $f = APP_BASE.'/.env';
//     if (!file_exists($f)) return;
//     foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
//         $line = trim($line);
//         if ($line === '' || $line[0] === '#') continue;
//         if (!str_contains($line, '=')) continue;
//         [$k, $v] = array_map('trim', explode('=', $line, 2));
//         if ($k !== '' && !array_key_exists($k, $_ENV)) {
//             putenv("$k=$v");
//             $_ENV[$k] = $v;
//         }
//     }
// })();

define('DB_HOST',    getenv('DB_HOST') ?: 'localhost');
define('DB_NAME',    getenv('DB_NAME') ?: 'snet1');
define('DB_USER',    getenv('DB_USER') ?: 'snet1');
define('DB_PASS',    getenv('DB_PASS') ?: 'snet1');
define('DB_CHARSET', 'utf8mb4');
define('APP_SECRET', getenv('APP_SECRET') ?: 'change_me_in_env_file');

date_default_timezone_set('Asia/Jayapura');
error_reporting(E_ALL);
ini_set('display_errors', 0);
if (session_status() === PHP_SESSION_NONE) session_start();

// ── PDO ──────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo;
    if (!$pdo) {
        try {
            $pdo = new PDO(
                "mysql:unix_socket=/tmp/mysql.sock;dbname=".DB_NAME.";charset=".DB_CHARSET,
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
                 PDO::ATTR_EMULATE_PREPARES=>false,
                 PDO::ATTR_TIMEOUT=>5]
            );
        } catch(PDOException $e) {
            die('<div style="font-family:monospace;padding:20px;background:#FEE2E2;color:#B91C1C;border-radius:8px">
                <strong>Database Error:</strong> '.$e->getMessage().'<br><br>
                Edit DB_USER dan DB_PASS di <code>.env</code></div>');
        }
    }
    return $pdo;
}

// ── Auth Admin ────────────────────────────────────────────────────
function isAdmin(): bool { return !empty($_SESSION['admin_id']); }
function adminUser(): array {
    if (!isAdmin()) return null;
    return ['id'=>$_SESSION['admin_id'],'username'=>$_SESSION['admin_user'],
            'full_name'=>$_SESSION['admin_name'],'role'=>$_SESSION['admin_role']];
}
function requireAdmin(string $role='operator'){
    if (!isAdmin()) { header('Location: '.ADMIN_URL.'/login.php'); exit; }
    $map=['teknisi'=>0,'operator'=>1,'admin'=>2,'superadmin'=>3];
    // Teknisi hanya boleh akses halaman khusus teknisi
    if ($_SESSION['admin_role']==='teknisi') {
        $allowed=['teknisi_portal.php','teknisi_laporan.php','teknisi_laporan_ajax.php','penagihan.php','penagihan_ajax.php','pppoe.php','pppoe_detail.php'];
        $cur=basename($_SERVER['PHP_SELF']);
        if(!in_array($cur,$allowed)){
            header('Location: '.ADMIN_URL.'/teknisi_portal.php'); exit;
        }
        // Lepas session lock — halaman lain tidak perlu antri
        session_write_close();
        return;
    }
    if (($map[$_SESSION['admin_role']]??0) < ($map[$role]??1)) {
        header('Location: '.ADMIN_URL.'/dashboard.php'); exit;
    }
    // Lepas session lock setelah verifikasi selesai
    // Ini kunci utama: request navigasi berikutnya tidak diblok
    session_write_close();
}

// ── Auth Customer ─────────────────────────────────────────────────
function isCust(): bool { return !empty($_SESSION['cust_id']); }
function custUser(): array {
    if (!isCust()) return null;
    return ['id'=>$_SESSION['cust_id'],'customer_id'=>$_SESSION['cust_cid'],
            'full_name'=>$_SESSION['cust_name'],'device_id'=>$_SESSION['cust_device_id']];
}
function requireCust(){
    if (!isCust()) { header('Location: '.PORTAL_URL.'/login.php'); exit; }
    session_write_close();
}

// ── Helpers ───────────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function logoB64(): string {
    $p = APP_BASE.'/assets/logo.png';
    return file_exists($p) ? 'data:image/png;base64,'.base64_encode(file_get_contents($p)) : '';
}
function generateCustomerId(): string {
    // Menggunakan transaction untuk mencegah race condition
    $db = db();
    for ($i = 0; $i < 5; $i++) {
        $last = $db->query("SELECT customer_id FROM customers ORDER BY id DESC LIMIT 1")->fetchColumn();
        $n = 1;
        if ($last && preg_match('/(\d+)$/', $last, $m)) $n = (int)$m[1] + 1;
        $cid = 'SNET-'.str_pad($n, 4, '0', STR_PAD_LEFT);
        // Cek apakah ID sudah ada (hindari race condition)
        $ck = $db->prepare("SELECT id FROM customers WHERE customer_id=? LIMIT 1");
        $ck->execute([$cid]);
        if (!$ck->fetchColumn()) return $cid;
        usleep(50000); // tunggu 50ms dan coba lagi
    }
    // Fallback: gunakan timestamp
    return 'SNET-'.date('ymdHis');
}
function generateRandomPort(array $used=[]): int {
    $i=0; do { $p=rand(10000,65000); } while(in_array($p,$used)&&++$i<2000); return $p;
}
function auditLog(string $action, string $target='', string $detail='', $type='admin'){
    $u = $type==='customer' ? custUser() : adminUser();
    try {
        db()->prepare("INSERT INTO audit_log(actor_type,actor_id,actor_name,action,target,detail,ip_address)VALUES(?,?,?,?,?,?,?)")
           ->execute([$type,$u['id']??0,$u['full_name']??'system',$action,$target,$detail,$_SERVER['REMOTE_ADDR']??'']);
    } catch(Exception $e) {}
}
function saveOntConfig(int $custId, string $devId, string $type, string $name, array $data): bool {
    $db = db();
    try {
        $ex=$db->prepare("SELECT id FROM ont_configs WHERE customer_id=? AND genie_device_id=? AND config_type=? AND config_name=? LIMIT 1");
        $ex->execute([$custId,$devId,$type,$name]);
        $existId=$ex->fetchColumn();
        if ($existId) {
            $db->prepare("UPDATE ont_configs SET config_data=?,push_status='success',push_count=push_count+1,last_pushed=NOW() WHERE id=?")
               ->execute([json_encode($data), $existId]);
        } else {
            $db->prepare("INSERT INTO ont_configs(customer_id,genie_device_id,config_type,config_name,config_data,push_status,created_by)VALUES(?,?,?,?,?,'success',?)")
               ->execute([$custId,$devId,$type,$name,json_encode($data),adminUser()['id']??null]);
        }
        return true;
    } catch(Exception $e) { return false; }
}

// ================================================================
// CSRF PROTECTION
// ================================================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="'.h(csrfToken()).'">';
}
function csrfVerify(){
    $tok = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals(csrfToken(), $tok)) {
        http_response_code(403);
        die('<div style="font-family:monospace;padding:20px;background:#FEE2E2;color:#B91C1C">
            <strong>403 Forbidden:</strong> CSRF token tidak valid. <a href="javascript:history.back()">Kembali</a></div>');
    }
}

// ================================================================
// RATE LIMITING (Login Brute-Force Protection)
// ================================================================
define('LOGIN_MAX_ATTEMPTS', 5);   // max gagal sebelum lockout
define('LOGIN_LOCKOUT_MINS', 15);  // durasi lockout (menit)

function _ensureLoginAttemptsTable(){
    static $checked = false;
    if ($checked) return;
    $checked = true;
    try {
        db()->query("SELECT 1 FROM login_attempts LIMIT 1");
    } catch(Exception $e) {
        db()->exec("CREATE TABLE IF NOT EXISTS login_attempts(
            id INT AUTO_INCREMENT PRIMARY KEY,
            identifier VARCHAR(150) NOT NULL,
            ip_address VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
            attempt_type VARCHAR(20) NOT NULL DEFAULT 'admin',
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_id_type_time (identifier, attempt_type, attempted_at),
            INDEX idx_ip_type_time (ip_address, attempt_type, attempted_at)
        )");
    }
}

function checkLoginRateLimit($identifier, $type='admin'): bool {
    _ensureLoginAttemptsTable();
    try {
        $db = db();
        $cutoff = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINS * 60);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $st = $db->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (identifier=? OR ip_address=?) AND attempt_type=? AND attempted_at > ?"
        );
        $st->execute([$identifier, $ip, $type, $cutoff]);
        return (int)$st->fetchColumn() < LOGIN_MAX_ATTEMPTS;
    } catch(Exception $e) {
        return true;
    }
}
function recordLoginAttempt($identifier, $type='admin'){
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        db()->prepare("INSERT INTO login_attempts(identifier,ip_address,attempt_type)VALUES(?,?,?)")
           ->execute([$identifier, $ip, $type]);
    } catch(Exception $e) {}
}
function clearLoginAttempts($identifier, $type='admin'){
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        db()->prepare("DELETE FROM login_attempts WHERE (identifier=? OR ip_address=?) AND attempt_type=?")
           ->execute([$identifier, $ip, $type]);
    } catch(Exception $e) {}
}
function loginAttemptsLeft($identifier, $type='admin'): int {
    try {
        $cutoff = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINS * 60);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $st = db()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE (identifier=? OR ip_address=?) AND attempt_type=? AND attempted_at > ?"
        );
        $st->execute([$identifier, $ip, $type, $cutoff]);
        return max(0, LOGIN_MAX_ATTEMPTS - (int)$st->fetchColumn());
    } catch(Exception $e) {
        return LOGIN_MAX_ATTEMPTS;
    }
}

// ================================================================
// Load API Classes
// ================================================================
require_once __DIR__.'/MikrotikAPI.php';
require_once __DIR__.'/GenieACS.php';
require_once __DIR__.'/MikhmonMonitor.php';
