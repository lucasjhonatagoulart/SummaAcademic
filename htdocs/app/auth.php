<?php
// Sessão, usuário atual, permissões efetivas e portais.

function start_session(): void {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('academus');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    // Sessão expira após 2 h sem uso
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 7200) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
}

/** Instituição em uso nesta sessão: a escolhida no menu do perfil (se o usuário tem acesso) ou a de vínculo dele.
 *  Antes do login, a instituição padrão da plataforma. */
function inst_id(): int {
    static $id = null, $for = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($id !== null && $for === $uid) return $id;
    $for = $uid;
    if ($uid) {
        $home = (int) val('SELECT institution_id FROM users WHERE id = ?', [$uid]);
        $want = (int) ($_SESSION['inst'] ?? 0);
        if ($want && $want !== $home && in_array($want, accessible_institution_ids($uid), true)) return $id = $want;
        if ($home) return $id = $home;
    }
    return $id = (int) (setting('active_institution_id') ?: val('SELECT MIN(id) FROM institutions'));
}
/** Instituições que o usuário pode usar: todas para Acesso total ou quem administra a plataforma; senão a de vínculo e as extras */
function accessible_institution_ids(int $uid): array {
    $acc = user_access($uid);
    if (in_array('ADMIN', $acc['roles'], true) || in_array('platform:manage', $acc['perms'], true)) return array_map('intval', col('SELECT id FROM institutions ORDER BY name'));
    $home = (int) val('SELECT institution_id FROM users WHERE id = ?', [$uid]);
    return array_values(array_unique(array_merge([$home], array_map('intval', col('SELECT institution_id FROM user_institutions WHERE user_id = ?', [$uid])))));
}
/** Trecho SQL: o usuário (alias $a) pertence à instituição em uso, pelo vínculo ou por acesso extra. Use com inst_params(). */
function user_in_inst(string $a = 'u'): string { return "($a.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = $a.id AND ui.institution_id = ?))"; }
function inst_params(): array { return [inst_id(), inst_id()]; }
function institution(): array {
    static $i = null;
    return $i ??= row('SELECT * FROM institutions WHERE id = ?', [inst_id()]) ?? ['name' => 'Instituição', 'short_name' => APP_NAME, 'logo_path' => null];
}

