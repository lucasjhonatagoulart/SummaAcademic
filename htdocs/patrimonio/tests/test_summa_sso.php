<?php

declare(strict_types=1);

// Login único Summa → Patrimônio: conferência do token sem banco de dados.
function admin_permission_definitions(): array
{
    return ['edit_invoices' => '', 'edit_assets' => '', 'manage_catalogs' => '', 'manage_users' => '', 'export_data' => '', 'restore_backup' => ''];
}
function utf8_length(string $value): int { return mb_strlen($value, 'UTF-8'); }
require __DIR__ . '/../app/summa.php';

$secret = str_repeat('k', 64);
$now = 1_800_000_000;
function make_token(array $claims, string $secret): string
{
    $body = summa_b64url_encode(json_encode($claims));
    return $body . '.' . summa_b64url_encode(hash_hmac('sha256', $body, $secret, true));
}
$base = ['aud' => 'patrimonio', 'u' => 'ana@exemplo.edu.br', 'n' => 'Ana', 'p' => ['edit_assets', 'super_admin', 'export_data'], 'iat' => $now, 'exp' => $now + 120, 'jti' => str_repeat('a', 32)];

$failures = 0;
function check(bool $ok, string $label): void { global $failures; if (!$ok) { $failures++; fwrite(STDERR, "FALHOU: $label\n"); } else echo "OK - $label\n"; }
function rejects(callable $fn, string $expected): bool { try { $fn(); return false; } catch (RuntimeException $e) { return str_contains($e->getMessage(), $expected); } }

$claims = summa_sso_decode(make_token($base, $secret), $secret, $now);
check($claims['u'] === 'ana@exemplo.edu.br' && $claims['n'] === 'Ana', 'token válido devolve usuário e nome');
check($claims['p'] === ['edit_assets', 'export_data'], 'permissões desconhecidas são descartadas');
check(rejects(fn() => summa_sso_decode(make_token($base, $secret), str_repeat('x', 64), $now), 'assinatura inválida'), 'segredo diferente é recusado');
$tampered = make_token($base, $secret); $tampered[5] = $tampered[5] === 'A' ? 'B' : 'A';
check(rejects(fn() => summa_sso_decode($tampered, $secret, $now), 'assinatura'), 'conteúdo alterado é recusado');
check(rejects(fn() => summa_sso_decode(make_token($base, $secret), $secret, $now + 121), 'expirou'), 'token vencido é recusado');
check(rejects(fn() => summa_sso_decode(make_token(['exp' => $now + 3600] + $base, $secret), $secret, $now), 'expirou'), 'validade longa demais é recusada');
check(rejects(fn() => summa_sso_decode(make_token(['aud' => 'financeiro'] + $base, $secret), $secret, $now), 'não foi emitido'), 'token de outro módulo é recusado');
check(rejects(fn() => summa_sso_decode(make_token(['u' => ''] + $base, $secret), $secret, $now), 'incompleto'), 'token sem usuário é recusado');
check(rejects(fn() => summa_sso_decode(make_token(['jti' => 'curto'] + $base, $secret), $secret, $now), 'incompleto'), 'token sem identificador único é recusado');
check(rejects(fn() => summa_sso_decode('lixo', $secret, $now), 'inválido'), 'texto qualquer é recusado');

$GLOBALS['config'] = ['summa' => ['secret' => 'curto']];
check(!summa_enabled(), 'segredo com menos de 32 caracteres não liga a integração');
$GLOBALS['config'] = ['summa' => ['secret' => $secret, 'url' => '..']];
check(summa_enabled() && summa_url('workspace') === '../index.php?r=workspace', 'integração ligada monta os endereços do Summa');
$GLOBALS['config'] = [];
check(!summa_enabled(), 'sem o bloco summa o sistema funciona sozinho');

$sources = (string)file_get_contents(__DIR__ . '/../index.php') . file_get_contents(__DIR__ . '/../app/actions.php') . file_get_contents(__DIR__ . '/../templates/layout.php');
check(str_contains($sources, "summa_sso_login(\$_GET['sso'])") && str_contains($sources, 'summa_logout_url()') && str_contains($sources, "summa_url('workspace')"), 'entrada, saída e "Todos os módulos" passam pelo Summa');
check(str_contains($sources, 'summa_manages_users()'), 'criação de usuários fica bloqueada no modo módulo');

if ($failures) exit(1);
echo "Total: 15 verificações do login único aprovadas.\n";
