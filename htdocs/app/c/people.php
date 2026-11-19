<?php
// Alunos e professores (secretaria e diretoria), ficha com abas, histórico escolar e documentos emitidos.

// ---------- Lista de alunos ----------
function students_list(string $route, string $fichaRoute, bool $readonly): void {
    $q = g('q'); $curso = gi('curso'); $per = gi('per'); $turma = gi('turma'); $disc = gi('disciplina'); $sit = g('situacao', 'ATIVO');
    $term = active_term();
    $w = ['u.institution_id = ?']; $par = [inst_id()];
    if ($q !== '') { $w[] = '(u.name LIKE ? OR s.ra LIKE ? OR u.email LIKE ?)'; array_push($par, "%$q%", '%' . (only_digits($q) ?: $q) . '%', "%$q%"); }
    if ($curso) { $w[] = 's.course_id = ?'; $par[] = $curso; }
    if ($per) { $w[] = 'g.period_number = ?'; $par[] = $per; }
    if ($turma) { $w[] = 's.group_id = ?'; $par[] = $turma; }
    if ($disc) { $w[] = "EXISTS (SELECT 1 FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE e.student_id = s.id AND se.subject_id = ? AND e.status = 'CURSANDO')"; $par[] = $disc; }
    if (isset(STUDENT_STATUS[$sit])) { $w[] = 's.status = ?'; $par[] = $sit; }
    $list = rows('SELECT s.*, u.name, u.email, u.avatar_path, c.code AS course_code, g.code AS group_code, g.period_number FROM students s JOIN users u ON u.id = s.user_id JOIN courses c ON c.id = s.course_id LEFT JOIN class_groups g ON g.id = s.group_id WHERE ' . implode(' AND ', $w) . ' ORDER BY u.name LIMIT 1000', $par);
    $total = (int) val('SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE u.institution_id = ?', [inst_id()]);
    $tr = '';
    foreach ($list as $s) {
        $href = url($fichaRoute, ['id' => $s['id']]);
        $tr .= '<tr class="clickable" data-href="' . h($href) . '"><td><div class="row" style="flex-wrap:nowrap">' . avatar($s['avatar_path'], $s['name'], 'width:30px;height:30px;font-size:11px') . '<div><a class="cell-link" href="' . h($href) . '">' . h($s['name']) . '</a><p class="cell-sub">' . h($s['email']) . '</p></div></div></td>'
            . '<td class="tnum">' . h($s['ra']) . '</td><td>' . h($s['course_code']) . '</td><td>' . ($s['group_code'] ? badge(h($s['group_code'])) . ' <span class="small muted">' . $s['period_number'] . 'º</span>' : '<span class="muted">—</span>') . '</td>'
            . '<td>' . badge(STUDENT_STATUS[$s['status']], $s['status'] === 'ATIVO' ? 'b-ok' : 'b-neutral') . '</td><td class="r chev-cell">' . icon('chevron-right') . '</td></tr>';
    }
    $courses = []; foreach (rows('SELECT id, code, name FROM courses WHERE institution_id = ? ORDER BY name', [inst_id()]) as $c) $courses[$c['id']] = $c['code'] . ' · ' . $c['name'];
    $groups = []; foreach ($term ? rows('SELECT id, code FROM class_groups WHERE term_id = ? ORDER BY code', [$term['id']]) : [] as $g) $groups[$g['id']] = $g['code'];
    $subjects = []; foreach ($term ? rows('SELECT DISTINCT su.id, su.name FROM sections se JOIN subjects su ON su.id = se.subject_id WHERE se.term_id = ? ORDER BY su.name', [$term['id']]) : [] as $x) $subjects[$x['id']] = $x['name'];
    $pers = []; for ($i = 1; $i <= 10; $i++) $pers[$i] = "{$i}º período";
    $action = !$readonly && can('students:manage') ? '<a class="btn btn-primary" href="' . h(url('admin.aluno_form')) . '">' . icon('user-plus') . ' Novo aluno</a>' : '';
    $filtered = $q || $curso || $per || $turma || $disc || $sit !== 'ATIVO';
    layout('Alunos', page_head('Alunos', 'Busque por nome ou RA e filtre por curso, período, turma ou disciplina. Clique no aluno para abrir a ficha.', $action)
        . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="' . h($route) . '">'
        . input('q', 'Buscar', $q, 'search', 'placeholder="Nome, RA ou e-mail"', '', 'filters-q') . select('curso', 'Curso', $courses, $curso, 'data-autosubmit', '', '', 'Todos')
        . select('per', 'Período', $pers, $per, 'data-autosubmit', '', '', 'Todos') . select('turma', 'Turma', $groups, $turma, 'data-autosubmit', '', '', 'Todas')
        . select('disciplina', 'Disciplina', $subjects, $disc, 'data-autosubmit', '', '', 'Todas') . select('situacao', 'Situação', STUDENT_STATUS + ['todos' => 'Todas'], $sit, 'data-autosubmit')
        . '<button class="btn btn-secondary" type="submit">' . icon('search') . ' Buscar</button>' . ($filtered ? '<a class="btn btn-ghost" href="' . h(url($route)) . '">Limpar</a>' : '') . '</form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Aluno</th><th>RA</th><th>Curso</th><th>Turma</th><th>Situação</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('search', 'Nenhum aluno encontrado', 'Ajuste a busca ou os filtros.') . '</td></tr>') . '</tbody></table></div>'
        . result_line(count($list), $total, 'alunos') . '</div>');
}
function route_admin__alunos(): void { require_perm('students:manage'); students_list('admin.alunos', 'admin.aluno', false); }
function route_dir__alunos(): void { require_perm('reports:institutional'); students_list('dir.alunos', 'dir.aluno', true); }

