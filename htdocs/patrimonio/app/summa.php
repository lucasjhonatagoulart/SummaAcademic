<?php

declare(strict_types=1);

/*
 * Integração com a plataforma Summa (modo módulo).
 *
 * O Summa autentica o usuário e, na rota index.php?r=patrimonio, gera um token curto assinado
 * (HMAC-SHA256 com o segredo compartilhado `summa.secret`). O Patrimônio confere o token,
 * cria ou atualiza o usuário local com o mesmo nome e aplica as permissões que vieram do Summa.
 * Sem a chave `summa` no config.php, o sistema funciona sozinho, como antes.
 */

const SUMMA_MODULE_NAME = 'Summa Patrimônio';
const SUMMA_TOKEN_MAX_AGE = 120;      // segundos de validade do token
const SUMMA_IDLE_TIMEOUT = 7200;      // mesma inatividade máxima do Summa (2 h)

function summa_config(): ?array
{
    global $config;
    $summa = is_array($config['summa'] ?? null) ? $config['summa'] : null;
    // Sem o bloco 'summa' no config.php, vale o arquivo app/summa.secret.php (liga o módulo sem editar o config.php)
    $file = __DIR__ . '/summa.secret.php';
    if (!$summa && is_file($file)) { $extra = require $file; if (is_array($extra)) $summa = $extra; }
    if (!$summa) return null;
    $secret = (string)($summa['secret'] ?? '');
    if (strlen($secret) < 32) return null;
    $base = (string)($summa['url'] ?? '../');
    if ($base !== '' && !str_ends_with($base, '/')) $base .= '/';
    return ['secret' => $secret, 'url' => $base, 'local_login' => !empty($summa['local_login'])];
}

function summa_enabled(): bool { return summa_config() !== null; }

/** Endereço de uma rota do Summa (index.php?r=...). */
function summa_url(string $route): string
{
    $cfg = summa_config();
    return ($cfg['url'] ?? '../') . 'index.php?' . http_build_query(['r' => $route]);
}

/** Caminho de imagem aceito no acesso: só arquivos de imagem dentro de uploads/ do Summa */
function summa_avatar_path(mixed $path): string
{
    return is_string($path) && preg_match('#^uploads/[A-Za-z0-9_][A-Za-z0-9_./-]{0,180}\.(?:jpe?g|png|webp)$#', $path)
        && !str_contains($path, '..') && !str_contains($path, '//') ? $path : '';
}

/** Endereço de um arquivo da marca do Summa (assets/brand), ou '' se o Summa não estiver na pasta de cima */
function summa_brand(string $file): string
{
    $cfg = summa_config();
    if (!$cfg || $cfg['url'] !== '../' || !preg_match('/^[a-z0-9-]+\.(?:png|ico)$/', $file)) return '';
    return is_file(dirname(__DIR__, 2) . '/assets/brand/' . $file) ? '../assets/brand/' . $file : '';
}

/** Endereço de saída: leva uma assinatura curta para o Summa aceitar encerrar a sessão dele também. */
function summa_logout_url(?int $now = null): string
{
    $cfg = summa_config();
    if (!$cfg) return 'index.php?page=login';
    $ts = (string)($now ?? time());
    $sig = summa_b64url_encode(hash_hmac('sha256', 'sair|' . $ts, $cfg['secret'], true));
    return $cfg['url'] . 'index.php?' . http_build_query(['r' => 'patrimonio_sair', 't' => $ts, 's' => $sig]);
}

function summa_b64url_decode(string $value): string|false
{
    if ($value === '' || preg_match('/[^A-Za-z0-9_-]/', $value)) return false;
    return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
}

function summa_b64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Confere assinatura, validade e formato do token. Função pura (sem banco), para teste.
 * Retorna as declarações: sub, u (usuário), n (nome), p (permissões), iat, exp, jti.
 */
