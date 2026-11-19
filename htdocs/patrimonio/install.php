<?php

declare(strict_types=1);

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Crie config.php a partir de config.example.php antes da instalação.');
}
$config = require $configFile;
if (!is_array($config) || empty($config['db_host']) || empty($config['db_name']) || empty($config['db_user'])) {
    http_response_code(500);
    exit('Configure os dados do MySQL em config.php.');
}
require_once __DIR__ . '/app/ui.php';
require_once __DIR__ . '/app/security.php';

$isHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
ini_set('session.use_strict_mode', '1');
session_name('ativa_install');
session_start();
$token = $_SESSION['install_csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$done = false;
$pdo = null;

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db_host'] . ';dbname=' . $config['db_name'] . ';charset=utf8mb4',
        $config['db_user'],
        (string)($config['db_pass'] ?? ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    try {
        $done = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
    } catch (Throwable) {
        $done = false;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$done) {
        if (!hash_equals($token, (string)($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('A sessão expirou. Recarregue e tente novamente.');
        }

        $credentials = validate_admin_credentials($_POST['username'] ?? null, $_POST['password'] ?? null, $_POST['password_confirm'] ?? null);
        $username = $credentials['username'];
        $password = $credentials['password'];

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('O arquivo schema.sql não foi encontrado.');
        }
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $pdo->exec($statement);
        }

        // Serializa a primeira criação para impedir que dois POSTs concorrentes
        // registrem duas contas iniciais em um banco ainda vazio.
        $lockName = 'ativa-first-admin-' . substr(hash('sha256', (string)$config['db_name']), 0, 44);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            throw new RuntimeException('Não foi possível reservar a configuração inicial. Recarregue a página e tente novamente.');
        }

        $created = false;
        try {
            $done = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() > 0;
            if (!$done) {
                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
                    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
                    $adminId = (int)$pdo->lastInsertId();
                    $pdo->prepare('INSERT INTO admin_permission_seeded (admin_id) VALUES (?)')->execute([$adminId]);
                    $grant = $pdo->prepare('INSERT INTO admin_permissions (admin_id, permission) VALUES (?, ?)');
                    foreach (array_keys(admin_permission_definitions()) as $permission) $grant->execute([$adminId, $permission]);
                    $pdo->commit();
                    $created = true;
                    $done = true;
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
            }
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }

        if ($created) {
            $_SESSION = [];
            session_destroy();
            header('Location: index.php?page=login&installed=1', true, 303);
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($done) {
    header('Location: index.php?page=login', true, 303);
    exit;
}

header('Cache-Control: no-store, private');
?><!doctype html>
<html lang="pt-BR">
<head>
    <?= head_assets() ?>
    <title>Instalar · <?= APP_TITLE ?></title>
</head>
<body>
<div class="login">
    <?= auth_brand_panel() ?>
    <section class="login-form-wrap">
        <main class="login-box">
            <?= auth_mobile_logo() ?>
            <div>
                <p class="eyebrow">Instalação segura</p>
                <h1>Configurar o sistema</h1>
            </div>
            <?php if ($done): ?>
                <div class="alert success">Já existe uma conta administradora. O instalador está bloqueado e não pode criar outra conta.</div>
                <a class="button primary" href="index.php?page=login">Ir para o login</a>
            <?php else: ?>
                <p>Crie a primeira conta administradora. Depois dela, o acesso ao sistema será somente por usuários cadastrados.</p>
                <div class="alert">Antes de criar a primeira conta, proteja temporariamente o site inteiro com o recurso Protected Directories do painel InfinityFree. Remova essa proteção depois que o cadastro terminar.</div>
                <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                    <label class="field"><span>Usuário administrador</span><input type="text" name="username" minlength="3" maxlength="80" required autocomplete="username"></label>
                    <label class="field"><span>Senha nova (mínimo 8 caracteres)</span><input type="password" name="password" minlength="8" maxlength="72" required autocomplete="new-password"><small>Recomendamos 12 ou mais caracteres. O limite técnico é de 72 bytes para evitar truncamento pelo algoritmo de hash usado no PHP.</small></label>
                    <label class="field"><span>Confirmar senha</span><input type="password" name="password_confirm" minlength="8" maxlength="72" required autocomplete="new-password"></label>
                    <button class="button primary" type="submit">Criar primeira conta e instalar</button>
                </form>
                <p class="tiny-note">Esta página só permite criar a primeira conta quando o banco não tem administradores. Depois do cadastro, o instalador ficará bloqueado.</p>
            <?php endif; ?>
            <?= appearance_panel() ?>
        </main>
    </section>
</div>
</body>
</html>