// ---------- Ficha do aluno ----------
function student_profile(string $route, bool $readonly): void {
    $s = student_full(gi('id'));
    if (!$s) abort(404);
    $canEdit = !$readonly && can('students:manage');
    if (is_post() && $canEdit) student_profile_post($s);
    $tab = g('aba', 'resumo');
    $base = url($route, ['id' => $s['id']]);
    $items = [['resumo', 'Resumo', 'layout-dashboard'], ['dados', 'Dados cadastrais', 'id-card'], ['disciplinas', 'Disciplinas', 'book-open'], ['matriz', 'Matriz curricular', 'list-checks']];
    if (can('finance:read')) $items[] = ['financeiro', 'Financeiro', 'wallet'];
    if (!$readonly && can('transcripts:issue')) $items[] = ['historico', 'Histórico escolar', 'scroll-text'];
    if (!in_array($tab, array_column($items, 0), true)) $tab = 'resumo';
    $term = active_term();
    $prog = course_progress($s);
    $head = crumb($readonly ? 'dir.alunos' : 'admin.alunos', 'Alunos') . page_head('Ficha do aluno', 'Dados cadastrais, situação acadêmica e andamento no curso.', $canEdit ? '<a class="btn btn-secondary" href="' . h(url('admin.aluno_form', ['id' => $s['id']])) . '">' . icon('pencil') . ' Editar cadastro</a>' : '')
        . '<div class="card ficha-head" style="margin-bottom:16px"><div class="card-body" style="padding-top:20px"><div class="row" style="gap:16px;flex-wrap:wrap">' . avatar($s['avatar_path'], $s['name'], 'width:56px;height:56px;font-size:18px')
        . '<div class="grow"><h2 style="font-size:19px;font-weight:600;color:var(--navy)">' . h($s['name']) . '</h2><div class="row ficha-meta" style="gap:10px;margin-top:4px;flex-wrap:wrap"><span class="tnum">RA <strong>' . h($s['ra']) . '</strong></span><span>' . h($s['course_name']) . '</span>' . ($s['group_code'] ? badge(h($s['group_code'])) : '') . badge(STUDENT_STATUS[$s['status']], $s['status'] === 'ATIVO' ? 'b-ok' : 'b-neutral') . '</div></div>'
        . '<div style="min-width:200px"><p class="small muted">Carga horária obrigatória cumprida</p><p style="font-weight:600;color:var(--navy)">' . $prog['done'] . ' de ' . $prog['required'] . ' h (' . num($prog['pct']) . '%)</p><div class="bar ok" style="width:100%;margin-top:6px"><span style="width:' . $prog['pct'] . '%"></span></div></div></div></div></div>'
        . tabs(array_map(fn($i) => [$i[0], $i[1], $i[2], $base . '&aba=' . $i[0]], $items), $tab, 'Seções da ficha');
    $body = match ($tab) {
        'dados' => student_tab_dados($s),
        'disciplinas' => student_tab_disciplinas($s, $term, $canEdit, $route),
        'matriz' => student_tab_matriz($prog),
        'financeiro' => student_tab_financeiro($s),
        'historico' => student_tab_historico($s, $route),
        default => student_tab_resumo($s, $term, $prog),
    };
    layout($s['name'], $head . $body);
}
function route_admin__aluno(): void { require_perm('students:manage'); student_profile('admin.aluno', false); }
function route_dir__aluno(): void { require_perm('reports:institutional'); student_profile('dir.aluno', true); }

function student_profile_post(array $s): void {
    $act = p('acao');
    if ($act === 'matricular') {
        $sec = section_full(pi_('section_id'));
        if (!$sec || $sec['term_closed']) flash('bad', 'Oferta inválida.');
        elseif (row('SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?', [$s['id'], $sec['id']])) flash('bad', 'O aluno já está nesta oferta.');
        else {
            $mine = rows("SELECT sc.*, se.code FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN enrollments e ON e.section_id = se.id WHERE e.student_id = ? AND se.term_id = ? AND e.status = 'CURSANDO'", [$s['id'], $sec['term_id']]);
            $conf = []; foreach ($sec['schedules'] as $a) foreach ($mine as $b) if (slots_overlap($a, $b)) $conf[] = $b['code'] . ' (' . slot_label($b) . ')';
            if ($conf) flash('bad', 'Conflito de horário com ' . h(implode(', ', array_unique($conf))) . '.');
            else { insert('enrollments', ['student_id' => $s['id'], 'section_id' => $sec['id'], 'status' => 'CURSANDO', 'created_at' => now()]); audit('student.enroll', "Matrícula de {$s['name']} em {$sec['code']}", 'student', (int) $s['id']); flash('ok', 'Matrícula feita em ' . h($sec['subject_name']) . '.'); }
        }
    } elseif ($act === 'trancar_disc') {
        $e = row('SELECT e.*, se.code FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE e.id = ? AND e.student_id = ?', [pi_('enrollment_id'), $s['id']]);
        if ($e && $e['status'] === 'CURSANDO') { update('enrollments', ['status' => 'TRANCADO'], 'id = ?', [$e['id']]); audit('student.drop', "Trancamento de {$e['code']} para {$s['name']}", 'student', (int) $s['id'], p('reason') ?: null); flash('ok', 'Disciplina trancada.'); }
    }
    go($GLOBALS['ROUTE'], ['id' => $s['id'], 'aba' => g('aba')]);
}

