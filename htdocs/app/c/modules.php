<?php
// Módulos por integração: telas do Painel de controle (incluir, atualizar, ligar/desligar, remover)
// e as rotas de login único (index.php?r=modulo.<slug>) e de saída (index.php?r=modulo_sair.<slug>).

function route_admin__modulos(): void {
    require_perm('modules:manage');
    if (is_post()) {
        [$m, $err] = ext_read_manifest(mb_strtolower(trim((string) p('folder'), " /\\\t")));
        $all = ext_modules(false);
        if (!$m) { flash('bad', h($err)); go('admin.modulos'); }
        if (isset($all[$m['slug']])) { flash('bad', 'Já existe um módulo incluído com o slug ' . h($m['slug']) . '. Abra-o na lista para atualizar.'); go('admin.modulos'); }
        foreach ($all as $x) if ($x['folder'] === $m['folder']) { flash('bad', 'A pasta ' . h($m['folder']) . ' já está incluída como o módulo ' . h($x['name']) . '.'); go('admin.modulos'); }
        $m += ['secret' => bin2hex(random_bytes(32)), 'active' => true, 'created_at' => now(), 'updated_at' => now()];
        $all[$m['slug']] = $m;
        ext_modules_save($all);
        $wrote = ext_write_secret($m);
        audit('module.create', 'Módulo incluído por integração: ' . $m['name'] . ' (pasta ' . $m['folder'] . ', ' . plural(count($m['perms']), 'permissão', 'permissões') . ')');
        flash('ok', 'Módulo ' . h($m['name']) . ' incluído. ' . ($wrote ? 'O segredo do login único já foi gravado na pasta dele.' : 'Falta gravar o segredo na pasta dele: veja as instruções abaixo.') . ' Agora libere o módulo para os usuários em Gerenciamento de usuários.');
        go('admin.modulo', ['m' => $m['slug']]);
    }
    $tr = '';
    foreach (ext_modules(false) as $slug => $m) {
        [$stripe, $ink, $soft] = EXT_PALETTE[$m['color']] ?? EXT_PALETTE['cinza'];
        $tr .= '<tr><td><div class="row" style="gap:12px;flex-wrap:nowrap"><span class="ws-ic sm" aria-hidden="true" style="--mc:' . $stripe . ';--mi:' . $ink . ';--ms:' . $soft . '">' . icon($m['icon']) . '</span><div><p class="cell-title"><a href="' . h(url('admin.modulo', ['m' => $slug])) . '">' . h($m['name']) . '</a></p><p class="cell-sub">Pasta ' . h($m['folder']) . '/</p></div></div></td>'
            . '<td class="tnum">' . count($m['perms']) . '</td><td class="tnum">' . ext_user_count($slug) . '</td>'
            . '<td>' . (!empty($m['active']) ? badge('Ligado', 'b-ok') : badge('Desligado', 'b-neutral')) . (ext_secret_in_place($m) ? '' : ' ' . badge('Sem segredo na pasta', 'b-warn')) . '</td>'
            . '<td class="r"><a class="btn btn-sm btn-secondary" href="' . h(url('admin.modulo', ['m' => $slug])) . '">Abrir</a></td></tr>';
    }
    $list = $tr
        ? '<div class="card"><div class="table-wrap"><table><thead><tr><th>Módulo</th><th>Permissões</th><th>Usuários</th><th>Situação</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>'
        : '<div class="card">' . empty_state('blocks', 'Nenhum módulo incluído', 'Envie a pasta do módulo para o servidor e informe o nome dela ao lado.') . '</div>';
    layout('Módulos', crumb('admin.controle', 'Painel de controle') . page_head('Módulos por integração', 'Sistemas à parte, em uma pasta deste site, que entram no Workspace com login único e as permissões que eles próprios declaram.')
        . $list
        . '<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Incluir módulo</h3><p>A pasta precisa estar ao lado do <span class="mono">index.php</span> do Summa e conter o arquivo <span class="mono">' . EXT_MANIFEST . '</span>.</p></div></div><div class="card-body">'
        . form_open('admin.modulos', [], 'class="row" style="align-items:flex-end;gap:12px"') . input('folder', 'Pasta do módulo', '', 'text', 'required maxlength="40" autocomplete="off" spellcheck="false" placeholder="Ex.: estoque"', '', 'grow')
        . '<button class="btn btn-primary" type="submit">' . icon('plus') . ' Ler manifesto e incluir</button></form>'
        . '<p class="small muted" style="margin-top:12px">Informe só o nome da pasta, sem barras. Ao incluir, o Summa lê o manifesto, gera um segredo e grava o arquivo <span class="mono">' . EXT_SECRET_FILE . '</span> na pasta do módulo. O formato do manifesto está em INTEGRACAO-MODULOS.md, no pacote do Summa.</p>'
        . '</div></div>');
}

