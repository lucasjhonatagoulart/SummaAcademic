<?php
// Cursos e matrizes, períodos letivos, turmas, ofertas e quadro de horários.

function courses_tabs(string $active): string {
    $t = [];
    if (can('courses:manage')) $t[] = ['cursos', 'Cursos e matrizes', 'graduation-cap', url('admin.cursos')];
    if (can('terms:manage')) $t[] = ['periodos', 'Períodos letivos', 'calendar-range', url('admin.periodos')];
    if (can('classes:manage')) { $t[] = ['turmas', 'Turmas', 'school', url('admin.turmas')]; $t[] = ['ofertas', 'Ofertas', 'layers', url('admin.ofertas')]; $t[] = ['horarios', 'Quadro de horários', 'calendar-days', url('admin.horarios')]; }
    return tabs($t, $active, 'Seção Cursos');
}
function term_select(int $termId, string $route, array $extra = []): string {
    $o = '<form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="' . h($route) . '">';
    foreach ($extra as $k => $v) $o .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    $opts = []; foreach (terms_all() as $t) $opts[$t['id']] = $t['name'] . ($t['active'] ? ' (ativo)' : ($t['closed'] ? ' (encerrado)' : ''));
    return $o . select('periodo', 'Período letivo', $opts, $termId, 'data-autosubmit') . '</form>';
}

// ---------- Cursos ----------
function route_admin__cursos(): void {
    if (!can('courses:manage')) { if (can('terms:manage')) go('admin.periodos'); if (can('classes:manage')) go('admin.ofertas'); abort(403); }
    if (is_post()) {
        $code = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p('code'))); $name = p('name');
        if (strlen($code) < 2 || mb_strlen($name) < 3) flash('bad', 'Informe código (2 a 12 letras/números) e nome.');
        elseif (row('SELECT id FROM courses WHERE institution_id = ? AND code = ?', [inst_id(), $code])) flash('bad', 'Já existe um curso com este código.');
        else { $id = insert('courses', ['institution_id' => inst_id(), 'code' => mb_substr($code, 0, 12), 'name' => $name, 'degree' => p('degree') ?: 'Bacharelado', 'periods' => max(1, min(14, pi_('periods') ?: 8)), 'active' => 1]); audit('course.create', "Curso cadastrado: $code · $name", 'course', $id); flash('ok', 'Curso cadastrado. Agora monte a matriz curricular.'); go('admin.curso', ['id' => $id]); }
        go('admin.cursos');
    }
    $list = rows('SELECT c.*, (SELECT COUNT(*) FROM course_subjects cs WHERE cs.course_id = c.id) AS subjects, (SELECT COALESCE(SUM(su.workload),0) FROM course_subjects cs JOIN subjects su ON su.id = cs.subject_id WHERE cs.course_id = c.id AND cs.required = 1) AS hours,
        (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id AND s.status = \'ATIVO\') AS students FROM courses c WHERE c.institution_id = ? ORDER BY c.name', [inst_id()]);
    $cards = '';
    foreach ($list as $c) $cards .= '<a class="card course-card" href="' . h(url('admin.curso', ['id' => $c['id']])) . '"><div class="row" style="justify-content:space-between"><span class="badge b-info">' . h($c['code']) . '</span>' . ($c['active'] ? '' : badge('Inativo')) . '</div><h3>' . h($c['name']) . '</h3>'
        . '<p class="small muted">' . h($c['degree']) . ' · ' . $c['periods'] . ' períodos</p><div class="row" style="gap:16px"><span class="small"><strong>' . $c['subjects'] . '</strong> disciplinas</span><span class="small"><strong>' . $c['hours'] . ' h</strong> obrigatórias</span><span class="small"><strong>' . $c['students'] . '</strong> alunos</span></div></a>';
    layout('Cursos', courses_tabs('cursos') . page_head('Cursos e matrizes curriculares', 'Clique em um curso para editar os dados e a matriz.')
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="course-cards">' . ($cards ?: empty_state('graduation-cap', 'Nenhum curso cadastrado')) . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Novo curso</h3></div></div><div class="card-body">' . form_open('admin.cursos', [], 'class="form-grid"') . input('code', 'Código', '', 'text', 'required maxlength="12" placeholder="Ex.: ENF"')
        . input('name', 'Nome', '', 'text', 'required') . select('degree', 'Grau', ['Bacharelado' => 'Bacharelado', 'Licenciatura' => 'Licenciatura', 'Tecnólogo' => 'Tecnólogo', 'Técnico' => 'Técnico', 'Pós-graduação' => 'Pós-graduação'], 'Bacharelado')
        . input('periods', 'Períodos (semestres)', '8', 'number', 'min="1" max="14"') . '<button class="btn btn-primary" type="submit">' . icon('plus') . ' Cadastrar curso</button></form></div></div></div>');
}

