<?php
// Portal do aluno: meus cursos, disciplinas (resumo, frequência, notas), eletivas, horários e financeiro.

function my_student(): array {
    $u = require_perm('student-portal:own');
    if (!$u['student']) abort(403, 'Seu usuário não está ligado a um cadastro de aluno.');
    return student_full((int) $u['student']['id']);
}

function route_aluno__cursos(): void {
    $s = my_student();
    $p = course_progress($s);
    $doing = (int) val("SELECT COUNT(*) FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE e.student_id = ? AND se.term_id = ? AND e.status = 'CURSANDO'", [$s['id'], active_term()['id'] ?? 0]);
    layout('Meus cursos', page_head('Meus cursos', 'Clique no curso para ver as disciplinas, notas e frequência.')
        . '<div class="course-cards"><a class="card course-card" href="' . h(url('aluno.curso')) . '"><div class="row" style="justify-content:space-between"><span class="badge b-info">' . h($s['course_code']) . '</span>' . badge(STUDENT_STATUS[$s['status']], 'b-ok') . '</div><h3>' . h($s['course_name']) . '</h3>'
        . '<p class="small muted">RA ' . h($s['ra']) . ($s['group_code'] ? ' · turma ' . h($s['group_code']) : '') . '</p><div><div class="row" style="justify-content:space-between"><span class="small">Carga horária concluída</span><strong class="small">' . num($p['pct']) . '%</strong></div><div class="bar ok" style="width:100%;margin-top:6px"><span style="width:' . $p['pct'] . '%"></span></div></div>'
        . '<p class="small muted">' . $p['done'] . ' de ' . $p['required'] . ' h obrigatórias · ' . plural($doing, 'disciplina', 'disciplinas') . ' em curso</p></a></div>');
}

function route_aluno__curso(): void {
    $s = my_student();
    $term = active_term();
    $p = course_progress($s);
    $enr = $term ? student_term_enrollments((int) $s['id'], (int) $term['id']) : [];
    $cards = '';
    foreach ($enr as $e) {
        if ($e['status'] === 'TRANCADO') continue;
        $sec = $e['section']; $r = enrollment_result($e, $sec);
        $cards .= '<a class="subject-card" href="' . h(url('aluno.disciplina', ['id' => $e['id']])) . '"><div class="row" style="justify-content:space-between"><span class="badge b-info">' . h($sec['subject_code']) . '</span>' . ($sec['modality'] === 'D' ? badge('EaD', 'b-warn') : '') . '</div>'
            . '<h4 style="color:var(--navy);font-size:15px">' . h($sec['subject_name']) . '</h4><p class="small muted">' . h(implode(', ', array_column($sec['profs'], 'name'))) . '</p>'
            . '<div class="row" style="gap:16px"><span class="small">Média <strong>' . num($r['avg']) . '</strong></span>' . ($sec['tracks_attendance'] ? '<span class="small">Frequência <strong>' . ($r['att'] === null ? '—' : num($r['att']) . '%') . '</strong></span>' : '<span class="small muted">Sem frequência</span>') . '</div>'
            . ($r['risk'] ? badge('Atenção: abaixo do mínimo', 'b-warn') : '') . '</a>';
    }
    $lbl = ['done' => badge('Concluída', 'b-ok'), 'doing' => badge('Cursando', 'b-info'), 'failed' => badge('Reprovada', 'b-bad'), 'todo' => '<span class="muted small">A cursar</span>'];
    $tr = ''; $cur = 0;
    foreach ($p['matrix'] as $m) { if ($m['period'] !== $cur) { $cur = $m['period']; $tr .= '<tr class="group-row"><td colspan="4"><strong>' . $cur . 'º período</strong></td></tr>'; } $tr .= '<tr><td>' . h($m['name']) . ($m['required'] ? '' : ' ' . badge('Optativa', 'b-info')) . '</td><td class="tnum">' . $m['workload'] . ' h</td><td class="tnum">' . num($m['final_grade'] === null ? null : (float) $m['final_grade']) . '</td><td>' . $lbl[$m['state']] . '</td></tr>'; }
    layout($s['course_name'], crumb('aluno.cursos', 'Meus cursos') . page_head(h($s['course_name']), 'Período ' . h($term['name'] ?? '—') . ' · ' . $p['done'] . ' de ' . $p['required'] . ' h obrigatórias concluídas (' . num($p['pct']) . '%)')
        . '<h3 class="sub-title">Disciplinas do período</h3><div class="subject-grid" style="display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(260px,1fr))">' . ($cards ?: empty_state('book-open', 'Nenhuma disciplina no período')) . '</div>'
        . '<div class="card" style="margin-top:20px"><div class="card-head"><div><h3>Andamento no curso</h3><p>Carga horária e situação em cada disciplina da matriz.</p></div></div><div class="card-body"><div class="prog"><i class="done" style="width:' . $p['pct'] . '%"></i><i class="cur" style="width:' . ($p['required'] ? $p['doing'] * 100 / $p['required'] : 0) . '%"></i></div><div class="prog-legend"><span style="--c:var(--green)">Concluída</span><span style="--c:var(--blue)">Cursando</span><span style="--c:var(--line)">A cursar</span></div></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Disciplina</th><th>CH</th><th>Média final</th><th>Situação</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>');
}

