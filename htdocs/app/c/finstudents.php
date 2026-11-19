<?php
// Financeiro › Alunos (painel + submenus) e Financeiro › Exportar (Excel para ERP, ex.: Omie).

function fin_students_tabs(string $active): string {
    return tabs([
        ['painel', 'Painel', 'layout-dashboard', url('fin.alunos')],
        ['situacao', 'Situação dos alunos', 'users', url('fin.situacao')],
        ['cobrancas', 'Cobranças', 'receipt', url('fin.cobrancas')],
        ['avisar', 'Avisar inadimplentes', 'mail', url('fin.notificar')],
    ], $active, 'Seção Alunos');
}

/** Painel de alunos do Financeiro */
function route_fin__alunos(): void {
    require_perm('finance:manage');
    $i = inst_id(); $t = today(); $m0 = date('Y-m-01'); $m1 = date('Y-m-t'); $in30 = date('Y-m-d', strtotime('+30 days'));
    $active = rows("SELECT s.id, s.monthly_fee_cents, s.course_id FROM students s JOIN users u ON u.id = s.user_id WHERE u.institution_id = ? AND s.status = 'ATIVO'", [$i]);
    $nActive = count($active);
    $late = rows("SELECT c.student_id, c.amount_cents, c.due_on FROM charges c WHERE c.institution_id = ? AND c.status = 'ABERTA' AND c.due_on < ?", [$i, $t]);
    $lateBy = []; foreach ($late as $c) $lateBy[$c['student_id']] = ($lateBy[$c['student_id']] ?? 0) + (int) $c['amount_cents'];
    $activeIds = array_flip(array_column($active, 'id'));
    $debtors = count(array_intersect_key($lateBy, $activeIds));
    $lateTotal = array_sum(array_column($late, 'amount_cents'));
    $received = (int) val("SELECT COALESCE(SUM(paid_cents),0) FROM charges WHERE institution_id = ? AND status = 'PAGA' AND paid_on >= ? AND paid_on <= ?", [$i, $m0, $m1]);
    $expected = (int) val("SELECT COALESCE(SUM(amount_cents),0) FROM charges WHERE institution_id = ? AND competence = ? AND status <> 'CANCELADA'", [$i, date('Y-m')]);
    $next30 = row("SELECT COUNT(*) AS n, COALESCE(SUM(amount_cents),0) AS v FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on >= ? AND due_on <= ?", [$i, $t, $in30]);
    $fees = array_filter(array_map(fn($s) => (int) $s['monthly_fee_cents'], $active));
    $avgFee = $fees ? intdiv(array_sum($fees), count($fees)) : 0;
    $rate = $nActive ? $debtors * 100 / $nActive : 0;
    $real = $expected ? $received * 100 / $expected : 0;

    // Faixas de atraso
    $aging = ['1 a 30 dias' => [0, 0, []], '31 a 60 dias' => [0, 0, []], '61 a 90 dias' => [0, 0, []], 'Mais de 90 dias' => [0, 0, []]];
    foreach ($late as $c) {
        $d = (int) ((strtotime($t) - strtotime($c['due_on'])) / 86400);
        $k = $d <= 30 ? '1 a 30 dias' : ($d <= 60 ? '31 a 60 dias' : ($d <= 90 ? '61 a 90 dias' : 'Mais de 90 dias'));
        $aging[$k][0]++; $aging[$k][1] += (int) $c['amount_cents']; $aging[$k][2][$c['student_id']] = 1;
    }
    $maxAg = max(1, ...array_values(array_map(fn($a) => $a[1], $aging)));
    $ag = '';
    foreach ($aging as $l => [$n, $v, $st]) $ag .= '<tr><td class="nowrap">' . $l . '<p class="cell-sub">' . plural(count($st), 'aluno', 'alunos') . ' · ' . plural($n, 'parcela', 'parcelas') . '</p></td><td style="width:34%;min-width:50px"><div class="bar" style="height:8px;background:var(--gray)"><span style="width:' . round($v * 100 / $maxAg) . '%;background:var(--red)"></span></div></td><td class="r tnum">' . ($v ? money($v) : '—') . '</td></tr>';

    // Por curso
    $courses = rows('SELECT id, code, name FROM courses WHERE institution_id = ? ORDER BY name', [$i]);
    $byCourse = [];
    foreach ($active as $s) { $c = $s['course_id']; $byCourse[$c] ??= [0, 0, 0]; $byCourse[$c][0]++; if (isset($lateBy[$s['id']])) { $byCourse[$c][1]++; $byCourse[$c][2] += $lateBy[$s['id']]; } }
    $ct = '';
    foreach ($courses as $c) if (isset($byCourse[$c['id']])) { [$n, $d, $v] = $byCourse[$c['id']]; $pc = $n ? $d * 100 / $n : 0;
        $ct .= '<tr><td><strong>' . h($c['code']) . '</strong><p class="cell-sub">' . h($c['name']) . '</p></td><td class="r tnum">' . $d . ' de ' . $n . '<p class="cell-sub">' . num($pc) . '%</p></td><td class="r tnum">' . ($v ? money($v) : '—') . '</td></tr>'; }

    // Maiores débitos, próximos vencimentos e últimos pagamentos
    $top = rows("SELECT s.id, u.name, s.ra, SUM(c.amount_cents) AS v, COUNT(*) AS n, MIN(c.due_on) AS oldest FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.institution_id = ? AND c.status = 'ABERTA' AND c.due_on < ? GROUP BY s.id, u.name, s.ra ORDER BY v DESC LIMIT 6", [$i, $t]);
    $tt = ''; foreach ($top as $d) $tt .= '<tr class="clickable" data-href="' . h(url('fin.aluno', ['id' => $d['id']])) . '"><td>' . h($d['name']) . '<p class="cell-sub">RA ' . h($d['ra']) . ' · ' . plural((int) $d['n'], 'parcela', 'parcelas') . ' desde ' . fmt_date($d['oldest']) . '</p></td><td class="r tnum"><strong>' . money((int) $d['v']) . '</strong></td></tr>';
    $soon = rows("SELECT c.student_id, c.due_on, c.amount_cents, c.description, u.name FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.institution_id = ? AND c.status = 'ABERTA' AND c.due_on >= ? AND c.due_on <= ? ORDER BY c.due_on, u.name LIMIT 6", [$i, $t, date('Y-m-d', strtotime('+7 days'))]);
    $st = ''; foreach ($soon as $c) $st .= '<tr class="clickable" data-href="' . h(url('fin.aluno', ['id' => $c['student_id']])) . '"><td>' . h($c['name']) . '<p class="cell-sub">' . h($c['description']) . '</p></td><td class="tnum">' . fmt_date($c['due_on']) . '</td><td class="r tnum">' . money((int) $c['amount_cents']) . '</td></tr>';
    $paid = rows("SELECT c.student_id, c.paid_on, c.paid_cents, c.method, u.name FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id WHERE c.institution_id = ? AND c.status = 'PAGA' ORDER BY c.paid_on DESC, c.id DESC LIMIT 6", [$i]);
    $pt = ''; foreach ($paid as $c) $pt .= '<tr class="clickable" data-href="' . h(url('fin.aluno', ['id' => $c['student_id']])) . '"><td>' . h($c['name']) . '<p class="cell-sub">' . h($c['method'] ?: '—') . '</p></td><td class="tnum">' . fmt_date($c['paid_on']) . '</td><td class="r tnum">' . money((int) $c['paid_cents']) . '</td></tr>';

    $card = fn(string $title, string $desc, string $body, string $action = '') => '<div class="card"><div class="card-head"><div><h3>' . $title . '</h3>' . ($desc ? '<p>' . $desc . '</p>' : '') . '</div>' . $action . '</div>' . $body . '</div>';
    $table = fn(string $head, string $rows, string $empty, string $ic) => '<div class="table-wrap"><table><thead><tr>' . $head . '</tr></thead><tbody>' . ($rows ?: '<tr><td colspan="9">' . empty_state($ic, $empty) . '</td></tr>') . '</tbody></table></div>';
    layout('Alunos', fin_students_tabs('painel') . page_head('Alunos', 'Situação financeira dos alunos ativos em ' . fmt_date($t) . '.')
        . '<div class="grid g4 pay-stats" style="margin-bottom:16px">'
        . stat_card('Alunos ativos', (string) $nActive, 'users', '', plural($nActive - $debtors, 'em dia', 'em dia'))
        . stat_card('Inadimplentes', (string) $debtors, 'user-x', $rate > 10 ? 'warn' : '', num($rate) . '% dos ativos')
        . stat_card('Vencido em aberto', money($lateTotal), 'triangle-alert', $lateTotal ? 'warn' : '', plural(count($late), 'parcela', 'parcelas'))
        . stat_card('Mensalidade média', money($avgFee), 'receipt', '', 'Alunos ativos com mensalidade')
        . '</div><div class="grid g4 pay-stats" style="margin-bottom:16px">'
        . stat_card('Previsto no mês', money($expected), 'calendar-range', '', 'Competência ' . month_label(date('Y-m')))
        . stat_card('Recebido no mês', money($received), 'circle-check', '', $expected ? num($real) . '% do previsto' : '')
        . stat_card('A receber em 30 dias', money((int) $next30['v']), 'calendar-clock', '', plural((int) $next30['n'], 'parcela', 'parcelas'))
        . stat_card('Ainda a receber no mês', money(max(0, $expected - $received)), 'hourglass')
        . '</div>'
        . '<div class="grid g2 fin-dash">'
        . $card('Inadimplência por curso', 'Alunos ativos com parcela vencida.', $table('<th>Curso</th><th class="r">Inadimplentes</th><th class="r">Vencido</th>', $ct, 'Nenhum aluno ativo', 'graduation-cap'))
        . $card('Tempo de atraso', 'Parcelas vencidas por faixa de dias em atraso.', $table('<th>Faixa</th><th></th><th class="r">Valor</th>', $ag, '', 'clock'))
        . $card('Maiores débitos', 'Clique para abrir o aluno.', $table('<th>Aluno</th><th class="r">Vencido</th>', $tt, 'Nenhum aluno em atraso', 'circle-check'), '<a class="btn btn-sm btn-secondary" href="' . h(url('fin.notificar')) . '">' . icon('mail') . ' Avisar</a>')
        . $card('Vencem nos próximos 7 dias', '', $table('<th>Aluno</th><th>Vencimento</th><th class="r">Valor</th>', $st, 'Nenhum vencimento nos próximos dias', 'calendar-check'))
        . $card('Últimos pagamentos', '', $table('<th>Aluno</th><th>Pago em</th><th class="r">Valor</th>', $pt, 'Nenhum pagamento registrado', 'receipt'))
        . $card('Atalhos', '', '<div class="card-body fin-shortcuts">'
            . '<a class="btn btn-secondary" href="' . h(url('fin.situacao', ['situacao' => 'inadimplentes'])) . '">' . icon('user-x') . ' Ver inadimplentes</a>'
            . '<a class="btn btn-secondary" href="' . h(url('fin.cobrancas', ['situacao' => 'VENCIDA'])) . '">' . icon('triangle-alert') . ' Cobranças vencidas</a>'
            . '<a class="btn btn-secondary" href="' . h(url('fin.cobrancas')) . '">' . icon('receipt') . ' Gerar mensalidades</a>'
            . '<a class="btn btn-secondary" href="' . h(url('fin.exportar')) . '">' . icon('file-spreadsheet') . ' Exportar para Excel</a></div>')
        . '</div>');
}