function student_tab_resumo(array $s, ?array $term, array $prog): string {
    $enr = $term ? student_term_enrollments((int) $s['id'], (int) $term['id']) : [];
    $risk = 0; $avgs = []; $atts = [];
    $tr = '';
    foreach ($enr as $e) {
        if ($e['status'] === 'TRANCADO') continue;
        $r = enrollment_result($e, $e['section']);
        if ($r['risk']) $risk++;
        if ($r['avg'] !== null) $avgs[] = $r['avg']; if ($r['att'] !== null) $atts[] = $r['att'];
        $tr .= '<tr><td>' . h($e['section']['subject_name']) . '<p class="cell-sub">' . h($e['section']['code']) . '</p></td><td class="tnum r">' . num($r['avg']) . '</td><td>' . ($e['section']['tracks_attendance'] ? pct_bar($r['att'], (float) $e['section']['min_attendance']) : '<span class="muted small">Sem frequência</span>') . '</td><td>' . ($r['risk'] ? badge('Em risco', 'b-warn') : badge(STATUS_LABEL[$r['status']], STATUS_TONE[$r['status']])) . '</td></tr>';
    }
    $fin = can('finance:read') ? (int) val("SELECT COUNT(*) FROM charges WHERE student_id = ? AND status = 'ABERTA' AND due_on < ?", [$s['id'], today()]) : null;
    return '<div class="grid g4" style="margin-bottom:16px">' . stat_card('Média geral no período', num($avgs ? array_sum($avgs) / count($avgs) : null), 'chart-line') . stat_card('Frequência média', $atts ? num(array_sum($atts) / count($atts)) . '%' : '—', 'calendar-check')
        . stat_card('Disciplinas em risco', (string) $risk, 'triangle-alert', $risk ? 'warn' : '') . ($fin !== null ? stat_card('Mensalidades vencidas', (string) $fin, 'wallet', $fin ? 'warn' : '') : stat_card('Curso concluído', num($prog['pct']) . '%', 'graduation-cap')) . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Período ' . h($term['name'] ?? '—') . '</h3><p>Média com as avaliações já divulgadas.</p></div></div><div class="table-wrap"><table><thead><tr><th>Disciplina</th><th class="r">Média</th><th>Frequência</th><th>Situação</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="4">' . empty_state('book-open', 'Sem disciplinas no período') . '</td></tr>') . '</tbody></table></div></div>';
}
function student_tab_dados(array $s): string {
    $dl = fn($k, $v) => '<dt>' . $k . '</dt><dd>' . ($v === null || $v === '' ? '<span class="muted">—</span>' : h($v)) . '</dd>';
    return '<div class="grid g2" style="align-items:start"><div class="card"><div class="card-head"><div><h3>Dados pessoais</h3></div></div><div class="card-body"><dl class="kv">' . $dl('Nome', $s['name']) . $dl('CPF', $s['cpf']) . $dl('Nascimento', $s['birth_date'] ? fmt_date($s['birth_date']) : '') . $dl('E-mail', $s['email']) . $dl('Telefone', $s['phone']) . $dl('Endereço', $s['address']) . $dl('Responsável', $s['guardian']) . '</dl></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Dados acadêmicos</h3></div></div><div class="card-body"><dl class="kv">' . $dl('RA', $s['ra']) . $dl('Curso', $s['course_name']) . $dl('Turma', $s['group_code']) . $dl('Período do curso', $s['period_number'] ? $s['period_number'] . 'º' : '') . $dl('Ingresso', $s['entry_term']) . $dl('Situação', STUDENT_STATUS[$s['status']]) . (can('finance:read') ? $dl('Mensalidade', money((int) $s['monthly_fee_cents'])) : '') . '</dl></div></div></div>';
}
function student_tab_disciplinas(array $s, ?array $term, bool $canEdit, string $route): string {
    $enr = $term ? student_term_enrollments((int) $s['id'], (int) $term['id']) : [];
    $tr = '';
    foreach ($enr as $e) {
        $r = enrollment_result($e, $e['section']);
        $tr .= '<tr><td>' . h($e['section']['subject_name']) . '<p class="cell-sub">' . h($e['section']['code']) . ' · ' . h(implode(', ', array_column($e['section']['profs'], 'name'))) . '</p></td><td class="small">' . h(schedules_text($e['section']['schedules'])) . '</td><td class="tnum r">' . num($r['avg']) . '</td><td>' . ($e['section']['tracks_attendance'] ? pct_bar($r['att']) : '<span class="muted small">Sem frequência</span>') . '</td>'
            . '<td>' . badge(STATUS_LABEL[$r['status']], STATUS_TONE[$r['status']]) . '</td><td class="r">' . ($canEdit && $e['status'] === 'CURSANDO' ? form_open($route, ['id' => $s['id'], 'aba' => 'disciplinas'], 'class="inline-form" data-confirm="Trancar esta disciplina para o aluno?"') . '<input type="hidden" name="acao" value="trancar_disc"><input type="hidden" name="enrollment_id" value="' . $e['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">Trancar</button></form>' : '') . '</td></tr>';
    }
    $add = '';
    if ($canEdit && $term) {
        $opts = []; foreach (rows('SELECT se.id, se.code, su.name FROM sections se JOIN subjects su ON su.id = se.subject_id WHERE se.term_id = ? AND se.id NOT IN (SELECT section_id FROM enrollments WHERE student_id = ?) ORDER BY su.name', [$term['id'], $s['id']]) as $o) $opts[$o['id']] = $o['name'] . ' · ' . $o['code'];
        $add = '<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Matrícula avulsa</h3><p>Dependência, adaptação ou disciplina de outra turma. O sistema confere conflito de horário.</p></div></div><div class="card-body">' . form_open($route, ['id' => $s['id'], 'aba' => 'disciplinas'], 'class="row" style="gap:8px;align-items:flex-end"') . '<input type="hidden" name="acao" value="matricular">' . select('section_id', 'Oferta', $opts, '', 'required', '', 'grow', 'Selecione') . '<button class="btn btn-secondary" type="submit">Matricular</button></form></div></div>';
    }
    return '<div class="card"><div class="card-head"><div><h3>Disciplinas em ' . h($term['name'] ?? '—') . '</h3></div></div><div class="table-wrap"><table><thead><tr><th>Disciplina</th><th>Horários</th><th class="r">Média</th><th>Frequência</th><th>Situação</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('book-open', 'Sem disciplinas') . '</td></tr>') . '</tbody></table></div></div>' . $add;
}
function student_tab_matriz(array $prog): string {
    $lbl = ['done' => badge('Concluída', 'b-ok'), 'doing' => badge('Cursando', 'b-info'), 'failed' => badge('Reprovada', 'b-bad'), 'todo' => '<span class="muted small">A cursar</span>'];
    $by = []; foreach ($prog['matrix'] as $m) $by[$m['period']][] = $m;
    $tr = '';
    foreach ($by as $p => $list) {
        $tr .= '<tr class="group-row"><td colspan="5"><strong>' . $p . 'º período</strong></td></tr>';
        foreach ($list as $m) $tr .= '<tr><td class="formula">' . h($m['code']) . '</td><td>' . h($m['name']) . ($m['required'] ? '' : ' ' . badge('Optativa', 'b-info')) . '</td><td class="tnum">' . $m['workload'] . ' h</td><td class="tnum">' . num($m['final_grade'] === null ? null : (float) $m['final_grade']) . '</td><td>' . $lbl[$m['state']] . '</td></tr>';
    }
    $pctDoing = $prog['required'] ? $prog['doing'] * 100 / $prog['required'] : 0;
    return '<div class="card"><div class="card-head"><div><h3>Andamento na matriz curricular</h3><p>' . $prog['done'] . ' h concluídas, ' . $prog['doing'] . ' h em curso, de ' . $prog['required'] . ' h obrigatórias.</p></div></div><div class="card-body"><div class="prog"><i class="done" style="width:' . $prog['pct'] . '%"></i><i class="cur" style="width:' . $pctDoing . '%"></i></div>'
        . '<div class="prog-legend"><span style="--c:var(--green)">Concluída</span><span style="--c:var(--blue)">Cursando</span><span style="--c:var(--line)">A cursar</span></div></div><div class="table-wrap"><table><thead><tr><th>Código</th><th>Disciplina</th><th>CH</th><th>Média</th><th>Situação</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';
}
function student_tab_financeiro(array $s): string {
    $list = rows('SELECT * FROM charges WHERE student_id = ? ORDER BY due_on DESC', [$s['id']]);
    $tr = ''; $open = 0; $late = 0;
    foreach ($list as $c) {
        $st = charge_state($c, today());
        if ($st === 'VENCIDA') $late += $c['amount_cents']; if (in_array($st, ['ABERTA', 'VENCIDA'], true)) $open += $c['amount_cents'];
        $tr .= '<tr><td>' . h($c['description']) . '</td><td class="tnum">' . fmt_date($c['due_on']) . '</td><td class="tnum r">' . money((int) $c['amount_cents']) . '</td><td>' . badge(CHARGE_LABEL[$st], CHARGE_TONE[$st]) . '</td><td class="small">' . ($c['paid_on'] ? fmt_date($c['paid_on']) . ' · ' . h($c['method']) : '') . '</td></tr>';
    }
    return '<div class="grid g3" style="margin-bottom:16px">' . stat_card('Em aberto', money($open), 'receipt') . stat_card('Vencido', money($late), 'triangle-alert', $late ? 'warn' : '') . stat_card('Mensalidade', money((int) $s['monthly_fee_cents']), 'wallet') . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Cobranças</h3></div>' . (can('finance:manage') ? '<a class="btn btn-sm btn-secondary" href="' . h(url('fin.aluno', ['id' => $s['id']])) . '">Abrir no Financeiro</a>' : '') . '</div><div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Vencimento</th><th class="r">Valor</th><th>Situação</th><th>Pagamento</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('wallet', 'Nenhuma cobrança') . '</td></tr>') . '</tbody></table></div></div>';
}

