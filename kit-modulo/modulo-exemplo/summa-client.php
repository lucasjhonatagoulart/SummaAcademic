<?php

declare(strict_types=1);

// Cliente de integração com o Summa (login único). Não tem dependências.
//
// Uso, no começo de cada página do módulo:
//
//     require __DIR__ . '/summa-client.php';
//     $user = summa_require_login();          // ['id', 'email', 'name', 'avatar', 'perms' => ['access', ...]]
//     if (summa_can('edit')) { /* mostra o botão de editar */ }
//
// Para sair: summa_logout();   Para voltar aos módulos: summa_workspace_url()
//
// O arquivo summa.secret.php, na mesma pasta, é gravado pelo Summa quando o módulo é incluído
// (Painel de controle › Módulos). Ele traz o slug, o segredo e o endereço do Summa.

const SUMMA_IDLE_SECONDS = 7200;   // sessão do módulo expira após 2 h sem uso, como no Summa
const SUMMA_CLOCK_SKEW = 60;       // tolerância de relógio, em segundos

/** Configuração gravada pelo Summa: ['slug', 'secret', 'url'] */
function summa_cfg(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $file = __DIR__ . '/summa.secret.php';
    $data = is_file($file) ? require $file : null;
    if (!is_array($data) || !preg_match('/^[a-z][a-z_]{1,23}$/', (string)($data['slug'] ?? '')) || strlen((string)($data['secret'] ?? '')) < 32) {
        summa_fail('Este módulo ainda não foi incluído no Summa (falta o arquivo summa.secret.php). Inclua-o em Painel de controle › Módulos.', 500);
    }
    $url = (string)($data['url'] ?? '../');
    return $cfg = ['slug' => $data['slug'], 'secret' => $data['secret'], 'url' => rtrim($url, '/') . '/'];
}

function summa_b64url_encode(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function summa_b64url_decode(string $txt): ?string
{
    if ($txt === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $txt)) return null;
    $bin = base64_decode(strtr($txt, '-_', '+/') . str_repeat('=', (4 - strlen($txt) % 4) % 4), true);
    return $bin === false ? null : $bin;
}