// ---------------- Exportar ----------------
const EXPORT_BASE = ['vencimento' => 'Vencimento (contas a receber)', 'pagamento' => 'Data do pagamento (recebimentos)', 'competencia' => 'Competência (mês de referência)'];
const EXPORT_SIT = ['todas' => 'Todas, exceto canceladas', 'abertas' => 'Só em aberto (inclui vencidas)', 'pagas' => 'Só pagas'];

function export_query(string $from, string $to, string $base, string $sit): array {
    $w = ['c.institution_id = ?', "c.status <> 'CANCELADA'"]; $p = [inst_id()];
    if ($base === 'pagamento') { $w[] = "c.status = 'PAGA' AND c.paid_on >= ? AND c.paid_on <= ?"; array_push($p, $from, $to); }
    elseif ($base === 'competencia') { $w[] = 'c.competence >= ? AND c.competence <= ?'; array_push($p, substr($from, 0, 7), substr($to, 0, 7)); }
    else { $w[] = 'c.due_on >= ? AND c.due_on <= ?'; array_push($p, $from, $to); }
    if ($sit === 'abertas') $w[] = "c.status = 'ABERTA'"; elseif ($sit === 'pagas') $w[] = "c.status = 'PAGA'";
    return rows('SELECT c.*, u.name, u.email, u.phone, s.ra, s.cpf, s.address, s.guardian, co.code AS course, co.name AS course_name
        FROM charges c JOIN students s ON s.id = c.student_id JOIN users u ON u.id = s.user_id JOIN courses co ON co.id = s.course_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . ($base === 'pagamento' ? 'c.paid_on' : 'c.due_on') . ', u.name', $p);
}
function export_options(): array {
    return [
        'base' => isset(EXPORT_BASE[g('base')]) ? g('base') : 'vencimento',
        'sit' => isset(EXPORT_SIT[g('situacao')]) ? g('situacao') : 'todas',
        'categoria' => trim(g('categoria')) !== '' ? mb_substr(trim(g('categoria')), 0, 80) : (string) setting('export_category', 'Mensalidades'),
        'conta' => trim(g('conta')) !== '' ? mb_substr(trim(g('conta')), 0, 80) : (string) setting('export_account', ''),
        'cliente' => in_array(g('cliente'), ['cpf', 'nome'], true) ? g('cliente') : (string) setting('export_client', 'cpf'),
    ];
}
function export_client(array $c, string $mode): string { return $mode === 'cpf' && !empty($c['cpf']) ? (string) $c['cpf'] : (string) $c['name']; }