// ---------- Histórico escolar ----------
// Quem pode assinar o histórico: chave => [campo da instituição, cargo impresso]
const TRANSCRIPT_SIGNERS = ['director' => ['director_name', 'Diretoria Geral'], 'teaching_director' => ['teaching_director_name', 'Diretoria de Ensino'], 'secretary' => ['secretary_name', 'Secretaria Acadêmica']];

function transcript_doc(array $t, ?array $issued, ?array $checked = null): string {
    $inst = institution();
    $logo = $inst['logo_path'] ?? null;
    $sit = ['APROVADO' => 'AP', 'REPROVADO' => 'RN', 'REP_FREQ' => 'RF', 'CURSANDO' => 'CS', 'TRANCADO' => 'TR', 'APROVEITADO' => 'AE'];
    $rowsHtml = '';
    foreach ($t['terms'] as $term => $list) {
        $rowsHtml .= '<tr class="term-row"><td colspan="6">Período letivo ' . h($term) . '</td></tr>';
        foreach ($list as $r) $rowsHtml .= '<tr><td class="tnum">' . h($r['code']) . '</td><td>' . h($r['name']) . '</td><td class="r tnum">' . $r['workload'] . '</td><td class="r tnum">' . num($r['final_grade']) . '</td><td class="r tnum">' . ($r['attendance'] === null ? '—' : num($r['attendance'], 0) . '%') . '</td><td><span class="sit sit-' . ($sit[$r['status']] ?? '') . '">' . ($sit[$r['status']] ?? '') . '</span> ' . (STATUS_LABEL[$r['status']] ?? '') . '</td></tr>';
    }
    $i = $t['institution']; $s = $t['student']; $sm = $t['summary'];
    // Assinaturas: no documento emitido, as escolhidas na emissão; na prévia, todas (o JS mostra só as marcadas).
    $sign = fn($name, $role, $attr = '') => '<div class="sign"' . $attr . '><div class="line"></div><strong>' . h($name) . '</strong><span>' . h($role) . '</span></div>';
    $signs = '';
    if (isset($t['signers'])) foreach ($t['signers'] as $g) $signs .= $sign($g['name'], $g['role']);
    elseif ($issued) { // documentos emitidos antes da escolha de assinaturas
        $signs = $sign($i['secretary'] ?: 'Secretário(a) acadêmico(a)', 'Secretaria Acadêmica') . $sign($i['director'] ?: 'Diretor(a)', 'Diretoria Geral');
    } else foreach (TRANSCRIPT_SIGNERS as $k => [, $role]) {
        $on = in_array($k, $checked ?? [], true);
        $signs .= $sign(($i[$k] ?? '') ?: 'Nome não cadastrado', $role, ' data-sign="' . $k . '"' . ($on ? '' : ' hidden'));
    }
    $mark = $logo ? '<img class="doc-logo" src="' . h($logo) . '" alt="Logo de ' . h($i['short'] ?? $i['name']) . '">' : '<div class="doc-seal">' . h(mb_strtoupper(mb_substr(($i['short'] ?? '') ?: $i['name'], 0, 3))) . '</div>';
    return '<div class="doc-stage"><article class="doc" aria-label="Histórico escolar de ' . h($s['name']) . '">' . ($issued ? '' : '<div class="watermark"><span>PRÉVIA · SEM VALIDADE</span></div>')
        . '<header class="doc-head">' . $mark . '<div><p class="inst">' . h($i['name']) . '</p><p class="inst-sub">' . ($i['emec'] ? 'e-MEC ' . h($i['emec']) . ' · ' : '') . ($i['cnpj'] ? 'CNPJ ' . h($i['cnpj']) . ' · ' : '') . h($i['address']) . '</p><p class="inst-sub">Secretaria Acadêmica</p></div></header>'
        . '<h2 class="doc-title">HISTÓRICO ESCOLAR</h2><p class="doc-sec">Dados do estudante</p><div class="doc-data"><div class="w2"><small>Nome</small><strong>' . h($s['name']) . '</strong></div><div><small>Matrícula (RA)</small><strong>' . h($s['ra']) . '</strong></div>'
        . '<div><small>CPF</small><strong>' . h($s['cpf'] ?: '—') . '</strong></div><div><small>Data de nascimento</small><strong>' . fmt_date($s['birth']) . '</strong></div><div><small>Situação</small><strong>' . h(STUDENT_STATUS[$s['status']] ?? $s['status']) . '</strong></div><div><small>Ingresso</small><strong>' . h($s['entry'] ?: '—') . '</strong></div></div>'
        . '<p class="doc-sec">Dados do curso</p><div class="doc-data"><div class="w2"><small>Curso</small><strong>' . h($t['course']['name']) . '</strong></div><div><small>Código</small><strong>' . h($t['course']['code']) . '</strong></div></div>'
        . '<p class="doc-sec">Componentes curriculares</p><table><thead><tr><th style="width:62px">Código</th><th>Disciplina</th><th class="r" style="width:42px">CH</th><th class="r" style="width:48px">Média</th><th class="r" style="width:52px">Freq.</th><th style="width:170px">Situação</th></tr></thead><tbody>' . ($rowsHtml ?: '<tr><td colspan="6">Sem registros.</td></tr>') . '</tbody></table>'
        . '<p class="doc-sec">Integralização</p><div class="summary"><div><small>CH exigida</small><strong>' . $sm['required_hours'] . ' h</strong></div><div><small>CH integralizada</small><strong>' . $sm['approved_hours'] . ' h (' . num($sm['progress']) . '%)</strong></div><div><small>Coeficiente de rendimento</small><strong>' . num($sm['cr'], 2) . '</strong></div></div>'
        . '<p class="legend">Escala de notas de 0 a 10. Aprovação com média igual ou superior à mínima da disciplina (em geral 6,0) e frequência mínima de 75% quando a disciplina registra frequência. CR: média das notas finais ponderada pela carga horária. AP aprovado · RN reprovado por nota · RF reprovado por falta · TR trancado · CS cursando.</p>'
        . '<footer class="doc-foot"><div class="verify">' . ($issued ? 'Emitido em ' . fmt_dt($issued['issued_at']) . '.<br>Código de verificação: <code>' . h($issued['code']) . '</code><br>Confira em ' . h(app_url()) . '/index.php?r=verificar informando o código.' : 'Prévia gerada a partir dos dados atuais. O documento oficial recebe um código de verificação ao ser emitido.') . '</div>'
        . '<div class="signs">' . $signs . '</div></footer></article></div>';
}
function student_tab_historico(array $s, string $route): string {
    if (is_post()) abort(400);
    $issued = rows('SELECT t.*, u.name AS by_name FROM transcripts t LEFT JOIN users u ON u.id = t.issued_by WHERE t.student_id = ? ORDER BY t.issued_at DESC', [$s['id']]);
    $snap = transcript_snapshot((int) $s['id']);
    $inst = institution();
    $default = array_keys(array_filter(TRANSCRIPT_SIGNERS, fn($d) => trim((string) ($inst[$d[0]] ?? '')) !== ''));
    $list = '';
    foreach ($issued as $t) $list .= '<tr><td class="formula">' . h($t['code']) . '</td><td class="tnum">' . fmt_dt($t['issued_at']) . '</td><td>' . h($t['by_name']) . '</td><td class="r"><a class="btn btn-sm btn-secondary" href="' . h(url('admin.documento', ['id' => $t['id']])) . '">' . icon('eye') . ' Ver e imprimir</a></td></tr>';
    $boxes = '';
    foreach (TRANSCRIPT_SIGNERS as $k => [$field, $role]) {
        $name = trim((string) ($inst[$field] ?? ''));
        $boxes .= '<label class="sign-opt' . ($name ? '' : ' is-off') . '"><input type="checkbox" name="signers[]" value="' . $k . '" data-sign-toggle' . (in_array($k, $default, true) ? ' checked' : '') . ($name ? '' : ' disabled') . '>'
            . '<span><strong>' . $role . '</strong><small>' . ($name ? h($name) : 'Nome não cadastrado') . '</small></span></label>';
    }
    $cfg = can('institution:settings') ? ' <a href="' . h(url('admin.instituicao')) . '">Editar nomes no cadastro da instituição</a>.' : '';
    return form_open('admin.emitir', ['id' => $s['id']], 'class="card sign-card" data-confirm="Emitir o histórico escolar oficial deste aluno?" data-sign-form')
        . '<div class="card-head"><div><h3>Assinaturas</h3><p>Marque quem assina este histórico. Só os marcados aparecem no documento, com a linha para assinatura.' . $cfg . '</p></div></div>'
        . '<div class="card-body"><div class="sign-opts">' . $boxes . '</div></div>'
        . '<div class="card-foot"><span class="small muted">Ao emitir, o documento recebe um código de verificação e fica guardado.</span><button class="btn btn-primary" type="submit">' . icon('file-badge') . ' Emitir histórico</button></div></form>'
        . transcript_doc($snap, null, $default)
        . '<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Documentos emitidos</h3></div></div><div class="table-wrap"><table><thead><tr><th>Código</th><th>Emitido em</th><th>Por</th><th></th></tr></thead><tbody>' . ($list ?: '<tr><td colspan="4">' . empty_state('file-badge', 'Nenhum histórico emitido') . '</td></tr>') . '</tbody></table></div></div>';
}
function route_admin__emitir(): void {
    $u = require_perm('transcripts:issue');
    if (!is_post()) abort(405);
    $snap = transcript_snapshot(gi('id'));
    if (!$snap) abort(404);
    $inst = institution(); $snap['signers'] = [];
    foreach (TRANSCRIPT_SIGNERS as $k => [$field, $role]) if (in_array($k, (array) ($_POST['signers'] ?? []), true) && trim((string) ($inst[$field] ?? '')) !== '') $snap['signers'][] = ['key' => $k, 'name' => trim($inst[$field]), 'role' => $role];
    if (!$snap['signers']) { flash('bad', 'Marque pelo menos uma assinatura para emitir o histórico.'); go('admin.aluno', ['id' => gi('id'), 'aba' => 'historico']); }
    do { $code = transcript_code(); } while (row('SELECT id FROM transcripts WHERE code = ?', [$code]));
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    $id = insert('transcripts', ['institution_id' => inst_id(), 'student_id' => gi('id'), 'code' => $code, 'snapshot' => $json, 'sha256' => hash('sha256', $json), 'issued_by' => $u['id'], 'issued_at' => now()]);
    audit('transcript.issue', 'Histórico emitido: ' . $snap['student']['name'] . " · código $code", 'transcript', $id);
    flash("ok", "Histórico emitido. Código de verificação: <b class=\"tnum\">$code</b>.");
    go('admin.documento', ['id' => $id]);
}
function route_admin__documento(): void {
    require_perm('transcripts:issue');
    $t = row('SELECT * FROM transcripts WHERE id = ? AND institution_id = ?', [gi('id'), inst_id()]);
    if (!$t) abort(404);
    $snap = json_decode($t['snapshot'], true);
    layout('Histórico escolar', '<div class="doc-toolbar">' . crumb('admin.aluno', 'Ficha de ' . $snap['student']['name'], ['id' => $t['student_id'], 'aba' => 'historico']) . '<button class="btn btn-primary" type="button" data-print>' . icon('printer') . ' Imprimir ou salvar em PDF</button></div>'
        . (hash_equals($t['sha256'], hash('sha256', $t['snapshot'])) ? '' : alert('bad', 'O conteúdo deste documento não confere com o registro de emissão.')) . transcript_doc($snap, $t));
}
function route_admin__documentos(): void {
    require_perm('transcripts:issue');
    $q = g('q');
    $list = rows('SELECT t.*, u.name AS by_name, su.name AS student, s.ra FROM transcripts t JOIN students s ON s.id = t.student_id JOIN users su ON su.id = s.user_id LEFT JOIN users u ON u.id = t.issued_by WHERE t.institution_id = ?' . ($q ? ' AND (su.name LIKE ? OR t.code LIKE ? OR s.ra LIKE ?)' : '') . ' ORDER BY t.issued_at DESC LIMIT 300', $q ? [inst_id(), "%$q%", "%$q%", "%$q%"] : [inst_id()]);
    $tr = '';
    foreach ($list as $t) $tr .= '<tr class="clickable" data-href="' . h(url('admin.documento', ['id' => $t['id']])) . '"><td class="formula">' . h($t['code']) . '</td><td>' . h($t['student']) . '<p class="cell-sub">RA ' . h($t['ra']) . '</p></td><td class="tnum">' . fmt_dt($t['issued_at']) . '</td><td>' . h($t['by_name']) . '</td><td class="r chev-cell">' . icon('chevron-right') . '</td></tr>';
    layout('Documentos emitidos', page_head('Documentos emitidos', 'Históricos escolares oficiais, com código de verificação. Qualquer pessoa confere a autenticidade na tela de verificação.', '<a class="btn btn-secondary" href="' . h(url('verificar')) . '" target="_blank" rel="noopener">' . icon('shield-check') . ' Tela de verificação</a>')
        . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="admin.documentos">' . input('q', 'Buscar', $q, 'search', 'placeholder="Aluno, RA ou código"', '', 'filters-q') . '<button class="btn btn-secondary" type="submit">Buscar</button></form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Código</th><th>Aluno</th><th>Emitido em</th><th>Por</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('file-badge', 'Nenhum documento emitido') . '</td></tr>') . '</tbody></table></div></div>');
}