function route_admin__curso(): void {
    require_perm('courses:manage');
    $c = row('SELECT * FROM courses WHERE id = ? AND institution_id = ?', [gi('id'), inst_id()]);
    if (!$c) abort(404);
    if (is_post()) {
        $act = p('acao');
        if ($act === 'curso') {
            $d = ['name' => p('name'), 'degree' => p('degree'), 'periods' => max(1, min(14, pi_('periods'))), 'active' => isset($_POST['active']) ? 1 : 0];
            if (mb_strlen($d['name']) < 3) flash('bad', 'Informe o nome do curso.'); else { update('courses', $d, 'id = ?', [$c['id']]); audit('course.update', "Curso alterado: {$c['code']} · {$d['name']}", 'course', (int) $c['id']); flash('ok', 'Curso salvo.'); }
        } elseif ($act === 'item') {
            $itemId = pi_('item');
            $period = max(1, min((int) $c['periods'], pi_('period')));
            $pre = implode(',', array_filter(array_map(fn($x) => mb_strtoupper(trim($x)), explode(',', p('prerequisites')))));
            $mod = p('modality') === 'D' ? 'D' : 'P'; $tracks = isset($_POST['tracks_attendance']) ? 1 : 0;
            if ($itemId) {
                $it = row('SELECT * FROM course_subjects WHERE id = ? AND course_id = ?', [$itemId, $c['id']]);
                if ($it) {
                    $wl = pi_('workload'); $nm = p('subject_name');
                    if ($wl < 10 || mb_strlen($nm) < 3) { flash('bad', 'Informe nome e carga horária (mínimo 10 h).'); go('admin.curso', ['id' => $c['id'], 'item' => $itemId]); }
                    update('course_subjects', ['period' => $period, 'required' => p('required') === '0' ? 0 : 1, 'prerequisites' => $pre ?: null], 'id = ?', [$itemId]);
                    update('subjects', ['name' => $nm, 'workload' => $wl, 'modality' => $mod, 'tracks_attendance' => $tracks], 'id = ?', [$it['subject_id']]);
                    audit('course.matrix', "Matriz {$c['code']}: disciplina alterada ($nm)", 'course', (int) $c['id']); flash('ok', 'Disciplina atualizada.');
                }
            } else {
                $code = mb_strtoupper(preg_replace('/[^A-Za-z0-9]/', '', p('subject_code')));
                $sub = $code ? row('SELECT * FROM subjects WHERE institution_id = ? AND code = ?', [inst_id(), $code]) : null;
                if (!$sub) {
                    $nm = p('subject_name'); $wl = pi_('workload');
                    if (strlen($code) < 3 || mb_strlen($nm) < 3 || $wl < 10) { flash('bad', 'Para disciplina nova, informe código, nome e carga horária (mínimo 10 h).'); go('admin.curso', ['id' => $c['id']]); }
                    $sid = insert('subjects', ['institution_id' => inst_id(), 'code' => mb_substr($code, 0, 12), 'name' => $nm, 'workload' => $wl, 'modality' => $mod, 'tracks_attendance' => $tracks]);
                } else $sid = (int) $sub['id'];
                if (row('SELECT id FROM course_subjects WHERE course_id = ? AND subject_id = ?', [$c['id'], $sid])) flash('bad', 'Esta disciplina já está na matriz.');
                else { insert('course_subjects', ['course_id' => $c['id'], 'subject_id' => $sid, 'period' => $period, 'required' => p('required') === '0' ? 0 : 1, 'prerequisites' => $pre ?: null]); audit('course.matrix', "Matriz {$c['code']}: disciplina incluída ($code)", 'course', (int) $c['id']); flash('ok', 'Disciplina incluída na matriz.'); }
            }
        } elseif ($act === 'remover') {
            $it = row('SELECT cs.*, su.code FROM course_subjects cs JOIN subjects su ON su.id = cs.subject_id WHERE cs.id = ? AND cs.course_id = ?', [pi_('item'), $c['id']]);
            $used = $it ? (int) val('SELECT COUNT(*) FROM enrollments e JOIN sections se ON se.id = e.section_id JOIN students s ON s.id = e.student_id WHERE se.subject_id = ? AND s.course_id = ?', [$it['subject_id'], $c['id']]) : 0;
            if (!$it) flash('bad', 'Disciplina não encontrada.');
            elseif ($used) flash('bad', "Não dá para remover: há $used matrículas de alunos deste curso nesta disciplina.");
            else { q('DELETE FROM course_subjects WHERE id = ?', [$it['id']]); audit('course.matrix', "Matriz {$c['code']}: disciplina removida ({$it['code']})", 'course', (int) $c['id']); flash('ok', 'Disciplina removida da matriz.'); }
        }
        go('admin.curso', ['id' => $c['id']]);
    }
    $items = rows('SELECT cs.*, su.code, su.name, su.workload, su.modality, su.tracks_attendance FROM course_subjects cs JOIN subjects su ON su.id = cs.subject_id WHERE cs.course_id = ? ORDER BY cs.period, su.name', [$c['id']]);
    $edit = gi('item') ? row('SELECT cs.*, su.code, su.name, su.workload, su.modality, su.tracks_attendance FROM course_subjects cs JOIN subjects su ON su.id = cs.subject_id WHERE cs.id = ? AND cs.course_id = ?', [gi('item'), $c['id']]) : null;
    $req = array_sum(array_map(fn($i) => $i['required'] ? $i['workload'] : 0, $items));
    $byPeriod = [];
    foreach ($items as $i) $byPeriod[$i['period']][] = $i;
    $m = '';
    foreach ($byPeriod as $per => $list) {
        $m .= '<tr class="group-row"><td colspan="7"><strong>' . $per . 'º período</strong> <span class="muted small">' . array_sum(array_column($list, 'workload')) . ' h</span></td></tr>';
        foreach ($list as $i) $m .= '<tr><td class="formula">' . h($i['code']) . '</td><td>' . h($i['name']) . '</td><td class="tnum">' . $i['workload'] . ' h</td><td>' . ($i['required'] ? 'Obrigatória' : badge('Optativa', 'b-info')) . '</td>'
            . '<td>' . ($i['modality'] === 'D' ? badge('A distância (EaD)', 'b-warn') : 'Presencial') . (!$i['tracks_attendance'] ? '<p class="cell-sub">Sem frequência</p>' : '') . '</td><td class="small">' . h($i['prerequisites'] ?: '—') . '</td>'
            . '<td class="r" style="white-space:nowrap"><a class="btn btn-sm btn-secondary" href="' . h(url('admin.curso', ['id' => $c['id'], 'item' => $i['id']])) . '#form-item">' . icon('pencil') . '</a> ' . form_open('admin.curso', ['id' => $c['id']], 'class="inline-form" data-confirm="Remover esta disciplina da matriz?"') . '<input type="hidden" name="acao" value="remover"><input type="hidden" name="item" value="' . $i['id'] . '"><button class="btn btn-sm btn-ghost" type="submit" title="Remover">' . icon('trash-2') . '</button></form></td></tr>';
    }
    $e = $edit ?? ['code' => '', 'name' => '', 'workload' => 60, 'modality' => 'P', 'tracks_attendance' => 1, 'period' => 1, 'required' => 1, 'prerequisites' => ''];
    $periodOpts = []; for ($i = 1; $i <= $c['periods']; $i++) $periodOpts[$i] = "$i º período";
    $form = form_open('admin.curso', ['id' => $c['id']], 'class="form-grid cols-2" id="form-item"') . '<input type="hidden" name="acao" value="item">' . ($edit ? '<input type="hidden" name="item" value="' . $edit['id'] . '">' : '')
        . ($edit ? '<div class="field"><label>Código</label><input class="input" value="' . h($e['code']) . '" disabled></div>' : input('subject_code', 'Código', '', 'text', 'required maxlength="12"', 'Se a disciplina já existir em outro curso, ela é reaproveitada.'))
        . input('subject_name', 'Nome', $e['name'], 'text', $edit ? 'required' : '', $edit ? 'Vale para todos os cursos que usam esta disciplina.' : '')
        . input('workload', 'Carga horária (h)', $e['workload'], 'number', 'min="10" max="400"') . select('period', 'Período', $periodOpts, $e['period'])
        . select('required', 'Tipo', ['1' => 'Obrigatória', '0' => 'Optativa (eletiva)'], $e['required'] ? '1' : '0') . select('modality', 'Modalidade', ['P' => 'Presencial', 'D' => 'A distância (EaD)'], $e['modality'])
        . input('prerequisites', 'Pré-requisitos', $e['prerequisites'], 'text', 'placeholder="Códigos separados por vírgula"', '', 'span-2')
        . '<div class="span-2">' . checkbox('tracks_attendance', 'Registrar frequência', (bool) $e['tracks_attendance'], 'Desmarcado: o aluno vê só as notas e a frequência não conta para a aprovação.') . '</div>'
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px">' . ($edit ? '<a class="btn btn-secondary" href="' . h(url('admin.curso', ['id' => $c['id']])) . '">Cancelar</a>' : '') . '<button class="btn btn-primary" type="submit">' . ($edit ? 'Salvar disciplina' : 'Incluir na matriz') . '</button></div></form>';
    layout($c['name'], courses_tabs('cursos') . crumb('admin.cursos', 'Cursos') . page_head(h($c['name']), h($c['code']) . ' · ' . h($c['degree']) . ' · ' . count($items) . ' disciplinas · ' . $req . ' h obrigatórias')
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="card"><div class="card-head"><div><h3>Matriz curricular</h3><p>Disciplinas por período.</p></div></div><div class="table-wrap"><table><thead><tr><th>Código</th><th>Disciplina</th><th>CH</th><th>Tipo</th><th>Modalidade</th><th>Pré-requisitos</th><th></th></tr></thead><tbody>'
        . ($m ?: '<tr><td colspan="7">' . empty_state('list-checks', 'Matriz vazia', 'Inclua as disciplinas ao lado.') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="stack"><div class="card"><div class="card-head"><div><h3>' . ($edit ? 'Editar disciplina' : 'Incluir disciplina') . '</h3></div></div><div class="card-body">' . $form . '</div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Dados do curso</h3></div></div><div class="card-body">' . form_open('admin.curso', ['id' => $c['id']], 'class="form-grid"') . '<input type="hidden" name="acao" value="curso">'
        . input('name', 'Nome', $c['name'], 'text', 'required') . select('degree', 'Grau', ['Bacharelado' => 'Bacharelado', 'Licenciatura' => 'Licenciatura', 'Tecnólogo' => 'Tecnólogo', 'Técnico' => 'Técnico', 'Pós-graduação' => 'Pós-graduação'], $c['degree'])
        . input('periods', 'Períodos', $c['periods'], 'number', 'min="1" max="14"') . checkbox('active', 'Curso ativo', (bool) $c['active'], 'Inativo: não aparece para novas turmas e matrículas.') . '<button class="btn btn-secondary" type="submit">Salvar curso</button></form></div></div></div></div>');
}

// ---------- Períodos letivos ----------
function route_admin__periodos(): void {
    require_perm('terms:manage');
    if (is_post()) {
        $act = p('acao');
        if ($act === 'novo') {
            $d = ['name' => p('name'), 'starts_on' => p('starts_on'), 'ends_on' => p('ends_on')];
            if (!preg_match('/^\d{4}\.[1-4]$/', $d['name']) || !valid_date($d['starts_on']) || !valid_date($d['ends_on']) || $d['ends_on'] <= $d['starts_on']) flash('bad', 'Informe o nome (ex.: 2027.1) e datas válidas (fim depois do início).');
            elseif (row('SELECT id FROM terms WHERE institution_id = ? AND name = ?', [inst_id(), $d['name']])) flash('bad', 'Já existe um período com este nome.');
            else { $id = insert('terms', $d + ['institution_id' => inst_id(), 'active' => 0, 'closed' => 0, 'max_electives' => 2]); audit('term.create', 'Período cadastrado: ' . $d['name'], 'term', $id); flash('ok', 'Período cadastrado.'); }
        } elseif ($act === 'editar') {
            $t = row('SELECT * FROM terms WHERE id = ? AND institution_id = ?', [pi_('id'), inst_id()]);
            $d = ['starts_on' => p('starts_on'), 'ends_on' => p('ends_on'), 'elective_start' => p('elective_start') ?: null, 'elective_end' => p('elective_end') ?: null, 'max_electives' => max(0, min(6, pi_('max_electives'))), 'grades_deadline' => p('grades_deadline') ?: null];
            if ($t && valid_date($d['starts_on']) && valid_date($d['ends_on'])) { update('terms', $d, 'id = ?', [$t['id']]); audit('term.update', 'Período alterado: ' . $t['name'], 'term', (int) $t['id']); flash('ok', 'Período salvo.'); } else flash('bad', 'Datas inválidas.');
        } elseif ($act === 'ativar') {
            $t = row('SELECT * FROM terms WHERE id = ? AND institution_id = ? AND closed = 0', [pi_('id'), inst_id()]);
            if ($t) { q('UPDATE terms SET active = 0 WHERE institution_id = ?', [inst_id()]); update('terms', ['active' => 1], 'id = ?', [$t['id']]); audit('term.activate', 'Período ativo: ' . $t['name'], 'term', (int) $t['id']); flash('ok', "Período {$t['name']} é o ativo agora."); }
        } elseif ($act === 'encerrar') {
            $t = row('SELECT * FROM terms WHERE id = ? AND institution_id = ? AND closed = 0', [pi_('id'), inst_id()]);
            if ($t && p('confirm') === $t['name']) {
                $n = close_term($t);
                audit('term.close', "Período encerrado: {$t['name']} · $n matrículas com situação final", 'term', (int) $t['id']);
                flash('ok', "Período {$t['name']} encerrado: $n matrículas receberam a situação final.");
            } else flash('bad', 'Para encerrar, digite o nome do período.');
        }
        go('admin.periodos');
    }
    $list = terms_all();
    $tr = '';
    foreach ($list as $t) {
        $st = $t['closed'] ? badge('Encerrado') : ($t['active'] ? badge('Ativo', 'b-ok') : badge('Planejado', 'b-info'));
        $enr = (int) val('SELECT COUNT(*) FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE se.term_id = ?', [$t['id']]);
        $tr .= '<tr><td><strong>' . h($t['name']) . '</strong></td><td class="tnum">' . fmt_date($t['starts_on']) . ' a ' . fmt_date($t['ends_on']) . '</td><td class="small">' . ($t['elective_start'] ? fmt_date($t['elective_start']) . ' a ' . fmt_date($t['elective_end']) . ' · até ' . $t['max_electives'] : '—') . '</td><td class="tnum">' . fmt_date($t['grades_deadline']) . '</td><td class="tnum">' . $enr . '</td><td>' . $st . '</td>'
            . '<td class="r" style="white-space:nowrap">' . ($t['closed'] ? '' : '<a class="btn btn-sm btn-secondary" href="' . h(url('admin.periodos', ['editar' => $t['id']])) . '">' . icon('pencil') . '</a> ' . ($t['active'] ? '' : form_open('admin.periodos', [], 'class="inline-form"') . '<input type="hidden" name="acao" value="ativar"><input type="hidden" name="id" value="' . $t['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">Tornar ativo</button></form>')) . '</td></tr>';
    }
    $ed = gi('editar') ? row('SELECT * FROM terms WHERE id = ? AND institution_id = ?', [gi('editar'), inst_id()]) : null;
    $side = $ed ? '<div class="card"><div class="card-head"><div><h3>Editar ' . h($ed['name']) . '</h3></div></div><div class="card-body">' . form_open('admin.periodos', [], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="editar"><input type="hidden" name="id" value="' . $ed['id'] . '">'
        . input('starts_on', 'Início', $ed['starts_on'], 'date', 'required') . input('ends_on', 'Fim', $ed['ends_on'], 'date', 'required') . input('elective_start', 'Eletivas: abertura', $ed['elective_start'], 'date') . input('elective_end', 'Eletivas: encerramento', $ed['elective_end'], 'date')
        . input('max_electives', 'Máximo de eletivas por aluno', $ed['max_electives'], 'number', 'min="0" max="6"') . input('grades_deadline', 'Prazo para notas e frequência', $ed['grades_deadline'], 'date', '', 'Depois dele, só quem tem permissão edita.')
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><a class="btn btn-secondary" href="' . h(url('admin.periodos')) . '">Cancelar</a><button class="btn btn-primary" type="submit">Salvar</button></div></form>'
        . '<hr style="border:0;border-top:1px solid var(--line);margin:18px 0">' . form_open('admin.periodos', [], 'class="stack" data-confirm="Encerrar o período? As situações finais serão gravadas."') . '<input type="hidden" name="acao" value="encerrar"><input type="hidden" name="id" value="' . $ed['id'] . '">'
        . '<p class="small muted">Encerrar calcula a média final e a frequência de cada matrícula em curso, grava a situação (aprovado, reprovado ou reprovado por frequência) e leva para o histórico.</p>' . input('confirm', 'Digite ' . h($ed['name']) . ' para confirmar', '', 'text') . '<div><button class="btn btn-danger" type="submit">Encerrar período</button></div></form></div></div>'
        : '<div class="card"><div class="card-head"><div><h3>Novo período</h3></div></div><div class="card-body">' . form_open('admin.periodos', [], 'class="form-grid"') . '<input type="hidden" name="acao" value="novo">'
        . input('name', 'Nome', '', 'text', 'required placeholder="Ex.: 2027.1"') . input('starts_on', 'Início', '', 'date', 'required') . input('ends_on', 'Fim', '', 'date', 'required') . '<button class="btn btn-primary" type="submit">Cadastrar período</button></form></div></div>';
    layout('Períodos letivos', courses_tabs('periodos') . page_head('Períodos letivos', 'O período ativo é o que aparece para professores e alunos.')
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="card"><div class="table-wrap"><table><thead><tr><th>Período</th><th>Datas</th><th>Eletivas</th><th>Prazo de notas</th><th>Matrículas</th><th>Situação</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>' . $side . '</div>');
}
/** Grava a situação final de todas as matrículas em curso do período */
function close_term(array $t): int {
    return tx(function () use ($t) {
        $n = 0;
        foreach (rows("SELECT e.*, se.id AS sid FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE se.term_id = ? AND e.status = 'CURSANDO'", [$t['id']]) as $e) {
            $sec = section_full((int) $e['sid']);
            $r = enrollment_result($e, $sec, false);
            $tracks = (bool) $sec['tracks_attendance'];
            update('enrollments', ['status' => final_status($r['avg'], $r['att'], (float) $sec['min_grade'], (float) $sec['min_attendance'], $tracks), 'final_grade' => $r['avg'], 'attendance_pct' => $r['att']], 'id = ?', [$e['id']]);
            $n++;
        }
        update('terms', ['closed' => 1, 'active' => 0], 'id = ?', [$t['id']]);
        return $n;
    });
}

// ---------- Turmas ----------
function route_admin__turmas(): void {
    require_perm('classes:manage');
    $termId = term_id_or_active();
    if (is_post()) {
        $course = row('SELECT * FROM courses WHERE id = ? AND institution_id = ?', [pi_('course_id'), inst_id()]);
        $per = pi_('period_number'); $shift = in_array(p('shift'), ['Manhã', 'Tarde', 'Noite', 'Integral'], true) ? p('shift') : 'Noite';
        $tid = pi_('term_id');
        if (!$course || $per < 1 || $per > $course['periods'] || !row('SELECT id FROM terms WHERE id = ? AND institution_id = ?', [$tid, inst_id()])) flash('bad', 'Escolha curso, período e semestre válidos.');
        else {
            $code = p('code') ?: $course['code'] . '-' . $per . mb_substr($shift, 0, 1);
            if (row('SELECT id FROM class_groups WHERE term_id = ? AND code = ?', [$tid, $code])) flash('bad', "Já existe a turma $code neste período.");
            else { $id = insert('class_groups', ['institution_id' => inst_id(), 'term_id' => $tid, 'course_id' => $course['id'], 'code' => mb_substr($code, 0, 20), 'period_number' => $per, 'shift' => $shift]); audit('section.group', "Turma criada: $code", 'group', $id); flash('ok', "Turma $code criada. Crie as ofertas das disciplinas em Ofertas."); }
        }
        go('admin.turmas', ['periodo' => $tid]);
    }
    $list = rows('SELECT g.*, c.name AS course, (SELECT COUNT(*) FROM students s WHERE s.group_id = g.id) AS students, (SELECT COUNT(*) FROM section_groups sg WHERE sg.group_id = g.id) AS offers FROM class_groups g JOIN courses c ON c.id = g.course_id WHERE g.term_id = ? ORDER BY g.code', [$termId]);
    $tr = '';
    foreach ($list as $g) $tr .= '<tr><td><strong>' . h($g['code']) . '</strong></td><td>' . h($g['course']) . '</td><td>' . $g['period_number'] . 'º</td><td>' . h($g['shift']) . '</td><td class="tnum">' . $g['students'] . '</td><td class="tnum">' . $g['offers'] . '</td>'
        . '<td class="r"><a class="btn btn-sm btn-secondary" href="' . h(url('admin.horarios', ['turma' => $g['id']])) . '">Horários</a> <a class="btn btn-sm btn-ghost" href="' . h(url('admin.alunos', ['turma' => $g['id']])) . '">Alunos</a></td></tr>';
    $courses = []; foreach (rows('SELECT id, code, name FROM courses WHERE institution_id = ? AND active = 1 ORDER BY name', [inst_id()]) as $c) $courses[$c['id']] = $c['code'] . ' · ' . $c['name'];
    $terms = []; foreach (terms_all() as $t) if (!$t['closed']) $terms[$t['id']] = $t['name'];
    layout('Turmas', courses_tabs('turmas') . page_head('Turmas', 'Cada turma reúne os alunos de um curso, período e turno em um semestre.') . '<div class="card" style="margin-bottom:16px"><div class="card-body" style="padding-top:16px">' . term_select($termId, 'admin.turmas') . '</div></div>'
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="card"><div class="table-wrap"><table><thead><tr><th>Turma</th><th>Curso</th><th>Período</th><th>Turno</th><th>Alunos</th><th>Ofertas</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="7">' . empty_state('school', 'Nenhuma turma neste período') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Nova turma</h3></div></div><div class="card-body">' . form_open('admin.turmas', [], 'class="form-grid"') . select('term_id', 'Semestre', $terms, $termId) . select('course_id', 'Curso', $courses, '', 'required', '', '', 'Selecione')
        . input('period_number', 'Período do curso', '1', 'number', 'min="1" max="14"') . select('shift', 'Turno', ['Manhã' => 'Manhã', 'Tarde' => 'Tarde', 'Noite' => 'Noite', 'Integral' => 'Integral'], 'Noite') . input('code', 'Código (opcional)', '', 'text', 'maxlength="20"', 'Em branco: CURSO-PERÍODO+TURNO, ex.: ADS-2N.')
        . '<button class="btn btn-primary" type="submit">Criar turma</button></form></div></div></div>');
}

// ---------- Ofertas ----------
function route_admin__ofertas(): void {
    require_perm('classes:manage');
    $termId = term_id_or_active();
    if (is_post()) {
        $sub = row('SELECT * FROM subjects WHERE id = ? AND institution_id = ?', [pi_('subject_id'), inst_id()]);
        $type = p('offer_type') === 'ELECTIVE' ? 'ELECTIVE' : 'REGULAR';
        $groups = array_map('intval', (array) ($_POST['groups'] ?? []));
        $prof = pi_('professor_id'); $tid = pi_('term_id');
        if (!$sub || !row('SELECT id FROM terms WHERE id = ? AND institution_id = ? AND closed = 0', [$tid, inst_id()])) flash('bad', 'Escolha a disciplina e o período.');
        elseif ($type === 'REGULAR' && !$groups) flash('bad', 'Marque pelo menos uma turma (ou escolha “Eletiva”).');
        else {
            $id = tx(function () use ($sub, $type, $groups, $prof, $tid) {
                $codes = $groups ? col('SELECT code FROM class_groups WHERE id IN (' . in_list($groups) . ') AND term_id = ? ORDER BY code', array_merge($groups, [$tid])) : [];
                $code = $sub['code'] . '-' . ($type === 'ELECTIVE' ? 'ELT' . (1 + (int) val("SELECT COUNT(*) FROM sections WHERE term_id = ? AND subject_id = ? AND offer_type = 'ELECTIVE'", [$tid, $sub['id']])) : implode('+', $codes));
                $id = insert('sections', ['institution_id' => inst_id(), 'term_id' => $tid, 'subject_id' => $sub['id'], 'code' => mb_substr($code, 0, 30), 'offer_type' => $type, 'room' => p('room') ?: null, 'capacity' => pi_('capacity') ?: null, 'min_grade' => 6, 'min_attendance' => 75]);
                if ($prof && row('SELECT id FROM professors WHERE id = ?', [$prof])) insert('section_professors', ['section_id' => $id, 'professor_id' => $prof]);
                if ($type === 'REGULAR') foreach (col('SELECT id FROM class_groups WHERE id IN (' . in_list($groups) . ') AND term_id = ?', array_merge($groups, [$tid])) as $gid) {
                    insert('section_groups', ['section_id' => $id, 'group_id' => $gid]);
                    foreach (col("SELECT id FROM students WHERE group_id = ? AND status = 'ATIVO'", [$gid]) as $st) if (!row('SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?', [$st, $id])) insert('enrollments', ['student_id' => $st, 'section_id' => $id, 'status' => 'CURSANDO', 'created_at' => now()]);
                }
                return $id;
            });
            audit('section.create', 'Oferta criada: ' . $sub['code'] . ' · ' . $sub['name'], 'section', $id);
            flash('ok', 'Oferta criada. Defina os horários.');
            go('admin.oferta', ['id' => $id]);
        }
        go('admin.ofertas', ['periodo' => $tid]);
    }
    $tipo = g('tipo') ?: 'todas';
    $list = rows('SELECT se.id FROM sections se JOIN subjects su ON su.id = se.subject_id WHERE se.term_id = ? AND se.institution_id = ? ORDER BY su.name, se.code', [$termId, inst_id()]);
    $secs = array_map(fn($r) => section_full((int) $r['id']), $list);
    $match = fn($s, $t) => $t === 'todas' || ($t === 'eletivas' ? $s['offer_type'] === 'ELECTIVE' : ($t === 'compartilhadas' ? count($s['groups']) > 1 : $s['offer_type'] === 'REGULAR'));
    $tr = '';
    foreach (array_filter($secs, fn($s) => $match($s, $tipo)) as $s) {
        $n = (int) val('SELECT COUNT(*) FROM enrollments WHERE section_id = ?', [$s['id']]);
        $href = url('admin.oferta', ['id' => $s['id']]);
        $tr .= '<tr class="clickable" data-href="' . h($href) . '"><td><a class="cell-link" href="' . h($href) . '">' . h($s['subject_name']) . '</a><p class="cell-sub">' . h($s['code']) . ' · ' . $s['workload'] . ' h</p><div class="row" style="gap:4px;margin-top:4px">' . ($s['offer_type'] === 'ELECTIVE' ? badge('Eletiva', 'b-info') : '') . (count($s['groups']) > 1 ? badge('Compartilhada', 'b-warn') : '') . '</div></td>'
            . '<td>' . ($s['offer_type'] === 'ELECTIVE' ? '<span class="muted small">Escolha do aluno</span>' : implode(' ', array_map(fn($g) => badge(h($g['code'])), $s['groups']))) . '</td>'
            . '<td class="small" style="white-space:nowrap">' . ($s['schedules'] ? implode('<br>', array_map(fn($x) => h(slot_label($x)), $s['schedules'])) : badge('Sem horário', 'b-warn')) . '</td>'
            . '<td>' . (h(implode(', ', array_column($s['profs'], 'name'))) ?: badge('Sem professor', 'b-warn')) . '</td><td class="tnum r">' . $n . ($s['capacity'] ? '<p class="cell-sub">de ' . $s['capacity'] . ' vagas</p>' : '') . '</td></tr>';
    }
    $u = url('admin.ofertas', ['periodo' => $termId]);
    $tabsHtml = '<nav class="tabs" aria-label="Tipo de oferta" style="padding:0 16px;border-bottom:1px solid var(--line)">';
    foreach (['todas' => 'Todas', 'regulares' => 'Regulares', 'compartilhadas' => 'Compartilhadas', 'eletivas' => 'Eletivas'] as $k => $l) $tabsHtml .= '<a class="tab' . ($tipo === $k ? ' active' : '') . '" href="' . h($u . '&tipo=' . $k) . '">' . $l . ' ' . badge((string) count(array_filter($secs, fn($s) => $match($s, $k)))) . '</a>';
    $tabsHtml .= '</nav>';
    $subjects = []; foreach (rows('SELECT id, code, name, workload FROM subjects WHERE institution_id = ? ORDER BY name', [inst_id()]) as $s) $subjects[$s['id']] = $s['name'] . ' (' . $s['code'] . ') · ' . $s['workload'] . ' h';
    $profs = []; foreach (rows("SELECT p.id, u.name FROM professors p JOIN users u ON u.id = p.user_id WHERE (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?)) AND u.status = 'ACTIVE' ORDER BY u.name", inst_params()) as $p) $profs[$p['id']] = $p['name'];
    $groupChecks = ''; foreach (rows('SELECT * FROM class_groups WHERE term_id = ? ORDER BY code', [$termId]) as $g) $groupChecks .= '<label class="row small" style="gap:6px"><input type="checkbox" name="groups[]" value="' . $g['id'] . '"> ' . h($g['code']) . ' <span class="muted">(' . h($g['shift']) . ')</span></label>';
    $terms = []; foreach (terms_all() as $t) if (!$t['closed']) $terms[$t['id']] = $t['name'];
    layout('Ofertas', courses_tabs('ofertas') . page_head('Ofertas', 'Disciplinas oferecidas no período: professor, horários e turmas atendidas. Uma oferta pode atender várias turmas.')
        . '<div class="card" style="margin-bottom:16px"><div class="card-body" style="padding-top:16px">' . term_select($termId, 'admin.ofertas') . '</div></div>'
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="card">' . $tabsHtml . '<div class="table-wrap"><table><thead><tr><th>Disciplina</th><th>Turmas atendidas</th><th>Horários</th><th>Professor</th><th class="r">Alunos</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('layers', 'Nenhuma oferta') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Nova oferta</h3><p>Os alunos das turmas marcadas são matriculados automaticamente.</p></div></div><div class="card-body">' . form_open('admin.ofertas', [], 'class="form-grid"') . select('term_id', 'Período', $terms, $termId)
        . select('subject_id', 'Disciplina', $subjects, '', 'required', '', '', 'Selecione') . select('offer_type', 'Tipo', ['REGULAR' => 'Regular (turmas definidas)', 'ELECTIVE' => 'Eletiva (o aluno escolhe)'], 'REGULAR')
        . '<fieldset class="field"><legend class="small" style="font-weight:500;color:var(--navy);margin-bottom:6px">Turmas atendidas (oferta regular)</legend><div class="stack" style="gap:6px">' . ($groupChecks ?: '<span class="muted small">Nenhuma turma neste período.</span>') . '</div></fieldset>'
        . select('professor_id', 'Professor', $profs, '', '', '', '', 'Definir depois') . input('room', 'Sala', '', 'text', 'maxlength="60" placeholder="Ex.: Lab 2"') . input('capacity', 'Vagas (eletivas)', '', 'number', 'min="1"')
        . '<button class="btn btn-primary" type="submit">Criar oferta</button></form></div></div></div>');
}

function route_admin__oferta(): void {
    require_perm('classes:manage');
    $s = section_full(gi('id'));
    if (!$s) abort(404);
    if (is_post()) {
        $act = p('acao');
        if ($act === 'horario') {
            $slot = ['weekday' => pi_('weekday'), 'start_time' => p('start_time'), 'end_time' => p('end_time')];
            if (!isset(WEEKDAYS[$slot['weekday']]) || !valid_time($slot['start_time']) || !valid_time($slot['end_time']) || $slot['end_time'] <= $slot['start_time']) flash('bad', 'Informe dia e horário válidos (fim depois do início).');
            elseif ($c = schedule_conflicts($s, $slot)) flash('bad', '<strong>Conflito de horário.</strong> ' . h(implode(' ', $c)));
            else { insert('schedules', $slot + ['section_id' => $s['id']]); audit('section.schedule', 'Horário incluído em ' . $s['code'] . ': ' . slot_label($slot), 'section', (int) $s['id']); flash('ok', 'Horário incluído.'); }
        } elseif ($act === 'tirar_horario') {
            q('DELETE FROM schedules WHERE id = ? AND section_id = ?', [pi_('sch'), $s['id']]); flash('ok', 'Horário removido.');
        } elseif ($act === 'dados') {
            $prof = pi_('professor_id');
            tx(function () use ($s, $prof) {
                update('sections', ['room' => p('room') ?: null, 'capacity' => pi_('capacity') ?: null, 'min_grade' => max(0, min(10, (float) str_replace(',', '.', p('min_grade')))), 'min_attendance' => max(0, min(100, pi_('min_attendance')))], 'id = ?', [$s['id']]);
                q('DELETE FROM section_professors WHERE section_id = ?', [$s['id']]);
                if ($prof) insert('section_professors', ['section_id' => $s['id'], 'professor_id' => $prof]);
            });
            audit('section.update', 'Oferta alterada: ' . $s['code'], 'section', (int) $s['id']); flash('ok', 'Oferta salva.');
        } elseif ($act === 'turma') {
            $g = row('SELECT * FROM class_groups WHERE id = ? AND term_id = ?', [pi_('group_id'), $s['term_id']]);
            if (!$g || $s['offer_type'] !== 'REGULAR') flash('bad', 'Turma inválida.');
            else {
                $conf = []; foreach ($s['schedules'] as $sl) foreach (rows('SELECT sc.*, se.code FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN section_groups sg ON sg.section_id = se.id WHERE sg.group_id = ? AND se.id <> ?', [$g['id'], $s['id']]) as $o) if (slots_overlap($sl, $o)) $conf[] = "{$o['code']} (" . slot_label($o) . ')';
                if ($conf) flash('bad', 'A turma ' . h($g['code']) . ' já tem aula nesses horários: ' . h(implode(', ', array_unique($conf))) . '.');
                else {
                    $n = tx(function () use ($s, $g) { if (!row('SELECT 1 FROM section_groups WHERE section_id = ? AND group_id = ?', [$s['id'], $g['id']])) insert('section_groups', ['section_id' => $s['id'], 'group_id' => $g['id']]); $n = 0; foreach (col("SELECT id FROM students WHERE group_id = ? AND status = 'ATIVO'", [$g['id']]) as $st) if (!row('SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?', [$st, $s['id']])) { insert('enrollments', ['student_id' => $st, 'section_id' => $s['id'], 'status' => 'CURSANDO', 'created_at' => now()]); $n++; } return $n; });
                    audit('section.group', "Turma {$g['code']} juntada à oferta {$s['code']}", 'section', (int) $s['id']); flash('ok', "Turma {$g['code']} incluída: $n alunos matriculados nesta oferta.");
                }
            }
        } elseif ($act === 'excluir') {
            $grades = (int) val('SELECT COUNT(*) FROM grades g JOIN assessments a ON a.id = g.assessment_id WHERE a.section_id = ?', [$s['id']]) + (int) val('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$s['id']]);
            if ($grades) flash('bad', 'Não dá para excluir: a oferta já tem aulas ou notas registradas.');
            else { q('DELETE FROM sections WHERE id = ?', [$s['id']]); audit('section.delete', 'Oferta excluída: ' . $s['code'], 'section', (int) $s['id']); flash('ok', 'Oferta excluída.'); go('admin.ofertas', ['periodo' => $s['term_id']]); }
        }
        go('admin.oferta', ['id' => $s['id']]);
    }
    $roster = section_roster((int) $s['id']);
    $profs = []; foreach (rows("SELECT p.id, u.name FROM professors p JOIN users u ON u.id = p.user_id WHERE (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?)) AND u.status = 'ACTIVE' ORDER BY u.name", inst_params()) as $p) $profs[$p['id']] = $p['name'];
    $other = []; foreach (rows('SELECT g.* FROM class_groups g WHERE g.term_id = ? AND g.id NOT IN (SELECT group_id FROM section_groups WHERE section_id = ?) ORDER BY g.code', [$s['term_id'], $s['id']]) as $g) $other[$g['id']] = $g['code'] . ' · ' . $g['period_number'] . 'º período · ' . $g['shift'];
    $sch = '';
    foreach ($s['schedules'] as $x) $sch .= '<li class="row" style="justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line)"><span>' . h(WEEKDAYS[$x['weekday']] . ', ' . $x['start_time'] . ' às ' . $x['end_time']) . '</span>' . form_open('admin.oferta', ['id' => $s['id']], 'class="inline-form"') . '<input type="hidden" name="acao" value="tirar_horario"><input type="hidden" name="sch" value="' . $x['id'] . '"><button class="btn btn-sm btn-ghost" type="submit" title="Remover">' . icon('x') . '</button></form></li>';
    $slots = []; foreach (STANDARD_SLOTS as [$a, $b]) $slots["$a-$b"] = "$a–$b";
    $stud = ''; foreach ($roster as $r) $stud .= '<tr><td>' . h($r['name']) . '</td><td class="tnum">' . h($r['ra']) . '</td><td>' . badge(STATUS_LABEL[$r['status']] ?? $r['status'], STATUS_TONE[$r['status']] ?? 'b-neutral') . '</td></tr>';
    layout($s['subject_name'], courses_tabs('ofertas') . crumb('admin.ofertas', 'Ofertas', ['periodo' => $s['term_id']]) . page_head(h($s['subject_name']), h($s['code']) . ' · ' . h($s['term_name']) . ' · ' . $s['workload'] . ' h · ' . ($s['offer_type'] === 'ELECTIVE' ? 'Eletiva' : 'Regular') . ' · ' . ($s['modality'] === 'D' ? 'A distância' : 'Presencial'))
        . '<div class="grid g2" style="align-items:start"><div class="stack">'
        . '<div class="card"><div class="card-head"><div><h3>Horários</h3><p>O sistema confere conflitos de sala, professor e turma.</p></div></div><div class="card-body stack"><ul style="list-style:none;padding:0;margin:0">' . ($sch ?: '<li class="muted small">Sem horário definido.</li>') . '</ul>'
        . form_open('admin.oferta', ['id' => $s['id']], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="horario">' . select('weekday', 'Dia', WEEKDAYS, 1) . '<div class="field"><label for="f-slot">Horário</label><select class="input" id="f-slot" data-slot-pick><option value="">Padrão…</option>'
        . implode('', array_map(fn($k, $v) => '<option value="' . $k . '">' . $v . '</option>', array_keys($slots), $slots)) . '</select></div>' . input('start_time', 'Início', '19:00', 'time', 'required') . input('end_time', 'Fim', '20:40', 'time', 'required')
        . '<div class="span-2"><button class="btn btn-primary" type="submit">' . icon('plus') . ' Incluir horário</button></div></form></div></div>'
        . ($s['offer_type'] === 'REGULAR' ? '<div class="card"><div class="card-head"><div><h3>Turmas atendidas</h3><p>' . (count($s['groups']) > 1 ? 'Oferta compartilhada: as turmas assistem à mesma aula.' : 'Junte outra turma para que assistam à mesma aula.') . '</p></div></div><div class="card-body stack"><div class="row" style="gap:6px">' . implode('', array_map(fn($g) => badge(h($g['code'])), $s['groups'])) . '</div>'
            . ($other ? form_open('admin.oferta', ['id' => $s['id']], 'class="row" style="gap:8px;align-items:flex-end"') . '<input type="hidden" name="acao" value="turma">' . select('group_id', 'Juntar turma', $other, '', '', '', 'grow') . '<button class="btn btn-secondary" type="submit">Juntar</button></form>' : '') . '</div></div>' : '')
        . '<div class="card"><div class="card-head"><div><h3>Dados da oferta</h3></div></div><div class="card-body">' . form_open('admin.oferta', ['id' => $s['id']], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="dados">'
        . select('professor_id', 'Professor', $profs, $s['profs'][0]['id'] ?? '', '', '', 'span-2', 'Sem professor') . input('room', 'Sala', $s['room']) . input('capacity', 'Vagas', $s['capacity'], 'number', 'min="1"')
        . input('min_grade', 'Média mínima', num((float) $s['min_grade'], 1), 'text') . input('min_attendance', 'Frequência mínima (%)', $s['min_attendance'], 'number', 'min="0" max="100"')
        . '<div class="span-2 row" style="justify-content:space-between"><button class="btn btn-primary" type="submit">Salvar</button></div></form>'
        . form_open('admin.oferta', ['id' => $s['id']], 'data-confirm="Excluir esta oferta?" style="margin-top:12px"') . '<input type="hidden" name="acao" value="excluir"><button class="btn btn-ghost btn-sm" type="submit">' . icon('trash-2') . ' Excluir oferta</button></form></div></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Alunos matriculados</h3><p>' . plural(count($roster), 'aluno', 'alunos') . ($s['capacity'] ? ' de ' . $s['capacity'] . ' vagas' : '') . '</p></div></div><div class="table-wrap"><table><thead><tr><th>Aluno</th><th>RA</th><th>Situação</th></tr></thead><tbody>' . ($stud ?: '<tr><td colspan="3">' . empty_state('users', 'Nenhum aluno') . '</td></tr>') . '</tbody></table></div></div></div>');
}

// ---------- Quadro de horários ----------
function route_admin__horarios(): void {
    require_perm('classes:manage');
    $termId = term_id_or_active();
    $turma = gi('turma'); $prof = gi('professor');
    if ($turma) { $g = row('SELECT * FROM class_groups WHERE id = ?', [$turma]); if ($g) $termId = (int) $g['term_id']; }
    $groups = []; foreach (rows('SELECT * FROM class_groups WHERE term_id = ? ORDER BY code', [$termId]) as $g) $groups[$g['id']] = $g['code'];
    $profs = []; foreach (rows("SELECT p.id, u.name FROM professors p JOIN users u ON u.id = p.user_id WHERE (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?)) ORDER BY u.name", inst_params()) as $p) $profs[$p['id']] = $p['name'];
    if (!$turma && !$prof && $groups) $turma = (int) array_key_first($groups);
    $items = [];
    if ($turma) $src = rows('SELECT sc.*, se.code, se.room, su.name FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN subjects su ON su.id = se.subject_id JOIN section_groups sg ON sg.section_id = se.id WHERE sg.group_id = ?', [$turma]);
    else $src = rows('SELECT sc.*, se.code, se.room, su.name FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN subjects su ON su.id = se.subject_id JOIN section_professors sp ON sp.section_id = se.id WHERE sp.professor_id = ? AND se.term_id = ?', [$prof, $termId]);
    foreach ($src as $x) $items[] = ['weekday' => $x['weekday'], 'start_time' => $x['start_time'], 'end_time' => $x['end_time'], 'title' => $x['name'], 'sub' => $x['code'] . ($x['room'] ? ' · ' . $x['room'] : '')];
    $pending = (int) val('SELECT COUNT(*) FROM sections se WHERE se.term_id = ? AND NOT EXISTS (SELECT 1 FROM schedules sc WHERE sc.section_id = se.id) AND se.room <> \'EaD\'', [$termId]);
    layout('Quadro de horários', courses_tabs('horarios') . page_head('Quadro de horários', 'Grade semanal por turma ou por professor. Para mudar um horário, abra a oferta.')
        . ($pending ? alert('warn', plural($pending, 'oferta presencial está', 'ofertas presenciais estão') . ' sem horário neste período. <a href="' . h(url('admin.ofertas', ['periodo' => $termId])) . '">Ver ofertas</a>.') : '')
        . '<div class="card" style="margin:16px 0"><div class="card-body" style="padding-top:16px"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="admin.horarios"><input type="hidden" name="periodo" value="' . $termId . '">'
        . select('turma', 'Turma', $groups, $turma, 'data-autosubmit', '', '', $prof ? 'Ver por professor' : 'Selecione') . select('professor', 'ou Professor', $profs, $prof, 'data-autosubmit', '', '', 'Selecione') . '</form></div></div>'
        . '<div class="card"><div class="card-body" style="padding-top:16px">' . week_grid($items) . '</div></div>');
}
