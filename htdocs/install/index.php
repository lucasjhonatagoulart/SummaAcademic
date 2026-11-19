<?php
// Instalador da plataforma: cria as tabelas, o administrador e (opcional) dados de exemplo.
// Depois de instalar, APAGUE esta pasta (install/) pelo gerenciador de arquivos.
date_default_timezone_set('America/Sao_Paulo');
mb_internal_encoding('UTF-8');
$root = dirname(__DIR__);
$cfgFile = $root . '/config.php';
require $root . '/app/brand.php';
require $root . '/app/perms.php';
require $root . '/app/domain.php';

function e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function page($body) {
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Instalar ' . APP_NAME . '</title><link rel="icon" href="../assets/brand/favicon-64.png">'
        . '<link rel="stylesheet" href="../assets/app.css"><link rel="stylesheet" href="../assets/extra.css"></head><body><div class="install">'
        . '<div style="margin-bottom:18px"><img src="../assets/brand/logo-fundo-claro.png" alt="' . BRAND['full'] . '" style="height:' . max(48, (int) round(BRAND['login_logo_h'] * .75)) . 'px;width:auto"><p class="muted" style="font-size:13px;margin-top:4px">' . (APP_SLOGAN ? APP_SLOGAN . ' · ' : '') . 'Instalação</p></div>' . $body . '</div></body></html>';
    exit;
}

if (is_file($cfgFile)) {
    define('CONFIG', require $cfgFile);
    require $root . '/app/db.php';
    try { $n = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn(); } catch (Throwable $e) { $n = 0; }
    if ($n > 0) page('<div class="alert a-ok"><div><strong>O ' . APP_NAME . ' já está instalado.</strong>Por segurança, apague a pasta <b>install/</b> no gerenciador de arquivos. <a href="../index.php">Ir para a tela de entrada</a>.</div></div>');
}

$f = array_merge(['db_host' => 'sql200.infinityfree.com', 'db_name' => 'if0_43405800_Academic', 'db_user' => 'if0_43405800', 'db_pass' => '', 'inst_name' => 'Centro Universitário de Demonstração', 'inst_short' => 'UNIDEMO',
    'inst_city' => 'São Paulo', 'inst_state' => 'SP', 'admin_name' => 'Administrador do Sistema', 'admin_email' => 'admin@academus.dev', 'admin_pass' => 'Aquinosys', 'demo' => '1'], $_POST);
if ($_SERVER['REQUEST_METHOD'] === 'POST') $f['demo'] = isset($_POST['demo']) ? '1' : '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    $driver = $local && ($_POST['driver'] ?? '') === 'sqlite' ? 'sqlite' : 'mysql';
    $cfg = ['debug' => false, 'db' => $driver === 'sqlite' ? ['driver' => 'sqlite', 'path' => $root . '/../academus.sqlite'] : ['driver' => 'mysql', 'host' => trim($f['db_host']), 'port' => 3306, 'name' => trim($f['db_name']), 'user' => trim($f['db_user']), 'pass' => $f['db_pass']]];
    if (!filter_var($f['admin_email'], FILTER_VALIDATE_EMAIL)) $err = 'Informe um e-mail válido para o administrador.';
    elseif (mb_strlen($f['admin_pass']) < 8) $err = 'A senha do administrador precisa ter pelo menos 8 caracteres.';
    elseif (trim($f['inst_name']) === '' || trim($f['inst_short']) === '') $err = 'Informe o nome e a sigla da instituição.';
    if (!$err) {
        if (!defined('CONFIG')) define('CONFIG', $cfg);
        require_once $root . '/app/db.php';
        require_once $root . '/app/install_lib.php';
        if (!function_exists('now')) { function now() { return date('Y-m-d H:i:s'); } }
        try {
            db();
            install_run($f);
            $php = "<?php\n// Gerado pelo instalador em " . date('d/m/Y H:i') . "\nreturn " . var_export($cfg, true) . ";\n";
            $saved = @file_put_contents($cfgFile, $php) !== false;
            page('<div class="alert a-ok"><div><strong>Instalação concluída.</strong>Entre com <b>' . e($f['admin_email']) . '</b> e a senha que você definiu.' . (!empty($f['demo']) ? ' Os dados de exemplo usam a senha <b>Academus@2026</b> (secretaria@, diretoria@, financeiro@, professor@ e aluno@academus.dev).' : '') . '</div></div>'
                . ($saved ? '' : '<div class="alert a-warn" style="margin-top:12px"><div><strong>Não consegui gravar o config.php.</strong>Crie o arquivo <b>config.php</b> na pasta principal (ao lado do index.php) com este conteúdo:<pre style="white-space:pre-wrap;font-size:12px">' . e($php) . '</pre></div></div>')
                . '<div class="alert a-bad" style="margin-top:12px"><div><strong>Importante:</strong> apague agora a pasta <b>install/</b> no gerenciador de arquivos.</div></div><p style="margin-top:16px"><a class="btn btn-primary" href="../index.php">Ir para a tela de entrada</a></p>');
        } catch (Throwable $ex) {
            $err = 'Não foi possível instalar: ' . $ex->getMessage();
        }
    }
}

