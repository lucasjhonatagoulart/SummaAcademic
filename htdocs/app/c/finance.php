<?php
// Financeiro (cobranças, pagamentos, inadimplência, balancete, avisos) e indicadores institucionais.

function finance_summary(): array {
    $i = inst_id(); $t = today(); $m = date('Y-m');
    return [
        'expected' => (int) val("SELECT COALESCE(SUM(amount_cents),0) FROM charges WHERE institution_id = ? AND competence = ? AND status <> 'CANCELADA'", [$i, $m]),
        'received' => (int) val("SELECT COALESCE(SUM(paid_cents),0) FROM charges WHERE institution_id = ? AND status = 'PAGA' AND paid_on >= ? AND paid_on <= ?", [$i, "$m-01", date("Y-m-t")]),
        'overdue' => (int) val("SELECT COALESCE(SUM(amount_cents),0) FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on < ?", [$i, $t]),
        'debtors' => (int) val("SELECT COUNT(DISTINCT student_id) FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on < ?", [$i, $t]),
        'active' => (int) val("SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE u.institution_id = ? AND s.status = 'ATIVO'", [$i]),
    ];
}
function finance_months(int $limit = 12): array {
    $i = inst_id();
    $exp = array_column(rows("SELECT competence AS m, SUM(amount_cents) AS v FROM charges WHERE institution_id = ? AND status <> 'CANCELADA' GROUP BY competence", [$i]), 'v', 'm');
    $rec = [];
    foreach (rows("SELECT paid_on, paid_cents FROM charges WHERE institution_id = ? AND status = 'PAGA'", [$i]) as $r) { $k = substr($r['paid_on'], 0, 7); $rec[$k] = ($rec[$k] ?? 0) + $r['paid_cents']; }
    $late = [];
    foreach (rows("SELECT competence, amount_cents FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on < ?", [$i, today()]) as $r) $late[$r['competence']] = ($late[$r['competence']] ?? 0) + $r['amount_cents'];
    $months = array_unique(array_merge(array_keys($exp), array_keys($rec)));
    rsort($months);
    $months = array_slice($months, 0, $limit);
    return array_map(fn($m) => ['m' => $m, 'expected' => (int) ($exp[$m] ?? 0), 'received' => (int) ($rec[$m] ?? 0), 'late' => (int) ($late[$m] ?? 0)], $months);
}
function month_label(string $ym): string { $n = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']; return $n[(int) substr($ym, 5, 2) - 1] . '/' . substr($ym, 0, 4); }

function finance_overview(bool $manage): string {
    $f = finance_summary();
    $rate = $f['active'] ? $f['debtors'] * 100 / $f['active'] : 0;
    $rows = finance_months(6); $max = max(1, ...array_map(fn($r) => max($r['expected'], $r['received']), $rows ?: [['expected' => 1, 'received' => 1]]));
    $tr = '';
    foreach ($rows as $r) $tr .= '<tr><td>' . month_label($r['m']) . '</td><td><div class="stack" style="gap:4px;min-width:90px"><div class="bar" style="width:100%;height:8px;background:var(--gray)"><span style="width:' . round($r['expected'] * 100 / $max) . '%;background:var(--navy-muted)"></span></div><div class="bar ok" style="width:100%;height:8px"><span style="width:' . round($r['received'] * 100 / $max) . '%"></span></div></div></td>'
        . '<td class="r tnum">' . money($r['expected']) . '</td><td class="r tnum">' . money($r['received']) . '</td><td class="r tnum">' . ($r['late'] ? '<span style="color:var(--red)">' . money($r['late']) . '</span>' : '—') . '</td></tr>';
    $debt = rows("SELECT s.id, u.name, s.ra, SUM(c.amount_cents) AS v, COUNT(*) AS n, MIN(c.due_on) AS oldest FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.institution_id = ? AND c.status = 'ABERTA' AND c.due_on < ? GROUP BY s.id, u.name, s.ra ORDER BY v DESC LIMIT 8", [inst_id(), today()]);
    $dt = ''; foreach ($debt as $d) $dt .= '<tr' . ($manage ? ' class="clickable" data-href="' . h(url('fin.aluno', ['id' => $d['id']])) . '"' : '') . '><td>' . h($d['name']) . '<p class="cell-sub">RA ' . h($d['ra']) . '</p></td><td class="tnum">' . $d['n'] . '</td><td class="tnum">' . fmt_date($d['oldest']) . '</td><td class="r tnum"><strong>' . money((int) $d['v']) . '</strong></td></tr>';
    return '<div class="grid g4" style="margin-bottom:16px">' . stat_card('Previsto no mês', money($f['expected']), 'calendar-range') . stat_card('Recebido no mês', money($f['received']), 'circle-check') . stat_card('Vencido em aberto', money($f['overdue']), 'triangle-alert', $f['overdue'] ? 'warn' : '') . stat_card('Inadimplência', num($rate) . '%', 'users', $rate > 10 ? 'warn' : '', plural($f['debtors'], 'aluno', 'alunos') . ' de ' . $f['active']) . '</div>'
        . '<div class="grid g2" style="grid-template-columns:minmax(0,3fr) minmax(0,2fr);align-items:start"><div class="card"><div class="card-head"><div><h3>Previsto x recebido</h3><p>Barra cinza: previsto pela competência. Verde: recebido no mês.</p></div></div><div class="table-wrap"><table><thead><tr><th>Mês</th><th></th><th class="r">Previsto</th><th class="r">Recebido</th><th class="r">Vencido</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('wallet', 'Sem cobranças') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Maiores débitos vencidos</h3></div>' . ($manage ? '<a class="btn btn-sm btn-secondary" href="' . h(url('fin.notificar')) . '">' . icon('mail') . ' Avisar inadimplentes</a>' : '') . '</div><div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Parcelas</th><th>Mais antiga</th><th class="r">Total</th></tr></thead><tbody>' . ($dt ?: '<tr><td colspan="4">' . empty_state('circle-check', 'Nenhum débito vencido') . '</td></tr>') . '</tbody></table></div></div></div>';
}
function route_fin__indicadores(): void { require_perm('finance:manage'); layout('Indicadores financeiros', page_head('Indicadores', 'Mensalidades e inadimplência.') . finance_overview(true)); }
function route_dir__financeiro(): void { require_perm('reports:finance'); layout('Financeiro', page_head('Financeiro', 'Visão de consulta das mensalidades e da inadimplência.') . finance_overview(false)); }

function balancete(): string {
    $rows = finance_months(24);
    $tr = ''; $te = 0; $tr2 = 0; $tl = 0;
    foreach ($rows as $r) { $te += $r['expected']; $tr2 += $r['received']; $tl += $r['late']; $tr .= '<tr><td>' . month_label($r['m']) . '</td><td class="r tnum">' . money($r['expected']) . '</td><td class="r tnum">' . money($r['received']) . '</td><td class="r tnum">' . money($r['received'] - $r['expected']) . '</td><td class="r tnum">' . money($r['late']) . '</td><td class="r tnum">' . ($r['expected'] ? num($r['received'] * 100 / $r['expected']) . '%' : '—') . '</td></tr>'; }
    return '<div class="card"><div class="table-wrap"><table><thead><tr><th>Mês</th><th class="r">Previsto (competência)</th><th class="r">Recebido (caixa)</th><th class="r">Diferença</th><th class="r">Vencido em aberto</th><th class="r">Realização</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('scale', 'Sem movimento') . '</td></tr>') . '</tbody>'
        . '<tfoot><tr><td><strong>Total</strong></td><td class="r tnum"><strong>' . money($te) . '</strong></td><td class="r tnum"><strong>' . money($tr2) . '</strong></td><td class="r tnum"><strong>' . money($tr2 - $te) . '</strong></td><td class="r tnum"><strong>' . money($tl) . '</strong></td><td class="r tnum"><strong>' . ($te ? num($tr2 * 100 / $te) . '%' : '—') . '</strong></td></tr></tfoot></table></div></div>';
}
function route_fin__balancete(): void { require_perm('finance:manage'); layout('Balancete', page_head('Balancete', 'Previsto pela competência das mensalidades e recebido pela data de pagamento.', '<button class="btn btn-secondary" type="button" data-print>' . icon('printer') . ' Imprimir</button>') . balancete()); }
function route_dir__balancete(): void { require_perm('reports:finance'); layout('Balancete', page_head('Balancete', 'Previsto pela competência das mensalidades e recebido pela data de pagamento.', '<button class="btn btn-secondary" type="button" data-print>' . icon('printer') . ' Imprimir</button>') . balancete()); }

function route_fin__cobrancas(): void {
    require_perm('finance:manage');
    if (is_post() && p('acao') === 'gerar') {
        $comp = p('competence'); $day = max(1, min(28, pi_('due_day')));
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $comp)) { flash('bad', 'Informe a competência no formato AAAA-MM.'); go('fin.cobrancas'); }
        $desc = p('description') ?: 'Mensalidade ' . substr($comp, 5, 2) . '/' . substr($comp, 0, 4);
        $n = tx(function () use ($comp, $day, $desc) {
            $n = 0;
            foreach (rows("SELECT s.id, s.monthly_fee_cents FROM students s JOIN users u ON u.id = s.user_id WHERE u.institution_id = ? AND s.status = 'ATIVO' AND s.monthly_fee_cents > 0", [inst_id()]) as $s) {
                if (row("SELECT id FROM charges WHERE student_id = ? AND competence = ? AND status <> 'CANCELADA' AND description LIKE 'Mensalidade%'", [$s['id'], $comp])) continue;
                insert('charges', ['institution_id' => inst_id(), 'student_id' => $s['id'], 'description' => $desc, 'competence' => $comp, 'due_on' => sprintf('%s-%02d', $comp, $day), 'amount_cents' => $s['monthly_fee_cents'], 'status' => 'ABERTA', 'created_at' => now()]);
                $n++;
            }
            return $n;
        });
        audit('charge.generate', "Mensalidades geradas para $comp: $n cobranças");
        flash($n ? 'ok' : 'info', $n ? "$n mensalidades geradas para " . month_label($comp) . '.' : 'Nenhuma cobrança nova: todos os alunos ativos já têm a mensalidade desta competência (ou estão sem valor de mensalidade).');
        go('fin.cobrancas', ['comp' => $comp]);
    }
    $sit = g('situacao'); $comp = g('comp'); $q = g('q');
    $w = ['c.institution_id = ?']; $par = [inst_id()];
    if ($comp) { $w[] = 'c.competence = ?'; $par[] = $comp; }
    if ($q !== '') { $w[] = '(u.name LIKE ? OR s.ra LIKE ?)'; array_push($par, "%$q%", "%$q%"); }
    if ($sit === 'VENCIDA') { $w[] = "c.status = 'ABERTA' AND c.due_on < ?"; $par[] = today(); }
    elseif ($sit === 'ABERTA') { $w[] = "c.status = 'ABERTA' AND c.due_on >= ?"; $par[] = today(); }
    elseif (in_array($sit, ['PAGA', 'CANCELADA'], true)) { $w[] = 'c.status = ?'; $par[] = $sit; }
    $list = rows('SELECT c.*, u.name, s.ra FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY c.due_on DESC, u.name LIMIT 500', $par);
    $tr = ''; $sum = 0;
    foreach ($list as $c) { $st = charge_state($c, today()); $sum += $c['amount_cents'];
        $tr .= '<tr class="clickable" data-href="' . h(url('fin.aluno', ['id' => $c['student_id']])) . '"><td><a class="cell-link" href="' . h(url('fin.aluno', ['id' => $c['student_id']])) . '">' . h($c['name']) . '</a><p class="cell-sub">RA ' . h($c['ra']) . '</p></td><td>' . h($c['description']) . '</td><td class="tnum">' . fmt_date($c['due_on']) . '</td><td class="r tnum">' . money((int) $c['amount_cents']) . '</td><td>' . badge(CHARGE_LABEL[$st], CHARGE_TONE[$st]) . '</td><td class="small">' . ($c['paid_on'] ? fmt_date($c['paid_on']) . ' · ' . h($c['method']) : '') . '</td></tr>'; }
    $comps = []; foreach (col('SELECT DISTINCT competence FROM charges WHERE institution_id = ? ORDER BY competence DESC', [inst_id()]) as $m) $comps[$m] = month_label($m);
    layout('Cobranças', fin_students_tabs('cobrancas') . page_head('Cobranças', 'Clique em uma cobrança para registrar pagamento ou cancelar.')
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start"><div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="fin.cobrancas">'
        . input('q', 'Aluno', $q, 'search', 'placeholder="Nome ou RA"', '', 'filters-q') . select('comp', 'Competência', $comps, $comp, 'data-autosubmit', '', '', 'Todas') . select('situacao', 'Situação', ['ABERTA' => 'Em aberto', 'VENCIDA' => 'Vencida', 'PAGA' => 'Paga', 'CANCELADA' => 'Cancelada'], $sit, 'data-autosubmit', '', '', 'Todas') . '<button class="btn btn-secondary" type="submit">Filtrar</button></form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Descrição</th><th>Vencimento</th><th class="r">Valor</th><th>Situação</th><th>Pagamento</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('receipt', 'Nenhuma cobrança encontrada') . '</td></tr>') . '</tbody></table></div><p class="small muted" style="padding:12px 20px;border-top:1px solid var(--line)">' . plural(count($list), 'cobrança', 'cobranças') . ' · ' . money($sum) . '</p></div>'
        . '<div class="card"><div class="card-head"><div><h3>Gerar mensalidades</h3><p>Uma cobrança por aluno ativo, com o valor da mensalidade do cadastro. Quem já tem a mensalidade da competência é ignorado.</p></div></div><div class="card-body">' . form_open('fin.cobrancas', [], 'class="form-grid"') . '<input type="hidden" name="acao" value="gerar">'
        . input('competence', 'Competência', date('Y-m', strtotime('first day of next month')), 'month', 'required') . input('due_day', 'Dia do vencimento', '10', 'number', 'min="1" max="28"') . input('description', 'Descrição (opcional)', '', 'text', 'placeholder="Mensalidade MM/AAAA"')
        . '<button class="btn btn-primary" type="submit">' . icon('receipt') . ' Gerar mensalidades</button></form></div></div></div>');
}