/** Confere o token que veio do Summa. Devolve os dados ou lança RuntimeException com o motivo. */
function summa_verify_token(string $token, string $secret, string $slug, ?int $now = null): array
{
    $now ??= time();
    $parts = explode('.', $token);
    if (strlen($token) > 6000 || count($parts) !== 2) throw new RuntimeException('O acesso enviado pelo Summa está incompleto.');
    [$body, $sig] = $parts;
    if (!hash_equals(summa_b64url_encode(hash_hmac('sha256', $body, $secret, true)), $sig)) throw new RuntimeException('O acesso enviado pelo Summa tem assinatura inválida.');
    $claims = json_decode((string)summa_b64url_decode($body), true);
    if (!is_array($claims)) throw new RuntimeException('O acesso enviado pelo Summa está ilegível.');
    if (($claims['iss'] ?? '') !== 'summa' || ($claims['aud'] ?? '') !== $slug) throw new RuntimeException('Este acesso não é para este módulo.');
    $iat = (int)($claims['iat'] ?? 0); $exp = (int)($claims['exp'] ?? 0);
    if ($exp < $now - SUMMA_CLOCK_SKEW || $iat > $now + SUMMA_CLOCK_SKEW || $exp - $iat > 300) throw new RuntimeException('O acesso pelo Summa expirou. Abra o módulo de novo.');
    if (!preg_match('/^[a-f0-9]{32}$/', (string)($claims['jti'] ?? ''))) throw new RuntimeException('O acesso enviado pelo Summa está incompleto.');
    $email = (string)($claims['u'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('O acesso enviado pelo Summa não identifica o usuário.');
    $perms = [];
    foreach ((array)($claims['p'] ?? []) as $code) if (is_string($code) && preg_match('/^[a-z][a-z0-9_-]{0,30}$/', $code)) $perms[] = $code;
    $a = $claims['a'] ?? '';
    $avatar = is_string($a) && preg_match('#^uploads/[A-Za-z0-9_][A-Za-z0-9_./-]{0,180}\.(?:jpe?g|png|webp)$#', $a) && !str_contains($a, '..') && !str_contains($a, '//') ? $a : '';
    return ['id' => (int)($claims['sub'] ?? 0), 'email' => $email, 'name' => trim((string)($claims['n'] ?? '')) ?: $email, 'avatar' => $avatar,
        'perms' => array_values(array_unique($perms)), 'jti' => $claims['jti'], 'exp' => $exp];
}

/** Uso único: guarda o identificador do token em summa-data/ (pasta fechada ao navegador). Falso se já foi usado. */
function summa_token_once(string $jti, ?int $now = null): bool
{
    $now ??= time();
    $dir = __DIR__ . '/summa-data';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    foreach ((array)@scandir($dir) as $f) {
        if (preg_match('/^[a-f0-9]{32}$/', (string)$f) && (int)@filemtime("$dir/$f") < $now - 900) @unlink("$dir/$f");
    }
    $h = @fopen("$dir/$jti", 'x');   // 'x' falha se o arquivo já existe: é o que garante o uso único
    if ($h === false) return false;
    fclose($h);
    return true;
}

function summa_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    // Identificador explícito: se o módulo já usou a sessão dele neste pedido, o PHP reaproveitaria o identificador dela
    // e as duas sessões passariam a dividir os mesmos dados.
    $name = 'summa_mod_' . summa_cfg()['slug'];
    $sid = (string)($_COOKIE[$name] ?? '');
    session_name($name);
    session_id(preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid) ? $sid : session_create_id());
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

/** Usuário que entrou pelo Summa, ou null */
function summa_user(): ?array
{
    summa_session();
    $u = $_SESSION['summa'] ?? null;
    if (!is_array($u) || time() - (int)($u['seen'] ?? 0) > SUMMA_IDLE_SECONDS) { unset($_SESSION['summa']); return null; }
    $_SESSION['summa']['seen'] = time();
    return $u;
}

/** Garante o login. Recebe o token (?sso=), abre a sessão do módulo e devolve o usuário; sem sessão, manda entrar pelo Summa. */
function summa_require_login(): array
{
    $cfg = summa_cfg();
    summa_session();
    if (isset($_GET['sso'])) {
        try {
            $u = summa_verify_token((string)$_GET['sso'], $cfg['secret'], $cfg['slug']);
            if (!summa_token_once($u['jti'])) throw new RuntimeException('Este acesso já foi usado. Abra o módulo de novo pelo Summa.');
        } catch (RuntimeException $e) {
            summa_fail($e->getMessage(), 403);
        }
        session_regenerate_id(true);
        $_SESSION = ['summa' => ['id' => $u['id'], 'email' => $u['email'], 'name' => $u['name'], 'avatar' => $u['avatar'] !== '' ? $cfg['url'] . $u['avatar'] : '', 'perms' => $u['perms'], 'seen' => time()]];
        // Tira o token do endereço antes de mostrar a página
        $query = $_GET; unset($query['sso']);
        $path = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: 'index.php';
        header('Location: ' . $path . ($query ? '?' . http_build_query($query) : ''), true, 303);
        exit;
    }
    $u = summa_user();
    if (!$u) { header('Location: ' . $cfg['url'] . 'index.php?r=modulo.' . $cfg['slug'], true, 303); exit; }
    return $u;
}

/** O usuário tem alguma destas permissões? (códigos do manifesto) */
function summa_can(string ...$codes): bool
{
    $u = summa_user();
    return $u !== null && array_intersect($codes, $u['perms']) !== [];
}
function summa_require_perm(string ...$codes): array
{
    $u = summa_require_login();
    if (!summa_can(...$codes)) summa_fail('Seu usuário não tem permissão para esta tela. A liberação é feita no Summa, em Gerenciamento de usuários.', 403);
    return $u;
}

function summa_workspace_url(): string { return summa_cfg()['url'] . 'index.php?r=workspace'; }
/** Endereço que encerra também a sessão do Summa (assinado, vale 2 minutos) */
function summa_logout_url(?int $now = null): string
{
    $cfg = summa_cfg();
    $ts = (string)($now ?? time());
    $sig = summa_b64url_encode(hash_hmac('sha256', 'sair|' . $ts, $cfg['secret'], true));
    return $cfg['url'] . 'index.php?r=modulo_sair.' . $cfg['slug'] . '&' . http_build_query(['t' => $ts, 's' => $sig]);
}
/** Sai do módulo e do Summa. Chame a partir de um POST (formulário), nunca de um link. */
function summa_logout(): never
{
    $url = summa_logout_url();
    summa_session();
    $_SESSION = [];
    session_regenerate_id(true);
    header('Location: ' . $url, true, 303);
    exit;
}

function summa_fail(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $back = '../index.php';
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Acesso pelo Summa</title>',
        '<body style="font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:#F4F6FA;color:#172B4D;margin:0;padding:40px 16px">',
        '<div style="max-width:520px;margin:0 auto;background:#fff;border:1px solid #E1E5EC;border-radius:12px;padding:28px">',
        '<h1 style="font-size:18px;margin:0 0 8px">Não foi possível entrar</h1><p style="margin:0 0 16px;color:#5E6C84">', htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), '</p>',
        '<a href="', $back, '" style="display:inline-block;padding:10px 16px;border:1px solid #C5CFDF;border-radius:8px;color:#172B4D;text-decoration:none;font-weight:600">Voltar ao Summa</a></div></body></html>';
    exit;
}
