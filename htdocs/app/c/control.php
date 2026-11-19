<?php
// Painel de controle: usuários e permissões por módulo, instituição, e-mail e auditoria.

function route_admin__controle(): void {
    require_perm(...CONTROL_PANEL_PERMS);
    $i = inst_id();
    $users = (int) val('SELECT COUNT(*) FROM users u WHERE ' . user_in_inst(), inst_params());
    $blocked = (int) val("SELECT COUNT(*) FROM users u WHERE " . user_in_inst() . " AND u.status <> 'ACTIVE'", inst_params());
    $cards = [
        ['admin.usuarios', 'user-cog', 'Gerenciamento de usuários', 'Contas de acesso, senha provisória, bloqueio e as permissões de cada usuário, separadas por módulo.', plural($users, 'usuário', 'usuários') . ' · ' . plural($blocked, 'bloqueado', 'bloqueados') . ' · ' . count(array_filter(platform_modules(), fn($m) => empty($m['soon']))) . ' módulos', 'users:manage', true],
        ['admin.modulos', 'blocks', 'Módulos por integração', 'Incluir no Workspace um sistema que está em outra pasta deste site, com login único e as permissões que ele declara.', function_exists('ext_modules') ? plural(count(ext_modules(false)), 'módulo incluído', 'módulos incluídos') : '', 'modules:manage'],
        ['admin.instituicao', 'landmark', 'Instituição e e-mail', 'Dados cadastrais, logo, responsáveis que assinam os documentos e o envio de e-mails (SMTP).', institution()['short_name'] . (mail_configured() ? ' · e-mail configurado' : ' · e-mail não configurado'), 'institution:settings'],
        ['admin.instituicoes', 'building-2', 'Instituições cadastradas', 'Cadastrar instituições e escolher qual está em uso na plataforma.', plural((int) val('SELECT COUNT(*) FROM institutions'), 'cadastrada', 'cadastradas'), 'platform:manage'],
        ['admin.auditoria', 'history', 'Auditoria', 'Registro das operações importantes: quem fez, o quê e quando.', plural((int) val('SELECT COUNT(*) FROM audit_logs WHERE institution_id = ?', [$i]), 'registro', 'registros'), 'audit:read'],
    ];
    $o = '';
    foreach ($cards as $c) {
        if (!can($c[5])) continue;
        $o .= '<a class="admin-card' . (!empty($c[6]) ? ' main' : '') . '" href="' . h(url($c[0])) . '"><span class="admin-ic">' . icon($c[1]) . '</span><div class="grow"><h3>' . $c[2] . '</h3><p>' . $c[3] . '</p><span class="admin-stat">' . h($c[4]) . '</span></div><span class="admin-go">' . icon('arrow-right') . '</span></a>';
    }
    layout('Painel de controle', page_head('Painel de controle', 'Opções administrativas do sistema.') . '<div class="admin-cards">' . $o . '</div>');
}

// ---------- Usuários ----------
/** Rótulo de acesso do usuário: acesso total, professor/aluno e módulos liberados */
function access_badges(array $roles, array $perms): string {
    if (in_array('ADMIN', $roles, true)) return badge('Acesso total', 'b-warn');
    $o = '';
    foreach (RECORD_ROLES as $k) if (in_array($k, $roles, true)) $o .= badge(ACCESS_LABEL[$k], 'b-info') . ' ';
    $mods = [];
    foreach ($perms as $c) if (isset(perm_catalog()[$c]) && !in_array(perm_catalog()[$c][0], ['classroom', 'student'], true)) $mods[perm_catalog()[$c][0]] = true;
    foreach (array_keys(module_labels()) as $m) if (isset($mods[$m])) $o .= '<span class="chip-mod">' . h(module_labels()[$m]) . '</span> ';
    return $o ?: '<span class="muted small">Sem permissões</span>';
}
function route_admin__usuarios(): void {
    require_perm('users:manage');
    $q = g('q'); $mod = g('modulo'); $sit = g('situacao');
    $where = [user_in_inst()]; $par = inst_params();
    if ($q !== '') { $where[] = '(u.name LIKE ? OR u.email LIKE ? OR s.ra LIKE ?)'; array_push($par, "%$q%", "%$q%", '%' . (only_digits($q) ?: $q) . '%'); }
    if ($sit === 'ativo') $where[] = "u.status = 'ACTIVE'"; elseif ($sit === 'bloqueado') $where[] = "u.status <> 'ACTIVE'";
    $list = rows('SELECT u.*, s.ra FROM users u LEFT JOIN students s ON s.user_id = u.id WHERE ' . implode(' AND ', $where) . ' ORDER BY u.name LIMIT 1000', $par);
    $total = (int) val('SELECT COUNT(*) FROM users u WHERE ' . user_in_inst(), inst_params());
    $me = current_user()['id'];
    $rows = [];
    foreach ($list as $u) {
        $acc = user_access((int) $u['id']);
        $isAdmin = in_array('ADMIN', $acc['roles'], true);
        if ($mod === 'total' && !$isAdmin) continue;
        if (isset(module_labels()[$mod]) && !$isAdmin && !array_filter($acc['perms'], fn($c) => (perm_catalog()[$c][0] ?? '') === $mod)) continue;
        $rank = $isAdmin ? 0 : (array_intersect(RECORD_ROLES, $acc['roles']) ? (in_array('PROFESSOR', $acc['roles'], true) ? 2 : 3) : 1);
        $rows[] = [$rank, fold($u['name']), $u, $acc];
    }
    usort($rows, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    $tr = '';
    foreach ($rows as [, , $u, $acc]) {
        $href = url('admin.usuario', ['id' => $u['id']]);
        $tr .= '<tr class="clickable" data-href="' . h($href) . '"><td><div class="row" style="flex-wrap:nowrap">' . avatar($u['avatar_path'], $u['name'], 'width:30px;height:30px;font-size:11px') . '<div><a class="cell-link" href="' . h($href) . '">' . h($u['name']) . '</a>' . ((int) $u['id'] === (int) $me ? ' <span class="muted small">(você)</span>' : '') . '<p class="cell-sub">' . h($u['email']) . ($u['ra'] ? ' · RA ' . h($u['ra']) : '') . '</p></div></div></td>'
            . '<td><div class="mod-chips">' . access_badges($acc['roles'], $acc['perms']) . '</div></td>'
            . '<td>' . ($u['status'] === 'ACTIVE' ? ($u['must_change_password'] ? badge('Aguardando 1º acesso', 'b-info') : badge('Ativo', 'b-ok')) : badge(icon('lock') . ' Bloqueado', 'b-bad')) . '</td>'
            . '<td class="r chev-cell">' . icon('chevron-right') . '</td></tr>';
    }
    $modOpts = ['total' => 'Acesso total'] + module_labels();
    layout('Gerenciamento de usuários', crumb('admin.controle', 'Painel de controle') . page_head('Gerenciamento de usuários', 'Clique em um usuário para liberar ou bloquear módulos e permissões, enviar senha provisória ou bloquear o acesso.', '<a class="btn btn-primary" href="' . h(url('admin.usuario_novo')) . '">' . icon('user-plus') . ' Novo usuário</a>')
        . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="admin.usuarios">'
        . input('q', 'Buscar usuário', $q, 'search', 'placeholder="Nome, e-mail ou RA"', '', 'filters-q')
        . select('modulo', 'Com acesso à área', $modOpts, $mod, 'data-autosubmit', '', '', 'Todas as áreas') . select('situacao', 'Situação', ['ativo' => 'Ativo', 'bloqueado' => 'Bloqueado'], $sit, 'data-autosubmit', '', '', 'Todas')
        . '<button class="btn btn-secondary" type="submit">' . icon('search') . ' Buscar</button>' . ($q || $mod || $sit ? '<a class="btn btn-ghost" href="' . h(url('admin.usuarios')) . '">Limpar</a>' : '') . '</form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Usuário</th><th>Módulos liberados</th><th>Situação</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="4">' . empty_state('search', 'Nenhum usuário encontrado', 'Ajuste a busca ou os filtros.') . '</td></tr>') . '</tbody></table></div>'
        . result_line(count($rows), $total, 'usuários') . '</div>');
}