function route_fin__situacao(): void {
    require_perm('finance:manage');
    $q = g('q'); $sit = g('situacao');
    $list = rows("SELECT s.id, s.ra, s.monthly_fee_cents, u.name, c.code AS course,
        (SELECT COALESCE(SUM(amount_cents),0) FROM charges x WHERE x.student_id = s.id AND x.status = 'ABERTA' AND x.due_on < ?) AS late,
        (SELECT COALESCE(SUM(amount_cents),0) FROM charges x WHERE x.student_id = s.id AND x.status = 'ABERTA') AS open_
        FROM students s JOIN users u ON u.id = s.user_id JOIN courses c ON c.id = s.course_id WHERE u.institution_id = ? AND s.status = 'ATIVO'" . ($q ? ' AND (u.name LIKE ? OR s.ra LIKE ?)' : '') . ' ORDER BY u.name', $q ? [today(), inst_id(), "%$q%", "%$q%"] : [today(), inst_id()]);
    if ($sit === 'inadimplentes') $list = array_filter($list, fn($s) => $s['late'] > 0); elseif ($sit === 'em_dia') $list = array_filter($list, fn($s) => (int) $s['late'] === 0);
    $tr = '';
    foreach ($list as $s) $tr .= '<tr class="clickable" data-href="' . h(url('fin.aluno', ['id' => $s['id']])) . '"><td><a class="cell-link" href="' . h(url('fin.aluno', ['id' => $s['id']])) . '">' . h($s['name']) . '</a><p class="cell-sub">RA ' . h($s['ra']) . ' · ' . h($s['course']) . '</p></td><td class="r tnum">' . money((int) $s['monthly_fee_cents']) . '</td><td class="r tnum">' . money((int) $s['open_']) . '</td><td class="r tnum">' . ($s['late'] ? '<strong style="color:var(--red)">' . money((int) $s['late']) . '</strong>' : '—') . '</td><td>' . ($s['late'] ? badge('Inadimplente', 'b-bad') : badge('Em dia', 'b-ok')) . '</td></tr>';
    layout('Situação dos alunos', fin_students_tabs('situacao') . page_head('Situação dos alunos', 'Alunos ativos e a situação das mensalidades.') . '<div class="card"><div class="card-head"><form method="get" action="index.php" class="filters"><input type="hidden" name="r" value="fin.situacao">'
        . input('q', 'Aluno', $q, 'search', 'placeholder="Nome ou RA"', '', 'filters-q') . select('situacao', 'Situação', ['em_dia' => 'Em dia', 'inadimplentes' => 'Inadimplentes'], $sit, 'data-autosubmit', '', '', 'Todos') . '<button class="btn btn-secondary" type="submit">Filtrar</button></form></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Aluno</th><th class="r">Mensalidade</th><th class="r">Em aberto</th><th class="r">Vencido</th><th>Situação</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('users', 'Nenhum aluno') . '</td></tr>') . '</tbody></table></div>' . result_line(count($list), count($list), 'alunos') . '</div>');
}

function route_fin__aluno(): void {
    require_perm('finance:manage');
    $s = student_full(gi('id'));
    if (!$s) abort(404);
    if (is_post()) {
        $act = p('acao');
        $c = pi_('charge_id') ? row('SELECT * FROM charges WHERE id = ? AND student_id = ?', [pi_('charge_id'), $s['id']]) : null;
        if ($act === 'pagar' && $c && $c['status'] === 'ABERTA') {
            $amt = parse_money(p('amount')); $date = p('paid_on');
            if ($amt === null || $amt <= 0 || !valid_date($date) || $date > today()) flash('bad', 'Informe o valor pago e a data (até hoje).');
            else { update('charges', ['status' => 'PAGA', 'paid_on' => $date, 'paid_cents' => $amt, 'method' => mb_substr(p('method'), 0, 20) ?: 'PIX'], 'id = ?', [$c['id']]); audit('charge.pay', "Pagamento registrado: {$s['name']} · {$c['description']} · " . money($amt), 'charge', (int) $c['id']); flash('ok', 'Pagamento registrado.'); }
        } elseif ($act === 'estornar' && $c && $c['status'] === 'PAGA') {
            update('charges', ['status' => 'ABERTA', 'paid_on' => null, 'paid_cents' => null, 'method' => null], 'id = ?', [$c['id']]); audit('charge.refund', "Pagamento desfeito: {$s['name']} · {$c['description']}", 'charge', (int) $c['id'], p('reason') ?: null); flash('ok', 'Pagamento desfeito: a cobrança voltou para em aberto.');
        } elseif ($act === 'cancelar' && $c && $c['status'] === 'ABERTA') {
            if (mb_strlen(p('reason')) < 5) flash('bad', 'Informe o motivo do cancelamento.');
            else { update('charges', ['status' => 'CANCELADA'], 'id = ?', [$c['id']]); audit('charge.cancel', "Cobrança cancelada: {$s['name']} · {$c['description']}", 'charge', (int) $c['id'], p('reason')); flash('ok', 'Cobrança cancelada.'); }
        } elseif ($act === 'nova') {
            $amt = parse_money(p('amount')); $due = p('due_on');
            if (mb_strlen(p('description')) < 3 || !$amt || !valid_date($due)) flash('bad', 'Informe descrição, valor e vencimento.');
            else { $id = insert('charges', ['institution_id' => inst_id(), 'student_id' => $s['id'], 'description' => mb_substr(p('description'), 0, 160), 'competence' => substr($due, 0, 7), 'due_on' => $due, 'amount_cents' => $amt, 'status' => 'ABERTA', 'created_at' => now()]); audit('charge.create', "Cobrança avulsa: {$s['name']} · " . p('description') . ' · ' . money($amt), 'charge', $id); flash('ok', 'Cobrança criada.'); }
        }
        go('fin.aluno', ['id' => $s['id']]);
    }
    $list = rows('SELECT * FROM charges WHERE student_id = ? ORDER BY due_on DESC', [$s['id']]);
    $tr = '';
    foreach ($list as $c) {
        $st = charge_state($c, today());
        $act = '';
        if ($c['status'] === 'ABERTA') $act = '<details class="no-print"><summary class="btn btn-sm btn-primary" style="list-style:none">Registrar pagamento</summary><div class="card" style="position:absolute;right:20px;z-index:5;width:300px;padding:14px;margin-top:6px">'
            . form_open('fin.aluno', ['id' => $s['id']], 'class="form-grid"') . '<input type="hidden" name="acao" value="pagar"><input type="hidden" name="charge_id" value="' . $c['id'] . '">' . input('amount', 'Valor pago', number_format($c['amount_cents'] / 100, 2, ',', '.'), 'text', 'inputmode="decimal" required') . input('paid_on', 'Data', today(), 'date', 'required max="' . today() . '"')
            . select('method', 'Forma', ['PIX' => 'PIX', 'Boleto' => 'Boleto', 'Cartão' => 'Cartão', 'Dinheiro' => 'Dinheiro', 'Transferência' => 'Transferência'], 'PIX') . '<button class="btn btn-primary" type="submit">Confirmar</button></form>'
            . form_open('fin.aluno', ['id' => $s['id']], 'class="form-grid" style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px" data-confirm="Cancelar esta cobrança?"') . '<input type="hidden" name="acao" value="cancelar"><input type="hidden" name="charge_id" value="' . $c['id'] . '">' . input('reason', 'Motivo do cancelamento', '', 'text') . '<button class="btn btn-ghost btn-sm" type="submit">Cancelar cobrança</button></form></div></details>';
        elseif ($c['status'] === 'PAGA') $act = form_open('fin.aluno', ['id' => $s['id']], 'class="inline-form" data-confirm="Desfazer este pagamento? A cobrança volta para em aberto."') . '<input type="hidden" name="acao" value="estornar"><input type="hidden" name="charge_id" value="' . $c['id'] . '"><button class="btn btn-sm btn-ghost" type="submit">Desfazer pagamento</button></form>';
        $tr .= '<tr><td>' . h($c['description']) . '</td><td class="tnum">' . fmt_date($c['due_on']) . '</td><td class="r tnum">' . money((int) $c['amount_cents']) . '</td><td>' . badge(CHARGE_LABEL[$st], CHARGE_TONE[$st]) . '</td><td class="small">' . ($c['paid_on'] ? fmt_date($c['paid_on']) . ' · ' . money((int) $c['paid_cents']) . ' · ' . h($c['method']) : '') . '</td><td class="r" style="position:relative">' . $act . '</td></tr>';
    }
    $late = array_sum(array_map(fn($c) => charge_state($c, today()) === 'VENCIDA' ? $c['amount_cents'] : 0, $list));
    layout($s['name'], crumb('fin.situacao', 'Situação dos alunos') . page_head(h($s['name']), 'RA ' . h($s['ra']) . ' · ' . h($s['course_name']) . ' · mensalidade ' . money((int) $s['monthly_fee_cents']))
        . ($late ? alert('warn', 'Débito vencido: <strong>' . money($late) . '</strong>.') : alert('ok', 'Sem débitos vencidos.'))
        . '<div class="grid g2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start;margin-top:16px"><div class="card"><div class="table-wrap"><table><thead><tr><th>Descrição</th><th>Vencimento</th><th class="r">Valor</th><th>Situação</th><th>Pagamento</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="6">' . empty_state('receipt', 'Nenhuma cobrança') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Cobrança avulsa</h3><p>Taxa, segunda via, acordo.</p></div></div><div class="card-body">' . form_open('fin.aluno', ['id' => $s['id']], 'class="form-grid"') . '<input type="hidden" name="acao" value="nova">' . input('description', 'Descrição', '', 'text', 'required maxlength="160"') . input('amount', 'Valor (R$)', '', 'text', 'inputmode="decimal" required') . input('due_on', 'Vencimento', today(), 'date', 'required') . '<button class="btn btn-secondary" type="submit">Criar cobrança</button></form></div></div></div>');
}

function route_fin__notificar(): void {
    require_perm('finance:manage');
    $debt = rows("SELECT s.id, u.name, u.email, SUM(c.amount_cents) AS v, COUNT(*) AS n, MIN(c.due_on) AS oldest FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.institution_id = ? AND c.status = 'ABERTA' AND c.due_on < ? GROUP BY s.id, u.name, u.email ORDER BY u.name", [inst_id(), today()]);
    $default = "Olá, {nome}!\n\nConsta em aberto no seu cadastro o valor de {valor}, referente a {parcelas} mensalidade(s), a mais antiga com vencimento em {vencimento}.\n\nSe você já pagou, desconsidere esta mensagem. Para negociar, procure o setor financeiro.\n\n" . institution()['short_name'];
    if (is_post()) {
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $text = (string) ($_POST['text'] ?? $default);
        if (!mail_configured()) { flash('bad', 'O envio de e-mail não está configurado (Painel de controle › Instituição e e-mail).'); go('fin.notificar'); }
        $ok = 0; $fail = 0;
        foreach ($debt as $d) if (in_array((int) $d['id'], $ids, true)) {
            $body = strtr($text, ['{nome}' => first_name($d['name']), '{valor}' => money((int) $d['v']), '{parcelas}' => $d['n'], '{vencimento}' => fmt_date($d['oldest'])]);
            [$sent] = send_mail($d['email'], 'Mensalidades em aberto · ' . institution()['short_name'], $body);
            $sent ? $ok++ : $fail++;
        }
        audit('charge.notify', "Aviso de inadimplência enviado a $ok alunos" . ($fail ? " ($fail falhas)" : ''));
        flash($fail ? 'warn' : 'ok', "Avisos enviados: $ok." . ($fail ? " Falharam: $fail." : ''));
        go('fin.notificar');
    }
    $tr = ''; foreach ($debt as $d) $tr .= '<tr><td><label class="row" style="gap:8px"><input type="checkbox" name="ids[]" value="' . $d['id'] . '" checked> ' . h($d['name']) . '</label><p class="cell-sub">' . h($d['email']) . '</p></td><td class="tnum">' . $d['n'] . '</td><td class="tnum">' . fmt_date($d['oldest']) . '</td><td class="r tnum">' . money((int) $d['v']) . '</td></tr>';
    layout('Avisar inadimplentes', fin_students_tabs('avisar') . page_head('Avisar inadimplentes', 'Envia um e-mail para cada aluno com mensalidade vencida.') . (mail_configured() ? '' : alert('warn', 'O envio de e-mail ainda não está configurado. Configure em Painel de controle › Instituição e e-mail.'))
        . form_open('fin.notificar', [], 'class="grid g2" style="align-items:start" data-confirm="Enviar os e-mails para os alunos marcados?"') . '<div class="card"><div class="table-wrap"><table><thead><tr><th>Aluno</th><th>Parcelas</th><th>Mais antiga</th><th class="r">Total</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="4">' . empty_state('circle-check', 'Nenhum aluno inadimplente') . '</td></tr>') . '</tbody></table></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Mensagem</h3><p>Use {nome}, {valor}, {parcelas} e {vencimento}.</p></div></div><div class="card-body stack"><textarea class="input" name="text" rows="12" aria-label="Mensagem">' . h($default) . '</textarea><button class="btn btn-primary" type="submit"' . ($debt && mail_configured() ? '' : ' disabled') . '>' . icon('send') . ' Enviar e-mails</button></div></div></form>');
}

// ---------- Indicadores institucionais ----------
function institutional_indicators(string $studentRoute): string {
    $i = inst_id(); $term = active_term();
    $active = (int) val("SELECT COUNT(*) FROM students s JOIN users u ON u.id = s.user_id WHERE u.institution_id = ? AND s.status = 'ATIVO'", [$i]);
    $profs = (int) val("SELECT COUNT(*) FROM professors p JOIN users u ON u.id = p.user_id WHERE (u.institution_id = ? OR EXISTS (SELECT 1 FROM user_institutions ui WHERE ui.user_id = u.id AND ui.institution_id = ?)) AND u.status = 'ACTIVE'", [$i, $i]);
    $last = row('SELECT * FROM terms WHERE institution_id = ? AND closed = 1 ORDER BY ends_on DESC LIMIT 1', [$i]);
    $appr = $last ? row("SELECT SUM(CASE WHEN e.status = 'APROVADO' THEN 1 ELSE 0 END) AS ok, COUNT(*) AS n FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE se.term_id = ? AND e.status IN ('APROVADO','REPROVADO','REP_FREQ')", [$last['id']]) : null;
    $att = $term ? val('SELECT AVG(present) * 100 FROM attendance a JOIN lessons l ON l.id = a.lesson_id JOIN sections se ON se.id = l.section_id WHERE se.term_id = ?', [$term['id']]) : null;
    $courses = rows("SELECT c.id, c.code, c.name, (SELECT COUNT(*) FROM students s WHERE s.course_id = c.id AND s.status = 'ATIVO') AS students, (SELECT COUNT(*) FROM class_groups g WHERE g.course_id = c.id AND g.term_id = ?) AS groups_ FROM courses c WHERE c.institution_id = ? ORDER BY c.name", [$term['id'] ?? 0, $i]);
    $max = max(1, ...array_map(fn($c) => (int) $c['students'], $courses ?: [['students' => 1]]));
    $tr = ''; foreach ($courses as $c) $tr .= '<tr><td>' . h($c['name']) . '<p class="cell-sub">' . h($c['code']) . '</p></td><td><div class="bar ok" style="width:160px"><span style="width:' . round($c['students'] * 100 / $max) . '%"></span></div></td><td class="tnum r">' . $c['students'] . '</td><td class="tnum r">' . $c['groups_'] . '</td><td class="r"><a class="btn btn-sm btn-ghost" href="' . h(url($studentRoute, ['curso' => $c['id']])) . '">Ver alunos</a></td></tr>';
    // Alunos em risco no período (média ou frequência abaixo do mínimo)
    $risk = 0; $checked = 0;
    if ($term) foreach (rows("SELECT e.*, se.id AS sid FROM enrollments e JOIN sections se ON se.id = e.section_id WHERE se.term_id = ? AND e.status = 'CURSANDO' LIMIT 3000", [$term['id']]) as $e) { static $cache = []; $sec = $cache[$e['sid']] ??= section_full((int) $e['sid']); $checked++; if (enrollment_result($e, $sec)['risk']) $risk++; }
    return '<div class="grid g4" style="margin-bottom:16px">' . stat_card('Alunos ativos', (string) $active, 'users') . stat_card('Professores', (string) $profs, 'users-round') . stat_card('Frequência média no período', $att === null ? '—' : num((float) $att) . '%', 'calendar-check')
        . stat_card('Aprovação em ' . h($last['name'] ?? '—'), $appr && $appr['n'] ? num($appr['ok'] * 100 / $appr['n']) . '%' : '—', 'circle-check') . '</div>'
        . ($risk ? alert('warn', plural($risk, 'matrícula está', 'matrículas estão') . ' abaixo da média ou da frequência mínima no período ' . h($term['name']) . ' (de ' . $checked . ').', 'Atenção') : '')
        . '<div class="card" style="margin-top:16px"><div class="card-head"><div><h3>Alunos por curso</h3><p>Período ' . h($term['name'] ?? '—') . '.</p></div></div><div class="table-wrap"><table><thead><tr><th>Curso</th><th></th><th class="r">Alunos ativos</th><th class="r">Turmas</th><th></th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="5">' . empty_state('graduation-cap', 'Nenhum curso') . '</td></tr>') . '</tbody></table></div></div>';
}
function route_admin__indicadores(): void { require_perm('reports:institutional', 'students:manage'); layout('Indicadores', page_head('Indicadores', 'Visão geral do período letivo ' . h(active_term()['name'] ?? '') . '.') . institutional_indicators(can('students:manage') ? 'admin.alunos' : 'dir.alunos')); }
function route_dir__indicadores(): void { require_perm('reports:institutional'); layout('Indicadores', page_head('Indicadores', 'Visão institucional do período letivo ' . h(active_term()['name'] ?? '') . '.') . institutional_indicators('dir.alunos')); }
