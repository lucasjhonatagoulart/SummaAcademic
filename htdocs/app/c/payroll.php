<?php
// Financeiro › Professores: valor da aula, balanço de aulas no período, rateio por turma e pagamentos.
// O valor da aula é visível somente para quem tem a permissão Financeiro › Professores (payroll:manage).
// Disciplina compartilhada = uma oferta com várias turmas: o professor recebe o valor da aula uma vez
// e esse valor é rateado (dividido) entre as turmas, para mostrar o custo de cada turma.

const RATE_UNIT = ['AULA' => 'Por aula', 'HORA' => 'Por hora-aula'];
const PAY_METHODS = ['PIX' => 'PIX', 'Transferência' => 'Transferência', 'Depósito' => 'Depósito', 'Folha de pagamento' => 'Folha de pagamento', 'Dinheiro' => 'Dinheiro'];

/** Período curto: "01/11 a 30/11/2026" (ano só no fim quando é o mesmo) */
function period_short(string $a, string $b): string {
    return substr($a, 0, 4) === substr($b, 0, 4) ? date('d/m', strtotime($a)) . ' a ' . fmt_date($b) : fmt_date($a) . ' a ' . fmt_date($b);
}
function prof_rate(int $pid): ?array { return row('SELECT * FROM professor_rates WHERE professor_id = ? AND institution_id = ?', [$pid, inst_id()]) ?: null; }

/** Período escolhido (de/até); padrão: mês atual */
function payroll_period(): array {
    $from = g('de'); $to = g('ate');
    if (!valid_date($from)) $from = date('Y-m-01');
    if (!valid_date($to)) $to = date('Y-m-t', strtotime($from));
    if ($to < $from) [$from, $to] = [$to, $from];
    return [$from, $to];
}
function payroll_period_bar(string $route, array $params, string $from, string $to): string {
    $q = fn($a, $b) => url($route, $params + ['de' => $a, 'ate' => $b]);
    $m0 = date('Y-m-01'); $m1 = date('Y-m-01', strtotime('first day of last month'));
    $term = active_term();
    $chips = [['Este mês', $m0, date('Y-m-t')], ['Mês passado', $m1, date('Y-m-t', strtotime($m1))]];
    if ($term && !empty($term['starts_on']) && !empty($term['ends_on'])) $chips[] = ['Período ' . $term['name'], $term['starts_on'], $term['ends_on']];
    $c = '';
    foreach ($chips as [$l, $a, $b]) $c .= '<a class="chip' . ($a === $from && $b === $to ? ' on' : '') . '" href="' . h($q($a, $b)) . '">' . h($l) . '</a>';
    $hidden = '<input type="hidden" name="r" value="' . h($route) . '">';
    foreach ($params as $k => $v) $hidden .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return '<div class="card period-bar no-print"><form method="get" action="index.php" class="period-form">' . $hidden
        . '<div class="field"><label for="f-de">De</label><input class="input" id="f-de" type="date" name="de" value="' . h($from) . '" required></div>'
        . '<div class="field"><label for="f-ate">Até</label><input class="input" id="f-ate" type="date" name="ate" value="' . h($to) . '" required></div>'
        . '<button class="btn btn-primary" type="submit">' . icon('calendar-search') . ' Ver período</button></form><div class="chips" role="group" aria-label="Atalhos de período">' . $c . '</div></div>';
}

/**
 * Aulas do professor no período (instituição em uso), com turmas da oferta, valor e rateio.
 * Se a oferta tem mais de um professor, a aula conta para quem a registrou; aulas lançadas
 * pela secretaria (ou sem autor) contam para todos os professores da oferta.
 */