// ---------- Cadastro de aluno ----------
function route_admin__aluno_form(): void {
    require_perm('students:manage');
    $s = gi('id') ? student_full(gi('id')) : null;
    if (gi('id') && !$s) abort(404);
    $f = $s ?? ['name' => '', 'email' => '', 'phone' => '', 'cpf' => '', 'birth_date' => '', 'address' => '', 'guardian' => '', 'course_id' => '', 'group_id' => '', 'entry_term' => active_term()['name'] ?? '', 'status' => 'ATIVO', 'monthly_fee_cents' => 0, 'ra' => ''];
    $err = '';
    if (is_post()) {
        $f = array_merge($f, ['name' => p('name'), 'email' => mb_strtolower(p('email')), 'phone' => p('phone'), 'cpf' => p('cpf'), 'birth_date' => p('birth_date'), 'address' => p('address'), 'guardian' => p('guardian'), 'course_id' => pi_('course_id'), 'group_id' => pi_('group_id') ?: null, 'entry_term' => p('entry_term'), 'status' => p('status'), 'monthly_fee_cents' => parse_money(p('fee')) ?? 0]);
        $course = row('SELECT * FROM courses WHERE id = ? AND institution_id = ?', [$f['course_id'], inst_id()]);
        $group = $f['group_id'] ? row('SELECT * FROM class_groups WHERE id = ? AND institution_id = ?', [$f['group_id'], inst_id()]) : null;
        if (mb_strlen($f['name']) < 3) $err = 'Informe o nome completo.';
        elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err = 'Informe um e-mail válido.';
        elseif (row('SELECT id FROM users WHERE email = ?' . ($s ? ' AND id <> ?' : ''), $s ? [$f['email'], $s['uid']] : [$f['email']])) $err = 'Já existe um usuário com este e-mail.';
        elseif ($f['cpf'] && !valid_cpf($f['cpf'])) $err = 'CPF inválido.';
        elseif (!$course) $err = 'Escolha o curso.';
        elseif ($group && (int) $group['course_id'] !== (int) $course['id']) $err = 'A turma escolhida é de outro curso.';
        elseif (!isset(STUDENT_STATUS[$f['status']])) $err = 'Situação inválida.';
        elseif ($f['birth_date'] && !valid_date($f['birth_date'])) $err = 'Data de nascimento inválida.';
        if (!$err) {
            $cpf = $f['cpf'] ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', only_digits($f['cpf'])) : null;
            $data = ['course_id' => $course['id'], 'group_id' => $group['id'] ?? null, 'entry_term' => $f['entry_term'] ?: null, 'status' => $f['status'], 'cpf' => $cpf, 'birth_date' => $f['birth_date'] ?: null, 'address' => $f['address'] ?: null, 'guardian' => $f['guardian'] ?: null, 'monthly_fee_cents' => $f['monthly_fee_cents']];
            if ($s) {
                tx(function () use ($s, $f, $data, $group) {
                    update('users', ['name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'] ?: null], 'id = ?', [$s['uid']]);
                    update('students', $data, 'id = ?', [$s['id']]);
                    if ($group && (int) $group['id'] !== (int) $s['group_id']) enroll_in_group_sections((int) $s['id'], (int) $group['id']);
                });
                audit('student.update', "Cadastro do aluno alterado: {$f['name']}", 'student', (int) $s['id']);
                flash('ok', 'Cadastro salvo.');
                go('admin.aluno', ['id' => $s['id'], 'aba' => 'dados']);
            }
            [$sid, $uid, $n] = tx(function () use ($f, $data, $group) {
                $uid = insert('users', ['institution_id' => inst_id(), 'name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'] ?: null, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'must_change_password' => 1, 'created_at' => now()]);
                insert('user_roles', ['user_id' => $uid, 'role_id' => (int) val("SELECT id FROM roles WHERE kind = 'STUDENT'")]);
                $year = date('Y'); $next = (int) val('SELECT COUNT(*) FROM students') + 1001;
                do { $ra = $year . str_pad((string) $next++, 5, '0', STR_PAD_LEFT); } while (row('SELECT id FROM students WHERE ra = ?', [$ra]));
                $sid = insert('students', $data + ['user_id' => $uid, 'ra' => $ra]);
                $n = $group ? enroll_in_group_sections($sid, (int) $group['id']) : 0;
                return [$sid, $uid, $n];
            });
            [$sent, $pass] = issue_temp_password(row('SELECT * FROM users WHERE id = ?', [$uid]), false);
            audit('student.create', "Aluno cadastrado: {$f['name']} · RA " . val('SELECT ra FROM students WHERE id = ?', [$sid]), 'student', $sid);
            flash('ok', 'Aluno cadastrado' . ($n ? " e matriculado em $n disciplinas da turma" : '') . '.');
            temp_password_notice($sent, $pass, $f['email']);
            go('admin.aluno', ['id' => $sid]);
        }
    }
    $courses = []; foreach (rows('SELECT id, code, name FROM courses WHERE institution_id = ? AND active = 1 ORDER BY name', [inst_id()]) as $c) $courses[$c['id']] = $c['code'] . ' · ' . $c['name'];
    $groups = []; foreach (rows('SELECT g.id, g.code, t.name AS term FROM class_groups g JOIN terms t ON t.id = g.term_id WHERE g.institution_id = ? AND t.closed = 0 ORDER BY t.starts_on DESC, g.code', [inst_id()]) as $g) $groups[$g['id']] = $g['code'] . ' (' . $g['term'] . ')';
    layout($s ? 'Editar aluno' : 'Novo aluno', crumb($s ? 'admin.aluno' : 'admin.alunos', $s ? 'Ficha do aluno' : 'Alunos', $s ? ['id' => $s['id']] : []) . page_head($s ? 'Editar cadastro' : 'Novo aluno', $s ? 'RA ' . h($s['ra']) : 'O RA é gerado automaticamente. Ao escolher a turma, o aluno é matriculado nas disciplinas dela.')
        . ($err ? alert('bad', h($err)) : '') . '<div class="card" style="max-width:900px"><div class="card-body" style="padding-top:20px">' . form_open('admin.aluno_form', $s ? ['id' => $s['id']] : [], 'class="form-grid cols-2"')
        . input('name', 'Nome completo', $f['name'], 'text', 'required', '', 'span-2') . input('email', 'E-mail', $f['email'], 'email', 'required', $s ? '' : 'A senha provisória vai para este endereço.') . input('phone', 'Telefone', $f['phone'], 'tel')
        . input('cpf', 'CPF', $f['cpf'], 'text', 'inputmode="numeric" placeholder="000.000.000-00"') . input('birth_date', 'Data de nascimento', $f['birth_date'], 'date') . input('address', 'Endereço', $f['address'], 'text', '', '', 'span-2') . input('guardian', 'Responsável (se menor de idade)', $f['guardian'], 'text', '', '', 'span-2')
        . select('course_id', 'Curso', $courses, $f['course_id'], 'required', '', '', 'Selecione') . select('group_id', 'Turma', $groups, $f['group_id'], '', 'Matricula nas disciplinas regulares da turma.', '', 'Sem turma')
        . input('entry_term', 'Período de ingresso', $f['entry_term'], 'text', 'placeholder="Ex.: 2026.2"') . select('status', 'Situação', STUDENT_STATUS, $f['status'])
        . input('fee', 'Mensalidade (R$)', $f['monthly_fee_cents'] ? number_format($f['monthly_fee_cents'] / 100, 2, ',', '.') : '', 'text', 'inputmode="decimal" placeholder="0,00"', 'Usada ao gerar as cobranças do período.')
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><a class="btn btn-secondary" href="' . h($s ? url('admin.aluno', ['id' => $s['id']]) : url('admin.alunos')) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . ($s ? 'Salvar' : 'Cadastrar aluno') . '</button></div></form></div></div>');
}