function route_aluno__disciplina(): void {
    $s = my_student();
    $e = row('SELECT * FROM enrollments WHERE id = ? AND student_id = ?', [gi('id'), $s['id']]);
    if (!$e) abort(404);
    $sec = section_full((int) $e['section_id']);
    $r = enrollment_result($e, $sec);
    $tracks = (bool) $sec['tracks_attendance'];
    $tab = $tracks ? g('aba', 'resumo') : 'notas';
    $base = url('aluno.disciplina', ['id' => $e['id']]);
    $head = crumb('aluno.curso', $s['course_name']) . page_head(h($sec['subject_name']), h($sec['subject_code']) . ' · ' . $sec['workload'] . ' h · ' . h(implode(', ', array_column($sec['profs'], 'name'))) . ' · ' . h(schedules_text($sec['schedules'])))
        . ($tracks ? tabs([['resumo', 'Resumo', 'layout-dashboard', $base], ['frequencia', 'Frequência', 'calendar-check', $base . '&aba=frequencia'], ['notas', 'Notas', 'clipboard-check', $base . '&aba=notas']], $tab) : alert('info', 'Esta disciplina não registra frequência: você acompanha só as notas.'));
    $notes = '';
    foreach ($r['items'] as $i) if ($i['published']) $notes .= '<tr><td>' . h($i['name']) . '<p class="cell-sub">' . ($i['due_on'] ? fmt_date($i['due_on']) . ' · ' : '') . 'peso ' . num((float) $i['weight']) . '</p></td><td class="r tnum"><strong>' . ($i['score'] === null ? '—' : num((float) $i['score'])) . '</strong></td></tr>';
    $pending = count(array_filter($r['items'], fn($i) => !$i['published']));
    $notesCard = '<div class="card"><div class="card-head"><div><h3>Notas</h3><p>' . ($pending ? plural($pending, 'avaliação ainda não divulgada', 'avaliações ainda não divulgadas') . '.' : 'Todas as avaliações divulgadas.') . '</p></div></div><div class="table-wrap"><table><thead><tr><th>Avaliação</th><th class="r">Nota</th></tr></thead><tbody>' . ($notes ?: '<tr><td colspan="2">' . empty_state('clipboard-check', 'Nenhuma nota divulgada ainda') . '</td></tr>') . '</tbody><tfoot><tr><td><strong>Média atual</strong></td><td class="r tnum"><strong>' . num($r['avg']) . '</strong></td></tr></tfoot></table></div></div>';
    if ($tab === 'notas') { layout($sec['subject_name'], $head . $notesCard); return; }
    if ($tab === 'frequencia') {
        $tr = '';
        foreach (rows('SELECT l.lesson_date, l.topic, l.hours, a.present FROM lessons l LEFT JOIN attendance a ON a.lesson_id = l.id AND a.enrollment_id = ? WHERE l.section_id = ? ORDER BY l.lesson_date DESC', [$e['id'], $sec['id']]) as $l)
            $tr .= '<tr><td class="tnum">' . fmt_date($l['lesson_date']) . '</td><td>' . h($l['topic']) . '</td><td>' . ($l['present'] === null ? '<span class="muted small">—</span>' : ($l['present'] ? badge('Presente', 'b-ok') : badge('Falta', 'b-bad'))) . '</td></tr>';
        layout($sec['subject_name'], $head . '<div class="grid g3" style="margin-bottom:16px">' . stat_card('Frequência', $r['att'] === null ? '—' : num($r['att']) . '%', 'calendar-check', $r['att'] !== null && $r['att'] < $sec['min_attendance'] ? 'warn' : '') . stat_card('Faltas', (string) $r['absences'], 'calendar-x') . stat_card('Mínimo exigido', $sec['min_attendance'] . '%', 'shield-check') . '</div>'
            . '<div class="card"><div class="table-wrap"><table><thead><tr><th>Data</th><th>Conteúdo</th><th>Presença</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="3">' . empty_state('calendar', 'Nenhuma aula registrada') . '</td></tr>') . '</tbody></table></div></div>');
        return;
    }
    $msg = $r['status'] !== 'CURSANDO' ? alert($r['status'] === 'APROVADO' ? 'ok' : 'bad', 'Situação final: ' . STATUS_LABEL[$r['status']] . '.')
        : ($r['risk'] ? alert('warn', 'Sua média ou frequência está abaixo do mínimo (média ' . num((float) $sec['min_grade']) . ' e ' . $sec['min_attendance'] . '% de frequência). Procure o professor.', 'Atenção') : alert('ok', 'Você está dentro do mínimo exigido até agora.'));
    layout($sec['subject_name'], $head . $msg . '<div class="grid g3" style="margin:16px 0">' . stat_card('Média atual', num($r['avg']), 'chart-line') . stat_card('Frequência', $r['att'] === null ? '—' : num($r['att']) . '%', 'calendar-check') . stat_card('Aulas registradas', (string) $r['lessons'], 'notebook-pen') . '</div>' . $notesCard);
}