function route_fin__exportar(): void {
    $u = require_perm('finance:manage');
    [$from, $to] = payroll_period();
    $o = export_options();
    $list = export_query($from, $to, $o['base'], $o['sit']);
    if (g('baixar') === '1') {
        require_once __DIR__ . '/../xlsx.php';
        set_setting('export_category', $o['categoria']); set_setting('export_account', $o['conta']); set_setting('export_client', $o['cliente']);
        $inst = institution();
        $omie = []; $rec = []; $cli = []; $noCpf = 0;
        foreach ($list as $c) {
            $client = export_client($c, $o['cliente']); if ($o['cliente'] === 'cpf' && empty($c['cpf'])) $noCpf++;
            $obs = $c['description'] . ' · Aluno: ' . $c['name'] . ' · RA ' . $c['ra'] . ' · ' . $c['course'] . ($c['status'] === 'PAGA' ? ' · Pago em ' . fmt_date($c['paid_on']) . ($c['method'] ? ' (' . $c['method'] . ')' : '') : '');
            $omie[] = [$client, $o['categoria'], $o['conta'], (int) $c['amount_cents'], $c['competence'] . '-01', $c['due_on'], $c['due_on'], '001/001', 'MENS-' . $c['id'], $obs];
            if ($c['status'] === 'PAGA') $rec[] = [$client, $c['name'], $c['ra'], $c['description'], $c['due_on'], (int) $c['amount_cents'], $c['paid_on'], (int) $c['paid_cents'], (int) $c['paid_cents'] - (int) $c['amount_cents'], $c['method'] ?: '', $o['conta'], 'MENS-' . $c['id']];
            $cli[$c['student_id']] = [$c['name'], $c['name'], $c['cpf'] ?? '', $c['email'], $c['phone'] ?? '', $c['address'] ?? '', 'RA ' . $c['ra'] . ' · ' . $c['course_name'] . ($c['guardian'] ? ' · Responsável: ' . $c['guardian'] : '')];
        }
        $all = array_sum(array_column($list, 'amount_cents'));
        $pd = array_sum(array_map(fn($c) => $c['status'] === 'PAGA' ? (int) $c['paid_cents'] : 0, $list));
        $sheets = [
            ['name' => 'Contas a Receber (Omie)', 'cols' => [['Cliente', 34], ['Categoria', 20], ['Conta Corrente', 22], ['Valor da Conta', 15, 'money'], ['Data de Registro', 16, 'date'], ['Data de Vencimento', 18, 'date'], ['Data de Previsão', 16, 'date'], ['Parcela', 10], ['Código de Integração', 20], ['Observação', 70]], 'rows' => $omie],
            ['name' => 'Recebimentos', 'cols' => [['Cliente', 34], ['Aluno', 30], ['RA', 12], ['Descrição', 26], ['Vencimento', 13, 'date'], ['Valor da Conta', 15, 'money'], ['Data do Pagamento', 18, 'date'], ['Valor Recebido', 15, 'money'], ['Juros/Desconto', 15, 'money'], ['Forma de Pagamento', 20], ['Conta Corrente', 22], ['Código de Integração', 20]], 'rows' => $rec],
            ['name' => 'Clientes (Omie)', 'cols' => [['Razão Social / Nome Completo', 34], ['Nome Fantasia / Nome Abreviado', 30], ['CNPJ / CPF', 16], ['E-mail', 32], ['Telefone', 16], ['Endereço', 40], ['Observação', 50]], 'rows' => array_values($cli)],
            ['name' => 'Resumo', 'nofreeze' => 1, 'nofilter' => 1, 'cols' => [['Exportação financeira', 34], ['', 60]], 'rows' => [
                ['Instituição', $inst['name']], ['Período', fmt_date($from) . ' a ' . fmt_date($to)], ['Considerando a data de', EXPORT_BASE[$o['base']]], ['Situação', EXPORT_SIT[$o['sit']]],
                ['Categoria (Omie)', $o['categoria']], ['Conta corrente (Omie)', $o['conta'] ?: '(preencher no Omie)'], ['Cliente identificado por', $o['cliente'] === 'cpf' ? 'CPF (nome quando o aluno não tem CPF)' : 'Nome completo'],
                ['Contas exportadas', (string) count($list)], ['Valor total das contas', 'R$ ' . number_format($all / 100, 2, ',', '.')], ['Valor recebido', 'R$ ' . number_format($pd / 100, 2, ',', '.')],
                ['Gerado em', date('d/m/Y H:i') . ' por ' . $u['name']], ['', ''],
                ['Como importar no Omie', ''],
                ['1. Clientes', 'Os clientes precisam existir no Omie antes. Se algum aluno ainda não estiver cadastrado, importe a aba "Clientes (Omie)" pela planilha padrão de clientes do Omie.'],
                ['2. Contas a receber', 'No Omie: Finanças › Contas a Receber › Importar Planilha. Baixe a planilha padrão (versão mais recente) e cole as colunas da aba "Contas a Receber (Omie)" nas colunas de mesmo nome.'],
                ['3. Conferência', 'Categoria e Conta Corrente precisam estar escritas exatamente como no cadastro do Omie. Valores e datas já estão no formato do Excel. A Data de Registro é o 1º dia do mês de competência da mensalidade.'],
                ['4. Baixas', 'A aba "Recebimentos" traz as parcelas pagas no período, para dar baixa ou conferir a conciliação bancária.'],
            ]],
        ];
        audit('finance.export', 'Exportação para Excel: ' . plural(count($list), 'conta', 'contas') . ' de ' . fmt_date($from) . ' a ' . fmt_date($to) . ' (' . EXPORT_BASE[$o['base']] . ')');
        xlsx_download($sheets, 'financeiro-' . (trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) ($inst['short_name'] ?? '')))), '-') ?: 'instituicao') . '-' . str_replace('-', '', $from) . '-a-' . str_replace('-', '', $to) . '.xlsx');
    }
    // Prévia
    $all = array_sum(array_column($list, 'amount_cents'));
    $pd = array_sum(array_map(fn($c) => $c['status'] === 'PAGA' ? (int) $c['paid_cents'] : 0, $list));
    $noCpf = count(array_unique(array_column(array_filter($list, fn($c) => empty($c['cpf'])), 'student_id')));
    $tr = '';
    foreach (array_slice($list, 0, 8) as $c) { $stt = charge_state($c, today()); $tr .= '<tr><td>' . h(export_client($c, $o['cliente'])) . '<p class="cell-sub">' . h($c['name']) . ' · RA ' . h($c['ra']) . '</p></td><td class="r tnum">' . money((int) $c['amount_cents']) . '<p class="cell-sub">vence ' . fmt_date($c['due_on']) . '</p><p class="cell-sub">' . badge(CHARGE_LABEL[$stt], CHARGE_TONE[$stt]) . '</p></td></tr>'; }
    $keep = ['base' => $o['base'], 'situacao' => $o['sit'], 'categoria' => $o['categoria'], 'conta' => $o['conta'], 'cliente' => $o['cliente']];
    $form = '<form method="get" action="index.php" class="card export-form" data-no-loader><input type="hidden" name="r" value="fin.exportar">'
        . '<div class="card-head"><div><h3>O que exportar</h3><p>Receitas de mensalidades no período escolhido.</p></div></div><div class="card-body form-grid cols-2">'
        . '<input type="hidden" name="de" value="' . h($from) . '"><input type="hidden" name="ate" value="' . h($to) . '">'
        . '<div class="span-2 export-period">' . icon('calendar-range') . ' Período: <strong>' . fmt_date($from) . ' a ' . fmt_date($to) . '</strong> <span class="muted small">(altere no topo da página)</span></div>'
        . select('base', 'Considerar a data de', EXPORT_BASE, $o['base']) . select('situacao', 'Situação das parcelas', EXPORT_SIT, $o['sit'])
        . '<div class="span-2 export-sep"><strong>Campos do Omie</strong><span class="small muted">Escreva exatamente como estão cadastrados no Omie. Ficam guardados para a próxima exportação.</span></div>'
        . input('categoria', 'Categoria', $o['categoria'], 'text', 'maxlength="80" required', 'Ex.: Mensalidades, Receita de Serviços.')
        . input('conta', 'Conta Corrente', $o['conta'], 'text', 'maxlength="80" placeholder="Nome da conta no Omie"', 'O "Nome da Conta" do cadastro de contas correntes.')
        . select('cliente', 'Identificar o cliente por', ['cpf' => 'CPF do aluno', 'nome' => 'Nome completo'], $o['cliente'], '', 'O cliente precisa estar cadastrado no Omie.')
        . '<div class="field export-actions"><span class="label-like">&nbsp;</span><div class="row" style="gap:8px;flex-wrap:wrap"><button class="btn btn-secondary" type="submit">' . icon('eye') . ' Atualizar prévia</button>'
        . '<button class="btn btn-primary" type="submit" name="baixar" value="1"' . ($list ? '' : ' disabled') . '>' . icon('download') . ' Baixar Excel (.xlsx)</button></div></div>'
        . '</div></form>';
    $chipsBase = payroll_period_bar('fin.exportar', $keep, $from, $to);
    layout('Exportar', page_head('Exportar', 'Planilha Excel com as receitas do período, organizada para importar em ERP (ex.: Omie).')
        . $chipsBase
        . '<div class="grid g4 pay-stats" style="margin:16px 0">' . stat_card('Contas no arquivo', (string) count($list), 'file-spreadsheet', '', plural(count(array_unique(array_column($list, 'student_id'))), 'aluno', 'alunos'))
        . stat_card('Valor das contas', money($all), 'wallet') . stat_card('Recebido', money($pd), 'circle-check') . stat_card('Em aberto', money($all - array_sum(array_map(fn($c) => $c['status'] === 'PAGA' ? (int) $c['amount_cents'] : 0, $list))), 'hourglass') . '</div>'
        . ($o['cliente'] === 'cpf' && $noCpf ? alert('warn', plural($noCpf, 'aluno está', 'alunos estão') . ' sem CPF no cadastro. Nesses casos a planilha usa o nome completo; confira se o cliente está com o mesmo nome no Omie.') : '')
        . '<div class="grid g2 export-grid">' . $form
        . '<div class="card"><div class="card-head"><div><h3>O arquivo terá 4 abas</h3></div></div><div class="card-body"><ul class="export-tabs">'
        . '<li><strong>Contas a Receber (Omie)</strong><span>Cliente, Categoria, Conta Corrente, Valor da Conta, Data de Registro, Data de Vencimento, Data de Previsão, Parcela, Código de Integração e Observação. Cole na planilha padrão do Omie.</span></li>'
        . '<li><strong>Recebimentos</strong><span>Parcelas pagas: data do pagamento, valor recebido, juros/desconto e forma de pagamento, para baixa e conciliação.</span></li>'
        . '<li><strong>Clientes (Omie)</strong><span>Nome, CPF, e-mail, telefone e endereço dos alunos do arquivo, para cadastrar quem ainda não está no Omie.</span></li>'
        . '<li><strong>Resumo</strong><span>Filtros usados, totais e o passo a passo da importação.</span></li></ul></div></div></div>'
        . '<div class="card export-preview" style="margin-top:16px"><div class="card-head"><div><h3>Prévia</h3><p>' . ($list ? 'Primeiras ' . min(8, count($list)) . ' de ' . count($list) . ' linhas da aba Contas a Receber · Categoria: ' . h($o['categoria']) . ' · Conta Corrente: ' . h($o['conta'] ?: '(em branco)') . '.' : 'Nenhuma conta neste período com esses filtros.') . '</p></div></div>'
        . '<div class="table-wrap"><table><thead><tr><th>Cliente</th><th class="r">Valor</th></tr></thead><tbody>' . ($tr ?: '<tr><td colspan="2">' . empty_state('file-x', 'Nada para exportar', 'Escolha outro período ou situação.') . '</td></tr>') . '</tbody></table></div></div>');
}
