<?php
/* Núcleo: conexão com o banco, sessão, permissões e utilitários. Compatível com PHP 7.4 ou superior. */

if (!is_file(__DIR__ . '/../config.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Configuração não encontrada. Copie config.example.php para config.php, na pasta ti, e preencha os dados do banco.');
}
require __DIR__ . '/../config.php';
date_default_timezone_set(isset($CONFIG['timezone']) ? $CONFIG['timezone'] : 'America/Sao_Paulo');

const COLECOES = ['pcs', 'os', 'osx', 'manut', 'redes', 'projetores'];
const PAPEIS   = ['admin', 'tecnico', 'diretor', 'solicitante'];

function cfg($k) { global $CONFIG; return isset($CONFIG[$k]) ? $CONFIG[$k] : null; }

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $opt = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    if (cfg('db_driver') === 'sqlite') {
        $pdo = new PDO('sqlite:' . cfg('db_name'), null, null, $opt);
    } else {
        $pdo = new PDO('mysql:host=' . cfg('db_host') . ';dbname=' . cfg('db_name') . ';charset=utf8mb4', cfg('db_user'), cfg('db_pass'), $opt);
    }
    return $pdo;
}

function tam($s) { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen(utf8_decode($s)); }

function agora() { return date('Y-m-d H:i:s'); }

function https_ativo() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
}

function iniciar_sessao() {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('PTISESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => https_ativo(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function json_saida($dados, $codigo = 200) {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function erro($msg, $codigo = 400) { json_saida(['ok' => false, 'erro' => $msg], $codigo); }

function corpo() {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return [];
    $j = json_decode($raw, true);
    return is_array($j) ? $j : [];
}

/** Conta logada (relida do banco a cada pedido: mudança de função vale na hora). */
function usuario_atual() {
    iniciar_sessao();
    if (empty($_SESSION['uid'])) return null;
    if (!empty($_SESSION['mod'])) {   /* entrou pelo Summa: encerra após 2 h parado */
        if (time() - (int)(isset($_SESSION['ult']) ? $_SESSION['ult'] : 0) > 7200) { $_SESSION = []; return null; }
        $_SESSION['ult'] = time();
    }
    $st = db()->prepare('SELECT id, nome, email, role, ativo, trocar_senha FROM accounts WHERE id = ?');
    $st->execute([(int)$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || !(int)$u['ativo']) { $_SESSION = []; return null; }
    return $u;
}

function csrf_token() {
    iniciar_sessao();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function exigir_csrf() {
    $t = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    iniciar_sessao();
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $t)) erro('Sessão expirada. Recarregue a página.', 403);
}

function equipe($u) { return in_array($u['role'], ['admin', 'tecnico'], true); }
function staff($u)  { return in_array($u['role'], ['admin', 'tecnico', 'diretor'], true); }

function id_valido($id) { return is_string($id) && preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $id) === 1; }

/* ---------- documentos ---------- */

function doc_ler($col, $id) {
    $st = db()->prepare('SELECT dados FROM docs WHERE colecao = ? AND doc_id = ?');
    $st->execute([$col, $id]);
    $r = $st->fetch();
    if (!$r) return null;
    $d = json_decode($r['dados'], true);
    return is_array($d) ? $d : [];
}

function versao_subir($col) {
    $pdo = db();
    $st = $pdo->prepare('UPDATE versoes SET versao = versao + 1 WHERE colecao = ?');
    $st->execute([$col]);
    if ($st->rowCount() === 0) {
        try { $pdo->prepare('INSERT INTO versoes (colecao, versao) VALUES (?, 2)')->execute([$col]); }
        catch (Exception $e) { $pdo->prepare('UPDATE versoes SET versao = versao + 1 WHERE colecao = ?')->execute([$col]); }
    }
}

function versao_de($col) {
    $st = db()->prepare('SELECT versao FROM versoes WHERE colecao = ?');
    $st->execute([$col]);
    $r = $st->fetch();
    return $r ? (int)$r['versao'] : 1;
}

/** Pode ler este documento? ($dono = id do autor da OS, para osx) */
function pode_ler($u, $col, $dados, $osId = null) {
    if ($col === 'pcs') return true;
    if (staff($u)) return true;
    if ($col === 'os') return isset($dados['solicitanteId']) && (string)$dados['solicitanteId'] === (string)$u['id'];
    if ($col === 'osx') {
        $os = $osId ? doc_ler('os', $osId) : null;
        return $os && isset($os['solicitanteId']) && (string)$os['solicitanteId'] === (string)$u['id'];
    }
    return false; // manut
}

function limpar_dados($d) {
    if (!is_array($d)) erro('Dados inválidos.');
    $json = json_encode($d, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 200000) erro('Registro grande demais.', 413);
    return $d;
}

function remover_marcas($d) { // suporta {"__delete__":true} no update
    foreach ($d as $k => $v) { if (is_array($v) && isset($v['__delete__'])) unset($d[$k]); }
    return $d;
}

/* Remove a conta de verdade para o usuário: sem acesso, e-mail liberado, some do painel.
   O nome continua gravado só para o histórico das ordens de serviço. */
function conta_apagar(PDO $pdo, int $id) {
    $pdo->prepare('UPDATE accounts SET ativo = 0, email = ?, senha_hash = ? WHERE id = ? AND email NOT LIKE ?')
        ->execute(['removida-' . $id . '-' . time() . '@removido.invalid', '!', $id, '%@removido.invalid']);
}