$in = fn($k, $label, $type = 'text', $hint = '') => '<div class="field"><label for="' . $k . '">' . $label . '</label><input class="input" id="' . $k . '" name="' . $k . '" type="' . $type . '" value="' . e($f[$k]) . '" required>' . ($hint ? '<small>' . $hint . '</small>' : '') . '</div>';
page('<h1 style="font-size:24px;color:var(--navy);margin-bottom:6px">Instalar o ' . APP_NAME . '</h1><p class="muted" style="margin-bottom:18px">No painel do InfinityFree, crie um banco MySQL (MySQL Databases) e copie os dados de conexão para os campos abaixo.</p>'
    . ($err ? '<div class="alert a-bad" style="margin-bottom:16px"><div>' . e($err) . '</div></div>' : '')
    . '<form method="post" class="stack"><div class="card"><div class="card-head"><div><h3>Banco de dados MySQL</h3><p>Em “MySQL Databases” no painel do InfinityFree.</p></div></div><div class="card-body"><div class="form-grid cols-2">'
    . $in('db_host', 'Servidor (MySQL Hostname)', 'text', 'Ex.: sql123.infinityfree.com') . $in('db_name', 'Nome do banco (MySQL DB Name)') . $in('db_user', 'Usuário (MySQL Username)') . str_replace(' required', '', $in('db_pass', 'Senha (MySQL Password)', 'password', 'A senha da sua conta de hospedagem.'))
    . '</div></div></div><div class="card"><div class="card-head"><div><h3>Instituição</h3></div></div><div class="card-body"><div class="form-grid cols-2">'
    . $in('inst_name', 'Nome da instituição') . $in('inst_short', 'Sigla') . $in('inst_city', 'Cidade') . $in('inst_state', 'UF')
    . '</div></div></div><div class="card"><div class="card-head"><div><h3>Administrador</h3><p>Perfil Administrador já criado, com acesso a tudo.</p></div></div><div class="card-body"><div class="form-grid cols-2">'
    . $in('admin_name', 'Nome') . $in('admin_email', 'E-mail de acesso', 'email') . $in('admin_pass', 'Senha', 'text', 'Troque depois em “Alterar senha”.')
    . '</div><label class="check-field" style="margin-top:14px"><input type="checkbox" name="demo" value="1"' . (!empty($f['demo']) ? ' checked' : '') . '><span><strong>Carregar dados de exemplo</strong><span class="muted small">Cursos, turmas, professores, alunos, notas e mensalidades fictícios, para conhecer o sistema.</span></span></label>'
    . '</div></div>' . (isset($_GET['sqlite']) ? '<input type="hidden" name="driver" value="sqlite">' : '') . '<button class="btn btn-primary btn-lg" type="submit">Instalar</button></form>');
