<?php
// Entrar, sair, criar/alterar senha, esqueci a senha, verificação pública de histórico.

function login_shell(string $inner): string {
    $inst = institution();
    $feats = [['clipboard-check', 'Notas e frequência', 'Diário de classe e chamada pelo professor.'], ['calendar-days', 'Horários e eletivas', 'Grade sem conflitos e inscrição online.'], ['file-badge', 'Histórico oficial', 'Emitido com código de verificação.'], ['wallet', 'Financeiro', 'Mensalidades, pagamentos e inadimplência.']];
    $li = ''; foreach ($feats as [$ic, $t, $d]) $li .= '<li>' . icon($ic) . '<div><strong>' . $t . '</strong>' . $d . '</div></li>';
    return '<div class="login"><section class="login-brand"><div class="login-logo">' . app_logo_anim('dark', BRAND['login_logo_h']) . (APP_SLOGAN ? '<p class="slogan">' . h(APP_SLOGAN) . '</p>' : '') . '</div>'
        . '<div class="login-hero"><h1>Gestão acadêmica em um só lugar.</h1><p>Notas, frequência, diário de classe, histórico e financeiro para alunos, professores e secretaria.</p><ul class="login-feats">' . $li . '</ul></div>'
        . '<p class="login-foot">' . icon('shield-check') . ' Seus dados são tratados conforme a LGPD.</p></section>'
        . '<section class="login-form-wrap"><div class="login-box"><div class="login-mobile-head">' . app_logo_anim('auto', (int) round(BRAND['login_logo_h'] * .7)) . (APP_SLOGAN ? '<p class="slogan">' . h(APP_SLOGAN) . '</p>' : '') . '</div>'
        . flashes() . $inner . '<div class="login-theme"><p>Aparência</p>' . theme_switch() . size_switch() . '</div></div></section></div>';
}

function route_login(): void {
    if (current_user()) go(home_route(current_user()));
    $err = '';
    if (is_post()) {
        [$ok, $err] = attempt_login(p('email'), (string) ($_POST['password'] ?? ''));
        if ($ok) {
            $u = current_user();
            audit('auth.login', 'Entrou no sistema', 'user', $u['id']);
            if ($u['must_change_password']) go('senha');
            $next = $_SESSION['after_login'] ?? ''; unset($_SESSION['after_login']);
            if ($next && str_contains($next, 'index.php?r=') && !str_contains($next, 'r=login')) { header('Location: ' . substr($next, strpos($next, 'index.php'))); exit; }
            go(home_route($u));
        }
    }
    bare_page('Entrar', login_shell('<div><h2 style="font-size:24px">Entrar</h2><p class="muted" style="margin-top:4px">Use o e-mail e a senha do seu cadastro.</p></div>'
        . ($err ? alert('bad', h($err)) : '')
        . form_open('login', [], 'class="form-grid" novalidate')
        . input('email', 'E-mail', p('email'), 'email', 'autocomplete="username" required autofocus')
        . input('password', 'Senha', '', 'password', 'autocomplete="current-password" required')
        . '<button class="btn btn-primary btn-lg" type="submit">Entrar</button></form>'
        . '<p class="small" style="text-align:center"><a href="' . h(url('esqueci')) . '">Esqueci minha senha</a></p>'));
}

function route_logout(): void {
    if (is_post()) { if (current_user()) audit('auth.logout', 'Saiu do sistema'); $_SESSION = []; session_regenerate_id(true); }
    go('login');
}

/** Esqueci a senha: envia uma senha provisória, sem revelar se o e-mail existe */
function route_esqueci(): void {
    if (is_post()) {
        $last = $_SESSION['forgot_at'] ?? 0;
        if (time() - $last < 60) { flash('warn', 'Aguarde um minuto antes de pedir de novo.'); go('esqueci'); }
        $_SESSION['forgot_at'] = time();
        $u = row("SELECT * FROM users WHERE email = ? AND status = 'ACTIVE'", [mb_strtolower(p('email'))]);
        if ($u && mail_configured()) { issue_temp_password($u, true); insert('audit_logs', ['institution_id' => inst_id(), 'actor_id' => $u['id'], 'action' => 'auth.forgot', 'entity' => 'user', 'entity_id' => $u['id'], 'summary' => 'Pediu nova senha provisória', 'ip' => $_SERVER['REMOTE_ADDR'] ?? '', 'created_at' => now()]); }
        flash('ok', mail_configured() ? 'Se o e-mail estiver cadastrado, você vai receber uma senha provisória em instantes.' : 'O envio de e-mail não está configurado. Peça ao administrador do sistema uma nova senha provisória.');
        go('login');
    }
    bare_page('Esqueci minha senha', login_shell('<div><h2 style="font-size:24px">Esqueci minha senha</h2><p class="muted" style="margin-top:4px">Informe o e-mail do cadastro. Enviaremos uma senha provisória; no próximo acesso você cria uma nova.</p></div>'
        . form_open('esqueci', [], 'class="form-grid"') . input('email', 'E-mail', '', 'email', 'required autofocus')
        . '<button class="btn btn-primary btn-lg" type="submit">Enviar senha provisória</button></form><p class="small" style="text-align:center"><a href="' . h(url('login')) . '">Voltar para entrar</a></p>'));
}