function route_aluno__eletivas(): void {
    $u = require_perm('electives:enroll:own');
    $s = my_student();
    $term = active_term();
    if (!$term) { layout('Eletivas', page_head('Eletivas') . alert('warn', 'Não há período letivo ativo.')); return; }
    $open = $term['elective_start'] && $term['elective_end'] && today() >= $term['elective_start'] && today() <= $term['elective_end'];
    $optSubjects = col('SELECT subject_id FROM course_subjects WHERE course_id = ? AND required = 0', [$s['course_id']]);
    $mine = rows("SELECT e.*, se.subject_id FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE e.student_id = ? AND se.term_id = ? AND se.offer_type = 'ELECTIVE' AND e.status = 'CURSANDO'", [$s['id'], $term['id']]);
    if (is_post()) {
        $sec = section_full(pi_('section_id'));
        $act = p('acao');
        if (!$open) flash('bad', 'As inscrições em eletivas estão fechadas.');
        elseif (!$sec || $sec['offer_type'] !== 'ELECTIVE' || (int) $sec['term_id'] !== (int) $term['id'] || !in_array($sec['subject_id'], $optSubjects)) flash('bad', 'Eletiva inválida para o seu curso.');
        elseif ($act === 'cancelar') {
            $e = row('SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?', [$s['id'], $sec['id']]);
            if ($e) { q('DELETE FROM enrollments WHERE id = ?', [$e['id']]); audit('student.elective_cancel', 'Cancelou a eletiva ' . $sec['code'], 'student', (int) $s['id']); flash('ok', 'Inscrição cancelada.'); }
        } else {
            $swap = null; foreach ($mine as $m) if ((int) $m['subject_id'] === (int) $sec['subject_id']) $swap = $m;
            $count = count($mine) - ($swap ? 1 : 0);
            $taken = (int) val("SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'CURSANDO'", [$sec['id']]);
            $busy = rows("SELECT sc.*, se.code FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN enrollments e ON e.section_id = se.id WHERE e.student_id = ? AND se.term_id = ? AND e.status = 'CURSANDO'" . ($swap ? ' AND e.id <> ' . (int) $swap['id'] : ''), [$s['id'], $term['id']]);
            $conf = []; foreach ($sec['schedules'] as $a) foreach ($busy as $b) if (slots_overlap($a, $b)) $conf[] = $b['code'];
            if ($count >= (int) $term['max_electives']) flash('bad', 'Você já atingiu o limite de ' . $term['max_electives'] . ' eletivas.');
            elseif ($sec['capacity'] && $taken >= $sec['capacity']) flash('bad', 'Não há mais vagas neste horário.');
            elseif ($conf) flash('bad', 'Este horário conflita com ' . h(implode(', ', array_unique($conf))) . '.');
            else {
                tx(function () use ($swap, $s, $sec) { if ($swap) q('DELETE FROM enrollments WHERE id = ?', [$swap['id']]); insert('enrollments', ['student_id' => $s['id'], 'section_id' => $sec['id'], 'status' => 'CURSANDO', 'created_at' => now()]); });
                audit('student.elective', ($swap ? 'Trocou para a eletiva ' : 'Escolheu a eletiva ') . $sec['code'], 'student', (int) $s['id']);
                flash('ok', ($swap ? 'Horário trocado: ' : 'Inscrição feita em ') . h($sec['subject_name']) . '.');
            }
        }
        go('aluno.eletivas');
    }
    $mineIds = array_map('intval', array_column($mine, 'section_id'));
    $offers = $optSubjects ? rows("SELECT se.id FROM sections se WHERE se.term_id = ? AND se.offer_type = 'ELECTIVE' AND se.subject_id IN (" . in_list($optSubjects) . ') ORDER BY se.subject_id, se.code', array_merge([$term['id']], $optSubjects)) : [];
    $by = []; foreach ($offers as $o) { $sec = section_full((int) $o['id']); $by[$sec['subject_id']][] = $sec; }
    $cards = '';
    foreach ($by as $list) {
        $first = $list[0]; $tr = '';
        foreach ($list as $sec) {
            $taken = (int) val("SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status = 'CURSANDO'", [$sec['id']]);
            $free = $sec['capacity'] ? max(0, $sec['capacity'] - $taken) : null;
            $isMine = in_array((int) $sec['id'], $mineIds, true);
            $btn = $isMine ? badge('Inscrito', 'b-ok') . ($open ? ' ' . form_open('aluno.eletivas', [], 'class="inline-form" data-confirm="Cancelar a inscrição nesta eletiva?"') . '<input type="hidden" name="acao" value="cancelar"><input type="hidden" name="section_id" value="' . $sec['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">Cancelar</button></form>' : '')
                : (!$open ? badge('Encerrado') : ($free === 0 ? badge('Sem vagas') : form_open('aluno.eletivas', [], 'class="inline-form"') . '<input type="hidden" name="section_id" value="' . $sec['id'] . '"><button class="btn btn-sm btn-primary" type="submit">' . (array_intersect(array_column($mine, 'subject_id'), [$sec['subject_id']]) ? 'Trocar para este horário' : 'Escolher') . '</button></form>'));
            $tr .= '<tr><td>' . h($sec['code']) . '</td><td class="small">' . h(schedules_text($sec['schedules'])) . '</td><td>' . h(implode(', ', array_column($sec['profs'], 'name'))) . '</td><td>' . h($sec['room']) . '</td><td class="tnum">' . ($free === null ? 'Livre' : $free . ' livres') . '</td><td class="r" style="white-space:nowrap">' . $btn . '</td></tr>';
        }
        $cards .= '<div class="card"><div class="card-head"><div><h3>' . h($first['subject_name']) . '</h3><p>' . h($first['subject_code']) . ' · ' . $first['workload'] . ' h · ' . ($first['modality'] === 'D' ? 'A distância' : 'Presencial') . '</p></div></div><div class="table-wrap"><table><thead><tr><th>Turma</th><th>Horário</th><th>Professor</th><th>Sala</th><th>Vagas</th><th></th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>';
    }
    layout('Eletivas', page_head('Eletivas', 'Período ' . h($term['name']) . '. Escolha a eletiva e o horário que preferir.')
        . alert($open ? 'info' : 'warn', 'Escolha até ' . $term['max_electives'] . ' eletivas. O sistema não deixa escolher horário que conflite com as suas aulas. Você já tem ' . count($mine) . '.', $open ? 'Inscrições abertas até ' . fmt_date($term['elective_end']) : 'Inscrições fechadas')
        . '<div class="stack" style="margin-top:16px">' . ($cards ?: '<div class="card">' . empty_state('list-checks', 'Nenhuma eletiva oferecida para o seu curso neste período') . '</div>') . '</div>');
}

function route_aluno__horarios(): void {
    $s = my_student(); $term = active_term();
    $items = [];
    foreach ($term ? rows("SELECT sc.*, se.code, se.room, su.name FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN subjects su ON su.id = se.subject_id JOIN enrollments e ON e.section_id = se.id WHERE e.student_id = ? AND se.term_id = ? AND e.status = 'CURSANDO'", [$s['id'], $term['id']]) : [] as $x)
        $items[] = ['weekday' => $x['weekday'], 'start_time' => $x['start_time'], 'end_time' => $x['end_time'], 'title' => $x['name'], 'sub' => $x['room'] ?? ''];
    layout('Horários', page_head('Meus horários', 'Período ' . h($term['name'] ?? '—') . '. Disciplinas a distância não aparecem na grade.') . '<div class="card"><div class="card-body" style="padding-top:16px">' . week_grid($items) . '</div></div>');
}

function route_aluno__financeiro(): void {
    $s = my_student();
    layout('Financeiro', page_head('Financeiro', 'Suas mensalidades. Para segunda via ou negociação, procure o setor financeiro.') . student_tab_financeiro($s));
}
