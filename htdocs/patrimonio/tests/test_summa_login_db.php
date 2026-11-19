<?php

declare(strict_types=1);
ob_start(); // a sessão é renovada a cada login; no terminal isso exige que nada tenha sido impresso antes

// Login único Summa → Patrimônio com banco (SQLite em memória): conta local, permissões e uso único do token.
function admin_permission_definitions(): array
{
    return ['edit_invoices' => '', 'edit_assets' => '', 'manage_catalogs' => '', 'manage_users' => '', 'export_data' => '', 'restore_backup' => ''];
}
function utf8_length(string $value): int { return mb_strlen($value, 'UTF-8'); }
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pdo->exec('CREATE TABLE admins (id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, last_login TEXT)');
    $pdo->exec('CREATE TABLE admin_permissions (admin_id INTEGER NOT NULL, permission TEXT NOT NULL, PRIMARY KEY (admin_id, permission))');
    $pdo->exec('CREATE TABLE admin_permission_seeded (admin_id INTEGER PRIMARY KEY)');
    return $pdo;
}
$secret = str_repeat('k', 64);
$config = ['summa' => ['secret' => $secret, 'url' => '../']];
require __DIR__ . '/../app/summa.php';
ini_set('session.use_cookies', '0');
session_start();

$failures = 0; $total = 0;
function check(bool $ok, string $label): void { global $failures, $total; $total++; if (!$ok) { $failures++; fwrite(STDERR, "FALHOU: $label\n"); } else echo "OK - $label\n"; }
function make_token(array $claims, string $secret): string
{
    $body = summa_b64url_encode(json_encode($claims));
    return $body . '.' . summa_b64url_encode(hash_hmac('sha256', $body, $secret, true));
}
function claims(array $perms, string $jti): array
{
    return ['aud' => 'patrimonio', 'u' => 'ana@exemplo.edu.br', 'n' => 'Ana Souza', 'p' => $perms, 'iat' => time(), 'exp' => time() + 120, 'jti' => $jti];
}
function perms_of(int $id): array { $s = db()->prepare('SELECT permission FROM admin_permissions WHERE admin_id = ? ORDER BY permission'); $s->execute([$id]); return $s->fetchAll(PDO::FETCH_COLUMN); }

$first = make_token(claims(['edit_assets', 'export_data'], str_repeat('a', 32)), $secret);
summa_sso_login($first);
$id = (int)$_SESSION['admin_id'];
check($id > 0 && $_SESSION['admin_name'] === 'Ana Souza' && $_SESSION['via_summa'] === true, 'primeiro acesso cria a conta local e abre a sessão');
check(perms_of($id) === ['edit_assets', 'export_data'], 'permissões gravadas são as que vieram do Summa');
check((int)db()->query('SELECT COUNT(*) FROM admin_permission_seeded')->fetchColumn() === 1, 'conta nova fica marcada como já configurada');

try { summa_sso_login($first); $reused = false; } catch (RuntimeException $e) { $reused = str_contains($e->getMessage(), 'já foi usado'); }
check($reused, 'o mesmo token não entra duas vezes');

summa_sso_login(make_token(claims([], str_repeat('b', 32)), $secret));
check((int)$_SESSION['admin_id'] === $id && (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 1, 'segundo acesso reaproveita a mesma conta');
check(perms_of($id) === [], 'permissão retirada no Summa some no próximo acesso (só consulta)');

summa_sso_login(make_token(claims(['manage_users', 'super_admin'], str_repeat('c', 32)), $secret));
check(perms_of($id) === ['manage_users'], 'permissão desconhecida não é gravada');

check(($_SESSION['admin_avatar'] ?? null) === '', 'sem foto no acesso, a sessão fica sem foto');
summa_sso_login(make_token(claims([], str_repeat('d', 32)) + ['a' => 'uploads/avatars/u7-abc.jpg'], $secret));
check($_SESSION['admin_avatar'] === '../uploads/avatars/u7-abc.jpg', 'foto do Summa vai para a sessão com o endereço relativo ao módulo');
foreach (['../config.php', 'uploads/../config.php', 'https://exemplo.com/x.jpg', 'uploads/x.php', ['uploads/x.jpg']] as $i => $bad) {
    summa_sso_login(make_token(claims([], str_repeat('e', 31) . $i) + ['a' => $bad], $secret));
    check($_SESSION['admin_avatar'] === '', 'caminho de foto fora do padrão é ignorado (' . (is_array($bad) ? 'lista' : $bad) . ')');
}
check(summa_brand('logo-fundo-escuro.png') === '../assets/brand/logo-fundo-escuro.png' && summa_brand('../config.php') === '' && summa_brand('nao-existe.png') === '', 'logo do Summa só é usada se o arquivo existir na pasta de cima');

$url = summa_logout_url(1_800_000_000);
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
check(str_starts_with($url, '../index.php?') && $q['r'] === 'patrimonio_sair' && $q['t'] === '1800000000'
    && hash_equals(summa_b64url_encode(hash_hmac('sha256', 'sair|1800000000', $secret, true)), $q['s']), 'endereço de saída leva hora e assinatura');

echo "Total: $total verificações do login único com banco, $failures falha(s).\n";
exit($failures ? 1 : 0);
