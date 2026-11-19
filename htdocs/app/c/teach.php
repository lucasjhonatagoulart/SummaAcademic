<?php
// Portal do professor: turmas, diário de classe, chamada, avaliações e notas.

function my_professor(): array {
    $u = require_perm('classroom:own');
    if (!$u['professor']) abort(403, 'Seu usuário não está ligado a um cadastro de professor.');
    return $u['professor'];
}
/** Seção do professor (ou 403) */
function my_section(int $id): array {
    $p = my_professor();
    $s = section_full($id);
    if (!$s || !in_array((int) $p['id'], array_map('intval', array_column($s['profs'], 'id')), true)) abort(403, 'Esta turma não é sua.');
    return $s;
}
/** Pode lançar? Período aberto e dentro do prazo, ou com permissão especial */
function can_record(array $s): bool {
    if ($s['term_closed']) return false;
    return !$s['grades_deadline'] || today() <= $s['grades_deadline'] || can('records:edit-after-deadline');
}

function route_prof__turmas(): void {
    $p = my_professor();
    $term = active_term();
    $secs = $term ? array_map(fn($r) => section_full((int) $r['section_id']), rows('SELECT sp.section_id FROM section_professors sp JOIN sections se ON se.id = sp.section_id WHERE sp.professor_id = ? AND se.term_id = ? ORDER BY se.code', [$p['id'], $term['id']])) : [];
    $cards = '';
    foreach ($secs as $s) {
        $n = (int) val('SELECT COUNT(*) FROM enrollments WHERE section_id = ? AND status <> \'TRANCADO\'', [$s['id']]);
        $l = (int) val('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$s['id']]);
        $a = (int) val('SELECT COUNT(*) FROM assessments WHERE section_id = ?', [$s['id']]);
        $cards .= '<a class="card course-card" href="' . h(url('prof.turma', ['id' => $s['id']])) . '"><div class="row" style="justify-content:space-between"><span class="badge b-info">' . h($s['code']) . '</span>' . ($s['modality'] === 'D' ? badge('EaD', 'b-warn') : '') . '</div><h3>' . h($s['subject_name']) . '</h3>'
            . '<p class="small muted">' . h(implode(', ', array_column($s['groups'], 'code')) ?: 'Eletiva') . ' · ' . h(schedules_text($s['schedules'])) . ($s['room'] ? ' · ' . h($s['room']) : '') . '</p>'
            . '<div class="row" style="gap:16px"><span class="small"><strong>' . $n . '</strong> alunos</span><span class="small"><strong>' . $l . '</strong> aulas registradas</span><span class="small"><strong>' . $a . '</strong> avaliações</span></div></a>';
    }
    layout('Minhas turmas', page_head('Minhas turmas', 'Período ' . h($term['name'] ?? '—') . ($term && $term['grades_deadline'] ? ' · prazo para notas e frequência: ' . fmt_date($term['grades_deadline']) : '') . '.')
        . '<div class="course-cards">' . ($cards ?: empty_state('school', 'Nenhuma turma no período', 'A secretaria vincula os professores às ofertas.')) . '</div>');
}

function route_prof__horarios(): void {
    $p = my_professor(); $term = active_term();
    $items = [];
    foreach ($term ? rows('SELECT sc.*, se.code, se.room, su.name FROM schedules sc JOIN sections se ON se.id = sc.section_id JOIN subjects su ON su.id = se.subject_id JOIN section_professors sp ON sp.section_id = se.id WHERE sp.professor_id = ? AND se.term_id = ?', [$p['id'], $term['id']]) : [] as $x)
        $items[] = ['weekday' => $x['weekday'], 'start_time' => $x['start_time'], 'end_time' => $x['end_time'], 'title' => $x['name'], 'sub' => $x['code'] . ($x['room'] ? ' · ' . $x['room'] : '')];
    layout('Meus horários', page_head('Meus horários', 'Período ' . h($term['name'] ?? '—') . '.') . '<div class="card"><div class="card-body" style="padding-top:16px">' . week_grid($items) . '</div></div>');
}

function route_prof__turma(): void {
    $s = my_section(gi('id'));
    $open = can_record($s);
    $tracks = (bool) $s['tracks_attendance'];
    $roster = array_values(array_filter(section_roster((int) $s['id']), fn($r) => $r['status'] !== 'TRANCADO'));
    if (is_post()) {
        if (!$open) { flash('bad', $s['term_closed'] ? 'O período está encerrado.' : 'O prazo para lançamentos terminou. Peça à secretaria.'); go('prof.turma', ['id' => $s['id'], 'aba' => g('aba')]); }
        $act = p('acao');
        if ($act === 'aula') {
            $date = p('lesson_date'); $topic = p('topic'); $lid = pi_('lesson_id');
            if (!valid_date($date) || mb_strlen($topic) < 3) flash('bad', 'Informe a data e o conteúdo da aula.');
            elseif ($date > today()) flash('bad', 'Não dá para registrar aula em data futura.');
            else {
                tx(function () use ($s, $date, $topic, $lid, $roster, $tracks) {
                    $own = $lid ? row('SELECT * FROM lessons WHERE id = ? AND section_id = ?', [$lid, $s['id']]) : null;
                    if ($own) update('lessons', ['lesson_date' => $date, 'topic' => mb_substr($topic, 0, 255), 'hours' => max(1, min(8, pi_('hours')))], 'id = ?', [$own['id']]);
                    else $lid = insert('lessons', ['section_id' => $s['id'], 'lesson_date' => $date, 'topic' => mb_substr($topic, 0, 255), 'hours' => max(1, min(8, pi_('hours') ?: 2)), 'created_by' => current_user()['id']]);
                    if ($tracks) {
                        q('DELETE FROM attendance WHERE lesson_id = ?', [$lid]);
                        $present = array_map('intval', (array) ($_POST['present'] ?? []));
                        foreach ($roster as $r) insert('attendance', ['lesson_id' => $lid, 'enrollment_id' => $r['id'], 'present' => in_array((int) $r['id'], $present, true) ? 1 : 0]);
                    }
                });
                audit('lesson.save', "Aula registrada em {$s['code']} (" . fmt_date($date) . ')', 'section', (int) $s['id']);
                flash('ok', $tracks ? 'Aula e chamada salvas.' : 'Aula registrada.');
            }
        } elseif ($act === 'excluir_aula') {
            q('DELETE FROM lessons WHERE id = ? AND section_id = ?', [pi_('lesson_id'), $s['id']]); audit('lesson.delete', "Aula excluída em {$s['code']}", 'section', (int) $s['id']); flash('ok', 'Aula excluída.');
        } elseif ($act === 'avaliacao') {
            $name = p('name'); $w = (float) str_replace(',', '.', p('weight'));
            if (mb_strlen($name) < 2 || $w <= 0 || $w > 10) flash('bad', 'Informe o nome e um peso entre 0,1 e 10.');
            else { insert('assessments', ['section_id' => $s['id'], 'name' => mb_substr($name, 0, 80), 'weight' => $w, 'due_on' => valid_date(p('due_on')) ? p('due_on') : null, 'published' => 0]); audit('grade.assessment', "Avaliação criada em {$s['code']}: $name", 'section', (int) $s['id']); flash('ok', 'Avaliação criada. Lance as notas e publique quando estiver pronto.'); }
        } elseif ($act === 'notas') {
            $a = row('SELECT * FROM assessments WHERE id = ? AND section_id = ?', [pi_('assessment_id'), $s['id']]);
            if ($a) {
                $bad = 0;
                tx(function () use ($a, $roster, &$bad) {
                    foreach ($roster as $r) {
                        $raw = trim((string) ($_POST['score'][$r['id']] ?? ''));
                        q('DELETE FROM grades WHERE assessment_id = ? AND enrollment_id = ?', [$a['id'], $r['id']]);
                        if ($raw === '') continue;
                        $v = (float) str_replace(',', '.', $raw);
                        if (!is_numeric(str_replace(',', '.', $raw)) || $v < 0 || $v > 10) { $bad++; continue; }
                        insert('grades', ['assessment_id' => $a['id'], 'enrollment_id' => $r['id'], 'score' => round($v, 1)]);
                    }
                    if (isset($_POST['publish'])) update('assessments', ['published' => 1], 'id = ?', [$a['id']]);
                });
                audit('grade.save', "Notas lançadas em {$s['code']}: {$a['name']}" . (isset($_POST['publish']) ? ' (publicadas)' : ''), 'section', (int) $s['id']);
                flash($bad ? 'warn' : 'ok', $bad ? "Notas salvas, mas $bad valor(es) fora de 0 a 10 foram ignorados." : (isset($_POST['publish']) ? 'Notas salvas e publicadas para os alunos.' : 'Notas salvas (ainda não publicadas).'));
            }
        } elseif ($act === 'publicar') {
            $a = row('SELECT * FROM assessments WHERE id = ? AND section_id = ?', [pi_('assessment_id'), $s['id']]);
            if ($a) { update('assessments', ['published' => $a['published'] ? 0 : 1], 'id = ?', [$a['id']]); flash('ok', $a['published'] ? 'Avaliação ocultada dos alunos.' : 'Notas publicadas para os alunos.'); }
        } elseif ($act === 'excluir_avaliacao') {
            q('DELETE FROM assessments WHERE id = ? AND section_id = ?', [pi_('assessment_id'), $s['id']]); flash('ok', 'Avaliação excluída.');
        }
        go('prof.turma', ['id' => $s['id'], 'aba' => g('aba'), 'av' => g('av')]);
    }
    $tab = g('aba', 'resumo');
    $base = url('prof.turma', ['id' => $s['id']]);
    $tabsList = [['resumo', 'Resumo', 'layout-dashboard', $base], ['aulas', $tracks ? 'Diário e chamada' : 'Diário de classe', 'notebook-pen', $base . '&aba=aulas'], ['notas', 'Avaliações e notas', 'clipboard-check', $base . '&aba=notas']];
    $head = crumb('prof.turmas', 'Minhas turmas') . page_head(h($s['subject_name']), h($s['code']) . ' · ' . h(implode(', ', array_column($s['groups'], 'code')) ?: 'Eletiva') . ' · ' . h(schedules_text($s['schedules'])) . ' · ' . plural(count($roster), 'aluno', 'alunos'))
        . (!$open ? alert('warn', $s['term_closed'] ? 'Período encerrado: somente consulta.' : 'O prazo para lançamentos terminou em ' . fmt_date($s['grades_deadline']) . '. Alterações só pela secretaria.') : '')
        . (!$tracks ? alert('info', 'Esta disciplina não registra frequência: os alunos veem só as notas.') : '') . tabs($tabsList, $tab);

    if ($tab === 'aulas') {
        $lessons = rows('SELECT l.*, (SELECT COUNT(*) FROM attendance a WHERE a.lesson_id = l.id AND a.present = 0) AS absents FROM lessons l WHERE l.section_id = ? ORDER BY l.lesson_date DESC, l.id DESC', [$s['id']]);
        $edit = gi('aula') ? row('SELECT * FROM lessons WHERE id = ? AND section_id = ?', [gi('aula'), $s['id']]) : null;
        $presentIds = $edit ? array_map('intval', col('SELECT enrollment_id FROM attendance WHERE lesson_id = ? AND present = 1', [$edit['id']])) : array_map(fn($r) => (int) $r['id'], $roster);
        $chamada = '';
        if ($tracks) foreach ($roster as $r) $chamada .= '<label class="check-field" style="padding:8px 10px"><input type="checkbox" name="present[]" value="' . $r['id'] . '"' . (in_array((int) $r['id'], $presentIds, true) ? ' checked' : '') . '><span><strong>' . h($r['name']) . '</strong><span class="muted small">RA ' . h($r['ra']) . '</span></span></label>';
        $form = $open ? '<div class="card"><div class="card-head"><div><h3>' . ($edit ? 'Editar aula de ' . fmt_date($edit['lesson_date']) : 'Registrar aula') . '</h3><p>' . ($tracks ? 'Desmarque quem faltou.' : 'Conteúdo trabalhado.') . '</p></div></div><div class="card-body">'
            . form_open('prof.turma', ['id' => $s['id'], 'aba' => 'aulas'], 'class="stack"') . '<input type="hidden" name="acao" value="aula">' . ($edit ? '<input type="hidden" name="lesson_id" value="' . $edit['id'] . '">' : '')
            . '<div class="form-grid cols-2">' . input('lesson_date', 'Data', $edit['lesson_date'] ?? today(), 'date', 'required max="' . today() . '"') . input('hours', 'Horas-aula', $edit['hours'] ?? 2, 'number', 'min="1" max="8"') . input('topic', 'Conteúdo', $edit['topic'] ?? '', 'text', 'required maxlength="255"', '', 'span-2') . '</div>'
            . ($tracks ? '<div class="row" style="justify-content:space-between"><strong class="small">Chamada</strong><span class="row" style="gap:6px"><button type="button" class="btn btn-ghost btn-sm" data-att-all="1">Todos presentes</button><button type="button" class="btn btn-ghost btn-sm" data-att-all="0">Todos ausentes</button></span></div><div class="perm-checks">' . $chamada . '</div>' : '')
            . '<div class="row" style="justify-content:flex-end;gap:8px">' . ($edit ? '<a class="btn btn-secondary" href="' . h($base . '&aba=aulas') . '">Cancelar</a>' : '') . '<button class="btn btn-primary" type="submit">' . icon('save') . ' Salvar aula</button></div></form></div></div>' : '';
        $tr = '';
        foreach ($lessons as $l) $tr .= '<tr><td class="tnum">' . fmt_date($l['lesson_date']) . '</td><td>' . h($l['topic']) . '</td><td class="tnum">' . $l['hours'] . ' h</td>' . ($tracks ? '<td class="tnum">' . ($l['absents'] ? badge(plural((int) $l['absents'], 'falta', 'faltas'), 'b-warn') : '<span class="muted small">Sem faltas</span>') . '</td>' : '')
            . '<td class="r" style="white-space:nowrap">' . ($open ? '<a class="btn btn-sm btn-secondary" href="' . h($base . '&aba=aulas&aula=' . $l['id']) . '">' . icon('pencil') . '</a> ' . form_open('prof.turma', ['id' => $s['id'], 'aba' => 'aulas'], 'class="inline-form" data-confirm="Excluir esta aula e a chamada dela?"') . '<input type="hidden" name="acao" value="excluir_aula"><input type="hidden" name="lesson_id" value="' . $l['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">' . icon('trash-2') . '</button></form>' : '') . '</td></tr>';
        layout($s['subject_name'], $head . '<div class="grid g2" style="align-items:start">' . $form . '<div class="card"><div class="card-head"><div><h3>Aulas registradas</h3><p>' . plural(count($lessons), 'aula', 'aulas') . ' · ' . array_sum(array_column($lessons, 'hours')) . ' h de ' . $s['workload'] . ' h</p></div></div><div class="table-wrap"><table><thead><tr><th>Data</th><th>Conteúdo</th><th>CH</th>' . ($tracks ? '<th>Faltas</th>' : '') . '<th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('notebook-pen', 'Nenhuma aula registrada') . '</td></tr>') . '</tbody></table></div></div></div>');
        return;
    }
    if ($tab === 'notas') {
        $as = rows('SELECT * FROM assessments WHERE section_id = ? ORDER BY due_on, id', [$s['id']]);
        $cur = null; foreach ($as as $a) if ((int) $a['id'] === gi('av')) $cur = $a; $cur ??= $as[0] ?? null;
        $list = '';
        foreach ($as as $a) $list .= '<tr' . ($cur && $a['id'] === $cur['id'] ? ' style="background:var(--blue-soft)"' : '') . '><td><a class="cell-link" href="' . h($base . '&aba=notas&av=' . $a['id']) . '">' . h($a['name']) . '</a><p class="cell-sub">Peso ' . num((float) $a['weight']) . ($a['due_on'] ? ' · ' . fmt_date($a['due_on']) : '') . '</p></td><td>' . ($a['published'] ? badge('Publicada', 'b-ok') : badge('Rascunho')) . '</td>'
            . '<td class="r" style="white-space:nowrap">' . ($open ? form_open('prof.turma', ['id' => $s['id'], 'aba' => 'notas', 'av' => $a['id']], 'class="inline-form"') . '<input type="hidden" name="acao" value="publicar"><input type="hidden" name="assessment_id" value="' . $a['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">' . ($a['published'] ? 'Ocultar' : 'Publicar') . '</button></form>'
            . form_open('prof.turma', ['id' => $s['id'], 'aba' => 'notas'], 'class="inline-form" data-confirm="Excluir a avaliação e as notas dela?"') . '<input type="hidden" name="acao" value="excluir_avaliacao"><input type="hidden" name="assessment_id" value="' . $a['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">' . icon('trash-2') . '</button></form>' : '') . '</td></tr>';
        $grid = '';
        if ($cur) {
            $sc = array_column(rows('SELECT enrollment_id, score FROM grades WHERE assessment_id = ?', [$cur['id']]), 'score', 'enrollment_id');
            $tr = '';
            foreach ($roster as $r) $tr .= '<tr><td>' . h($r['name']) . '<p class="cell-sub">RA ' . h($r['ra']) . '</p></td><td class="r"><input class="input grade-input" name="score[' . $r['id'] . ']" inputmode="decimal" value="' . (isset($sc[$r['id']]) ? num((float) $sc[$r['id']], 1) : '') . '"' . ($open ? '' : ' disabled') . ' aria-label="Nota de ' . h($r['name']) . '"></td></tr>';
            $grid = '<div class="card"><div class="card-head"><div><h3>' . h($cur['name']) . '</h3><p>Notas de 0 a 10. Em branco: sem nota. ' . ($cur['published'] ? 'Já publicada para os alunos.' : 'Os alunos só veem depois de publicar.') . '</p></div></div>'
                . form_open('prof.turma', ['id' => $s['id'], 'aba' => 'notas', 'av' => $cur['id']]) . '<input type="hidden" name="acao" value="notas"><input type="hidden" name="assessment_id" value="' . $cur['id'] . '"><div class="table-wrap"><table><thead><tr><th>Aluno</th><th class="r">Nota</th></tr></thead><tbody>' . $tr . '</tbody></table></div>'
                . ($open ? '<div class="row" style="justify-content:flex-end;gap:8px;padding:12px 20px">' . ($cur['published'] ? '' : '<label class="row small" style="gap:6px"><input type="checkbox" name="publish" value="1"> Publicar ao salvar</label>') . '<button class="btn btn-primary" type="submit">' . icon('save') . ' Salvar notas</button></div>' : '') . '</form></div>';
        }
        $new = $open ? '<div class="card"><div class="card-head"><div><h3>Nova avaliação</h3><p>A média é ponderada pelos pesos.</p></div></div><div class="card-body">' . form_open('prof.turma', ['id' => $s['id'], 'aba' => 'notas'], 'class="form-grid cols-2"') . '<input type="hidden" name="acao" value="avaliacao">'
            . input('name', 'Nome', '', 'text', 'required maxlength="80" placeholder="Ex.: Prova 2"', '', 'span-2') . input('weight', 'Peso', '1', 'text', 'inputmode="decimal"') . input('due_on', 'Data', '', 'date') . '<div class="span-2"><button class="btn btn-secondary" type="submit">' . icon('plus') . ' Criar avaliação</button></div></form></div></div>' : '';
        layout($s['subject_name'], $head . '<div class="grid g2" style="align-items:start"><div class="stack"><div class="card"><div class="card-head"><div><h3>Avaliações</h3></div></div><div class="table-wrap"><table><tbody>' . ($list ?: '<tr><td>' . empty_state('clipboard-check', 'Nenhuma avaliação') . '</td></tr>') . '</tbody></table></div></div>' . $new . '</div>' . ($grid ?: '<div></div>') . '</div>');
        return;
    }
    // Resumo: média (todas as avaliações com nota), frequência e situação de cada aluno
    $tr = ''; $risk = 0; $avgs = [];
    foreach ($roster as $r) {
        $res = enrollment_result($r, $s, false);
        if ($res['risk']) $risk++; if ($res['avg'] !== null) $avgs[] = $res['avg'];
        $tr .= '<tr><td>' . h($r['name']) . '<p class="cell-sub">RA ' . h($r['ra']) . '</p></td><td class="r tnum">' . num($res['avg']) . '</td>' . ($tracks ? '<td>' . pct_bar($res['att'], (float) $s['min_attendance']) . '</td><td class="tnum">' . $res['absences'] . '</td>' : '') . '<td>' . ($res['risk'] ? badge('Em risco', 'b-warn') : badge(STATUS_LABEL[$res['status']], STATUS_TONE[$res['status']])) . '</td></tr>';
    }
    $lessons = (int) val('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$s['id']]);
    layout($s['subject_name'], $head . '<div class="grid g4" style="margin-bottom:16px">' . stat_card('Alunos', (string) count($roster), 'users') . stat_card('Média da turma', num($avgs ? array_sum($avgs) / count($avgs) : null), 'chart-line') . stat_card('Em risco', (string) $risk, 'triangle-alert', $risk ? 'warn' : '') . stat_card('Aulas registradas', (string) $lessons, 'notebook-pen') . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Alunos</h3><p>Média parcial com todas as avaliações lançadas (inclusive as não publicadas). Mínimo: média ' . num((float) $s['min_grade']) . ($tracks ? ' e ' . $s['min_attendance'] . '% de frequência' : '') . '.</p></div></div><div class="table-wrap"><table><thead><tr><th>Aluno</th><th class="r">Média</th>' . ($tracks ? '<th>Frequência</th><th>Faltas</th>' : '') . '<th>Situação</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('users', 'Nenhum aluno') . '</td></tr>') . '</tbody></table></div></div>');
}