// ---------- Professores ----------
function professors_list(string $route, string $fichaRoute, bool $readonly): void {
    $q = g('q');
    $list = rows("SELECT p.*, u.name, u.email, u.avatar_path, u.status, (SELECT COUNT(*) FROM section_professors sp JOIN sections se ON se.id = sp.section_id WHERE sp.professor_id = p.id AND se.term_id = ?) AS sections FROM professors p JOIN users u ON u.id = p.user_id WHERE (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?))" . ($q ? ' AND (u.name LIKE ? OR p.registration LIKE ?)' : '') . ' ORDER BY u.name', $q ? [active_term()['id'] ?? 0, inst_id(), inst_id(), "%$q%", "%$q%"] : [active_term()['id'] ?? 0, inst_id(), inst_id()]);
    $tr = '';
    foreach ($list as $p) { $href = url($fichaRoute, ['id' => $p['id']]); $tr .= '<tr class="clickable" data-href="' . h($href) . '"><td><div class="row" style="flex-wrap:nowrap">' . avatar($p['avatar_path'], $p['name'], 'width:30px;height:30px;font-size:11px') . '<div><a class="cell-link" href="' . h($href) . '">' . h($p['name']) . '</a><p class="cell-sub">' . h($p['email']) . '</p></div></div></td><td class="tnum">' . h($p['registration']) . '</td><td>' . h($p['title']) . '</td><td>' . h($p['department']) . '</td><td class="tnum">' . $p['sections'] . '</td><td class="r chev-cell">' . icon('chevron-right') . '</td></tr>'; }
    layout('Professores', page_head('Professores', 'Clique no professor para abrir a ficha.', !$readonly && can('professors:manage') ? '<a class="btn btn-primary" href="' . h(url('admin.professor_form')) . '">' . icon('user-plus') . ' Novo professor</a>' : '')
        . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="' . h($route) . '">' . input('q', 'Buscar', $q, 'search', 'placeholder="Nome ou matrícula"', '', 'filters-q') . '<button class="btn btn-secondary" type="submit">Buscar</button></form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Professor</th><th>Matrícula</th><th>Titulação</th><th>Departamento</th><th>Ofertas no período</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('users-round', 'Nenhum professor encontrado') . '</td></tr>') . '</tbody></table></div></div>');
}
function route_admin__professores(): void { require_perm('professors:manage'); professors_list('admin.professores', 'admin.professor', false); }
function route_dir__professores(): void { require_perm('reports:institutional'); professors_list('dir.professores', 'dir.professor', true); }