/** Permissões em blocos por módulo. $full = acesso total marcado. */
function perm_checklist(array $selected, bool $full = false, bool $showFull = true): string {
    $o = $showFull ? '<label class="full-access"><input type="checkbox" name="full" value="1" data-full-toggle' . ($full ? ' checked' : '') . '><span class="grow"><strong>' . icon('shield') . ' Acesso total (Administrador)</strong><span class="muted small">Libera todos os módulos e áreas, inclusive os que forem criados no futuro. Use só para quem administra o sistema.</span></span></label>' : '';
    $o .= '<div class="perm-list">'
        . '<div class="perm-sum"><span class="badge b-info" data-sum="n"></span><span class="grow"></span><button type="button" class="btn btn-ghost btn-sm" data-clear>Desmarcar todas</button></div><div class="plat-mods">';
    foreach (platform_modules() as $k => $pm) {
        if (!empty($pm['soon'])) continue;
        $codes = array_keys(array_filter(perm_catalog(), fn($p) => in_array($p[0], $pm['areas'], true)));
        $on = $full || array_intersect($codes, $selected);
        $o .= '<section class="plat-mod' . ($on ? '' : ' is-off') . '" data-plat-mod style="--mc:' . $pm['stripe'] . ';--mi:' . $pm['ink'] . ';--ms:' . $pm['soft'] . '">'
            . '<div class="plat-mod-head"><button type="button" class="plat-mod-expand" aria-expanded="false" aria-controls="pm-' . $k . '" data-mod-expand>'
            . '<span class="ws-ic sm" aria-hidden="true">' . icon($pm['icon']) . '</span><span class="plat-mod-name"><strong>' . h($pm['name']) . '</strong><span class="small muted" data-mod-sum></span></span>' . icon('chevron-down') . '</button>'
            . '<label class="mod-switch"><input type="checkbox" data-mod-toggle aria-label="Liberar o módulo ' . h($pm['name']) . '"' . ($on ? ' checked' : '') . '><span class="switch-ui" aria-hidden="true"></span><span class="switch-txt" data-mod-state>' . ($on ? 'Liberado' : 'Bloqueado') . '</span></label></div>'
            . '<div class="plat-mod-body" id="pm-' . $k . '" hidden><p class="plat-mod-off" data-mod-off>' . icon('lock') . ' Módulo bloqueado: as áreas abaixo ficam sem efeito até você liberar o módulo.</p><p class="plat-mod-empty" data-mod-empty hidden>' . icon('info') . ' Módulo liberado, mas sem nenhuma área marcada: marque abaixo o que este usuário pode usar.</p>';
        foreach ($pm['areas'] as $m) {
            $items = array_filter(perm_catalog(), fn($p) => $p[0] === $m);
            if (!$items) continue;
            [$ic, $desc] = module_info()[$m];
            $o .= '<fieldset class="perm-group" aria-labelledby="mod-' . $m . '"><div class="perm-group-head"><span class="mod-ic">' . icon($ic) . '</span><div class="mod-text"><h4 class="mod-title" id="mod-' . $m . '">' . h(module_labels()[$m]) . ' <span class="muted small" data-count></span></h4><p class="small muted">' . h($desc) . '</p></div><button type="button" class="btn btn-ghost btn-sm" data-all>Marcar todas</button></div><div class="perm-checks">';
            foreach ($items as $code => [, $d]) $o .= '<label class="check-field perm-item" data-code="' . h($code) . '"><input type="checkbox" name="perm[]" value="' . h($code) . '"' . ($full || in_array($code, $selected, true) ? ' checked' : '') . '><span class="grow"><strong>' . h($d) . '</strong></span></label>';
            $o .= '</div></fieldset>';
        }
        $o .= '</div></section>';
    }
    return $o . '</div></div>';
}
/** Instituição de vínculo e acessos extras de um usuário (só as instituições que quem edita também acessa) */
function inst_fields(int $home, array $extras, bool $fullAccess, bool $record): string {
    $mine = accessible_institution_ids((int) current_user()['id']);
    $list = $mine ? rows('SELECT id, name, short_name FROM institutions WHERE id IN (' . in_list($mine) . ') ORDER BY name', $mine) : [];
    $opts = ''; $boxes = '';
    foreach ($list as $i) {
        $opts .= '<option value="' . $i['id'] . '"' . ((int) $i['id'] === $home ? ' selected' : '') . '>' . h($i['short_name'] . ' — ' . $i['name']) . '</option>';
        $boxes .= '<label class="check-field inst-extra" data-inst="' . $i['id'] . '"><input type="checkbox" name="inst_extra[]" value="' . $i['id'] . '"' . (in_array((int) $i['id'], $extras, true) || $fullAccess ? ' checked' : '') . ($fullAccess ? ' disabled' : '') . '><span class="grow"><strong>' . h($i['short_name']) . '</strong><span class="muted small">' . h($i['name']) . '</span></span></label>';
    }
    return '<div class="card inst-card" style="margin-bottom:16px"><div class="card-head"><div><h3>' . icon('building-2') . ' Instituições</h3><p>Onde este usuário está vinculado e quais outras instituições ele pode acessar pelo menu do perfil.</p></div></div><div class="card-body form-grid cols-2">'
        . '<div class="field"><label for="inst_home">Instituição de vínculo</label><select class="input" id="inst_home" name="inst_home" data-inst-home>' . $opts . '</select><small>' . ($record ? 'Para aluno e professor: é onde ficam o curso, a turma e as aulas.' : 'É a instituição que abre quando ele entra no sistema.') . '</small></div>'
        . '<div class="field"><span class="label-like">Também tem acesso a</span>' . (count($list) > 1 ? '<div class="inst-extras">' . $boxes . '</div><small>' . ($fullAccess ? 'Com Acesso total, ele acessa todas as instituições.' : 'Ex.: professor que dá aula em mais de uma instituição.') . '</small>' : '<p class="small muted">Há só uma instituição cadastrada.</p>') . '</div>'
        . '</div></div>';
}
/** Grava vínculo e acessos extras; devolve um resumo da mudança para a auditoria ('' se nada mudou) */
function save_inst_access(int $uid, int $oldHome): string {
    $mine = accessible_institution_ids((int) current_user()['id']);
    $home = pi_('inst_home') ?: $oldHome;
    if (!in_array($home, $mine, true)) $home = $oldHome;
    $old = array_map('intval', col('SELECT institution_id FROM user_institutions WHERE user_id = ?', [$uid]));
    $keepOthers = array_values(array_diff($old, $mine)); // acessos a instituições que quem edita não vê continuam
    $sel = array_values(array_intersect(array_map('intval', (array) ($_POST['inst_extra'] ?? [])), $mine));
    $extras = array_values(array_unique(array_diff(array_merge($keepOthers, $sel), [$home])));
    update('users', ['institution_id' => $home], 'id = ?', [$uid]);
    q('DELETE FROM user_institutions WHERE user_id = ?', [$uid]);
    foreach ($extras as $iid) insert('user_institutions', ['user_id' => $uid, 'institution_id' => $iid]);
    sort($old); sort($extras);
    $name = fn($id) => (string) val('SELECT short_name FROM institutions WHERE id = ?', [$id]);
    $ch = [];
    if ($home !== $oldHome) $ch[] = 'vínculo ' . $name($oldHome) . ' → ' . $name($home);
    if ($old !== $extras) $ch[] = 'acesso extra: ' . ($extras ? implode(', ', array_map($name, $extras)) : 'nenhum');
    return implode('; ', $ch);
}
function selected_perms(): array { return array_values(array_intersect(array_keys(perm_catalog()), (array) ($_POST['perm'] ?? []))); }
/** Permissões que o usuário recebe automaticamente pelo cadastro de professor/aluno */
function record_role_perms(array $roles): array {
    $k = array_values(array_intersect($roles, RECORD_ROLES));
    return $k ? col('SELECT DISTINCT rp.perm FROM role_permissions rp JOIN roles r ON r.id = rp.role_id WHERE r.kind IN (' . in_list($k) . ')', $k) : [];
}
function save_overrides(int $uid, array $over): void {
    q('DELETE FROM user_permissions WHERE user_id = ?', [$uid]);
    foreach ($over as $code => $eff) insert('user_permissions', ['user_id' => $uid, 'perm' => $code, 'effect' => $eff]);
}
function set_admin_role(int $uid, bool $on): void {
    $rid = (int) val("SELECT id FROM roles WHERE kind = 'ADMIN'");
    q('DELETE FROM user_roles WHERE user_id = ? AND role_id = ?', [$uid, $rid]);
    if ($on) insert('user_roles', ['user_id' => $uid, 'role_id' => $rid]);
}
function active_admins(): int {
    return (int) val("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id WHERE u.status = 'ACTIVE' AND r.kind = 'ADMIN'"); // vale para a plataforma toda: o Administrador acessa todas as instituições
}
/** Mensagem depois de gerar senha provisória: avisa se o e-mail saiu ou mostra a senha para repassar */
function temp_password_notice(bool $sent, string $pass, string $email): void {
    if ($sent) flash('ok', "Senha provisória enviada para $email.");
    else flash('warn', 'O e-mail não foi enviado' . (mail_configured() ? ' (falha no servidor de e-mail)' : ' (envio de e-mail não configurado em Painel de controle › Instituição e e-mail)') . '. Passe esta senha provisória ao usuário por um canal seguro: <strong class="tnum" style="font-size:15px;user-select:all">' . h($pass) . '</strong> — ela vale por ' . TEMP_PASSWORD_DAYS . ' dias e não será mostrada de novo.');
}