function summa_sso_decode(string $token, string $secret, ?int $now = null): array
{
    $now ??= time();
    if (strlen($token) > 4096 || substr_count($token, '.') !== 1) throw new RuntimeException('Acesso pelo Summa inválido.');
    [$body, $signature] = explode('.', $token, 2);
    $expected = summa_b64url_encode(hash_hmac('sha256', $body, $secret, true));
    if (!hash_equals($expected, $signature)) throw new RuntimeException('Acesso pelo Summa com assinatura inválida.');
    $json = summa_b64url_decode($body);
    $claims = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($claims)) throw new RuntimeException('Acesso pelo Summa inválido.');
    if (($claims['aud'] ?? '') !== 'patrimonio') throw new RuntimeException('Este acesso não foi emitido para o Patrimônio.');
    $iat = (int)($claims['iat'] ?? 0);
    $exp = (int)($claims['exp'] ?? 0);
    if ($exp < $now || $iat > $now + 60 || $exp - $iat > SUMMA_TOKEN_MAX_AGE) throw new RuntimeException('O acesso pelo Summa expirou. Abra o módulo de novo.');
    $username = trim((string)($claims['u'] ?? ''));
    $jti = (string)($claims['jti'] ?? '');
    if ($username === '' || utf8_length($username) > 80 || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $jti)) throw new RuntimeException('Acesso pelo Summa incompleto.');
    $perms = $claims['p'] ?? [];
    if (!is_array($perms)) $perms = [];
    $known = admin_permission_definitions();
    $claims['p'] = array_values(array_unique(array_filter(array_map('strval', $perms), static fn(string $p): bool => isset($known[$p]))));
    $claims['u'] = $username;
    $claims['n'] = trim((string)($claims['n'] ?? '')) ?: $username;
    return $claims;
}

/** Impede que o mesmo token seja usado duas vezes. */
function summa_consume_token_id(string $jti, int $exp): void
{
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS summa_sso_used (jti VARCHAR(64) NOT NULL PRIMARY KEY, expires_at INT NOT NULL)');
    $pdo->prepare('DELETE FROM summa_sso_used WHERE expires_at < ?')->execute([time() - 86400]);
    try {
        $pdo->prepare('INSERT INTO summa_sso_used (jti, expires_at) VALUES (?, ?)')->execute([$jti, $exp]);
    } catch (PDOException) {
        throw new RuntimeException('Este acesso já foi usado. Abra o módulo de novo pelo Summa.');
    }
}

/** Entra com o usuário do Summa: cria ou atualiza a conta local e copia as permissões. */
function summa_sso_login(string $token): void
{
    $cfg = summa_config();
    if (!$cfg) throw new RuntimeException('A integração com o Summa não está configurada.');
    $claims = summa_sso_decode($token, $cfg['secret']);
    summa_consume_token_id((string)$claims['jti'], (int)$claims['exp']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
        $find->execute([$claims['u']]);
        $adminId = (int)($find->fetchColumn() ?: 0);
        if (!$adminId) {
            // Conta criada pelo Summa: senha aleatória, porque o acesso é sempre pela plataforma.
            $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)')
                ->execute([$claims['u'], password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
            $adminId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO admin_permission_seeded (admin_id) VALUES (?)')->execute([$adminId]);
        }
        $pdo->prepare('DELETE FROM admin_permissions WHERE admin_id = ?')->execute([$adminId]);
        $grant = $pdo->prepare('INSERT INTO admin_permissions (admin_id, permission) VALUES (?, ?)');
        foreach ($claims['p'] as $permission) $grant->execute([$adminId, $permission]);
        $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([$adminId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    session_regenerate_id(true);
    $_SESSION = [
        'admin_id' => $adminId,
        'admin_name' => (string)$claims['n'],
        'admin_avatar' => ($a = summa_avatar_path($claims['a'] ?? '')) !== '' ? $cfg['url'] . $a : '',
        'csrf' => bin2hex(random_bytes(32)),
        'via_summa' => true,
        'summa_seen' => time(),
    ];
}

/** Sessão aberta pelo Summa expira após 2 h sem uso, como no Summa. */
function summa_enforce_idle_timeout(): void
{
    if (empty($_SESSION['via_summa'])) return;
    if (time() - (int)($_SESSION['summa_seen'] ?? 0) > SUMMA_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['summa_seen'] = time();
}

/** Usuários e permissões são definidos no Summa quando o módulo está integrado. */
function summa_manages_users(): bool { return summa_enabled(); }