function professor_profile(string $route, bool $readonly): void {
    $p = professor_full(gi('id'));
    if (!$p) abort(404);
    $term = active_term();
    $tab = g('aba', 'resumo');
    $base = url($route, ['id' => $p['id']]);
    $secs = $term ? array_map(fn($r) => section_full((int) $r['section_id']), rows('SELECT sp.section_id FROM section_professors sp JOIN sections se ON se.id = sp.section_id WHERE sp.professor_id = ? AND se.term_id = ?', [$p['id'], $term['id']])) : [];
    $head = crumb($readonly ? 'dir.professores' : 'admin.professores', 'Professores') . page_head('Ficha do professor', '', !$readonly && can('professors:manage') ? '<a class="btn btn-secondary" href="' . h(url('admin.professor_form', ['id' => $p['id']])) . '">' . icon('pencil') . ' Editar cadastro</a>' : '')
        . '<div class="card ficha-head" style="margin-bottom:16px"><div class="card-body" style="padding-top:20px"><div class="row" style="gap:16px">' . avatar($p['avatar_path'], $p['name'], 'width:56px;height:56px;font-size:18px') . '<div><h2 style="font-size:19px;font-weight:600;color:var(--navy)">' . h($p['name']) . '</h2><div class="row" style="gap:10px;margin-top:4px"><span class="muted">' . h($p['email']) . '</span><span class="tnum">Matrícula ' . h($p['registration']) . '</span>' . ($p['title'] ? badge(h($p['title']), 'b-info') : '') . '</div></div></div></div></div>'
        . tabs([['resumo', 'Resumo', 'layout-dashboard', $base], ['turmas', 'Turmas', 'school', $base . '&aba=turmas'], ['horarios', 'Horários', 'calendar-days', $base . '&aba=horarios']], $tab);
    if ($tab === 'horarios') {
        $items = []; foreach ($secs as $s) foreach ($s['schedules'] as $x) $items[] = $x + ['title' => $s['subject_name'], 'sub' => $s['code'] . ($s['room'] ? ' · ' . $s['room'] : '')];
        layout($p['name'], $head . '<div class="card"><div class="card-body" style="padding-top:16px">' . week_grid($items) . '</div></div>'); return;
    }
    $tr = ''; $students = 0; $hours = 0;
    foreach ($secs as $s) { $n = (int) val('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$s['id']]); $students += $n; $hours += $s['workload']; $l = (int) val('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$s['id']]);
        $tr .= '<tr><td>' . h($s['subject_name']) . '<p class="cell-sub">' . h($s['code']) . '</p></td><td>' . implode(' ', array_map(fn($g) => badge(h($g['code'])), $s['groups'])) . '</td><td class="small">' . h(schedules_text($s['schedules'])) . '</td><td class="tnum">' . $n . '</td><td class="tnum">' . $l . '</td></tr>'; }
    $table = '<div class="card"><div class="card-head"><div><h3>Turmas em ' . h($term['name'] ?? '—') . '</h3></div></div><div class="table-wrap"><table><thead><tr><th>Disciplina</th><th>Turmas</th><th>Horários</th><th>Alunos</th><th>Aulas registradas</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('school', 'Sem turmas no período') . '</td></tr>') . '</tbody></table></div></div>';
    if ($tab === 'turmas') { layout($p['name'], $head . $table); return; }
    layout($p['name'], $head . '<div class="grid g3" style="margin-bottom:16px">' . stat_card('Ofertas no período', (string) count($secs), 'school') . stat_card('Alunos', (string) $students, 'users') . stat_card('Carga horária', $hours . ' h', 'clock') . '</div>'
        . '<div class="card"><div class="card-body"><dl class="kv"><dt>Departamento</dt><dd>' . h($p['department'] ?: '—') . '</dd><dt>Titulação</dt><dd>' . h($p['title'] ?: '—') . '</dd><dt>Admissão</dt><dd>' . fmt_date($p['hired_on']) . '</dd><dt>Telefone</dt><dd>' . h($p['phone'] ?: '—') . '</dd></dl></div></div>');
}
function route_admin__professor(): void { require_perm('professors:manage'); professor_profile('admin.professor', false); }
function route_dir__professor(): void { require_perm('reports:institutional'); professor_profile('dir.professor', true); }