/** Criar senha (primeiro acesso com a provisória) ou alterar senha */
function route_senha(): void {
    $u = current_user();
    $first = (bool) $u['must_change_password'];
    $err = '';
    if (is_post()) {
        $cur = (string) ($_POST['current'] ?? ''); $new = (string) ($_POST['password'] ?? ''); $conf = (string) ($_POST['confirm'] ?? '');
        if (!password_verify($cur, $u['password_hash'])) $err = $first ? 'A senha provisória não confere. Copie do e-mail que você recebeu.' : 'A senha atual não confere.';
        else $err = validate_new_password($new, $cur, $u['email'], $conf) ?? '';
        if (!$err) {
            update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'must_change_password' => 0, 'password_expires_at' => null], 'id = ?', [$u['id']]);
            session_regenerate_id(true);
            audit('auth.password_changed', $first ? 'Criou a própria senha no primeiro acesso' : 'Alterou a senha', 'user', $u['id']);
            flash('ok', $first ? 'Senha criada. Bem-vindo(a)!' : 'Senha alterada.');
            go(home_route($u));
        }
    }
    $form = '<div class="row" style="gap:12px;align-items:flex-start;flex-wrap:nowrap"><span class="admin-ic" style="background:var(--blue-soft);color:var(--blue)">' . icon('key-round') . '</span><div><h1 style="font-size:20px;font-weight:600;color:var(--navy)">' . ($first ? 'Crie a sua senha' : 'Alterar senha') . '</h1>'
        . '<p class="small muted" style="margin-top:4px">' . ($first ? 'Olá, ' . h(first_name($u['name'])) . '! Você entrou com a senha provisória enviada para ' . h($u['email']) . '. Para continuar, crie uma senha só sua.' : 'Informe a senha atual e escolha a nova.') . '</p></div></div>'
        . ($err ? alert('bad', h($err)) : '')
        . form_open('senha', [], 'class="form-grid"')
        . input('current', $first ? 'Senha provisória (do e-mail)' : 'Senha atual', '', 'password', 'autocomplete="current-password" required')
        . input('password', 'Nova senha', '', 'password', 'autocomplete="new-password" required minlength="8"', 'Pelo menos 8 caracteres, com letras e números.')
        . input('confirm', 'Confirme a nova senha', '', 'password', 'autocomplete="new-password" required minlength="8"')
        . '<button class="btn btn-primary" type="submit">' . ($first ? 'Criar senha e entrar' : 'Salvar nova senha') . '</button></form>';
    $foot = $first ? form_open('logout') . '<button class="btn btn-ghost btn-sm" type="submit">Sair</button></form>' : '<a class="btn btn-ghost btn-sm" href="' . h(url(home_route($u))) . '">Voltar</a>';
    if ($first) bare_page('Crie a sua senha', '<div class="pw-gate"><div class="card pw-card"><div class="card-body stack" style="padding:28px">' . $form . '<div class="row" style="justify-content:flex-end">' . $foot . '</div></div></div></div>');
    else layout('Alterar senha', '<div style="max-width:480px"><div class="card"><div class="card-body stack" style="padding-top:20px">' . $form . '</div></div></div>');
}

/** Verificação pública de histórico: confere código e integridade */
function route_verificar(): void {
    $code = strtoupper(preg_replace('/[^A-Z0-9-]/i', '', g('codigo')));
    $out = '';
    if ($code !== '') {
        $t = row('SELECT t.*, i.name AS inst FROM transcripts t JOIN institutions i ON i.id = t.institution_id WHERE t.code = ?', [$code]);
        if (!$t) $out = alert('bad', 'Nenhum documento encontrado com este código.');
        else {
            $snap = json_decode($t['snapshot'], true);
            $intact = hash_equals($t['sha256'], hash('sha256', $t['snapshot']));
            $out = $intact ? alert('ok', 'Histórico escolar de <strong>' . h($snap['student']['name']) . '</strong> (RA ' . h($snap['student']['ra']) . '), ' . h($snap['course']['name']) . ', emitido por ' . h($t['inst']) . ' em ' . fmt_dt($t['issued_at']) . '.', 'Documento autêntico')
                : alert('bad', 'O conteúdo registrado deste documento não confere. Procure a instituição.', 'Documento com divergência');
        }
    }
    bare_page('Verificar documento', login_shell('<div><h2 style="font-size:24px">Verificar histórico escolar</h2><p class="muted" style="margin-top:4px">Digite o código impresso no documento.</p></div>' . $out
        . '<form method="get" action="index.php" class="form-grid"><input type="hidden" name="r" value="verificar">' . input('codigo', 'Código de verificação', $code, 'text', 'placeholder="XXXX-XXXX-XXXX" required')
        . '<button class="btn btn-primary" type="submit">Verificar</button></form>'));
}