function route_admin__modulo(): void {
    require_perm('modules:manage');
    $slug = (string) g('m');
    $all = ext_modules(false);
    $m = $all[$slug] ?? null;
    if (!$m) abort(404);
    if (is_post()) {
        $acao = p('acao');
        if ($acao === 'atualizar') {
            [$new, $err] = ext_read_manifest($m['folder']);
            if (!$new) flash('bad', h($err));
            elseif ($new['slug'] !== $slug) flash('bad', 'O manifesto agora traz o slug ' . h($new['slug']) . '. O slug não pode mudar: ele identifica as permissões já liberadas. Para trocar, remova o módulo e inclua de novo.');
            else {
                $gone = array_values(array_diff(array_column($m['perms'], 'code'), array_column($new['perms'], 'code')));
                $all[$slug] = $new + ['secret' => $m['secret'], 'active' => $m['active'], 'created_at' => $m['created_at'], 'updated_at' => now()];
                tx(function () use ($all, $slug, $gone) { ext_modules_save($all); if ($gone) ext_drop_perms($slug, $gone); });
                audit('module.update', 'Manifesto do módulo ' . $new['name'] . ' atualizado' . ($gone ? ' (permissões retiradas: ' . implode(', ', $gone) . ')' : ''));
                flash('ok', 'Manifesto atualizado.' . ($gone ? ' Permissões que saíram do manifesto foram retiradas dos usuários: ' . implode(', ', $gone) . '.' : ''));
            }
        } elseif ($acao === 'ligar' || $acao === 'desligar') {
            $all[$slug]['active'] = $acao === 'ligar';
            ext_modules_save($all);
            audit('module.' . ($acao === 'ligar' ? 'enable' : 'disable'), 'Módulo ' . $m['name'] . ($acao === 'ligar' ? ' ligado' : ' desligado'));
            flash('ok', $acao === 'ligar' ? 'Módulo ligado: ele volta a aparecer para quem tem permissão.' : 'Módulo desligado: ninguém o vê nem entra nele. As permissões dos usuários ficam guardadas.');
        } elseif ($acao === 'segredo') {
            $all[$slug]['secret'] = bin2hex(random_bytes(32));
            ext_modules_save($all);
            $wrote = ext_write_secret($all[$slug]);
            audit('module.secret', 'Novo segredo de login único para o módulo ' . $m['name']);
            flash($wrote ? 'ok' : 'warn', $wrote ? 'Novo segredo gerado e gravado na pasta do módulo. Quem estava no módulo continua até sair.' : 'Novo segredo gerado, mas não consegui gravar na pasta do módulo. Copie o arquivo abaixo para lá.');
        } elseif ($acao === 'gravar') {
            flash(ext_write_secret($m) ? 'ok' : 'bad', ext_secret_in_place($m) ? 'Segredo gravado na pasta do módulo.' : 'Não consegui gravar na pasta do módulo. Crie o arquivo à mão, com o conteúdo abaixo.');
        } elseif ($acao === 'remover') {
            unset($all[$slug]);
            tx(function () use ($all, $slug) { ext_modules_save($all); ext_drop_perms($slug); });
            if (ext_folder_ok($m['folder']) && ext_secret_in_place($m)) @unlink(ext_site_root() . '/' . $m['folder'] . '/' . EXT_SECRET_FILE);
            audit('module.delete', 'Módulo por integração removido: ' . $m['name'] . ' (pasta ' . $m['folder'] . ')');
            flash('ok', 'Módulo ' . h($m['name']) . ' removido do Summa. A pasta e os dados dele não foram apagados.');
            go('admin.modulos');
        }
        go('admin.modulo', ['m' => $slug]);
    }
    [$stripe, $ink, $soft] = EXT_PALETTE[$m['color']] ?? EXT_PALETTE['cinza'];
    $on = !empty($m['active']); $placed = ext_secret_in_place($m);
    $tr = '';
    foreach ($m['perms'] as $p) $tr .= '<tr><td><p class="cell-title">' . h($p['label']) . '</p><p class="cell-sub">No Workspace: ' . h($p['short']) . '</p></td><td class="mono small">' . h($p['code']) . '</td></tr>';
    $act = fn(string $acao, string $label, string $cls, string $ic, string $confirm = '') => form_open('admin.modulo', ['m' => $slug], 'class="inline-form"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : ''))
        . '<input type="hidden" name="acao" value="' . $acao . '"><button class="btn btn-sm ' . $cls . '" type="submit">' . icon($ic) . ' ' . $label . '</button></form>';
    $secretFile = "<?php\nreturn [\n    'slug' => '" . $slug . "',\n    'secret' => '" . $m['secret'] . "',\n    'url' => '../',\n];";
    layout($m['name'], crumb('admin.modulos', 'Módulos por integração')
        . page_head(h($m['name']) . ' ' . ($on ? badge('Ligado', 'b-ok') : badge('Desligado', 'b-neutral')), h($m['desc']),
            $act('atualizar', 'Atualizar manifesto', 'btn-secondary', 'refresh-cw') . ($on ? $act('desligar', 'Desligar', 'btn-secondary', 'power') : $act('ligar', 'Ligar', 'btn-primary', 'power')))
        . '<div class="grid g2" style="align-items:start">'
        . '<div class="card"><div class="card-head"><div><h3>Dados do módulo</h3><p>Vêm do arquivo <span class="mono">' . h($m['folder']) . '/' . EXT_MANIFEST . '</span>. Para mudar, edite o arquivo e clique em Atualizar manifesto.</p></div></div><div class="card-body">'
        . '<dl class="kv"><dt>Card</dt><dd><span class="row" style="gap:10px;flex-wrap:nowrap"><span class="ws-ic sm" aria-hidden="true" style="--mc:' . $stripe . ';--mi:' . $ink . ';--ms:' . $soft . '">' . icon($m['icon']) . '</span>' . h($m['name']) . ' · cor ' . h($m['color']) . '</span></dd>'
        . '<dt>Slug</dt><dd class="mono">' . h($slug) . '</dd><dt>Pasta</dt><dd class="mono">' . h($m['folder']) . '/</dd><dt>Entrada</dt><dd class="mono">' . h($m['folder'] . '/' . $m['entry']) . '</dd>'
        . '<dt>Usuários</dt><dd>' . plural(ext_user_count($slug), 'usuário com permissão', 'usuários com permissão') . ', além de quem tem Acesso total</dd>'
        . '<dt>Incluído em</dt><dd>' . h(fmt_dt($m['created_at'])) . '</dd><dt>Atualizado em</dt><dd>' . h(fmt_dt($m['updated_at'])) . '</dd></dl></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Permissão</th><th>Código</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>'
        . '<div><div class="card" style="margin-bottom:16px"><div class="card-head"><div><h3>Login único</h3><p>O Summa assina o acesso com um segredo que só ele e o módulo conhecem.</p></div></div><div class="card-body">'
        . ($placed ? alert('ok', 'O arquivo <span class="mono">' . h($m['folder']) . '/' . EXT_SECRET_FILE . '</span> está na pasta do módulo com o segredo atual.')
            : alert('warn', 'O segredo atual não está na pasta do módulo, então o login único não vai funcionar. Clique em Gravar na pasta ou crie o arquivo <span class="mono">' . h($m['folder']) . '/' . EXT_SECRET_FILE . '</span> com o conteúdo abaixo.')
                . '<label class="label-like" for="secret-file">Conteúdo do arquivo</label><textarea class="input mono" id="secret-file" rows="7" readonly spellcheck="false">' . h($secretFile) . '</textarea>')
        . '<div class="row" style="margin-top:12px;gap:8px">' . ($placed ? '' : $act('gravar', 'Gravar na pasta', 'btn-primary', 'save'))
        . $act('segredo', 'Gerar novo segredo', 'btn-secondary', 'key-round', 'Gerar um novo segredo? O anterior deixa de valer na hora.') . '</div></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Remover do Summa</h3><p>Tira o card do Workspace e apaga as permissões deste módulo de todos os usuários. A pasta e os dados do módulo não são apagados.</p></div></div><div class="card-body">'
        . $act('remover', 'Remover módulo', 'btn-danger', 'trash-2', 'Remover o módulo ' . $m['name'] . ' do Summa? As permissões dele serão apagadas de todos os usuários.') . '</div></div></div>'
        . '</div>');
}

/** Permissões do usuário neste módulo, com os códigos do manifesto (sem o prefixo do Summa) */
function ext_user_codes(array $u, string $slug): array {
    $prefix = ext_area($slug) . ':';
    $out = [];
    foreach ($u['perms'] as $c) if (str_starts_with($c, $prefix)) $out[] = substr($c, strlen($prefix));
    return $out;
}

/** Login único: confere a permissão e entrega ao módulo um token curto (2 min, uso único), assinado com o segredo dele. */
function route_modulo(string $slug = ''): void {
    $u = current_user();
    $m = ext_modules()[$slug] ?? null;
    if (!$m) abort(404);
    $codes = ext_user_codes($u, $slug);
    if (!$codes) abort(403, 'O módulo ' . $m['name'] . ' não está liberado para o seu usuário.');
    $now = time();
    $claims = ['iss' => 'summa', 'aud' => $slug, 'sub' => (int) $u['id'], 'u' => mb_strtolower((string) $u['email']), 'n' => (string) $u['name'], 'a' => sso_avatar($u['avatar_path'] ?? null),
        'p' => $codes, 'iat' => $now, 'exp' => $now + 120, 'jti' => bin2hex(random_bytes(16))];
    $body = patrimony_b64url(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $token = $body . '.' . patrimony_b64url(hash_hmac('sha256', $body, $m['secret'], true));
    audit('auth.module', 'Abriu o módulo ' . $m['name']);
    header('Location: ' . $m['folder'] . '/' . $m['entry'] . '?sso=' . rawurlencode($token), true, 303);
    exit;
}

/** "Sair" dentro do módulo: encerra também a sessão do Summa, só com o link assinado pelo módulo (2 minutos). */
function route_modulo_sair(string $slug = ''): void {
    $m = ext_modules(false)[$slug] ?? null;
    if (!$m || !patrimony_logout_ok((string) ($_GET['t'] ?? ''), (string) ($_GET['s'] ?? ''), $m['secret'])) go(current_user() ? 'workspace' : 'login');
    if (current_user()) audit('auth.logout', 'Saiu do sistema pelo módulo ' . $m['name']);
    $_SESSION = [];
    session_regenerate_id(true);
    go('login');
}
