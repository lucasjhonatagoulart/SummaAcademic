<?php
// Consultas usadas por várias telas (aluno, turma, histórico, horários).

function terms_all(): array { return rows('SELECT * FROM terms WHERE institution_id = ? ORDER BY starts_on DESC', [inst_id()]); }
function term_id_or_active(): int { return gi('periodo') ?: (int) (active_term()['id'] ?? 0); }

function student_full(int $id): ?array {
    return row('SELECT s.*, u.name, u.email, u.phone, u.avatar_path, u.status AS user_status, u.id AS uid, c.name AS course_name, c.code AS course_code, c.periods AS course_periods, g.code AS group_code, g.period_number
        FROM students s JOIN users u ON u.id = s.user_id JOIN courses c ON c.id = s.course_id LEFT JOIN class_groups g ON g.id = s.group_id
        WHERE s.id = ? AND u.institution_id = ?', [$id, inst_id()]);
}
function professor_full(int $id): ?array {
    return row('SELECT p.*, u.name, u.email, u.phone, u.avatar_path, u.status AS user_status FROM professors p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?))', array_merge([$id], inst_params()));
}

function section_full(int $id): ?array {
    $s = row('SELECT se.*, su.name AS subject_name, su.code AS subject_code, su.workload, su.modality, su.tracks_attendance, t.name AS term_name, t.closed AS term_closed, t.grades_deadline
        FROM sections se JOIN subjects su ON su.id = se.subject_id JOIN terms t ON t.id = se.term_id WHERE se.id = ? AND se.institution_id = ?', [$id, inst_id()]);
    if (!$s) return null;
    $s['schedules'] = rows('SELECT * FROM schedules WHERE section_id = ? ORDER BY weekday, start_time', [$id]);
    $s['groups'] = rows('SELECT g.* FROM section_groups sg JOIN class_groups g ON g.id = sg.group_id WHERE sg.section_id = ? ORDER BY g.code', [$id]);
    $s['profs'] = rows('SELECT p.id, u.name FROM section_professors sp JOIN professors p ON p.id = sp.professor_id JOIN users u ON u.id = p.user_id WHERE sp.section_id = ?', [$id]);
    return $s;
}
function schedules_text(array $sch): string { return $sch ? implode(', ', array_map('slot_label', $sch)) : 'Sem horário'; }

/** Média (avaliações publicadas, ou todas para o professor), frequência e situação de uma matrícula */
function enrollment_result(array $enr, array $section, bool $onlyPublished = true): array {
    $items = rows('SELECT a.id, a.name, a.weight, a.published, a.due_on, g.score FROM assessments a LEFT JOIN grades g ON g.assessment_id = a.id AND g.enrollment_id = ? WHERE a.section_id = ? ORDER BY a.due_on, a.id', [$enr['id'], $section['id']]);
    $use = array_filter($items, fn($i) => !$onlyPublished || $i['published']);
    $avg = weighted_average(array_map(fn($i) => ['score' => $i['score'] === null ? null : (float) $i['score'], 'weight' => $i['weight']], $use));
    $tracks = (bool) $section['tracks_attendance'];
    $total = (int) val('SELECT COUNT(*) FROM lessons WHERE section_id = ?', [$section['id']]);
    $present = (int) val('SELECT COUNT(*) FROM attendance a JOIN lessons l ON l.id = a.lesson_id WHERE l.section_id = ? AND a.enrollment_id = ? AND a.present = 1', [$section['id'], $enr['id']]);
    $recorded = (int) val('SELECT COUNT(*) FROM attendance a JOIN lessons l ON l.id = a.lesson_id WHERE l.section_id = ? AND a.enrollment_id = ?', [$section['id'], $enr['id']]);
    $att = $tracks ? attendance_pct($present, $recorded) : null;
    $status = $enr['status'];
    if ($status !== 'CURSANDO') { $avg = $enr['final_grade'] !== null ? (float) $enr['final_grade'] : $avg; $att = $enr['attendance_pct'] !== null ? (float) $enr['attendance_pct'] : $att; }
    return ['items' => $items, 'avg' => $avg, 'att' => $att, 'lessons' => $total, 'present' => $present, 'absences' => $recorded - $present,
        'status' => $status, 'risk' => $status === 'CURSANDO' && at_risk($avg, $att, (float) $section['min_grade'], (float) $section['min_attendance'], $tracks)];
}

function section_roster(int $sectionId): array {
    return rows('SELECT e.*, s.ra, u.name, u.avatar_path, s.id AS student_id FROM enrollments e JOIN students s ON s.id = e.student_id JOIN users u ON u.id = s.user_id WHERE e.section_id = ? ORDER BY u.name', [$sectionId]);
}

/** Disciplinas do aluno em um período (com seção, horários e professor) */
function student_term_enrollments(int $studentId, int $termId): array {
    $list = rows('SELECT e.*, se.id AS section_id FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE e.student_id = ? AND se.term_id = ? ORDER BY se.code', [$studentId, $termId]);
    foreach ($list as &$e) $e['section'] = section_full((int) $e['section_id']);
    return $list;
}

/** Andamento no curso: carga horária obrigatória cumprida e situação de cada disciplina da matriz */
function course_progress(array $student): array {
    $matrix = rows('SELECT cs.*, su.code, su.name, su.workload, su.modality, su.tracks_attendance FROM course_subjects cs JOIN subjects su ON su.id = cs.subject_id WHERE cs.course_id = ? ORDER BY cs.period, su.name', [$student['course_id']]);
    $hist = rows('SELECT e.status, e.final_grade, se.subject_id, t.starts_on FROM enrollments e JOIN sections se ON se.id = e.section_id JOIN terms t ON t.id = se.term_id WHERE e.student_id = ? ORDER BY t.starts_on', [$student['id']]);
    $by = [];
    foreach ($hist as $hrow) {
        $cur = $by[$hrow['subject_id']] ?? null;
        if (!$cur || $cur['status'] !== 'APROVADO') $by[$hrow['subject_id']] = $hrow;
    }
    $req = 0; $done = 0; $doing = 0;
    foreach ($matrix as &$m) {
        $st = $by[$m['subject_id']]['status'] ?? null;
        $m['state'] = $st === 'APROVADO' || $st === 'APROVEITADO' ? 'done' : ($st === 'CURSANDO' ? 'doing' : ($st ? 'failed' : 'todo'));
        $m['final_grade'] = $by[$m['subject_id']]['final_grade'] ?? null;
        if ($m['required']) { $req += $m['workload']; if ($m['state'] === 'done') $done += $m['workload']; if ($m['state'] === 'doing') $doing += $m['workload']; }
    }
    return ['matrix' => $matrix, 'required' => $req, 'done' => $done, 'doing' => $doing, 'pct' => $req ? round($done * 100 / $req, 1) : 0];
}

/** Retrato do histórico escolar (o que é assinado e guardado ao emitir) */
function transcript_snapshot(int $studentId): ?array {
    $s = student_full($studentId);
    if (!$s) return null;
    $rowsT = rows('SELECT e.status, e.final_grade, e.attendance_pct, su.code, su.name, su.workload, t.name AS term, t.starts_on
        FROM enrollments e JOIN sections se ON se.id = e.section_id JOIN subjects su ON su.id = se.subject_id JOIN terms t ON t.id = se.term_id
        WHERE e.student_id = ? ORDER BY t.starts_on, su.name', [$studentId]);
    $terms = [];
    foreach ($rowsT as $r) $terms[$r['term']][] = ['code' => $r['code'], 'name' => $r['name'], 'workload' => (int) $r['workload'], 'status' => $r['status'],
        'final_grade' => $r['final_grade'] === null ? null : (float) $r['final_grade'], 'attendance' => $r['attendance_pct'] === null ? null : (float) $r['attendance_pct']];
    $all = array_merge(...array_values($terms ?: [[]]));
    $prog = course_progress($s);
    $i = institution();
    return [
        'institution' => ['name' => $i['name'], 'cnpj' => $i['cnpj'] ?? '', 'emec' => $i['emec_code'] ?? '', 'address' => trim(($i['address'] ?? '') . ' · ' . ($i['city'] ?? '') . '/' . ($i['state'] ?? ''), ' ·/'), 'short' => $i['short_name'] ?? '', 'secretary' => $i['secretary_name'] ?? '', 'director' => $i['director_name'] ?? '', 'teaching_director' => $i['teaching_director_name'] ?? ''],
        'student' => ['name' => $s['name'], 'ra' => $s['ra'], 'cpf' => $s['cpf'], 'birth' => $s['birth_date'], 'entry' => $s['entry_term'], 'status' => $s['status']],
        'course' => ['name' => $s['course_name'], 'code' => $s['course_code']],
        'terms' => $terms,
        'summary' => ['cr' => weighted_cr($all), 'approved_hours' => array_sum(array_map(fn($x) => in_array($x['status'], ['APROVADO', 'APROVEITADO'], true) ? $x['workload'] : 0, $all)), 'required_hours' => $prog['required'], 'progress' => $prog['pct']],
        'generated_at' => now(),
    ];
}

/** Conflitos de um horário novo com professor, turmas e sala no mesmo período */
function schedule_conflicts(array $section, array $slot): array {
    $others = rows('SELECT sc.*, se.id AS sid, se.code, se.room FROM schedules sc JOIN sections se ON se.id = sc.section_id WHERE se.term_id = ? AND se.id <> ?', [$section['term_id'], $section['id']]);
    $profIds = array_column($section['profs'], 'id'); $groupIds = array_column($section['groups'], 'id');
    $out = [];
    foreach ($others as $o) {
        if (!slots_overlap($slot, $o)) continue;
        if ($section['room'] && $o['room'] && mb_strtolower($section['room']) === mb_strtolower($o['room'])) $out[] = "Sala {$o['room']} ocupada por {$o['code']} (" . slot_label($o) . ').';
        if ($profIds && val('SELECT COUNT(*) FROM section_professors WHERE section_id = ? AND professor_id IN (' . in_list($profIds) . ')', array_merge([$o['sid']], $profIds))) $out[] = "Professor já dá aula em {$o['code']} (" . slot_label($o) . ').';
        if ($groupIds && val('SELECT COUNT(*) FROM section_groups WHERE section_id = ? AND group_id IN (' . in_list($groupIds) . ')', array_merge([$o['sid']], $groupIds))) $out[] = "A turma já tem aula de {$o['code']} (" . slot_label($o) . ').';
    }
    return array_values(array_unique($out));
}

/** Grade semanal: [weekday][start] => lista de itens */
function week_grid(array $items): string {
    $times = [];
    foreach ($items as $it) $times[$it['start_time'] . '–' . $it['end_time']] = $it['start_time'];
    asort($times);
    if (!$times) return empty_state('calendar-days', 'Nenhum horário', 'Ainda não há aulas com horário definido.');
    $o = '<div class="table-wrap"><div class="week"><div></div>';
    foreach (WEEKDAYS as $d => $n) $o .= '<div class="wh">' . $n . '</div>';
    foreach ($times as $label => $start) {
        $o .= '<div class="wt">' . h($label) . '</div>';
        foreach (WEEKDAYS as $d => $n) {
            $cell = array_filter($items, fn($it) => (int) $it['weekday'] === $d && $it['start_time'] . '–' . $it['end_time'] === $label);
            $o .= '<div class="wc">' . implode('', array_map(fn($it) => '<div class="slot"><strong>' . h($it['title']) . '</strong>' . h($it['sub'] ?? '') . '</div>', $cell)) . '</div>';
        }
    }
    return $o . '</div></div>';
}

/** Matricula o aluno nas ofertas regulares da turma dele no período */
function enroll_in_group_sections(int $studentId, int $groupId): int {
    $n = 0;
    foreach (col("SELECT sg.section_id FROM section_groups sg JOIN sections se ON se.id = sg.section_id WHERE sg.group_id = ? AND se.offer_type = 'REGULAR'", [$groupId]) as $sid) {
        if (!row('SELECT id FROM enrollments WHERE student_id = ? AND section_id = ?', [$studentId, $sid])) { insert('enrollments', ['student_id' => $studentId, 'section_id' => $sid, 'status' => 'CURSANDO', 'created_at' => now()]); $n++; }
    }
    return $n;
}
