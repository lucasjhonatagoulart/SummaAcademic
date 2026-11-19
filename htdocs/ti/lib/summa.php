<?php
/* Integração com a plataforma Summa (login único).
   O módulo só entra em "modo módulo" quando a pasta tem summa-client.php (copiado do kit do Summa)
   e summa.secret.php (gerado pelo Summa ao incluir o módulo). Sem eles, funciona sozinho, com login próprio. */

const SUMMA_PERM_ROLE  = ['admin' => 'admin', 'tecnico' => 'tecnico', 'diretor' => 'diretor', 'solicitar' => 'solicitante'];
const SUMMA_ROLE_ORDEM = ['solicitante' => 1, 'diretor' => 2, 'tecnico' => 3, 'admin' => 4];

function modo_modulo() {
    return !cfg('forcar_independente')
        && is_file(__DIR__ . '/../summa-client.php')
        && is_file(__DIR__ . '/../summa.secret.php');
}

function summa_url() { return cfg('summa_url') ?: '../index.php'; }

/** Endereço de "Todos os módulos": a tela de módulos do Summa (o início do Summa devolveria ao módulo quem só tem este liberado) */
function summa_modulos_url() {
    if (cfg('summa_url')) return cfg('summa_url');
    return function_exists('summa_workspace_url') ? summa_workspace_url() : '../index.php?r=workspace';
}

/** Logo do Summa para o menu, se o Summa estiver na pasta de cima */
function summa_logo() {
    return is_file(__DIR__ . '/../../assets/brand/logo-fundo-escuro.png') ? '../assets/brand/logo-fundo-escuro.png' : '';
}

function pagina_aviso($titulo, $msg, $codigo = 403) {
    http_response_code($codigo);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . htmlspecialchars($titulo) . '</title></head><body style="font:16px/1.5 Arial,sans-serif;max-width:460px;margin:12vh auto;padding:0 20px;color:#172B4D"><h1 style="font-size:20px">'
        . htmlspecialchars($titulo) . '</h1><p>' . htmlspecialchars($msg) . '</p><p><a href="' . htmlspecialchars(summa_url()) . '" style="color:#1F68B8">Voltar ao Summa</a></p></body></html>';
    exit;
}

/** Valida a sessão no Summa, cria ou atualiza a conta local e abre a sessão do módulo. Devolve a conta ou encerra a página. */
function modulo_entrar() {
    require_once __DIR__ . '/../summa-client.php';
    $info = summa_require_login();   // sem sessão no Summa: redireciona para o login e encerra
    /* O cliente do Summa guarda o acesso em uma sessão só dele. Ela é fechada aqui para o módulo abrir a própria
       (PTISESS), que é a que a api.php lê. O identificador é informado à parte: sem isso o PHP reaproveitaria o da sessão
       do cliente e as duas passariam a dividir os mesmos dados. */
    session_write_close();
    $sid = isset($_COOKIE['PTISESS']) ? (string)$_COOKIE['PTISESS'] : '';
    session_id(preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid) ? $sid : session_create_id());

    $email = is_array($info) ? (string)($info['email'] ?? '') : '';
    $nome = is_array($info) ? (string)($info['name'] ?? '') : '';
    $permissoes = is_array($info) && is_array($info['perms'] ?? null) ? $info['perms'] : [];
    $foto = is_array($info) ? (string)($info['avatar'] ?? '') : '';
    $email = strtolower(trim((string)$email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        pagina_aviso('Não foi possível identificar você', 'O Summa não informou um e-mail válido para este acesso.', 500);
    }
    if (tam(trim((string)$nome)) < 3) $nome = ucfirst(explode('@', $email)[0]);
    $nome = mb_substr(trim((string)$nome), 0, 120, 'UTF-8');

    $role = null;
    foreach (SUMMA_PERM_ROLE as $codigo => $papel) {
        if (in_array($codigo, $permissoes, true) && (!$role || SUMMA_ROLE_ORDEM[$papel] > SUMMA_ROLE_ORDEM[$role])) $role = $papel;
    }
    if (!$role) pagina_aviso('Sem acesso ao Setor de TI', 'Peça ao administrador do Summa para liberar este módulo para você.');

    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM accounts WHERE email = ?');
    $st->execute([$email]);
    $c = $st->fetch();
    if ($c) {
        $id = (int)$c['id'];
        $pdo->prepare('UPDATE accounts SET nome = ?, role = ?, ativo = 1, trocar_senha = 0, ultimo_acesso = ? WHERE id = ?')->execute([$nome, $role, agora(), $id]);
    } else {
        /* senha aleatória: quem entra pelo Summa não usa senha local */
        $pdo->prepare('INSERT INTO accounts (nome, email, senha_hash, role, ativo, trocar_senha, criado_em, ultimo_acesso) VALUES (?, ?, ?, ?, 1, 0, ?, ?)')
            ->execute([$nome, $email, password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT), $role, agora(), agora()]);
        $id = (int)$pdo->lastInsertId();
    }
    versao_subir('users');

    iniciar_sessao();
    if (empty($_SESSION['uid']) || (int)$_SESSION['uid'] !== $id) session_regenerate_id(true);
    $_SESSION['uid'] = $id;
    $_SESSION['mod'] = 1;
    $_SESSION['foto'] = $foto;
    $_SESSION['ult'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return usuario_atual();
}

/** Encerra o módulo e o Summa. */
function modulo_sair($token) {
    iniciar_sessao();
    if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) { header('Location: index.php'); exit; }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    require_once __DIR__ . '/../summa-client.php';
    summa_logout();                  // normalmente redireciona e encerra
    header('Location: ' . summa_url());
    exit;
}
