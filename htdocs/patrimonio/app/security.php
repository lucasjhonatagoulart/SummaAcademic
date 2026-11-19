<?php

declare(strict_types=1);

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Método não permitido.');
    }
}

function verify_csrf_header_or_form(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    verify_csrf(is_string($token) ? $token : '');
}

function admin_permission_definitions(): array
{
    return [
        'edit_invoices' => 'Cadastrar, revisar e incorporar NF-e/importar XML',
        'edit_assets' => 'Editar ativos e registrar baixas; exclusões também exigem gestão de usuários',
        'manage_catalogs' => 'Gerenciar contas patrimoniais e locais, inclusive ao editar ativos',
        'manage_users' => 'Criar usuários e definir permissões',
        'export_data' => 'Exportar relatórios filtrados, planilhas, CSV, XML e backup completo',
        'restore_backup' => 'Restaurar o backup em uma base vazia',
    ];
}

function current_admin_permission_names(): array
{
    if (!is_logged_in()) return [];
    static $cache = [];
    $adminId = (int)$_SESSION['admin_id'];
    if (array_key_exists($adminId, $cache)) return $cache[$adminId];

    $allowed = admin_permission_definitions();
    $statement = db()->prepare('SELECT permission FROM admin_permissions WHERE admin_id = ?');
    $statement->execute([$adminId]);
    $permissions = [];
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $permission) {
        $permission = (string)$permission;
        if (isset($allowed[$permission])) $permissions[$permission] = true;
    }
    $cache[$adminId] = $permissions;
    return $permissions;
}

function can(string $permission): bool
{
    if (!array_key_exists($permission, admin_permission_definitions())) return false;
    return isset(current_admin_permission_names()[$permission]);
}

function can_restore_backup(): bool
{
    foreach (['restore_backup', 'edit_assets', 'edit_invoices', 'manage_catalogs'] as $permission) {
        if (!can($permission)) return false;
    }
    return true;
}

function can_grant_permission(string $permission): bool
{
    return $permission === 'restore_backup' ? can_restore_backup() : can($permission);
}

function require_restore_permissions(): void
{
    foreach (['restore_backup', 'edit_assets', 'edit_invoices', 'manage_catalogs'] as $permission) require_permission($permission);
}

function denied_response(string $message = 'Esta conta não tem permissão para abrir esta página ou executar esta operação.'): never
{
    http_response_code(403);
    if (isset($_GET['api']) || isset($_POST['api'])) {
        json_response(['error' => $message], 403);
    }
    $content = '<section class="panel"><div class="panel-body"><h1>Acesso restrito</h1><p>' . h($message) . '</p><a class="button" href="index.php">Voltar ao painel</a></div></section>';
    if (function_exists('render_template') && is_logged_in()) {
        echo render_template('layout.php', ['title' => 'Acesso restrito', 'page' => 'forbidden', 'content' => $content, 'flash' => null]);
    } else {
        echo function_exists('auth_page')
            ? auth_page('Acesso restrito', '<div><p class="eyebrow">Acesso restrito</p><h1>Sem permissão</h1></div><p>Esta conta não tem permissão para esta operação.</p><a class="button primary" href="index.php">Voltar</a>')
            : '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Acesso restrito</title><p>Esta conta não tem permissão para esta operação.</p></html>';
    }
    exit;
}

function require_permission(string $permission): void
{
    require_login();
    if (!array_key_exists($permission, admin_permission_definitions()) || !can($permission)) denied_response();
}

function validate_permission_selection(mixed $input): array
{
    if ($input === null || $input === '') return [];
    if (!is_array($input)) throw new RuntimeException('A lista de permissões está inválida.');
    $allowed = admin_permission_definitions();
    $selected = [];
    foreach ($input as $permission) {
        if (!is_string($permission) || !array_key_exists($permission, $allowed)) throw new RuntimeException('A lista contém uma permissão desconhecida.');
        if (!can_grant_permission($permission)) throw new RuntimeException('Você não pode conceder uma permissão que não possui. A restauração também exige todas as permissões de escrita.');
        $selected[$permission] = true;
    }
    validate_permission_dependencies(array_keys($selected));
    return array_keys($selected);
}

function validate_permission_dependencies(array $selected): void
{
    $set = array_fill_keys($selected, true);
    if (!isset($set['restore_backup'])) return;
    foreach (['edit_assets','edit_invoices','manage_catalogs'] as $required) {
        if (!isset($set[$required])) throw new RuntimeException('A permissão de restaurar backup também exige editar ativos, editar NF-e e gerenciar contas patrimoniais/locais.');
    }
}

function validate_admin_username(mixed $value): string
{
    if (!is_string($value)) throw new RuntimeException('Informe um nome de usuário válido.');
    $username = trim($value);
    if (!preg_match('/^[a-zA-Z0-9_.@-]{3,80}$/', $username)) throw new RuntimeException('Use um usuário de 3 a 80 caracteres: letras, números, ponto, @, _ ou hífen.');
    return $username;
}

function admin_password_character_count(string $password): int
{
    $count = preg_match_all('/./us', $password);
    if ($count === false) throw new RuntimeException('A senha contém uma sequência inválida de caracteres.');
    return $count;
}

function validate_admin_credentials(mixed $usernameInput, mixed $passwordInput, mixed $confirmationInput): array
{
    $username = validate_admin_username($usernameInput);
    if (!is_string($passwordInput) || !is_string($confirmationInput)) throw new RuntimeException('Informe uma senha e sua confirmação.');
    if (admin_password_character_count($passwordInput) < 8) throw new RuntimeException('A senha deve ter pelo menos 8 caracteres.');
    if (strlen($passwordInput) > 72) throw new RuntimeException('A senha não pode ultrapassar 72 bytes.');
    if (!hash_equals($passwordInput, $confirmationInput)) throw new RuntimeException('A confirmação da senha não confere.');
    return ['username' => $username, 'password' => $passwordInput];
}

function authenticate_admin(string $username, string $password): bool
{
    $pdo = db();
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ipHash = hash('sha256', $ip);
    $now = new DateTimeImmutable('now');
    $rate = $pdo->prepare('SELECT attempts, last_attempt, blocked_until FROM login_attempts WHERE ip_hash = ?');
    $rate->execute([$ipHash]);
    $entry = $rate->fetch();
    if ($entry && !empty($entry['blocked_until']) && strtotime((string)$entry['blocked_until']) > time()) {
        throw new RuntimeException('Muitas tentativas. Aguarde 15 minutos e tente novamente.');
    }

    $statement = $pdo->prepare('SELECT id, username, password_hash FROM admins WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    $admin = $statement->fetch();
    $valid = $admin && password_verify($password, (string)$admin['password_hash']);
    if (!$valid) {
        $attempts = $entry && strtotime((string)$entry['last_attempt']) > time() - 900 ? (int)$entry['attempts'] + 1 : 1;
        $blocked = $attempts >= 8 ? $now->modify('+15 minutes')->format('Y-m-d H:i:s') : null;
        $save = $pdo->prepare('INSERT INTO login_attempts (ip_hash, attempts, last_attempt, blocked_until) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt = VALUES(last_attempt), blocked_until = VALUES(blocked_until)');
        $save->execute([$ipHash, $attempts, $now->format('Y-m-d H:i:s'), $blocked]);
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['id'];
    $_SESSION['admin_name'] = (string)$admin['username'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?')->execute([(int)$admin['id']]);
    $pdo->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$ipHash]);
    return true;
}

function logout_admin(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}