function route_admin__professor_form(): void {
    require_perm('professors:manage');
    $p = gi('id') ? professor_full(gi('id')) : null;
    $f = $p ?? ['name' => '', 'email' => '', 'phone' => '', 'registration' => '', 'title' => '', 'department' => '', 'hired_on' => ''];
    $err = '';
    if (is_post()) {
        $f = array_merge($f, ['name' => p('name'), 'email' => mb_strtolower(p('email')), 'phone' => p('phone'), 'registration' => p('registration'), 'title' => p('title'), 'department' => p('department'), 'hired_on' => p('hired_on')]);
        if (mb_strlen($f['name']) < 3) $err = 'Informe o nome.';
        elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) $err = 'Informe um e-mail válido.';
        elseif (row('SELECT id FROM users WHERE email = ?' . ($p ? ' AND id <> ?' : ''), $p ? [$f['email'], $p['user_id']] : [$f['email']])) $err = 'Já existe um usuário com este e-mail.';
        elseif ($f['hired_on'] && !valid_date($f['hired_on'])) $err = 'Data de admissão inválida.';
        if (!$err) {
            $data = ['registration' => $f['registration'] ?: 'P' . str_pad((string) ((int) val('SELECT COUNT(*) FROM professors') + 101), 4, '0', STR_PAD_LEFT), 'title' => $f['title'] ?: null, 'department' => $f['department'] ?: null, 'hired_on' => $f['hired_on'] ?: null];
            if ($p) {
                tx(function () use ($p, $f, $data) { update('users', ['name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'] ?: null], 'id = ?', [$p['user_id']]); update('professors', $data, 'id = ?', [$p['id']]); });
                audit('professor.update', "Cadastro do professor alterado: {$f['name']}", 'professor', (int) $p['id']); flash('ok', 'Cadastro salvo.'); go('admin.professor', ['id' => $p['id']]);
            }
            [$pid, $uid] = tx(function () use ($f, $data) {
                $uid = insert('users', ['institution_id' => inst_id(), 'name' => $f['name'], 'email' => $f['email'], 'phone' => $f['phone'] ?: null, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), 'status' => 'ACTIVE', 'must_change_password' => 1, 'created_at' => now()]);
                insert('user_roles', ['user_id' => $uid, 'role_id' => (int) val("SELECT id FROM roles WHERE kind = 'PROFESSOR'")]);
                return [insert('professors', $data + ['user_id' => $uid]), $uid];
            });
            [$sent, $pass] = issue_temp_password(row('SELECT * FROM users WHERE id = ?', [$uid]), false);
            audit('professor.create', "Professor cadastrado: {$f['name']}", 'professor', $pid);
            flash('ok', 'Professor cadastrado. Vincule-o às ofertas em Cursos › Ofertas.'); temp_password_notice($sent, $pass, $f['email']);
            go('admin.professor', ['id' => $pid]);
        }
    }
    layout($p ? 'Editar professor' : 'Novo professor', crumb($p ? 'admin.professor' : 'admin.professores', $p ? 'Ficha do professor' : 'Professores', $p ? ['id' => $p['id']] : []) . page_head($p ? 'Editar cadastro' : 'Novo professor')
        . ($err ? alert('bad', h($err)) : '') . '<div class="card" style="max-width:820px"><div class="card-body" style="padding-top:20px">' . form_open('admin.professor_form', $p ? ['id' => $p['id']] : [], 'class="form-grid cols-2"')
        . input('name', 'Nome completo', $f['name'], 'text', 'required', '', 'span-2') . input('email', 'E-mail', $f['email'], 'email', 'required', $p ? '' : 'A senha provisória vai para este endereço.') . input('phone', 'Telefone', $f['phone'], 'tel')
        . input('registration', 'Matrícula', $f['registration'], 'text', 'maxlength="20"', 'Em branco: gerada automaticamente.') . select('title', 'Titulação', ['Graduado' => 'Graduado', 'Especialista' => 'Especialista', 'Mestre' => 'Mestre', 'Doutor' => 'Doutor', 'Doutora' => 'Doutora'], $f['title'], '', '', '', 'Selecione')
        . input('department', 'Departamento', $f['department']) . input('hired_on', 'Admissão', $f['hired_on'], 'date')
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><a class="btn btn-secondary" href="' . h($p ? url('admin.professor', ['id' => $p['id']]) : url('admin.professores')) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . ($p ? 'Salvar' : 'Cadastrar professor') . '</button></div></form></div></div>');
}