/** Perfis e permissões de um usuário (perfis ∪ liberadas − bloqueadas) */
function user_access(int $uid): array {
    $roles = col('SELECT r.kind FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$uid]);
    $rolePerms = in_array('ADMIN', $roles, true) ? array_keys(perm_catalog())
        : col('SELECT DISTINCT rp.perm FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id WHERE ur.user_id = ?', [$uid]);
    $over = array_column(rows('SELECT perm, effect FROM user_permissions WHERE user_id = ?', [$uid]), 'effect', 'perm');
    return ['roles' => $roles, 'role_perms' => $rolePerms, 'overrides' => $over, 'perms' => effective_perms($rolePerms, $over)];
}

function portals_for(array $roles, array $perms, bool $isProf, bool $isStudent): array {
    $has = fn($c) => in_array($c, $perms, true);
    $out = [];
    if (in_array('ADMIN', $roles, true) || array_intersect(ADMIN_PORTAL_PERMS, $perms)) $out[] = 'admin';
    if ($has('reports:institutional') || $has('reports:finance')) $out[] = 'dir';
    if ($has('finance:manage') || $has('payroll:manage')) $out[] = 'fin';
    if ($isProf && $has('classroom:own')) $out[] = 'prof';
    if ($isStudent && $has('student-portal:own')) $out[] = 'aluno';
    return $out ?: ['admin'];
}

function current_user(): ?array {
    static $u = false, $for = null;
    $uid = (int) ($_SESSION['uid'] ?? 0);
    if ($u !== false && $for === $uid) return $u;
    $for = $uid;
    if (!$uid) return $u = null;
    $row = row('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$row || $row['status'] !== 'ACTIVE') { unset($_SESSION['uid']); return $u = null; }
    $acc = user_access($uid);
    $prof = row('SELECT * FROM professors WHERE user_id = ?', [$uid]);
    $stu = row('SELECT * FROM students WHERE user_id = ?', [$uid]);
    $row += $acc + ['professor' => $prof, 'student' => $stu];
    $row['portals'] = portals_for($acc['roles'], $acc['perms'], (bool) $prof, (bool) $stu);
    return $u = $row;
}
function can(string ...$perms): bool {
    $u = current_user();
    if (!$u) return false;
    foreach ($perms as $p) if (in_array($p, $u['perms'], true)) return true;
    return false;
}
function require_perm(string ...$perms): array {
    $u = current_user();
    if (!$u) go('login');
    if ($perms && !can(...$perms)) abort(403);
    return $u;
}
/** Primeiro endereço do módulo para o usuário, ou null se ele não acessa o módulo */
function module_entry(array $u, string $mod): ?string {
    $m = platform_modules()[$mod] ?? null;
    if (!$m || !empty($m['soon'])) return null;
    $admin = in_array('ADMIN', $u['roles'], true);
    $areas = []; foreach ($u['perms'] as $c) if (isset(perm_catalog()[$c])) $areas[perm_catalog()[$c][0]] = true;
    if ($mod === 'controle') return ($admin || array_intersect(CONTROL_PANEL_PERMS, $u['perms'])) && in_array('admin', $u['portals'], true) ? $m['entry'] : null;
    if ($mod === 'academico') {
        if (in_array('admin', $u['portals'], true) && ($admin || isset($areas['academic']) || isset($areas['communication']))) return 'admin.inicio';
        foreach (['prof', 'aluno'] as $p) if (in_array($p, $u['portals'], true)) return $p . '.inicio';
        return null;
    }
    foreach ($m['portals'] as $p) if (in_array($p, $u['portals'], true)) return $p . '.inicio';
    // Módulo sem portal (sistema à parte, como o Patrimônio): abre pela rota 'entry' com qualquer permissão das áreas dele
    if (!$m['portals'] && !empty($m['entry'])) foreach ($m['areas'] as $a) if (isset($areas[$a])) return $m['entry'];
    return null;
}
/** Módulos da plataforma que o usuário pode abrir (na ordem do Workspace) */
function user_modules(array $u): array {
    $out = [];
    foreach (array_keys(platform_modules()) as $k) if (module_entry($u, $k)) $out[] = $k;
    return $out;
}
function module_of_portal(string $p): string { foreach (platform_modules() as $k => $m) if (in_array($p, $m['portals'], true)) return $k; return 'academico'; }
/** Módulo da tela atual: as telas do Painel de controle ficam no portal admin, mas são outro módulo */
function current_module(): string {
    $r = $GLOBALS['ROUTE'] ?? '';
    return in_array($r, CONTROL_ROUTES, true) ? 'controle' : module_of_portal(current_portal());
}
/** Para onde ir depois de entrar: o Workspace quando há mais de um módulo; senão, direto no módulo */
function home_route(array $u): string {
    $mods = user_modules($u);
    if (count($mods) > 1) return 'workspace';
    return $mods ? module_entry($u, $mods[0]) : $u['portals'][0] . '.inicio';
}
function current_portal(): string {
    $u = current_user();
    $p = $_SESSION['portal'] ?? '';
    return $u && in_array($p, $u['portals'], true) ? $p : ($u['portals'][0] ?? 'admin');
}
/** Resumo do acesso: "Administrador", ou cadastro (Professor/Aluno) + módulos liberados */
function role_names(array $kinds, array $perms = []): string {
    if (in_array('ADMIN', $kinds, true)) return 'Administrador';
    $n = array_map(fn($k) => ACCESS_LABEL[$k], array_values(array_intersect(RECORD_ROLES, $kinds)));
    $mods = [];
    foreach ($perms as $c) { $m = perm_catalog()[$c][0] ?? null; if ($m && !in_array($m, ['classroom', 'student'], true)) $mods[$m] = module_labels()[$m]; }
    $n = array_merge($n, array_values(array_intersect_key(module_labels(), $mods)));
    return implode(' · ', $n) ?: 'Sem permissões';
}

// ---------- Login ----------
const MAX_FAILED = 5;
const LOCK_MINUTES = 15;
/** Retorna [ok, mensagem] */
function attempt_login(string $email, string $password): array {
    $user = row('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    $generic = 'E-mail ou senha inválidos.';
    if (!$user) { password_verify($password, password_hash('academus-timing', PASSWORD_DEFAULT)); return [false, $generic]; }
    if ($user['locked_until'] && $user['locked_until'] > now()) return [false, 'Muitas tentativas. Tente de novo em alguns minutos.'];
    if (!password_verify($password, $user['password_hash'])) {
        $f = $user['failed_logins'] + 1;
        update('users', ['failed_logins' => $f >= MAX_FAILED ? 0 : $f, 'locked_until' => $f >= MAX_FAILED ? date('Y-m-d H:i:s', time() + LOCK_MINUTES * 60) : null], 'id = ?', [$user['id']]);
        return [false, $f >= MAX_FAILED ? 'Muitas tentativas. Acesso suspenso por ' . LOCK_MINUTES . ' minutos.' : $generic];
    }
    if ($user['status'] !== 'ACTIVE') return [false, 'Esta conta está bloqueada. Procure o administrador do sistema.'];
    if ($user['must_change_password'] && $user['password_expires_at'] && $user['password_expires_at'] < now())
        return [false, 'Sua senha provisória expirou. Peça ao administrador que envie uma nova.'];
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
    update('users', ['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()], 'id = ?', [$user['id']]);
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int) $user['id'], 'seen' => time(), 'inst' => (int) $user['institution_id']];
    return [true, ''];
}

/** Gera senha provisória (7 dias), envia por e-mail e devolve [enviado, senha] */
function issue_temp_password(array $user, bool $reset): array {
    $pass = gen_temp_password();
    update('users', ['password_hash' => password_hash($pass, PASSWORD_DEFAULT), 'must_change_password' => 1, 'password_expires_at' => date('Y-m-d H:i:s', time() + TEMP_PASSWORD_DAYS * 86400), 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$user['id']]);
    $inst = institution()['short_name'];
    $first = first_name($user['name']);
    $text = "Olá, $first!\n\n" . ($reset ? "Foi gerada uma nova senha provisória para o seu acesso ao " . APP_NAME . ", sistema acadêmico da $inst." : "Foi criado um acesso para você no " . APP_NAME . ", sistema acadêmico da $inst.")
        . "\n\nE-mail de acesso: {$user['email']}\nSenha provisória: $pass\n\nEntre em " . app_url() . "/index.php?r=login . No primeiro acesso, o sistema vai pedir que você crie a sua própria senha.\nA senha provisória vale por " . TEMP_PASSWORD_DAYS . " dias.\n\nSe você não esperava este e-mail, procure a secretaria da instituição.";
    [$ok] = send_mail($user['email'], ($reset ? 'Nova senha provisória' : 'Seu acesso ao ' . APP_NAME) . " · $inst", $text);
    return [$ok, $pass];
}
function app_url(): string {
    $set = rtrim((string) setting('app_url', ''), '/');
    if ($set) return $set;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}
