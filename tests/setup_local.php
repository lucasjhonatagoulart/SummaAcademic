<?php
// Cria um banco SQLite local com dados de exemplo para testar (não vai para o servidor).
$root = dirname(__DIR__) . '/htdocs';
$db = __DIR__ . '/test.sqlite';
@unlink($db);
file_put_contents($root . '/config.php', "<?php\nreturn " . var_export(['debug' => true, 'db' => ['driver' => 'sqlite', 'path' => $db], 'patrimonio_sso_secret' => str_repeat('s', 64), 'patrimonio_url' => 'patrimonio/'], true) . ";\n");
define('CONFIG', require $root . '/config.php');
date_default_timezone_set('America/Sao_Paulo');
foreach (['perms', 'domain', 'db', 'helpers', 'install_lib'] as $f) require "$root/app/$f.php";
install_run(['inst_name' => 'Centro Universitário de Demonstração', 'inst_short' => 'UNIDEMO', 'inst_city' => 'São Paulo', 'inst_state' => 'SP', 'admin_name' => 'Administrador do Sistema', 'admin_email' => 'admin@academus.dev', 'admin_pass' => 'Aquinosys', 'demo' => '1', 'director' => 'Dra. Helena Prado', 'secretary' => 'Mariana Souza']);
foreach (['users', 'students', 'sections', 'enrollments', 'lessons', 'attendance', 'grades', 'charges', 'announcements'] as $t) echo "$t: " . val("SELECT COUNT(*) FROM $t") . "  ";
echo "\n";
