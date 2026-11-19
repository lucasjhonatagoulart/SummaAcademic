<?php
// Início (quadro de avisos + comunicados), Meu perfil e gestão de comunicados e avisos.

/** Imagem de capa do comunicado/aviso: clique abre ampliada (sem JS, abre a imagem em outra aba) */
function ann_cover(array $n, string $cls): string {
    if (empty($n['image_path'])) return '';
    return '<a class="' . $cls . '" href="' . h($n['image_path']) . '" target="_blank" rel="noopener" data-lightbox title="Ampliar imagem"><img src="' . h($n['image_path']) . '" alt="Imagem: ' . h($n['title']) . '" loading="lazy"><span class="zoom-hint">' . icon('maximize-2') . '</span></a>';
}
function home_feed(): void {
    $u = current_user();
    $today = today();
    $seesAll = can('announcements:manage', 'board:manage');
    $aud = audiences_for($u['roles'], $u['perms']);
    $list = rows('SELECT * FROM announcements WHERE institution_id = ? AND published_on <= ? AND (expires_on IS NULL OR expires_on >= ?)' . ($seesAll ? '' : ' AND audience IN (' . in_list($aud) . ')') . ' ORDER BY pinned DESC, published_on DESC, id DESC',
        array_merge([inst_id(), $today, $today], $seesAll ? [] : $aud));
    $board = array_values(array_filter($list, fn($a) => $a['kind'] === 'BOARD'));
    $comms = array_values(array_filter($list, fn($a) => $a['kind'] === 'COMM'));
    $cats = array_values(array_filter(array_keys(BOARD_CATEGORY), fn($c) => in_array($c, array_column($board, 'category'), true)));
    $cat = in_array(strtoupper(g('categoria')), $cats, true) ? strtoupper(g('categoria')) : '';
    $shown = $cat ? array_filter($board, fn($a) => $a['category'] === $cat) : $board;
    $route = $GLOBALS['ROUTE'];
    $tag = fn($a) => $seesAll && $a['audience'] !== 'ALL' ? badge(AUDIENCE_LABEL[$a['audience']]) : '';
    $hour = (int) date('G');
    $day = date_long(today());
    $action = can('announcements:manage') ? '<a class="btn btn-primary" href="' . h(url('mural')) . '">' . icon('megaphone') . ' Publicar comunicado ou aviso</a>'
        : (can('board:manage') ? '<a class="btn btn-primary" href="' . h(url('mural', ['tipo' => 'avisos'])) . '">' . icon('megaphone') . ' Publicar aviso</a>' : '');
    $chips = '';
    if (count($cats) > 1) {
        $chips = '<div class="chips" role="group" aria-label="Filtrar avisos por categoria"><a class="chip ' . ($cat ? '' : 'on') . '" href="' . h(url($route)) . '">Todos</a>';
        foreach ($cats as $c) $chips .= '<a class="chip ' . ($cat === $c ? 'on' : '') . '" href="' . h(url($route, ['categoria' => strtolower($c)])) . '">' . BOARD_CATEGORY[$c][0] . '</a>';
        $chips .= '</div>';
    }
    $cards = '';
    foreach ($shown as $n) {
        [$label, $ic, $c1, $soft, $ink] = BOARD_CATEGORY[$n['category']] ?? BOARD_CATEGORY['INSTITUTIONAL'];
        $cards .= '<article class="notice notice-' . strtolower($n['category'] ?? 'institutional') . ($n['image_path'] ? ' has-img' : '') . '" style="--c:' . $c1 . ';--c-soft:' . $soft . ';--c-ink:' . $ink . '">' . ann_cover($n, 'notice-img') . '<div class="row notice-top"><span class="notice-cat">' . icon($ic) . $label . '</span>' . ($n['pinned'] ? '<span class="notice-pin" title="Fixado no topo">' . icon('pin') . '</span>' : '') . '</div>'
            . '<h4>' . h($n['title']) . '</h4><p class="notice-body">' . nl2br(h($n['body'])) . '</p><div class="notice-foot"><span>' . h($n['author_label']) . '</span><span>' . ($n['expires_on'] ? 'Até ' . date('d/m', strtotime($n['expires_on'])) : 'Desde ' . date('d/m', strtotime($n['published_on']))) . '</span></div>' . ($tag($n) ? '<span class="notice-aud">' . $tag($n) . '</span>' : '') . '</article>';
    }
    $commHtml = '';
    foreach ($comms as $n) {
        $long = mb_strlen($n['body']) > 220;
        $commHtml .= '<article class="comm">' . ann_cover($n, 'comm-img') . (($n['pinned'] || $tag($n)) ? '<div class="row" style="gap:6px;margin-bottom:6px">' . ($n['pinned'] ? badge(icon('pin') . ' Em destaque', 'b-warn') : '') . $tag($n) . '</div>' : '')
            . '<h4>' . h($n['title']) . '</h4><p class="comm-meta">' . fmt_date($n['published_on']) . ' · ' . h($n['author_label']) . '</p>'
            . ($long ? '<details><summary class="comm-body clamp">' . nl2br(h($n['body'])) . '</summary><p class="comm-body">' . nl2br(h($n['body'])) . '</p></details>' : '<p class="comm-body">' . nl2br(h($n['body'])) . '</p>') . '</article>';
    }
    layout('Início', page_head(($hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite')) . ', ' . h(first_name($u['name'])), h($day) . ' · comunicados da instituição e quadro de avisos.', $action)
        . '<div class="home-grid"><section class="card" aria-labelledby="h-board"><div class="card-head"><div><h3 id="h-board">Quadro de avisos</h3><p>Eventos, campanhas e lembretes da instituição.</p></div></div>' . $chips
        . '<div class="card-body">' . ($cards ? '<div class="notice-grid">' . $cards . '</div>' : empty_state('layout-grid', 'Nenhum aviso no quadro', 'Os avisos publicados pela instituição aparecem aqui.')) . '</div></section>'
        . '<section class="card" aria-labelledby="h-comms"><div class="card-head"><div><h3 id="h-comms">Comunicados</h3><p>Informações oficiais da instituição.</p></div></div><div class="card-body">' . ($commHtml ?: empty_state('megaphone', 'Nenhum comunicado no momento')) . '</div></section></div>');
}
function route_admin__inicio(): void { home_feed(); }
function route_dir__inicio(): void { home_feed(); }
function route_fin__inicio(): void { home_feed(); }
function route_prof__inicio(): void { home_feed(); }
function route_aluno__inicio(): void { home_feed(); }

// ---------- Meu perfil ----------
function route_perfil(): void {
    $u = current_user();
    $selfEdit = can('users:manage'); // o Administrador pode corrigir o próprio cadastro
    if (is_post() && p('acao') === 'salvar') {
        // 1) valida tudo antes de gravar qualquer coisa
        $errs = [];
        $phone = mb_substr(p('phone'), 0, 30);
        $data = ['phone' => $phone];
        $emailChanged = false;
        if ($selfEdit) {
            $name = preg_replace('/\s+/', ' ', p('name')); $email = mb_strtolower(p('email'));
            $emailChanged = $email !== mb_strtolower($u['email']);
            if (mb_strlen($name) < 3) $errs[] = 'Informe o nome completo.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'E-mail inválido.';
            elseif ($emailChanged && row('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) $errs[] = 'Este e-mail já está em uso por outro usuário.';
            elseif ($emailChanged && !password_verify((string) ($_POST['current'] ?? ''), (string) val('SELECT password_hash FROM users WHERE id = ?', [$u['id']]))) $errs[] = 'Para trocar o e-mail, confirme com a sua senha atual.';
            $data += ['name' => mb_substr($name, 0, 160), 'email' => $email];
        }
        $file = $_FILES['foto'] ?? null;
        if ((!$file || $file['error'] === UPLOAD_ERR_NO_FILE) && p('foto_data') !== '') $file = data_url_upload((string) $_POST['foto_data']) ?? ['error' => UPLOAD_ERR_PARTIAL];
        $newPhoto = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($errs) { flash('bad', implode(' ', $errs) . ' Nada foi salvo.'); go('perfil'); }
        // 2) grava
        $done = [];
        if ($newPhoto) {
            [$path, $err] = save_image($file, 'avatar-' . $u['id'], 'avatar');
            if ($err) { flash('bad', $err . ' Nada foi salvo.'); go('perfil'); }
            remove_upload($u['avatar_path']); $data['avatar_path'] = $path; $done[] = 'foto';
        } elseif (p('remover_foto') === '1' && $u['avatar_path']) { remove_upload($u['avatar_path']); $data['avatar_path'] = null; $done[] = 'foto removida'; }
        update('users', $data, 'id = ?', [$u['id']]);
        audit('user.self_update', 'Atualizou o próprio perfil' . ($done ? ' (' . implode(', ', $done) . ')' : '') . ($emailChanged ? " · e-mail {$u['email']} → {$data['email']}" : ''), 'user', $u['id']);
        flash('ok', 'Informações salvas.' . ($emailChanged ? ' Use o novo e-mail no próximo acesso.' : ''));
        go('perfil');
    }
    $st = $u['student'] ? student_full((int) $u['student']['id']) : null;
    $pr = $u['professor'];
    $dl = fn($k, $v) => '<dt>' . $k . '</dt><dd>' . ($v === null || $v === '' ? '<span class="muted">—</span>' : $v) . '</dd>';
    $info = ($selfEdit ? '' : $dl('Nome', h($u['name'])) . $dl('E-mail', h($u['email']))) . $dl('Acesso', h(role_names($u['roles'], $u['perms']))) . $dl('Último acesso', fmt_dt($u['last_login_at']));
    if ($st) $info .= $dl('RA', '<strong class="tnum">' . h($st['ra']) . '</strong>') . $dl('Curso', h($st['course_name'])) . $dl('Turma', h($st['group_code'] ?? '—')) . $dl('Ingresso', h($st['entry_term'])) . $dl('CPF', h($st['cpf'])) . $dl('Situação', STUDENT_STATUS[$st['status']] ?? $st['status']);
    if ($pr) $info .= $dl('Matrícula docente', h($pr['registration'])) . $dl('Titulação', h($pr['title'])) . $dl('Departamento', h($pr['department']));

    $photo = '<div class="avatar-edit"><label class="avatar-pick" title="Trocar foto">' . avatar($u['avatar_path'], $u['name'], 'width:112px;height:112px;font-size:34px') . '<span class="avatar-cam">' . icon('camera') . '</span>'
        . '<input type="file" name="foto" accept="image/jpeg,image/png,image/webp" data-cropper class="sr-only"></label>'
        . '<input type="hidden" name="foto_data" value=""><input type="hidden" name="remover_foto" value="0">'
        . '<div class="stack" style="gap:8px"><strong>' . h($u['name']) . '</strong><span class="small muted">' . h(role_names($u['roles'], $u['perms'])) . '</span><div class="row" style="gap:8px;flex-wrap:wrap">'
        . '<button class="btn btn-secondary btn-sm" type="button" data-pick-avatar>' . icon('image-up') . ' ' . ($u['avatar_path'] ? 'Trocar foto' : 'Escolher foto') . '</button>'
        . ($u['avatar_path'] ? '<button class="btn btn-ghost btn-sm" type="button" data-remove-avatar data-initials="' . h(initials($u['name'])) . '">Remover foto</button>' : '')
        . '</div><span class="small muted" data-photo-status>JPG, PNG ou WebP. Fotos grandes do celular também servem.</span></div></div>';
    $fields = $selfEdit
        ? input('name', 'Nome completo', $u['name'], 'text', 'required maxlength="160" autocomplete="name"', '', 'span-2')
            . input('email', 'E-mail de acesso', $u['email'], 'email', 'required autocomplete="email"')
            . input('phone', 'Telefone', $u['phone'], 'tel', 'maxlength="30" autocomplete="tel"')
            . input('current', 'Senha atual', '', 'password', 'autocomplete="current-password"', 'Só é exigida se você trocar o e-mail.', 'span-2')
        : input('phone', 'Telefone', $u['phone'], 'tel', 'maxlength="30" autocomplete="tel"', '', 'span-2');
    $form = '<form method="post" action="' . h(url('perfil')) . '" enctype="multipart/form-data" class="card" data-profile-form>' . csrf() . '<input type="hidden" name="acao" value="salvar">'
        . '<div class="card-head"><div><h3>' . ($selfEdit ? 'Meu cadastro' : 'Foto e contato') . '</h3><p>Altere o que precisar e clique em <strong>Salvar informações</strong>.</p></div></div>'
        . '<div class="card-body stack" style="gap:20px">' . $photo . '<div class="form-grid cols-2">' . $fields . '</div></div>'
        . '<div class="card-foot"><a class="btn btn-ghost" href="' . h(url('senha')) . '">' . icon('key-round') . ' Alterar senha</a><span class="small muted unsaved-note" data-unsaved hidden>Alterações não salvas</span><button class="btn btn-primary" type="submit">' . icon('check') . ' Salvar informações</button></div></form>';
    layout('Meu perfil', page_head('Meu perfil', $selfEdit ? 'Seus dados de cadastro, foto e senha.' : 'Seus dados de cadastro e foto. Para corrigir nome, CPF ou curso, procure a secretaria.')
        . '<div class="grid g2" style="align-items:start">' . $form
        . '<div class="card"><div class="card-head"><div><h3>' . ($selfEdit ? 'Acesso' : 'Dados do cadastro') . '</h3></div></div><div class="card-body"><dl class="kv">' . $info . '</dl></div></div></div>');
}

// ---------- Gestão de comunicados e avisos ----------
function ann_kinds(): array {
    return array_values(array_filter(['COMM', 'BOARD'], fn($k) => can($k === 'COMM' ? 'announcements:manage' : 'board:manage')));
}
function route_mural(): void {
    $u = require_perm('announcements:manage', 'board:manage');
    $kinds = ann_kinds();
    if (is_post() && p('acao') === 'remover') {
        $a = row('SELECT * FROM announcements WHERE id = ? AND institution_id = ?', [pi_('id'), inst_id()]);
        if ($a && in_array($a['kind'], $kinds, true) && can_edit_announcement($u['perms'], $u['roles'], $a)) {
            q('DELETE FROM announcements WHERE id = ?', [$a['id']]);
            remove_upload($a['image_path'] ?? null);
            audit('announcement.delete', ($a['kind'] === 'COMM' ? 'Comunicado' : 'Aviso') . ' removido: ' . $a['title'], 'announcement', (int) $a['id']);
            flash('ok', 'Publicação removida.');
        } else flash('bad', 'Você não pode remover esta publicação.');
        go('mural', ['tipo' => ($a['kind'] ?? 'COMM') === 'BOARD' ? 'avisos' : 'comunicados']);
    }
    $kind = g('tipo') === 'avisos' && in_array('BOARD', $kinds, true) ? 'BOARD' : (g('tipo') === 'comunicados' && in_array('COMM', $kinds, true) ? 'COMM' : $kinds[0]);
    $list = rows('SELECT a.*, u.name AS creator FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.institution_id = ? AND a.kind = ? ORDER BY a.published_on DESC, a.id DESC', [inst_id(), $kind]);
    $st = ['PUBLISHED' => ['Publicado', 'b-ok'], 'SCHEDULED' => ['Agendado', 'b-info'], 'EXPIRED' => ['Encerrado', 'b-neutral']];
    $one = $kind === 'COMM' ? 'comunicado' : 'aviso';
    $rowsHtml = '';
    foreach ($list as $a) {
        [$sl, $tone] = $st[announcement_state($a['published_on'], $a['expires_on'], today())];
        $edit = can_edit_announcement($u['perms'], $u['roles'], $a);
        $rowsHtml .= '<tr><td><p class="cell-title">' . ($a['pinned'] ? '<span style="color:#C58A1E">' . icon('pin') . '</span> ' : '') . h($a['title']) . ($a['image_path'] ? ' <span class="muted" title="Com imagem">' . icon('image') . '</span>' : '') . '</p>' . ($a['category'] ? '<p class="cell-sub">' . BOARD_CATEGORY[$a['category']][0] . '</p>' : '') . '</td>'
            . '<td>' . AUDIENCE_LABEL[$a['audience']] . '</td><td>' . h($a['author_label']) . '<p class="cell-sub">' . h($a['creator']) . '</p></td>'
            . '<td class="tnum" style="white-space:nowrap">' . fmt_date($a['published_on']) . ($a['expires_on'] ? ' a ' . fmt_date($a['expires_on']) : ' em diante') . '</td><td>' . badge($sl, $tone) . '</td>'
            . '<td class="r" style="white-space:nowrap">' . ($edit ? '<a class="btn btn-sm btn-secondary" href="' . h(url('mural.form', ['id' => $a['id']])) . '">' . icon('pencil') . ' Editar</a> '
                . form_open('mural', [], 'class="inline-form" data-confirm="Remover esta publicação?"') . '<input type="hidden" name="acao" value="remover"><input type="hidden" name="id" value="' . $a['id'] . '"><button class="btn btn-sm btn-ghost" type="submit" title="Remover" aria-label="Remover ' . h($a['title']) . '">' . icon('trash-2') . '</button></form>'
                : '<span class="muted small">Publicado por outro setor</span>') . '</td></tr>';
    }
    $tabs = count($kinds) > 1 ? tabs([['COMM', 'Comunicados', 'megaphone', url('mural', ['tipo' => 'comunicados'])], ['BOARD', 'Quadro de avisos', 'layout-grid', url('mural', ['tipo' => 'avisos'])]], $kind, 'Tipo de publicação') : '';
    layout('Comunicados e avisos', page_head(count($kinds) > 1 ? 'Comunicados e avisos' : 'Quadro de avisos', 'O que for publicado aparece na tela inicial de quem faz parte do público escolhido.',
            '<a class="btn btn-primary" href="' . h(url('mural.form', ['tipo' => $kind === 'COMM' ? 'comunicados' : 'avisos'])) . '">' . icon('plus') . ' Novo ' . $one . '</a>') . $tabs
        . alert('info', $kind === 'COMM' ? 'Prazos, rematrícula, feriados e outras informações da instituição. Use “Em destaque” para manter um comunicado no topo.' : 'Até 280 caracteres' . (can('announcements:manage') ? '' : '. Você edita e remove os avisos do seu setor') . '. Cada aviso sai do quadro sozinho na data “Exibir até”.', $kind === 'COMM' ? 'Comunicados oficiais' : 'Cartões curtos para eventos, campanhas e lembretes')
        . '<div class="card" style="margin-top:16px"><div class="table-wrap"><table><thead><tr><th>Título</th><th>Público</th><th>Publicado por</th><th>Exibição</th><th>Situação</th><th></th></tr></thead><tbody>' . $rowsHtml . '</tbody></table></div>'
        . ($list ? '' : '<div class="card-body">' . empty_state('megaphone', "Nenhum $one publicado") . '</div>') . '</div>');
}
function route_mural__form(): void {
    $u = require_perm('announcements:manage', 'board:manage');
    $id = gi('id');
    $a = $id ? row('SELECT * FROM announcements WHERE id = ? AND institution_id = ?', [$id, inst_id()]) : null;
    if ($id && (!$a || !can_edit_announcement($u['perms'], $u['roles'], $a))) abort(403);
    $kind = $a['kind'] ?? (g('tipo') === 'avisos' ? 'BOARD' : 'COMM');
    if (!in_array($kind, ann_kinds(), true)) abort(403);
    $d = $a ?? ['title' => '', 'body' => '', 'category' => 'EVENT', 'audience' => 'ALL', 'published_on' => today(), 'expires_on' => '', 'pinned' => 0];
    $err = '';
    if (is_post()) {
        $d = ['kind' => $kind, 'title' => p('title'), 'body' => p('body'), 'category' => $kind === 'BOARD' ? p('category') : null, 'audience' => p('audience'), 'published_on' => p('published_on'), 'expires_on' => p('expires_on') ?: null, 'pinned' => isset($_POST['pinned']) ? 1 : 0];
        $err = validate_announcement($d, today());
        $file = $_FILES['imagem'] ?? null;
        $newImg = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $oldImg = $a['image_path'] ?? null;
        if (!$err && $newImg) {
            $mime = $file['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
            if ($file['error'] === UPLOAD_ERR_OK && !in_array($mime, ['image/png', 'image/jpeg'], true)) $err = 'A imagem precisa ser PNG ou JPG.';
            else { [$path, $e] = save_image($file, 'mural', 'banner'); if ($e) $err = $e; else $d['image_path'] = $path; }
        } elseif (!$err && isset($_POST['remover_imagem']) && $oldImg) $d['image_path'] = null;
        if ($err) $d['image_path'] = $oldImg;
        if (!$err) {
            if (array_key_exists('image_path', $d) && $d['image_path'] !== $oldImg) remove_upload($oldImg);
            if ($a) { update('announcements', $d, 'id = ?', [$a['id']]); audit('announcement.update', ($kind === 'COMM' ? 'Comunicado' : 'Aviso') . ' alterado: ' . $d['title'], 'announcement', (int) $a['id']); flash('ok', 'Alterações salvas.'); }
            else {
                $nid = insert('announcements', $d + ['institution_id' => inst_id(), 'author_label' => sector_for($u['roles'], $u['perms']), 'created_by' => $u['id'], 'created_at' => now()]);
                audit('announcement.create', ($kind === 'COMM' ? 'Comunicado' : 'Aviso') . ' publicado: ' . $d['title'] . ' · ' . AUDIENCE_LABEL[$d['audience']], 'announcement', $nid);
                flash('ok', $d['published_on'] > today() ? 'Agendado: aparece na tela inicial a partir de ' . fmt_date($d['published_on']) . '.' : 'Publicado. Já aparece na tela inicial de: ' . mb_strtolower(AUDIENCE_LABEL[$d['audience']]) . '.');
            }
            go('mural', ['tipo' => $kind === 'COMM' ? 'comunicados' : 'avisos']);
        }
    }
    $board = $kind === 'BOARD';
    $one = $board ? 'aviso' : 'comunicado';
    layout(($a ? 'Editar ' : 'Novo ') . $one, crumb('mural', $board ? 'Quadro de avisos' : 'Comunicados', ['tipo' => $board ? 'avisos' : 'comunicados']) . page_head(($a ? 'Editar ' : 'Novo ') . $one, 'Aparece na tela inicial do público escolhido, na data de publicação.')
        . ($err ? alert('bad', h($err)) : '') . '<div class="card" style="max-width:760px"><div class="card-body" style="padding-top:20px">' . form_open('mural.form', $a ? ['id' => $a['id']] : ['tipo' => $board ? 'avisos' : 'comunicados'], 'class="form-grid cols-2" enctype="multipart/form-data"')
        . input('title', 'Título', $d['title'], 'text', 'required maxlength="120"', '', 'span-2')
        . textarea('body', 'Texto', $d['body'], 'required rows="' . ($board ? 3 : 7) . '" maxlength="' . ($board ? 280 : 2000) . '"', $board ? 'Até 280 caracteres, para caber no cartão do quadro.' : '', 'span-2')
        . ($board ? select('category', 'Categoria', array_map(fn($c) => $c[0], BOARD_CATEGORY), $d['category'], '', 'Define a cor do cartão.') : '')
        . select('audience', 'Público', AUDIENCE_LABEL, $d['audience'], '', 'Quem vê na tela inicial.', $board ? '' : 'span-2')
        . input('published_on', 'Publicar em', $d['published_on'], 'date', 'required', 'Data futura: fica agendado.')
        . input('expires_on', 'Exibir até (opcional)', $d['expires_on'] ?? '', 'date', '', 'Em branco: fica até ser removido.')
        . '<div class="field span-2"><span class="label-like">Imagem (opcional)</span>'
            . '<div class="ann-img-preview"' . (empty($d['image_path']) ? ' hidden' : '') . '>' . (!empty($d['image_path']) ? '<img src="' . h($d['image_path']) . '" alt="Imagem atual">' : '') . '</div>'
            . '<label class="file-pick"><input type="file" name="imagem" accept="image/png,image/jpeg" data-image-shrink="1600" data-max-bytes="' . upload_limit() . '" data-preview=".ann-img-preview" data-alt="Prévia da imagem">' . icon('image-up') . '<span>' . (empty($d['image_path']) ? 'Escolher imagem PNG ou JPG' : 'Trocar imagem') . '</span></label>'
            . '<small>Aparece como capa no ' . ($board ? 'cartão do quadro' : 'comunicado') . '; ao clicar, abre ampliada. Imagens grandes são reduzidas automaticamente.</small>'
            . (!empty($a['image_path']) ? checkbox('remover_imagem', 'Remover a imagem atual', false) : '') . '</div>'
        . '<div class="span-2">' . checkbox('pinned', $board ? 'Fixar no topo do quadro' : 'Em destaque', (bool) $d['pinned'], 'Aparece antes dos demais.') . '</div>'
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><a class="btn btn-secondary" href="' . h(url('mural', ['tipo' => $board ? 'avisos' : 'comunicados'])) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . ($a ? 'Salvar alterações' : 'Publicar') . '</button></div></form></div></div>');
}
