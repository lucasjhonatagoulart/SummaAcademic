<?php
// Ponto de entrada da plataforma. Todas as telas passam por aqui: index.php?r=<rota>
require __DIR__ . '/app/bootstrap.php';
start_session();

$r = (string) ($_GET['r'] ?? '');
if ($r === '') { $u = current_user(); go($u ? home_route($u) : 'login'); }
if (!preg_match('/^[a-z_]+(\.[a-z_]+)?$/', $r)) abort(404);
$GLOBALS['ROUTE'] = $r;

if (is_post() && !csrf_ok()) { flash('bad', 'A página ficou aberta por muito tempo. Tente de novo.'); header('Location: ' . url($r === 'login' ? 'login' : $r)); exit; }

$public = ['login', 'esqueci', 'verificar'];
if (!in_array($r, $public, true)) {
    $u = current_user();
    if (!$u) { $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? ''; go('login'); }
    // Senha provisória: primeiro cria a própria senha
    if ($u['must_change_password'] && !in_array($r, ['senha', 'logout'], true)) go('senha');
    $prefix = explode('.', $r)[0];
    if (str_contains($r, '.') && isset(PORTAL_LABEL[$prefix])) {
        if (!in_array($prefix, $u['portals'], true)) abort(403);
        $_SESSION['portal'] = $prefix;
    }
}

$fn = 'route_' . str_replace('.', '__', $r); $arg = [];
// Módulos por integração: index.php?r=modulo.<slug> (entrar) e index.php?r=modulo_sair.<slug> (sair)
if (preg_match('/^(modulo|modulo_sair)\.([a-z_]+)$/', $r, $mm)) { $fn = 'route_' . $mm[1]; $arg = [$mm[2]]; }
if (!function_exists($fn)) abort(404);
try { $fn(...$arg); }
catch (Throwable $e) {
    error_log('[academus] ' . $e);
    if (!empty(CONFIG['debug'])) throw $e;
    abort(500, 'Algo deu errado. Tente de novo; se continuar, avise o administrador.');
}