function route_admin__usuario_novo(): void {
    require_perm('users:manage');
    $err = ''; $f = ['name' => '', 'email' => '']; $sel = []; $full = false;
    if (is_post()) {
        $f = ['name' => preg_replace('/\s+/', ' ', p('name')), 'email' => mb_strtolower(p('email'))];
        $sel = selected_perms(); $full = p('full') === '1';
        if (mb_strlen($f['name']) < 3) $err = 'Informe o nome completo.';
        elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err = 'Informe um e-mail válido.';
        elseif (row('SELECT id FROM users WHERE email = ?', [$f['email']])) $err = 'Já existe um usuário com este e-mail.';
        elseif (!$full && !$sel) $err = 'Marque pelo menos uma permissão ou o acesso total.';
        if (!$err) {
            $uid = tx(function () use ($f, $sel, $full) {
                $uid = insert('users', ['institution_id' => in_array(pi_('inst_home'), accessible_institution_ids((int) current_user()['id']), true) ? pi_('inst_home') : inst_id(), 'name' => $f['name'], 'email' => $f['email'], 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'must_change_password' => 1, 'created_at' => now()]);
                set_admin_role($uid, $full);
                if (!$full) save_overrides($uid, overrides_from_selection([], $sel));
                save_inst_access($uid, (int) val('SELECT institution_id FROM users WHERE id = ?', [$uid]));
                return $uid;
            });
            $user = row('SELECT * FROM users WHERE id = ?', [$uid]);
            [$sent, $pass] = issue_temp_password($user, false);
            audit('user.create', 'Usuário cadastrado: ' . $f['name'] . ' (' . $f['email'] . ') · ' . ($full ? 'acesso total' : plural(count($sel), 'permissão', 'permissões')), 'user', $uid);
            flash('ok', 'Usuário cadastrado.');
            temp_password_notice($sent, $pass, $f['email']);
            go('admin.usuario', ['id' => $uid]);
        }
    }
    layout('Novo usuário', crumb('admin.usuarios', 'Usuários') . page_head('Novo usuário', 'Informe os dados e marque, módulo por módulo, o que esta pessoa pode fazer no sistema.')
        . ($err ? alert('bad', h($err)) : '') . '<div class="card"><div class="card-body" style="padding-top:20px">' . form_open('admin.usuario_novo')
        . '<div class="form-grid cols-2">' . input('name', 'Nome completo', $f['name'], 'text', 'required maxlength="160"')
        . input('email', 'E-mail', $f['email'], 'email', 'required', 'Usado para entrar no sistema. A senha provisória vai para este endereço.') . '</div>'
        . '<div style="margin:14px 0">' . alert('info', 'O sistema gera uma senha provisória e envia para o e-mail cadastrado. No primeiro acesso, o usuário cria a própria senha. A provisória vale por ' . TEMP_PASSWORD_DAYS . ' dias.' . (mail_configured() ? '' : ' <strong>O envio de e-mail ainda não está configurado:</strong> a senha aparece na tela para você repassar.'), 'Senha provisória automática') . '</div>'
        . inst_fields(is_post() && pi_('inst_home') ? pi_('inst_home') : inst_id(), array_map('intval', (array) ($_POST['inst_extra'] ?? [])), $full, false)
        . '<h3 class="sub-title">Módulos e áreas</h3><p class="small muted" style="margin:-4px 0 12px">Professores e alunos recebem o acesso ao próprio portal ao serem cadastrados em Professores e Alunos. Aqui você pode dar a eles permissões extras.</p>' . perm_checklist($sel, $full)
        . '<div class="row sticky-save"><a class="btn btn-secondary" href="' . h(url('admin.usuarios')) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . icon('user-plus') . ' Cadastrar usuário</button></div></form></div></div>');
}

