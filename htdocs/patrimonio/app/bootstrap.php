<?php
declare(strict_types=1);

class CsrfValidationException extends RuntimeException {}

date_default_timezone_set('America/Sao_Paulo');

$configFile = dirname(__DIR__) . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    echo 'Configuração não encontrada. Copie config.example.php para config.php e preencha os dados do MySQL.';
    exit;
}
$config = require $configFile;
if (!is_array($config) || empty($config['db_host']) || empty($config['db_name']) || empty($config['db_user'])) {
    http_response_code(500);
    echo 'Configuração MySQL incompleta. Confira config.php.';
    exit;
}

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
session_set_cookie_params(['lifetime' => 0,'path' => '/','secure' => $isHttps,'httponly' => true,'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ativa_session');
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

require_once __DIR__ . '/ui.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/summa.php';
require_once __DIR__ . '/summa_purchases.php';
require_once __DIR__ . '/nfe.php';
require_once __DIR__ . '/invoice_workflow.php';
require_once __DIR__ . '/repository.php';
require_once __DIR__ . '/export_helpers.php';
require_once __DIR__ . '/import_util.php';
require_once __DIR__ . '/zip.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/restore.php';
require_once __DIR__ . '/backup.php';
require_once __DIR__ . '/reporting.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/render.php';

function db(): PDO
{
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host=' . (string)$config['db_host'] . ';dbname=' . (string)$config['db_name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, (string)$config['db_user'], (string)($config['db_pass'] ?? ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10]);
    return $pdo;
}
function app_name(): string { return display_title(); }
function page_url(string $page, array $extra = []): string
{
    if ($page === 'download') {
        $kind = (string)($extra['kind'] ?? '');
        unset($extra['kind']);
        return 'index.php?' . http_build_query(array_merge(['download' => $kind], $extra));
    }
    if ($page === 'download-xml') return 'index.php?' . http_build_query(['download' => 'xml', 'id' => (int)($extra['id'] ?? 0)]);
    return 'index.php?' . http_build_query(array_merge(['page' => $page], $extra));
}
function redirect_to(string $page, array $extra = []): never { header('Location: ' . page_url($page,$extra),true,303); exit; }
function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function csrf_token(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return (string)$_SESSION['csrf']; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">'; }
function verify_csrf(?string $token = null): void { $submitted=$token??(isset($_POST['csrf'])?(string)$_POST['csrf']:''); if(!hash_equals(csrf_token(),$submitted)){http_response_code(403);throw new CsrfValidationException('A sessão do formulário expirou. Recarregue a página e tente novamente.');} }
function is_logged_in(): bool { return isset($_SESSION['admin_id'])&&(int)$_SESSION['admin_id']>0; }
function require_login(): void { if(is_logged_in())return; if(isset($_GET['api'])||isset($_POST['api']))json_response(['error'=>'Sessão encerrada. Entre novamente.'],401); if(summa_enabled()){header('Location: '.summa_url('patrimonio'),true,303);exit;} redirect_to('login'); }
function json_response(array $payload,int $status=200): never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function flash_set(string $type,string $message):void{$_SESSION['flash']=['type'=>$type,'message'=>$message];}
function flash_take():?array{$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);return is_array($flash)?$flash:null;}
function positive_int(mixed $value,string $label='Identificador'):int{$n=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($n===false)throw new RuntimeException($label.' inválido.');return(int)$n;}
function utf8_length(string $value): int
{
    if (function_exists('mb_strlen')) return mb_strlen($value, 'UTF-8');
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}
function text_value(mixed $value,int $limit=5000):string{if(!is_string($value)&&!is_numeric($value))return'';$value=trim((string)$value);if(utf8_length($value)>$limit)throw new RuntimeException('Um campo ultrapassou o limite permitido.');return$value;}
function money_br(mixed $value):string{return'R$ '.number_format((float)$value,2,',','.');}
function date_br(?string $value):string{if(!$value)return'—';$time=strtotime($value);return$time===false?h($value):date('d/m/Y',$time);}
function status_label(string $status):string{return match($status){'registered'=>'Incorporada','disposed'=>'Baixado','draft'=>'Rascunho',default=>'Ativo'};}
function is_blank_setup():bool{try{return(int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn()===0;}catch(Throwable){return true;}}

summa_enforce_idle_timeout();