function prof_lessons(array $prof, string $from, string $to, ?array $rate): array {
    $list = rows('SELECT l.id, l.lesson_date, l.hours, l.topic, l.created_by, se.id AS section_id, se.code, su.name AS subject
        FROM lessons l JOIN sections se ON se.id = l.section_id JOIN subjects su ON su.id = se.subject_id JOIN section_professors sp ON sp.section_id = se.id AND sp.professor_id = ?
        WHERE se.institution_id = ? AND l.lesson_date >= ? AND l.lesson_date <= ? ORDER BY l.lesson_date, se.code', [$prof['id'], inst_id(), $from, $to]);
    if (!$list) return [];
    $secIds = array_values(array_unique(array_column($list, 'section_id')));
    $groups = []; $teachers = [];
    foreach (rows('SELECT sg.section_id, g.id, g.code FROM section_groups sg JOIN class_groups g ON g.id = sg.group_id WHERE sg.section_id IN (' . in_list($secIds) . ') ORDER BY g.code', $secIds) as $g) $groups[$g['section_id']][] = ['id' => (int) $g['id'], 'code' => $g['code']];
    foreach (rows('SELECT sp.section_id, p.user_id FROM section_professors sp JOIN professors p ON p.id = sp.professor_id WHERE sp.section_id IN (' . in_list($secIds) . ')', $secIds) as $t) $teachers[$t['section_id']][] = (int) $t['user_id'];
    $paid = [];
    $lids = array_column($list, 'id');
    foreach (rows('SELECT pl.lesson_id, pl.amount_cents, pp.id AS payment_id, pp.paid_on FROM professor_payment_lessons pl JOIN professor_payments pp ON pp.id = pl.payment_id WHERE pp.professor_id = ? AND pl.lesson_id IN (' . in_list($lids) . ')', array_merge([$prof['id']], $lids)) as $p) $paid[$p['lesson_id']] = $p;
    $out = [];
    foreach ($list as $l) {
        $by = (int) $l['created_by'];
        if ($by && $by !== (int) $prof['user_id'] && in_array($by, $teachers[$l['section_id']] ?? [], true)) continue; // aula de outro professor da mesma oferta
        $gs = $groups[$l['section_id']] ?? [['id' => 0, 'code' => 'Oferta ' . $l['code']]]; // optativa sem turma: a própria oferta
        $p = $paid[$l['id']] ?? null;
        $value = $p ? (int) $p['amount_cents'] : ($rate ? lesson_value((int) $rate['rate_cents'], $rate['unit'], (int) $l['hours']) : null);
        $parts = $value === null ? [] : split_cents($value, count($gs));
        $out[] = $l + ['groups' => $gs, 'value' => $value, 'parts' => $parts, 'paid' => $p];
    }
    return $out;
}

/** Quanto já foi pago pelas aulas da lista: valor das aulas pagas + acréscimos/descontos dos pagamentos que estão inteiros no período */
function paid_total(array $ls): int {
    $v = 0; $per = [];
    foreach ($ls as $l) if ($l['paid']) { $v += (int) $l['value']; $per[$l['paid']['payment_id']] = ($per[$l['paid']['payment_id']] ?? 0) + 1; }
    if ($per) foreach (rows('SELECT id, lessons, adjust_cents FROM professor_payments WHERE id IN (' . in_list(array_keys($per)) . ')', array_keys($per)) as $x)
        if ((int) $x['lessons'] === $per[$x['id']]) $v += (int) $x['adjust_cents'];
    return $v;
}
function payroll_professors(): array {
    return rows('SELECT p.id, p.user_id, p.registration, p.title, p.department, u.name, u.email, u.status FROM professors p JOIN users u ON u.id = p.user_id WHERE ' . user_in_inst() . ' ORDER BY u.name', inst_params());
}

function route_fin__professores(): void {
    require_perm('payroll:manage');
    [$from, $to] = payroll_period();
    $tr = ''; $tot = ['n' => 0, 'h' => 0, 'v' => 0, 'paid' => 0, 'open' => 0]; $noRate = 0;
    foreach (payroll_professors() as $p) {
        $rate = prof_rate((int) $p['id']);
        $ls = prof_lessons($p, $from, $to, $rate);
        $n = count($ls); $hrs = array_sum(array_column($ls, 'hours'));
        $v = array_sum(array_map(fn($l) => (int) $l['value'], $ls));
        $pd = paid_total($ls);
        $openN = count(array_filter($ls, fn($l) => !$l['paid']));
        if (!$rate) $noRate++;
        $tot['n'] += $n; $tot['h'] += $hrs; $tot['v'] += $v; $tot['paid'] += $pd; $tot['open'] += array_sum(array_map(fn($l) => $l['paid'] ? 0 : (int) $l['value'], $ls));
        $state = !$n ? badge('Sem aulas', 'b-neutral') : (!$rate && $openN ? badge('Definir valor', 'b-warn') : ($openN ? ($pd ? badge('Parcial', 'b-info') : badge('A pagar', 'b-info')) : badge('Pago', 'b-ok')));
        $tr .= '<tr class="clickable" data-href="' . h(url('fin.professor', ['id' => $p['id'], 'de' => $from, 'ate' => $to])) . '"><td><p class="cell-title">' . h($p['name']) . '</p><p class="cell-sub">Matrícula ' . h($p['registration']) . ($p['title'] ? ' · ' . h($p['title']) : '') . '</p></td>'
            . '<td class="tnum">' . ($rate ? money((int) $rate['rate_cents']) . '<p class="cell-sub">' . RATE_UNIT[$rate['unit']] . '</p>' : '<span class="muted">Não definido</span>') . '</td>'
            . '<td class="r tnum">' . $n . '</td><td class="r tnum">' . $hrs . 'h</td><td class="r tnum"><strong>' . ($rate || $v ? money($v) : '—') . '</strong></td><td class="r tnum">' . ($pd ? money($pd) : '—') . '</td><td>' . $state . '</td></tr>';
    }
    layout('Professores', page_head('Professores', 'Valor da aula, aulas dadas no período e pagamentos. Visível só para quem tem a permissão de Professores no Financeiro.', '<button class="btn btn-secondary" type="button" data-print>' . icon('printer') . ' Imprimir</button>')
        . payroll_period_bar('fin.professores', [], $from, $to)
        . ($noRate ? alert('warn', plural($noRate, 'professor está', 'professores estão') . ' sem valor da aula definido. Abra o professor para cadastrar.') : '')
        . '<div class="grid g4 pay-stats" style="margin:16px 0">' . stat_card('Aulas no período', (string) $tot['n'], 'calendar-check', '', $tot['h'] . ' horas-aula') . stat_card('Total do período', money($tot['v']), 'wallet') . stat_card('Já pago', money($tot['paid']), 'circle-check') . stat_card('A pagar', money($tot['open']), 'hourglass', $tot['open'] ? 'warn' : '') . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Professores · ' . fmt_date($from) . ' a ' . fmt_date($to) . '</h3><p>Clique no professor para ver as aulas, o rateio por turma e registrar o pagamento.</p></div></div><div class="table-wrap"><table><thead><tr><th>Professor</th><th>Valor da aula</th><th class="r">Aulas</th><th class="r">Horas</th><th class="r">Valor no período</th><th class="r">Pago</th><th>Situação</th></tr></thead><tbody>'
        . ($tr ?: '<tr><td colspan="7">' . empty_state('users-round', 'Nenhum professor nesta instituição') . '</td></tr>') . '</tbody>'
        . ($tr ? '<tfoot><tr><td colspan="2"><strong>Total</strong></td><td class="r tnum"><strong>' . $tot['n'] . '</strong></td><td class="r tnum"><strong>' . $tot['h'] . 'h</strong></td><td class="r tnum"><strong>' . money($tot['v']) . '</strong></td><td class="r tnum"><strong>' . money($tot['paid']) . '</strong></td><td></td></tr></tfoot>' : '') . '</table></div></div>');
}

function payroll_prof_or_404(int $id): array {
    $p = row('SELECT p.id, p.user_id, p.registration, p.title, p.department, u.name, u.email FROM professors p JOIN users u ON u.id = p.user_id WHERE p.id = ? AND ' . user_in_inst(), array_merge([$id], inst_params()));
    if (!$p) abort(404);
    return $p;
}

function route_fin__professor(): void {
    $u = require_perm('payroll:manage');
    $p = payroll_prof_or_404(gi('id'));
    [$from, $to] = payroll_period();
    $back = ['id' => $p['id'], 'de' => $from, 'ate' => $to];
    $rate = prof_rate((int) $p['id']);
    if (is_post()) {
        $act = p('acao');
        if ($act === 'valor') {
            $cents = parse_money(p('rate')); $unit = isset(RATE_UNIT[p('unit')]) ? p('unit') : 'AULA';
            if (!$cents) flash('bad', 'Informe o valor da aula.');
            else {
                $d = ['rate_cents' => $cents, 'unit' => $unit, 'updated_by' => $u['id'], 'updated_at' => now()];
                if ($rate) update('professor_rates', $d, 'professor_id = ? AND institution_id = ?', [$p['id'], inst_id()]);
                else insert('professor_rates', $d + ['professor_id' => $p['id'], 'institution_id' => inst_id()]);
                audit('payroll.rate', "Valor da aula de {$p['name']}: " . ($rate ? money((int) $rate['rate_cents']) . ' → ' : '') . money($cents) . ' (' . mb_strtolower(RATE_UNIT[$unit]) . ')', 'professor', (int) $p['id']);
                flash('ok', 'Valor da aula salvo. As aulas já pagas mantêm o valor do pagamento.');
            }
        } elseif ($act === 'pagar') {
            if (!$rate) { flash('bad', 'Defina o valor da aula antes de registrar o pagamento.'); go('fin.professor', $back); }
            $open = array_values(array_filter(prof_lessons($p, $from, $to, $rate), fn($l) => !$l['paid']));
            $adj = p('adjust') === '' ? 0 : parse_money(ltrim(p('adjust'), '-+'));
            $neg = str_starts_with(trim(p('adjust')), '-') || p('adjust_kind') === 'desconto';
            $date = p('paid_on');
            if (!$open) flash('bad', 'Não há aulas a pagar neste período.');
            elseif ($adj === null) flash('bad', 'Valor do ajuste inválido.');
            elseif (!valid_date($date)) flash('bad', 'Informe a data do pagamento.');
            elseif ($adj && mb_strlen(p('adjust_reason')) < 3) flash('bad', 'Explique o motivo do acréscimo ou desconto.');
            else {
                $adj = $neg ? -$adj : $adj;
                $gross = array_sum(array_column($open, 'value'));
                if ($gross + $adj < 0) { flash('bad', 'O desconto é maior que o valor das aulas.'); go('fin.professor', $back); }
                $pid = tx(function () use ($p, $open, $gross, $adj, $date, $from, $to, $rate, $u) {
                    $id = insert('professor_payments', ['institution_id' => inst_id(), 'professor_id' => $p['id'], 'period_from' => $from, 'period_to' => $to, 'lessons' => count($open), 'hours' => array_sum(array_column($open, 'hours')),
                        'rate_cents' => $rate['rate_cents'], 'unit' => $rate['unit'], 'gross_cents' => $gross, 'adjust_cents' => $adj, 'adjust_reason' => mb_substr(p('adjust_reason'), 0, 160) ?: null, 'amount_cents' => $gross + $adj,
                        'paid_on' => $date, 'method' => isset(PAY_METHODS[p('method')]) ? p('method') : 'PIX', 'notes' => mb_substr(p('notes'), 0, 255) ?: null, 'created_by' => $u['id'], 'created_at' => now()]);
                    foreach ($open as $l) insert('professor_payment_lessons', ['payment_id' => $id, 'lesson_id' => $l['id'], 'amount_cents' => $l['value']]);
                    return $id;
                });
                audit('payroll.pay', "Pagamento de {$p['name']}: " . plural(count($open), 'aula', 'aulas') . ' de ' . fmt_date($from) . ' a ' . fmt_date($to) . ' · ' . money($gross + $adj), 'professor_payment', $pid);
                flash('ok', 'Pagamento registrado: ' . money($gross + $adj) . '.');
            }
        }
        go('fin.professor', $back);
    }
    $ls = prof_lessons($p, $from, $to, $rate);
    $open = array_values(array_filter($ls, fn($l) => !$l['paid']));
    $total = array_sum(array_map(fn($l) => (int) $l['value'], $ls));
    $openV = array_sum(array_map(fn($l) => (int) $l['value'], $open));
    // Aulas
    $tr = ''; $byGroup = [];
    foreach ($ls as $l) {
        $n = count($l['groups']);
        foreach ($l['groups'] as $k => $g) { $key = $g['code']; $byGroup[$key] ??= ['n' => 0, 'v' => 0, 'subjects' => []]; $byGroup[$key]['n']++; $byGroup[$key]['v'] += $l['parts'][$k] ?? 0; $byGroup[$key]['subjects'][$l['subject']] = true; }
        $split = $l['value'] === null ? '<span class="muted">—</span>' : ($n > 1
            ? '<span class="split-note">' . $n . ' turmas · ' . money($l['parts'][$n - 1]) . ' cada</span><p class="cell-sub">' . implode(' · ', array_map(fn($g, $v) => h($g['code']) . ' ' . money($v), $l['groups'], $l['parts'])) . '</p>'
            : '<span class="muted small">Turma única</span>');
        $tr .= '<tr><td class="tnum">' . fmt_date($l['lesson_date']) . '</td><td><p class="cell-title">' . h($l['subject']) . '</p><p class="cell-sub lesson-topic" title="' . h($l['topic']) . '">' . h($l['code']) . '</p></td>'
            . '<td>' . implode(' ', array_map(fn($g) => badge(h($g['code']), $n > 1 ? 'b-info' : 'b-neutral'), $l['groups'])) . '</td><td class="r tnum">' . (int) $l['hours'] . 'h</td>'
            . '<td class="r tnum"><strong>' . ($l['value'] === null ? '—' : money($l['value'])) . '</strong></td><td>' . $split . '</td>'
            . '<td>' . ($l['paid'] ? badge('Pago em ' . date('d/m', strtotime($l['paid']['paid_on'])), 'b-ok') : badge('A pagar', 'b-info')) . '</td></tr>';
    }
    ksort($byGroup);
    $gr = '';
    foreach ($byGroup as $code => $g) $gr .= '<tr><td><strong>' . h($code) . '</strong><p class="cell-sub">' . h(implode(', ', array_keys($g['subjects']))) . '</p></td><td class="r tnum">' . $g['n'] . '</td><td class="r tnum">' . money($g['v']) . '</td></tr>';
    $shared = count(array_filter($ls, fn($l) => count($l['groups']) > 1));
    // Pagamentos do professor
    $pays = rows('SELECT pp.*, u.name AS by_name FROM professor_payments pp LEFT JOIN users u ON u.id = pp.created_by WHERE pp.professor_id = ? AND pp.institution_id = ? ORDER BY pp.paid_on DESC, pp.id DESC LIMIT 24', [$p['id'], inst_id()]);
    $pr = '';
    foreach ($pays as $x) $pr .= '<tr><td data-label="Pago em" class="tnum"><span class="cv">' . fmt_date($x['paid_on']) . '<p class="cell-sub">' . h($x['method']) . '</p></span></td>'
        . '<td data-label="Período" class="nowrap"><span class="cv">' . fmt_date($x['period_from']) . ' a ' . fmt_date($x['period_to']) . '<p class="cell-sub">' . plural((int) $x['lessons'], 'aula', 'aulas') . ' · ' . (int) $x['hours'] . 'h</p></span></td>'
        . '<td data-label="Valor das aulas" class="r tnum"><span class="cv">' . money((int) $x['gross_cents']) . '</span></td>'
        . '<td data-label="Ajuste" class="r tnum"><span class="cv">' . ((int) $x['adjust_cents'] ? ((int) $x['adjust_cents'] > 0 ? '+' : '−') . money(abs((int) $x['adjust_cents'])) . ($x['adjust_reason'] ? '<p class="cell-sub">' . h($x['adjust_reason']) . '</p>' : '') : '<span class="muted">—</span>') . '</span></td>'
        . '<td data-label="Valor pago" class="r tnum"><span class="cv"><strong>' . money((int) $x['amount_cents']) . '</strong></span></td>'
        . '<td class="r"><a class="btn btn-sm btn-secondary" href="' . h(url('fin.prof_pagamento', ['id' => $x['id'], 'de' => $from, 'ate' => $to])) . '">' . icon('pencil') . ' Editar</a></td></tr>';
    $rateCard = '<div class="card"><div class="card-head"><div><h3>' . icon('lock') . ' Valor da aula</h3><p>Só quem tem a permissão de Professores vê este valor.</p></div></div><div class="card-body">'
        . ($rate ? '<p class="rate-big">' . money((int) $rate['rate_cents']) . ' <span>' . mb_strtolower(RATE_UNIT[$rate['unit']]) . '</span></p><p class="small muted" style="margin:0 0 12px">Atualizado em ' . fmt_dt($rate['updated_at']) . '</p>' : alert('warn', 'Ainda não definido. Cadastre para calcular o balanço.'))
        . '<details' . ($rate ? '' : ' open') . ' class="rate-edit"><summary class="btn btn-sm btn-secondary">' . icon('pencil') . ' ' . ($rate ? 'Alterar valor' : 'Cadastrar valor') . '</summary>'
        . form_open('fin.professor', $back, 'class="form-grid" style="margin-top:12px"') . '<input type="hidden" name="acao" value="valor">'
        . input('rate', 'Valor (R$)', $rate ? number_format($rate['rate_cents'] / 100, 2, ',', '.') : '', 'text', 'inputmode="decimal" required placeholder="0,00"')
        . select('unit', 'Cálculo', RATE_UNIT, $rate['unit'] ?? 'AULA', '', 'Por aula: cada aula registrada vale o valor. Por hora-aula: valor × horas da aula.')
        . '<button class="btn btn-primary" type="submit">Salvar valor</button></form></details></div></div>';
    $payCard = '<div class="card"><div class="card-head"><div><h3>Registrar pagamento</h3><p>Aulas a pagar de ' . fmt_date($from) . ' a ' . fmt_date($to) . '.</p></div></div><div class="card-body">'
        . (!$open ? empty_state('circle-check', 'Nada a pagar neste período', $ls ? 'Todas as aulas do período já foram pagas.' : 'Nenhuma aula registrada no período.')
        : (!$rate ? alert('warn', 'Defina o valor da aula para registrar o pagamento.')
        : '<dl class="kv pay-sum"><dt>Aulas</dt><dd>' . count($open) . ' · ' . array_sum(array_column($open, 'hours')) . 'h</dd><dt>Valor das aulas</dt><dd><strong data-pay-gross="' . $openV . '">' . money($openV) . '</strong></dd></dl>'
            . form_open('fin.professor', $back, 'class="form-grid cols-2" data-pay-form data-confirm="Registrar o pagamento destas aulas?"') . '<input type="hidden" name="acao" value="pagar">'
            . input('paid_on', 'Data do pagamento', today(), 'date', 'required') . select('method', 'Forma', PAY_METHODS, 'PIX')
            . select('adjust_kind', 'Ajuste', ['acrescimo' => 'Acréscimo', 'desconto' => 'Desconto'], 'acrescimo', 'data-pay-kind') . input('adjust', 'Valor do ajuste (R$)', '', 'text', 'inputmode="decimal" placeholder="0,00" data-pay-adjust', 'Opcional.')
            . input('adjust_reason', 'Motivo do ajuste', '', 'text', 'maxlength="160"', 'Ex.: aula extra, substituição, falta.', 'span-2')
            . input('notes', 'Observação', '', 'text', 'maxlength="255"', '', 'span-2')
            . '<div class="span-2 pay-total"><span>Total a pagar</span><strong data-pay-total>' . money($openV) . '</strong></div>'
            . '<div class="span-2 row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">' . icon('banknote') . ' Registrar pagamento</button></div></form>'))
        . '</div></div>';
    layout($p['name'], crumb('fin.professores', 'Professores', ['de' => $from, 'ate' => $to]) . page_head(h($p['name']), 'Matrícula ' . h($p['registration']) . ($p['title'] ? ' · ' . h($p['title']) : '') . ($p['department'] ? ' · ' . h($p['department']) : ''), '<button class="btn btn-secondary" type="button" data-print>' . icon('printer') . ' Imprimir</button>')
        . payroll_period_bar('fin.professor', ['id' => $p['id']], $from, $to)
        . '<div class="grid g4 pay-stats" style="margin:16px 0">' . stat_card('Aulas no período', (string) count($ls), 'calendar-check', '', array_sum(array_column($ls, 'hours')) . ' horas-aula') . stat_card('Valor do período', $rate || $total ? money($total) : '—', 'wallet') . stat_card('Já pago', money($paidV = paid_total($ls)), 'circle-check', '', $paidV !== $total - $openV ? 'Com acréscimos/descontos' : '') . stat_card('A pagar', $rate ? money($openV) : '—', 'hourglass', $openV ? 'warn' : '') . '</div>'
        . ($shared ? alert('info', plural($shared, 'aula foi', 'aulas foram') . ' de disciplina compartilhada por mais de uma turma. O professor recebe o valor da aula uma vez, e esse valor é dividido entre as turmas no rateio.', 'Disciplina compartilhada') : '')
        . '<div class="payroll-top no-print">' . $rateCard . $payCard
        . '<div class="card"><div class="card-head"><div><h3>Pagamentos</h3><p>Últimos pagamentos deste professor.</p></div></div>' . ($pr ? '<div class="table-wrap"><table class="pay-table"><thead><tr><th>Pago em</th><th>Período</th><th class="r">Valor das aulas</th><th class="r">Ajuste</th><th class="r">Valor pago</th><th><span class="sr-only">Ações</span></th></tr></thead><tbody>' . $pr . '</tbody></table></div>' : '<div class="card-body">' . empty_state('receipt', 'Nenhum pagamento registrado') . '</div>') . '</div>'
        . '</div>'
        . '<div class="card"><div class="card-head"><div><h3>Aulas no período</h3><p>Aulas registradas no diário de classe.</p></div></div><div class="table-wrap"><table><thead><tr><th>Data</th><th>Disciplina</th><th>Turmas</th><th class="r">Horas</th><th class="r">Valor</th><th>Rateio</th><th>Situação</th></tr></thead><tbody>'
        . ($tr ?: '<tr><td colspan="7">' . empty_state('calendar-x', 'Nenhuma aula neste período', 'Escolha outro período acima.') . '</td></tr>') . '</tbody>' . ($tr ? '<tfoot><tr><td colspan="3"><strong>Total</strong></td><td class="r tnum"><strong>' . array_sum(array_column($ls, 'hours')) . 'h</strong></td><td class="r tnum"><strong>' . ($rate || $total ? money($total) : '—') . '</strong></td><td colspan="2"></td></tr></tfoot>' : '') . '</table></div></div>'
        . ($gr ? '<div class="card"><div class="card-head"><div><h3>Rateio por turma</h3><p>Quanto do valor das aulas fica com cada turma. A soma é igual ao total do professor.</p></div></div><div class="table-wrap"><table><thead><tr><th>Turma</th><th class="r">Aulas</th><th class="r">Valor rateado</th></tr></thead><tbody>' . $gr . '</tbody><tfoot><tr><td><strong>Total</strong></td><td></td><td class="r tnum"><strong>' . money(array_sum(array_column($byGroup, 'v'))) . '</strong></td></tr></tfoot></table></div></div>' : '')
        . '');
}

/** Editar ou desfazer um pagamento de professor */
function route_fin__prof_pagamento(): void {
    $u = require_perm('payroll:manage');
    $x = row('SELECT * FROM professor_payments WHERE id = ? AND institution_id = ?', [gi('id'), inst_id()]);
    if (!$x) abort(404);
    $p = payroll_prof_or_404((int) $x['professor_id']);
    $back = ['id' => $p['id'], 'de' => valid_date(g('de')) ? g('de') : $x['period_from'], 'ate' => valid_date(g('ate')) ? g('ate') : $x['period_to']];
    $err = '';
    if (is_post()) {
        if (p('acao') === 'excluir') {
            if (mb_strlen(p('reason')) < 5) $err = 'Informe o motivo para desfazer o pagamento.';
            else {
                tx(function () use ($x) { q('DELETE FROM professor_payment_lessons WHERE payment_id = ?', [$x['id']]); q('DELETE FROM professor_payments WHERE id = ?', [$x['id']]); });
                audit('payroll.undo', "Pagamento desfeito: {$p['name']} · " . money((int) $x['amount_cents']) . ' de ' . fmt_date($x['paid_on']), 'professor_payment', (int) $x['id'], p('reason'));
                flash('ok', 'Pagamento desfeito: as aulas voltaram para "a pagar".');
                go('fin.professor', $back);
            }
        } else {
            $adj = p('adjust') === '' ? 0 : parse_money(ltrim(p('adjust'), '-+'));
            $neg = str_starts_with(trim(p('adjust')), '-') || p('adjust_kind') === 'desconto';
            $gross = parse_money(p('gross'));
            if ($gross === null) $err = 'Valor das aulas inválido.';
            elseif ($adj === null) $err = 'Valor do ajuste inválido.';
            elseif (!valid_date(p('paid_on'))) $err = 'Informe a data do pagamento.';
            elseif ($adj && mb_strlen(p('adjust_reason')) < 3) $err = 'Explique o motivo do acréscimo ou desconto.';
            elseif ($gross + ($neg ? -$adj : $adj) < 0) $err = 'O desconto é maior que o valor das aulas.';
            else {
                $adj = $neg ? -$adj : $adj;
                $d = ['paid_on' => p('paid_on'), 'method' => isset(PAY_METHODS[p('method')]) ? p('method') : $x['method'], 'gross_cents' => $gross, 'adjust_cents' => $adj, 'adjust_reason' => $adj ? mb_substr(p('adjust_reason'), 0, 160) : null,
                    'amount_cents' => $gross + $adj, 'notes' => mb_substr(p('notes'), 0, 255) ?: null, 'updated_by' => $u['id'], 'updated_at' => now()];
                tx(function () use ($x, $d, $gross) {
                    update('professor_payments', $d, 'id = ?', [$x['id']]);
                    if ($gross !== (int) $x['gross_cents']) { // valor das aulas corrigido: redistribui entre as aulas do pagamento
                        $ids = col('SELECT lesson_id FROM professor_payment_lessons WHERE payment_id = ? ORDER BY lesson_id', [$x['id']]);
                        foreach (split_cents($gross, count($ids)) as $k => $v) q('UPDATE professor_payment_lessons SET amount_cents = ? WHERE payment_id = ? AND lesson_id = ?', [$v, $x['id'], $ids[$k]]);
                    }
                });
                $chg = [];
                foreach (['paid_on' => 'data', 'method' => 'forma', 'gross_cents' => 'valor das aulas', 'adjust_cents' => 'ajuste', 'notes' => 'observação'] as $k => $l) if ((string) $d[$k] !== (string) $x[$k]) $chg[] = $l;
                audit('payroll.edit', "Pagamento de {$p['name']} alterado" . ($chg ? ' (' . implode(', ', $chg) . ')' : '') . ': ' . money((int) $x['amount_cents']) . ' → ' . money($d['amount_cents']), 'professor_payment', (int) $x['id']);
                flash('ok', 'Pagamento atualizado.');
                go('fin.professor', $back);
            }
        }
    }
    $lessons = rows('SELECT l.lesson_date, l.hours, se.code, su.name AS subject, pl.amount_cents FROM professor_payment_lessons pl JOIN lessons l ON l.id = pl.lesson_id JOIN sections se ON se.id = l.section_id JOIN subjects su ON su.id = se.subject_id WHERE pl.payment_id = ? ORDER BY l.lesson_date', [$x['id']]);
    $lt = ''; foreach ($lessons as $l) $lt .= '<tr><td class="tnum">' . fmt_date($l['lesson_date']) . '</td><td>' . h($l['subject']) . '<p class="cell-sub">' . h($l['code']) . '</p></td><td class="r tnum">' . (int) $l['hours'] . 'h</td><td class="r tnum">' . money((int) $l['amount_cents']) . '</td></tr>';
    $adj = (int) $x['adjust_cents'];
    layout('Editar pagamento', crumb('fin.professor', $p['name'], $back) . page_head('Editar pagamento', h($p['name']) . ' · aulas de ' . fmt_date($x['period_from']) . ' a ' . fmt_date($x['period_to']))
        . ($err ? alert('bad', h($err)) : '')
        . '<div class="grid g2" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr);align-items:start"><div class="card"><div class="card-body" style="padding-top:20px">'
        . form_open('fin.prof_pagamento', ['id' => $x['id'], 'de' => $back['de'], 'ate' => $back['ate']], 'class="form-grid cols-2" data-pay-form') . '<input type="hidden" name="acao" value="salvar">'
        . input('paid_on', 'Data do pagamento', p('paid_on') ?: $x['paid_on'], 'date', 'required') . select('method', 'Forma', PAY_METHODS, $x['method'])
        . input('gross', 'Valor das aulas (R$)', number_format($x['gross_cents'] / 100, 2, ',', '.'), 'text', 'inputmode="decimal" required data-pay-gross-input', plural((int) $x['lessons'], 'aula', 'aulas') . ' · ' . money((int) $x['rate_cents']) . ' ' . mb_strtolower(RATE_UNIT[$x['unit']] ?? '') . '. Corrija só se precisar.', 'span-2')
        . select('adjust_kind', 'Ajuste', ['acrescimo' => 'Acréscimo', 'desconto' => 'Desconto'], $adj < 0 ? 'desconto' : 'acrescimo', 'data-pay-kind') . input('adjust', 'Valor do ajuste (R$)', $adj ? number_format(abs($adj) / 100, 2, ',', '.') : '', 'text', 'inputmode="decimal" placeholder="0,00" data-pay-adjust')
        . input('adjust_reason', 'Motivo do ajuste', $x['adjust_reason'] ?? '', 'text', 'maxlength="160"', '', 'span-2')
        . input('notes', 'Observação', $x['notes'] ?? '', 'text', 'maxlength="255"', '', 'span-2')
        . '<div class="span-2 pay-total"><span>Total pago</span><strong data-pay-total>' . money((int) $x['amount_cents']) . '</strong></div>'
        . '<div class="span-2 row" style="justify-content:flex-end;gap:8px"><a class="btn btn-secondary" href="' . h(url('fin.professor', $back)) . '">Cancelar</a><button class="btn btn-primary" type="submit">Salvar alterações</button></div></form>'
        . '</div></div><div class="stack" style="gap:16px"><div class="card"><div class="card-head"><div><h3>Aulas deste pagamento</h3></div></div><div class="table-wrap"><table><thead><tr><th>Data</th><th>Disciplina</th><th class="r">Horas</th><th class="r">Valor</th></tr></thead><tbody>' . $lt . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Desfazer pagamento</h3><p>As aulas voltam para "a pagar".</p></div></div><div class="card-body">' . form_open('fin.prof_pagamento', ['id' => $x['id'], 'de' => $back['de'], 'ate' => $back['ate']], 'class="form-grid" data-confirm="Desfazer este pagamento?"') . '<input type="hidden" name="acao" value="excluir">'
        . input('reason', 'Motivo', '', 'text', 'required minlength="5" maxlength="160"') . '<button class="btn btn-ghost" type="submit">' . icon('undo-2') . ' Desfazer pagamento</button></form></div></div></div></div>');
}