function route_admin__usuario(): void {
    $me = require_perm('users:manage');
    $u = row('SELECT * FROM users u WHERE u.id = ? AND ' . user_in_inst(), array_merge([gi('id')], inst_params()));
    if (!$u) abort(404);
    $acc = user_access((int) $u['id']);
    $isAdmin = in_array('ADMIN', $acc['roles'], true);
    $self = (int) $u['id'] === (int) $me['id'];
    $before = ['id' => $u['id'], 'roles' => $acc['roles'], 'active' => $u['status'] === 'ACTIVE', 'perms' => $acc['perms']];
    if (is_post()) {
        $act = p('acao');
        if ($act === 'bloquear') {
            $reason = p('reason');
            $err = mb_strlen($reason) < 5 ? 'Informe o motivo do bloqueio.' : access_change_error((int) $me['id'], $before, ['active' => false] + $before, active_admins());
            if ($err) flash('bad', $err);
            else { update('users', ['status' => 'BLOCKED', 'blocked_reason' => $reason], 'id = ?', [$u['id']]); audit('user.block', "Acesso bloqueado: {$u['name']} ({$u['email']})", 'user', (int) $u['id'], $reason); flash('ok', 'Acesso bloqueado. O usuário sai do sistema no próximo clique.'); }
        } elseif ($act === 'liberar') {
            update('users', ['status' => 'ACTIVE', 'blocked_reason' => null, 'failed_logins' => 0, 'locked_until' => null], 'id = ?', [$u['id']]);
            audit('user.unblock', "Acesso liberado: {$u['name']}", 'user', (int) $u['id']); flash('ok', 'Acesso liberado.');
        } elseif ($act === 'senha' && !$self) {
            [$sent, $pass] = issue_temp_password($u, true);
            audit('user.temp_password', "Nova senha provisória: {$u['name']}", 'user', (int) $u['id']);
            temp_password_notice($sent, $pass, $u['email']);
        } elseif ($act === 'permissoes') {
            $full = p('full') === '1'; $sel = selected_perms();
            $roles = array_values(array_diff($acc['roles'], ['ADMIN'])); if ($full) $roles[] = 'ADMIN';
            $base = record_role_perms($roles);
            $over = $full ? [] : overrides_from_selection($base, $sel);
            $after = ['roles' => $roles, 'active' => $before['active'], 'perms' => $full ? array_keys(perm_catalog()) : effective_perms($base, $over)] + $before;
            $err = access_change_error((int) $me['id'], $before, $after, active_admins());
            if ($err) flash('bad', $err);
            else {
                $instChange = '';
                tx(function () use ($u, $full, $over, &$instChange) { set_admin_role((int) $u['id'], $full); save_overrides((int) $u['id'], $over); $instChange = save_inst_access((int) $u['id'], (int) $u['institution_id']); });
                if ($instChange) audit('user.institutions', "Instituições de {$u['name']}: $instChange", 'user', (int) $u['id']);
                $add = array_diff($after['perms'], $acc['perms']); $rem = array_diff($acc['perms'], $after['perms']);
                audit('user.permissions', "Permissões de {$u['name']}: " . ($full !== $isAdmin ? ($full ? 'acesso total liberado' : 'acesso total retirado') . '; ' : '') . ($add ? 'liberadas ' . implode(', ', $add) : 'nenhuma liberada') . '; ' . ($rem ? 'retiradas ' . implode(', ', $rem) : 'nenhuma retirada'), 'user', (int) $u['id']);
                flash('ok', 'Permissões salvas. Valem a partir do próximo clique do usuário.');
            }
        } elseif ($act === 'dados') {
            $name = p('name'); $email = mb_strtolower(p('email'));
            if (mb_strlen($name) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL)) flash('bad', 'Informe nome e e-mail válidos.');
            elseif (row('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) flash('bad', 'Já existe outro usuário com este e-mail.');
            else { update('users', ['name' => $name, 'email' => $email, 'phone' => mb_substr(p('phone'), 0, 30)], 'id = ?', [$u['id']]); audit('user.update', "Dados de acesso alterados: $name ($email)", 'user', (int) $u['id']); flash('ok', 'Dados salvos.'); }
        }
        go('admin.usuario', ['id' => $u['id'], 'aba' => g('aba')]);
    }
    $tab = g('aba') === 'acesso' ? 'acesso' : 'permissoes';
    $base = url('admin.usuario', ['id' => $u['id']]);
    $status = $u['status'] === 'ACTIVE' ? badge('Ativo', 'b-ok') : badge(icon('lock') . ' Bloqueado', 'b-bad');
    $head = crumb('admin.usuarios', 'Usuários') . page_head('Usuário', 'Permissões por módulo, situação da conta e senha.')
        . '<div class="card ficha-head" style="margin-bottom:16px"><div class="card-body" style="padding-top:20px"><div class="row" style="gap:16px;flex-wrap:wrap">' . avatar($u['avatar_path'], $u['name'], 'width:56px;height:56px;font-size:18px')
        . '<div class="grow"><h2 style="font-size:19px;font-weight:600;color:var(--navy)">' . h($u['name']) . ($self ? ' <span class="muted small">(você)</span>' : '') . '</h2><div class="row" style="gap:8px;margin-top:4px;flex-wrap:wrap"><span class="muted">' . h($u['email']) . '</span>' . $status . '</div>'
        . '<div class="mod-chips" style="margin-top:8px">' . access_badges($acc['roles'], $acc['perms']) . '</div></div></div></div></div>'
        . tabs([['permissoes', 'Instituições e permissões', 'key-round', $base], ['acesso', 'Conta e senha', 'id-card', $base . '&aba=acesso']], $tab);
    if ($tab === 'permissoes') {
        $rec = array_values(array_intersect($acc['roles'], RECORD_ROLES));
        $note = $rec ? alert('info', 'Este usuário tem cadastro de ' . implode(' e de ', array_map(fn($k) => mb_strtolower(ACCESS_LABEL[$k]), $rec)) . ', por isso já vem com o acesso ao próprio portal. Você pode desmarcar ou liberar outros módulos.') : '';
        layout('Usuário', $head . $note . '<div class="card" style="margin-top:16px"><div class="card-body" style="padding-top:20px">' . form_open('admin.usuario', ['id' => $u['id']]) . '<input type="hidden" name="acao" value="permissoes">'
            . inst_fields((int) $u['institution_id'], array_map('intval', col('SELECT institution_id FROM user_institutions WHERE user_id = ?', [$u['id']])), $isAdmin, (bool) $rec)
            . '<h3 class="sub-title">Módulos e áreas</h3>' . perm_checklist($acc['perms'], $isAdmin) . '<div class="row sticky-save"><button class="btn btn-primary" type="submit">' . icon('save') . ' Salvar instituições e permissões</button></div></form></div></div>');
        return;
    }
    $sit = $u['status'] === 'ACTIVE'
        ? ($self ? '<p class="small muted">Você não pode bloquear a própria conta.</p>' : form_open('admin.usuario', ['id' => $u['id'], 'aba' => 'acesso'], 'class="stack" data-confirm="Bloquear o acesso deste usuário?"') . '<input type="hidden" name="acao" value="bloquear"><p class="small muted">Bloquear impede novos acessos e encerra o uso no próximo clique. Os dados continuam guardados.</p>' . textarea('reason', 'Motivo', '', 'rows="2" required minlength="5" placeholder="Ex.: desligamento do colaborador"', 'Fica registrado na auditoria.') . '<div><button class="btn btn-danger" type="submit">' . icon('lock') . ' Bloquear acesso</button></div></form>')
        : ($u['blocked_reason'] ? alert('bad', h($u['blocked_reason']), 'Motivo do bloqueio') : '') . form_open('admin.usuario', ['id' => $u['id'], 'aba' => 'acesso'], 'style="margin-top:12px"') . '<input type="hidden" name="acao" value="liberar"><button class="btn btn-primary" type="submit">' . icon('lock-open') . ' Liberar acesso</button></form>';
    $pwInfo = $u['must_change_password'] ? ($u['password_expires_at'] && $u['password_expires_at'] < now() ? 'A senha provisória venceu antes do primeiro acesso.' : 'Aguardando o primeiro acesso: a senha provisória vale até ' . fmt_dt($u['password_expires_at']) . '.') : 'Senha criada pelo próprio usuário. Último acesso: ' . fmt_dt($u['last_login_at']) . '.';
    $pw = $self ? '<p class="small muted">Para a sua senha, use “Alterar senha” no menu do seu perfil.</p>'
        : form_open('admin.usuario', ['id' => $u['id'], 'aba' => 'acesso'], 'class="stack" data-confirm="Gerar e enviar uma nova senha provisória?"') . '<input type="hidden" name="acao" value="senha"><p class="small muted">Gera uma nova senha provisória e envia para ' . h($u['email']) . '. O usuário cria a própria senha no próximo acesso.</p><div><button class="btn btn-secondary" type="submit">' . icon('mail') . ' Enviar nova senha provisória</button></div></form>';
    layout('Usuário', $head . '<div class="grid g2" style="align-items:start">'
        . '<div class="card"><div class="card-head"><div><h3>Dados de acesso</h3></div></div><div class="card-body">' . form_open('admin.usuario', ['id' => $u['id'], 'aba' => 'acesso'], 'class="form-grid"') . '<input type="hidden" name="acao" value="dados">'
        . input('name', 'Nome', $u['name'], 'text', 'required') . input('email', 'E-mail', $u['email'], 'email', 'required') . input('phone', 'Telefone', $u['phone'], 'tel') . '<div><button class="btn btn-secondary" type="submit">Salvar dados</button></div></form></div></div>'
        . '<div class="stack" style="gap:16px"><div class="card"><div class="card-head"><div><h3>Situação da conta</h3><p>' . ($u['status'] === 'ACTIVE' ? 'Ativa: o usuário entra normalmente.' : 'Acesso bloqueado: o usuário não consegue entrar.') . '</p></div></div><div class="card-body">' . $sit . '</div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Senha de acesso</h3><p>' . $pwInfo . '</p></div></div><div class="card-body">' . $pw . '</div></div></div>'
        . '</div>');
}

function route_admin__instituicao(): void {
    require_perm('institution:settings');
    $i = institution();
    if (is_post()) {
        $act = p('acao');
        if ($act === 'dados') {
            $d = []; foreach (['name', 'short_name', 'legal_name', 'cnpj', 'emec_code', 'address', 'city', 'state', 'zip_code', 'phone', 'email', 'website', 'director_name', 'teaching_director_name', 'secretary_name'] as $k) $d[$k] = mb_substr(p($k), 0, 255) ?: null;
            $d['state'] = $d['state'] ? mb_strtoupper(mb_substr($d['state'], 0, 2)) : null;
            if (!$d['name'] || !$d['short_name']) flash('bad', 'Informe o nome e a sigla.');
            elseif ($d['cnpj'] && !valid_cnpj($d['cnpj'])) flash('bad', 'CNPJ inválido.');
            else { update('institutions', $d, 'id = ?', [$i['id']]); audit('institution.update', 'Dados da instituição alterados', 'institution', (int) $i['id']); flash('ok', 'Dados da instituição salvos.'); }
        } elseif ($act === 'logo') {
            [$name, $err] = save_image($_FILES['logo'] ?? null, 'logo-' . $i['id'], 'logo');
            if ($err) flash('bad', $err);
            else { remove_upload($i['logo_path']); update('institutions', ['logo_path' => $name], 'id = ?', [$i['id']]); audit('institution.logo', 'Logo da instituição trocada', 'institution', (int) $i['id']); flash('ok', 'Logo atualizada. Ela já aparece no menu e nos históricos.'); }
        } elseif ($act === 'remover_logo') {
            remove_upload($i['logo_path']); update('institutions', ['logo_path' => null], 'id = ?', [$i['id']]); audit('institution.logo', 'Logo da instituição removida', 'institution', (int) $i['id']); flash('ok', 'Logo removida.');
        } elseif ($act === 'email') {
            foreach (['smtp_host', 'smtp_port', 'smtp_secure', 'smtp_user', 'mail_from', 'mail_from_name', 'app_url'] as $k) set_setting($k, p($k) ?: null);
            if (p('smtp_pass') !== '') set_setting('smtp_pass', (string) $_POST['smtp_pass']);
            audit('settings.mail', 'Configuração de e-mail alterada');
            flash('ok', 'Configuração de e-mail salva.');
        } elseif ($act === 'teste') {
            [$ok, $e] = send_mail(current_user()['email'], 'Teste de e-mail · ' . APP_NAME, "Se você recebeu esta mensagem, o envio de e-mails do " . APP_NAME . " está funcionando.\n\n" . app_url());
            flash($ok ? 'ok' : 'bad', $ok ? 'E-mail de teste enviado para ' . h(current_user()['email']) . '.' : 'Falha no envio: ' . h($e));
        }
        go('admin.instituicao', ['aba' => g('aba')]);
    }
    $tab = g('aba') === 'email' ? 'email' : 'dados';
    $base = url('admin.instituicao');
    $head = crumb('admin.controle', 'Painel de controle') . page_head('Instituição em uso', h($i['name'])) . tabs([['dados', 'Dados e logo', 'landmark', $base], ['email', 'E-mail (SMTP)', 'mail', $base . '&aba=email']], $tab);
    if ($tab === 'email') {
        layout('E-mail', $head . alert('info', 'O InfinityFree gratuito não envia e-mails pela função mail() do PHP. Use um servidor SMTP externo: Gmail (com “senha de app”), Brevo, SMTP2GO, MailerSend ou Resend. Sem isso, as senhas provisórias aparecem na tela para você repassar.', 'Envio de e-mails')
            . '<div class="card" style="margin-top:16px;max-width:820px"><div class="card-body" style="padding-top:20px">' . form_open('admin.instituicao', ['aba' => 'email'], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="email">'
            . input('smtp_host', 'Servidor SMTP', setting('smtp_host'), 'text', 'placeholder="smtp.gmail.com"') . input('smtp_port', 'Porta', setting('smtp_port', '587'), 'number', '', '587 (TLS) ou 465 (SSL)')
            . select('smtp_secure', 'Segurança', ['tls' => 'TLS (STARTTLS)', 'ssl' => 'SSL', 'none' => 'Nenhuma'], setting('smtp_secure', 'tls')) . input('smtp_user', 'Usuário', setting('smtp_user'), 'text', 'autocomplete="off"')
            . input('smtp_pass', 'Senha', '', 'password', 'autocomplete="new-password" placeholder="' . (setting('smtp_pass') ? '•••••• (deixe em branco para manter)' : '') . '"', 'No Gmail, crie uma “senha de app” na conta Google.') . input('mail_from', 'E-mail do remetente', setting('mail_from'), 'email')
            . input('mail_from_name', 'Nome do remetente', setting('mail_from_name', APP_NAME)) . input('app_url', 'Endereço do sistema', setting('app_url', app_url()), 'url', '', 'Vai nos e-mails. Ex.: https://seusite.infinityfreeapp.com')
            . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><button class="btn btn-primary" type="submit">Salvar</button></div></form>'
            . form_open('admin.instituicao', ['aba' => 'email'], 'style="margin-top:12px"') . '<input type="hidden" name="acao" value="teste"><button class="btn btn-secondary" type="submit"' . (mail_configured() ? '' : ' disabled') . '>' . icon('send') . ' Enviar e-mail de teste para mim</button></form></div></div>');
        return;
    }
    $in = fn($k, $l, $hint = '', $cls = '') => input($k, $l, $i[$k] ?? '', 'text', '', $hint, $cls);
    layout('Instituição', $head . '<div class="grid g2" style="align-items:start"><div class="card"><div class="card-head"><div><h3>Dados cadastrais</h3><p>Aparecem no cabeçalho do histórico escolar.</p></div></div><div class="card-body">'
        . form_open('admin.instituicao', [], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="dados">'
        . $in('name', 'Nome', '', 'span-2') . $in('short_name', 'Sigla') . $in('emec_code', 'Código e-MEC') . $in('legal_name', 'Razão social', '', 'span-2') . $in('cnpj', 'CNPJ') . $in('phone', 'Telefone')
        . $in('address', 'Endereço', '', 'span-2') . $in('city', 'Cidade') . $in('state', 'UF') . $in('zip_code', 'CEP') . $in('email', 'E-mail') . $in('website', 'Site', '', 'span-2')
        . '<p class="span-2 form-sub">Assinaturas do histórico escolar</p>' . $in('director_name', 'Diretor(a) geral') . $in('teaching_director_name', 'Diretor(a) de ensino') . $in('secretary_name', 'Secretário(a) acadêmico(a)', 'Na emissão, você escolhe quem assina.', 'span-2')
        . '<div class="span-2 row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Salvar dados</button></div></form></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Logo</h3><p>PNG, JPG ou WebP. Aparece no menu e no histórico escolar. Prefira PNG com fundo transparente.</p></div></div><div class="card-body stack">'
        . '<div class="logo-preview">' . ($i['logo_path'] ? '<img src="' . h($i['logo_path']) . '" alt="Logo atual">' : '<p class="muted small">Nenhuma logo enviada.</p>') . '</div>'
        . '<form method="post" action="' . h(url('admin.instituicao')) . '" enctype="multipart/form-data" class="stack" style="gap:8px">' . csrf() . '<input type="hidden" name="acao" value="logo">'
        . '<label class="file-pick"><input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required data-image-shrink="1600" data-max-bytes="' . upload_limit() . '" data-preview=".logo-preview">' . icon('image-up') . '<span>Escolher imagem</span></label>'
        . '<p class="small muted">Imagens grandes são reduzidas automaticamente antes do envio.</p><button class="btn btn-primary" type="submit" style="align-self:flex-start">' . icon('upload') . ' Salvar logo</button></form>'
        . ($i['logo_path'] ? form_open('admin.instituicao', [], 'data-confirm="Remover a logo da instituição?"') . '<input type="hidden" name="acao" value="remover_logo"><button class="btn btn-ghost btn-sm" type="submit">Remover logo</button></form>' : '') . '</div></div></div>');
}

function route_admin__instituicoes(): void {
    require_perm('platform:manage');
    if (is_post()) {
        if (p('acao') === 'nova') {
            $name = p('name'); $short = p('short_name');
            if (mb_strlen($name) < 3 || mb_strlen($short) < 2) flash('bad', 'Informe o nome e a sigla.');
            else { $id = insert('institutions', ['name' => $name, 'short_name' => $short, 'city' => p('city') ?: null, 'state' => mb_strtoupper(p('state')) ?: null, 'created_at' => now()]); audit('institution.create', "Instituição cadastrada: $name", 'institution', $id); flash('ok', 'Instituição cadastrada.'); }
        } elseif (p('acao') === 'usar') {
            $id = pi_('id');
            if (in_array($id, accessible_institution_ids((int) current_user()['id']), true)) {
                $_SESSION['inst'] = $id;
                audit('institution.switch', 'Passou a usar outra instituição', 'institution', $id);
                flash('ok', 'Agora você está nesta instituição. Os outros usuários não são afetados.');
            }
        }
        go('admin.instituicoes');
    }
    $cur = inst_id(); $tr = '';
    foreach (rows('SELECT i.*, (SELECT COUNT(*) FROM users u WHERE u.institution_id = i.id OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = i.id)) AS users FROM institutions i ORDER BY i.name') as $x)
        $tr .= '<tr><td><p class="cell-title">' . h($x['name']) . '</p><p class="cell-sub">' . h($x['short_name']) . ($x['city'] ? ' · ' . h($x['city']) . '/' . h($x['state']) : '') . '</p></td><td class="tnum">' . $x['users'] . '</td><td>'
            . ((int) $x['id'] === $cur ? badge('Em uso', 'b-ok') : form_open('admin.instituicoes', [], 'class="row" style="gap:6px;flex-wrap:nowrap"') . '<input type="hidden" name="acao" value="usar"><input type="hidden" name="id" value="' . $x['id'] . '"><button class="btn btn-sm btn-secondary" type="submit">Entrar nesta</button></form>') . '</td></tr>';
    layout('Instituições', crumb('admin.controle', 'Painel de controle') . page_head('Instituições cadastradas', 'Cada pessoa escolhe a instituição em uso no menu do perfil. Trocar aqui vale só para você.')
        . '<div class="grid g2" style="align-items:start"><div class="card"><div class="table-wrap"><table><thead><tr><th>Instituição</th><th>Usuários</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Nova instituição</h3></div></div><div class="card-body">' . form_open('admin.instituicoes', [], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="nova">'
        . input('name', 'Nome', '', 'text', 'required', '', 'span-2') . input('short_name', 'Sigla', '', 'text', 'required') . input('city', 'Cidade') . input('state', 'UF', '', 'text', 'maxlength="2"') . '<div class="span-2 row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">Cadastrar</button></div></form></div></div></div>');
}

// ---------- Auditoria ----------
function route_admin__auditoria(): void {
    require_perm('audit:read');
    $q = g('q'); $acao = g('acao'); $de = g('de'); $ate = g('ate'); $page = max(1, gi('pagina'));
    $w = ['a.institution_id = ?']; $par = [inst_id()];
    if ($q !== '') { $w[] = '(a.summary LIKE ? OR u.name LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
    if ($acao !== '') { $w[] = 'a.action LIKE ?'; $par[] = $acao . '%'; }
    if (valid_date($de)) { $w[] = 'a.created_at >= ?'; $par[] = "$de 00:00:00"; }
    if (valid_date($ate)) { $w[] = 'a.created_at <= ?'; $par[] = "$ate 23:59:59"; }
    $where = implode(' AND ', $w);
    $total = (int) val("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE $where", $par);
    $per = 50;
    $list = rows("SELECT a.*, u.name AS actor FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_id WHERE $where ORDER BY a.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $par);
    $groups = ['auth' => 'Acesso', 'user' => 'Usuários', 'module' => 'Módulos', 'role' => 'Perfis', 'announcement' => 'Comunicados', 'institution' => 'Instituição', 'course' => 'Cursos', 'student' => 'Alunos', 'professor' => 'Professores', 'section' => 'Ofertas', 'grade' => 'Notas', 'lesson' => 'Diário', 'transcript' => 'Históricos', 'charge' => 'Financeiro', 'term' => 'Períodos'];
    $tr = '';
    foreach ($list as $a) $tr .= '<tr><td class="tnum" style="white-space:nowrap">' . fmt_dt($a['created_at']) . '</td><td>' . h($a['actor'] ?? 'Sistema') . '</td><td><span class="formula small">' . h($a['action']) . '</span></td><td>' . h($a['summary']) . ($a['reason'] ? '<p class="cell-sub">Motivo: ' . h($a['reason']) . '</p>' : '') . '</td><td class="small muted">' . h($a['ip']) . '</td></tr>';
    $pages = max(1, (int) ceil($total / $per));
    $nav = $pages > 1 ? '<div class="row" style="justify-content:space-between;padding:12px 20px;border-top:1px solid var(--line)"><span class="small muted">Página ' . $page . ' de ' . $pages . ' · ' . $total . ' registros</span><div class="row" style="gap:6px">'
        . ($page > 1 ? '<a class="btn btn-sm btn-secondary" href="' . h(url('admin.auditoria', compact('q', 'acao', 'de', 'ate') + ['pagina' => $page - 1])) . '">Anterior</a>' : '')
        . ($page < $pages ? '<a class="btn btn-sm btn-secondary" href="' . h(url('admin.auditoria', compact('q', 'acao', 'de', 'ate') + ['pagina' => $page + 1])) . '">Próxima</a>' : '') . '</div></div>' : result_line(count($list), $total, 'registros');
    layout('Auditoria', crumb('admin.controle', 'Painel de controle') . page_head('Auditoria', 'Quem fez o quê e quando. Os registros não podem ser alterados nem apagados pelo sistema.')
        . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="admin.auditoria">' . input('q', 'Buscar', $q, 'search', 'placeholder="Usuário ou descrição"', '', 'filters-q')
        . select('acao', 'Área', $groups, $acao, 'data-autosubmit', '', '', 'Todas') . input('de', 'De', $de, 'date') . input('ate', 'Até', $ate, 'date') . '<button class="btn btn-secondary" type="submit">Filtrar</button></form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Quando</th><th>Quem</th><th>Ação</th><th>Descrição</th><th>IP</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('history', 'Nenhum registro encontrado') . '</td></tr>') . '</tbody></table></div>' . $nav . '</div>');
}

/** Troca a instituição em uso nesta sessão (menu do perfil). Não afeta os outros usuários. */
function route_instituicao__trocar(): void {
    if (!is_post()) abort(405);
    $u = current_user(); $id = pi_('id');
    if (!in_array($id, accessible_institution_ids((int) $u['id']), true)) { flash('bad', 'Você não tem acesso a esta instituição.'); go(home_route($u)); }
    $_SESSION['inst'] = $id;
    $name = (string) val('SELECT name FROM institutions WHERE id = ?', [$id]);
    audit('institution.switch', 'Passou a usar a instituição ' . $name, 'institution', $id);
    flash('ok', 'Agora você está em ' . h($name) . '.');
    go(home_route($u));
}
