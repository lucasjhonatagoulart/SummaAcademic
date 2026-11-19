<?php
// Módulo Patrimônio (Summa Patrimônio): entrada com login único e saída.
// O Patrimônio fica em htdocs/patrimonio/ com banco próprio; esta rota entrega a ele
// um token curto (2 min, uso único) assinado com CONFIG['patrimonio_sso_secret'].

/** Permissões do Summa (área Patrimônio) → permissões internas do Patrimônio. */
const PATRIMONY_PERMISSION_MAP = [
    'patrimony:invoices' => 'edit_invoices',
    'patrimony:assets' => 'edit_assets',
    'patrimony:catalogs' => 'manage_catalogs',
    'patrimony:export' => 'export_data',
    'patrimony:admin' => 'manage_users',
    'patrimony:restore' => 'restore_backup',
];

/** Quem tem qualquer permissão da área Patrimônio pode abrir o módulo. */
function patrimony_allowed(array $u): bool {
    foreach ($u['perms'] ?? [] as $perm) if (str_starts_with((string) $perm, 'patrimony:')) return true;
    return false;
}

/** Segredo e endereço do módulo: do config.php do Summa ou, se não estiverem lá, do arquivo app/patrimonio.secret.php
 *  (um arquivo à parte, para ligar o módulo sem editar o config.php). */
function patrimony_settings(): array {
    $s = ['secret' => (string) (CONFIG['patrimonio_sso_secret'] ?? ''), 'url' => (string) (CONFIG['patrimonio_url'] ?? '')];
    $file = dirname(__DIR__) . '/patrimonio.secret.php';
    if ($s['secret'] === '' && is_file($file)) {
        $extra = require $file;
        if (is_array($extra)) $s = ['secret' => (string) ($extra['patrimonio_sso_secret'] ?? ''), 'url' => $s['url'] !== '' ? $s['url'] : (string) ($extra['patrimonio_url'] ?? '')];
    }
    if ($s['url'] === '') $s['url'] = 'patrimonio/';
    return $s;
}

/** Foto de perfil para o token: só um caminho de imagem dentro de uploads/ (o módulo monta o endereço a partir do Summa) */
function sso_avatar(?string $path): string {
    $path = (string) $path;
    return preg_match('#^uploads/[A-Za-z0-9_][A-Za-z0-9_./-]{0,180}\.(?:jpe?g|png|webp)$#', $path) && !str_contains($path, '..') && !str_contains($path, '//') ? $path : '';
}

function patrimony_b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }

function route_patrimonio(): void {
    $u = current_user();
    if (!$u) go('login');
    if (!patrimony_allowed($u)) abort(403, 'O módulo Patrimônio não está liberado para o seu usuário. Peça ao administrador para liberá-lo em Painel de controle › Usuários › Módulos e áreas.');
    $cfg = patrimony_settings(); $secret = $cfg['secret'];
    if (strlen($secret) < 32) abort(500, 'O módulo Patrimônio ainda não foi configurado: falta o segredo do módulo (arquivo app/patrimonio.secret.php ou patrimonio_sso_secret no config.php do Summa).');

    $perms = [];
    foreach (PATRIMONY_PERMISSION_MAP as $summaPerm => $localPerm) if (in_array($summaPerm, $u['perms'], true)) $perms[] = $localPerm;
    $email = mb_strtolower(trim((string) $u['email']));
    $now = time();
    $claims = [
        'iss' => 'summa', 'aud' => 'patrimonio',
        'sub' => (int) $u['id'],
        'u' => ($email !== '' && mb_strlen($email) <= 80) ? $email : 'summa-' . (int) $u['id'],
        'n' => (string) $u['name'],
        'a' => sso_avatar($u['avatar_path'] ?? null),
        'p' => $perms,
        'iat' => $now, 'exp' => $now + 120,
        'jti' => patrimony_b64url(random_bytes(24)),
    ];
    $body = patrimony_b64url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $token = $body . '.' . patrimony_b64url(hash_hmac('sha256', $body, $secret, true));
    audit('patrimonio.open', 'Abriu o módulo Patrimônio');
    header('Cache-Control: no-store');
    header('Location: ' . rtrim($cfg['url'], '/') . '/index.php?sso=' . rawurlencode($token), true, 303);
    exit;
}

/** "Sair" dentro do Patrimônio: encerra também a sessão do Summa.
 *  Só vale com o link assinado que o Patrimônio gera na hora (segredo compartilhado, 2 minutos);
 *  um link montado por outro site não derruba a sessão de ninguém. */
function patrimony_logout_ok(string $ts, string $sig, string $secret, ?int $now = null): bool {
    if (strlen($secret) < 32 || !ctype_digit($ts) || $sig === '') return false;
    if (abs(($now ?? time()) - (int) $ts) > 120) return false;
    return hash_equals(patrimony_b64url(hash_hmac('sha256', 'sair|' . $ts, $secret, true)), $sig);
}
function route_patrimonio_sair(): void {
    if (!patrimony_logout_ok((string) ($_GET['t'] ?? ''), (string) ($_GET['s'] ?? ''), patrimony_settings()['secret'])) go(current_user() ? 'workspace' : 'login');
    if (current_user()) audit('auth.logout', 'Saiu do sistema pelo módulo Patrimônio');
    $_SESSION = [];
    session_regenerate_id(true);
    go('login');
}
