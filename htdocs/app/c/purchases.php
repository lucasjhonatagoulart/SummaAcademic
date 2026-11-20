<?php
// Compras: solicitações, cotações (até 3), aprovação única, compra, recebimento e envio dos itens patrimoniáveis ao Patrimônio.
// Fluxo: Rascunho → Enviada → Em cotação → Aguardando aprovação → Aprovada → Comprada → Recebida (ou Recusada / Cancelada).

/** Quem vê todas as solicitações da instituição; os demais veem só as próprias */
function pc_sees_all(): bool { return can('purchase:manage', 'purchase:approve', 'purchase:read', 'purchase:admin'); }

function pc_badge(string $status): string { [$l, $c] = PURCHASE_STATUS[$status] ?? [$status, 'b-neutral']; return badge(h($l), $c); }

/** Solicitação da instituição em uso que o usuário pode ver; senão 404 */
function pc_find(int $id): array {
    $u = current_user();
    $r = row('SELECT r.*, u.name AS requester_name, u.email AS requester_email, d.name AS decider_name
        FROM purchase_requests r JOIN users u ON u.id = r.requester_id LEFT JOIN users d ON d.id = r.decided_by WHERE r.id = ? AND r.institution_id = ?', [$id, inst_id()]);
    if (!$r || ((int) $r['requester_id'] !== (int) $u['id'] && (!pc_sees_all() || $r['status'] === 'RASCUNHO'))) abort(404, 'Solicitação não encontrada.');
    return $r;
}
function pc_items(int $id): array { return rows('SELECT * FROM purchase_items WHERE request_id = ? ORDER BY position, id', [$id]); }
function pc_quotes(int $id): array {
    $qs = rows('SELECT * FROM purchase_quotes WHERE request_id = ? ORDER BY id', [$id]);
    $prices = [];
    foreach (rows('SELECT p.* FROM purchase_quote_prices p JOIN purchase_quotes q ON q.id = p.quote_id WHERE q.request_id = ?', [$id]) as $p) $prices[$p['quote_id']][$p['item_id']] = (int) $p['unit_cents'];
    foreach ($qs as &$q) $q['prices'] = $prices[$q['id']] ?? [];
    return $qs;
}
/** Registra no histórico; $from: etapa anterior, quando a ação muda a etapa */
function pc_event(int $id, string $action, ?string $note = null, ?string $from = null): void {
    insert('purchase_events', ['request_id' => $id, 'actor_id' => current_user()['id'], 'action' => $action, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'from_status' => $from, 'created_at' => now()]);
    update('purchase_requests', ['updated_at' => now()], 'id = ?', [$id]);
}
const PC_EVENT_LABEL = [
    'criada' => 'Salvou o rascunho', 'enviada' => 'Enviou para a direção', 'pedido_cotacao' => 'Pediu cotação de preços', 'aprovada_direto' => 'Assinou e aprovou sem cotação',
    'revertida' => 'Voltou a etapa', 'cotacao' => 'Registrou a cotação', 'cotacao_editada' => 'Alterou a cotação', 'cotacao_excluida' => 'Excluiu a cotação',
    'para_aprovacao' => 'Enviou as cotações para a direção', 'aprovada' => 'Assinou e aprovou a compra', 'recusada' => 'Recusou a compra', 'comprada' => 'Registrou a compra',
    'recebida' => 'Registrou o recebimento', 'patrimonio' => 'Enviou ao Patrimônio', 'patrimonio_nao' => 'Decidiu não enviar ao Patrimônio agora', 'cancelada' => 'Cancelou a solicitação',
];

/** Valor que melhor representa a solicitação na etapa em que está */
function pc_value(array $r): string {
    if ($r['approved_cents'] !== null) return money((int) $r['approved_cents']);
    if (!empty($r['min_quote'])) return money((int) $r['min_quote']) . '<span class="cell-sub"> menor cotação</span>';
    if (!empty($r['est_cents'])) return money((int) $r['est_cents']) . '<span class="cell-sub"> estimado</span>';
    return '—';
}
/** Lista com os totais usados nas telas; $f: etapa, q, limite */
function pc_list(array $f = []): array {
    $u = current_user();
    $w = ['r.institution_id = ?', "(r.status <> 'RASCUNHO' OR r.requester_id = ?)"]; $p = [inst_id(), $u['id']];
    if (!pc_sees_all()) { $w[] = 'r.requester_id = ?'; $p[] = $u['id']; }
    if (!empty($f['etapa']) && isset(PURCHASE_STATUS[$f['etapa']])) { $w[] = 'r.status = ?'; $p[] = $f['etapa']; }
    if (($f['q'] ?? '') !== '') {
        $like = '%' . $f['q'] . '%';
        $code = preg_match('/^\s*(?:SC-?|N[ºo°]?\.?\s*)?0*(\d+)\s*$/iu', $f['q'], $m) ? (int) $m[1] : 0;
        $w[] = '(r.sector LIKE ? OR u.name LIKE ? OR r.id = ? OR EXISTS (SELECT 1 FROM purchase_items i WHERE i.request_id = r.id AND i.description LIKE ?))'; array_push($p, $like, $like, $code, $like);
    }
    return rows('SELECT r.*, u.name AS requester_name,
        (SELECT COUNT(*) FROM purchase_items i WHERE i.request_id = r.id) AS n_items,
        (SELECT i.description FROM purchase_items i WHERE i.request_id = r.id ORDER BY i.position, i.id LIMIT 1) AS first_item,
        (SELECT COALESCE(SUM(i.quantity * COALESCE(i.est_unit_cents, 0)), 0) FROM purchase_items i WHERE i.request_id = r.id) AS est_cents,
        (SELECT MIN(q.total_cents) FROM purchase_quotes q WHERE q.request_id = r.id) AS min_quote,
        (SELECT COUNT(*) FROM purchase_quotes q WHERE q.request_id = r.id) AS n_quotes
        FROM purchase_requests r JOIN users u ON u.id = r.requester_id WHERE ' . implode(' AND ', $w) . ' ORDER BY r.updated_at DESC, r.id DESC LIMIT ' . (int) ($f['limit'] ?? 200), $p);
}

/** Andamento em 5 passos */
/** Andamento; $noQuote: decidida sem cotação (o passo Cotação aparece como dispensado) */
function pc_stepper(string $status, bool $compact = false, bool $noQuote = false): string {
    $at = purchase_step($status); $o = '';
    foreach (PURCHASE_STEPS as $i => $name) {
        if ($i === 1 && ($noQuote || $status === 'ENVIADA')) { $state = 'skip'; $name = $status === 'ENVIADA' ? 'Cotação, se pedida' : 'Sem cotação'; }
        else $state = $status === 'RECUSADA' && $i === $at ? 'bad' : ($status === 'CANCELADA' ? 'off' : ($i < $at ? 'done' : ($i === $at ? 'cur' : 'off')));
        $mark = $state === 'done' ? icon('check') : ($state === 'bad' ? icon('x') : ($state === 'skip' ? '–' : (string) ($i + 1)));
        $o .= '<li class="pc-step ' . $state . '"' . ($state === 'cur' ? ' aria-current="step"' : '') . '>' . ($compact ? '' : '<span class="pc-dot">' . $mark . '</span>') . '<span class="pc-step-name">' . h($name) . '</span></li>';
    }
    return '<ol class="pc-steps' . ($compact ? ' compact' : '') . '" aria-label="Andamento">' . $o . '</ol>';
}
/** Sem cotação: aprovada ou recusada direto pela direção */
function pc_no_quote(array $r, ?int $quotes = null): bool {
    return in_array($r['status'], ['APROVADA', 'COMPRADA', 'RECEBIDA', 'RECUSADA'], true) && !($quotes ?? (int) ($r['n_quotes'] ?? 0));
}
function pc_can_quote(array $r): bool { return (int) $r['requester_id'] === (int) current_user()['id'] || can('purchase:manage'); }
/** Próximo passo para quem está olhando: [texto, rótulo do botão, url] */
function pc_next(array $r): array {
    $u = current_user(); $mine = (int) $r['requester_id'] === (int) $u['id']; $id = (int) $r['id'];
    $ficha = url('comp.solicitacao', ['id' => $id]); $nq = (int) ($r['n_quotes'] ?? 0);
    return match ($r['status']) {
        'RASCUNHO' => ['Rascunho ainda não enviado. Revise e envie.', $mine ? 'Continuar rascunho' : '', url('comp.nova', ['id' => $id])],
        'ENVIADA' => can('purchase:approve') ? ['Aprove direto, peça cotação de preços ou recuse.', 'Decidir', $ficha] : ['Aguardando a direção: um diretor ou o presidente aprova direto ou pede cotação.', '', $ficha],
        'COTACAO' => pc_can_quote($r)
            ? ['A direção pediu cotação. ' . $nq . ' de ' . PURCHASE_MAX_QUOTES . ' registradas; com pelo menos uma, envie para a direção.', $nq < PURCHASE_MAX_QUOTES ? 'Registrar cotação' : 'Enviar para a direção', $nq < PURCHASE_MAX_QUOTES ? url('comp.cotacao', ['id' => $id]) : $ficha]
            : ['A direção pediu cotação. O solicitante está cotando.', '', $ficha],
        'APROVACAO' => can('purchase:approve') ? ['Compare as cotações e aprove ou recuse.', 'Aprovar ou recusar', $ficha] : ['Cotações enviadas. Aguardando a direção.', '', $ficha],
        'APROVADA' => can('purchase:manage') ? ['Aprovada. Registre o pedido feito ao fornecedor.', 'Registrar compra', $ficha] : ['Aprovada. Compras vai fazer o pedido.', '', $ficha],
        'COMPRADA' => can('purchase:manage') ? ['Quando a mercadoria chegar, registre o recebimento.', 'Registrar recebimento', url('comp.receber', ['id' => $id])] : ['Comprada. Aguardando a entrega.', '', $ficha],
        'RECEBIDA' => $r['patrimony_status'] === 'PENDENTE' && can('purchase:manage') ? ['Recebida. Há itens patrimoniáveis ainda não enviados ao Patrimônio.', 'Enviar ao Patrimônio', $ficha] : ['Concluída.', '', $ficha],
        'RECUSADA' => ['Recusada' . ($r['decision_note'] ? ': ' . $r['decision_note'] : '.'), '', $ficha],
        default => ['Cancelada.', '', $ficha],
    };
}

// ---------- Início (lista com abas por etapa) ----------
function route_comp__inicio(): void {
    require_perm('purchase:request', 'purchase:manage', 'purchase:approve', 'purchase:read', 'purchase:admin');
    $all = pc_list();
    $by = []; foreach ($all as $r) $by[$r['status']][] = $r;
    $n = fn(string ...$s) => array_sum(array_map(fn($k) => count($by[$k] ?? []), $s));
    $sum = fn(array $rs, string $k) => array_sum(array_map(fn($r) => (int) ($r[$k] ?? 0), $rs));
    $wait = array_merge($by['ENVIADA'] ?? [], $by['APROVACAO'] ?? []); $quoting = $by['COTACAO'] ?? [];
    $oldest = $quoting ? min(array_map(fn($r) => $r['updated_at'], $quoting)) : null;
    $month = date('Y-m');
    $bought = array_filter(array_merge($by['COMPRADA'] ?? [], $by['RECEBIDA'] ?? []), fn($r) => substr((string) $r['purchased_on'], 0, 7) === $month);
    $stats = '<div class="grid g4 pc-kpis" style="margin-bottom:16px">'
        . stat_card('Aguardando a direção', (string) count($wait), 'hourglass', count($wait) ? 'warn' : '', $wait ? money(array_sum(array_map(fn($r) => (int) ($r['min_quote'] ?: $r['est_cents']), $wait))) . ' para decidir' : 'Nada pendente')
        . stat_card('Em cotação', (string) count($quoting), 'clipboard-list', '', $oldest ? 'Pedida desde ' . fmt_date($oldest) : 'Nenhuma pedida')
        . stat_card('A receber', (string) $n('APROVADA', 'COMPRADA'), 'truck', 'navy', 'Aprovadas ou compradas')
        . stat_card('Comprado em ' . month_label($month), money($sum($bought, 'approved_cents')), 'shopping-cart', '', plural(count($bought), 'solicitação', 'solicitações'))
        . '</div>';
    $etapa = g('etapa'); if (!isset(PURCHASE_STATUS[$etapa])) $etapa = '';
    $tabs = '<nav class="tabs" aria-label="Etapas" style="padding:0 12px">';
    $defs = ['' => 'Todas'] + array_map(fn($x) => $x[0], PURCHASE_STATUS);
    foreach ($defs as $k => $l) {
        $c = $k === '' ? count($all) : $n($k);
        if ($k !== '' && !$c && !in_array($k, ['ENVIADA', 'COTACAO', 'APROVACAO', 'APROVADA', 'COMPRADA', 'RECEBIDA'], true)) continue;
        $tabs .= '<a class="tab' . ($k === $etapa ? ' active' : '') . '" href="' . h(url('comp.inicio', $k ? ['etapa' => $k] : [])) . '"' . ($k === $etapa ? ' aria-current="page"' : '') . '>' . h($l) . ' <span class="badge b-neutral">' . $c . '</span></a>';
    }
    $tabs .= '</nav>';
    $list = $etapa ? ($by[$etapa] ?? []) : $all;
    $tr = '';
    foreach (array_slice($list, 0, 50) as $r) {
        $tr .= '<tr class="clickable" data-href="' . h(url('comp.solicitacao', ['id' => $r['id']])) . '"><td class="tnum"><a href="' . h(url('comp.solicitacao', ['id' => $r['id']])) . '"><strong>' . purchase_code((int) $r['id']) . '</strong></a></td>'
            . '<td><p class="cell-title">' . h(purchase_summary($r['first_item'], (int) $r['n_items'])) . '</p><p class="cell-sub">' . h($r['sector']) . ($r['priority'] === 'URGENTE' ? ' · <strong style="color:var(--red)">Urgente</strong>' : '') . '</p></td>'
            . '<td><div class="row" style="flex-wrap:nowrap">' . avatar(null, $r['requester_name'], 'width:28px;height:28px;font-size:11px') . h($r['requester_name']) . '</div></td>'
            . '<td>' . pc_badge($r['status']) . '</td><td class="r tnum">' . pc_value($r) . '</td><td class="tnum muted">' . fmt_date($r['created_at']) . '</td></tr>';
    }
    $new = can('purchase:request') ? '<a class="btn btn-primary" href="' . h(url('comp.nova')) . '">' . icon('plus') . ' Nova solicitação</a>' : '';
    $table = '<div class="card">' . $tabs . '<div class="table-wrap"><table><thead><tr><th>Nº</th><th>Itens</th><th>Solicitante</th><th>Etapa</th><th class="r">Valor</th><th>Data</th></tr></thead><tbody>'
        . ($tr ?: '<tr><td colspan="6">' . empty_state('shopping-cart', $etapa ? 'Nada nesta etapa' : 'Nenhuma solicitação ainda', can('purchase:request') ? 'Comece pela nova solicitação.' : '') . '</td></tr>')
        . '</tbody></table></div>' . ($list ? result_line(min(50, count($list)), count($list), 'solicitações') : '') . '</div>';
    layout('Compras', page_head('Início', pc_sees_all() ? 'O que precisa da sua atenção e o andamento de todas as solicitações.' : 'O andamento das suas solicitações de compra.', $new) . $stats . $table);
}

// ---------- Solicitações (lista com painel ao lado) ----------
function route_comp__solicitacoes(): void {
    require_perm('purchase:request', 'purchase:manage', 'purchase:approve', 'purchase:read', 'purchase:admin');
    $q = mb_substr(g('q'), 0, 80); $etapa = g('etapa'); if (!isset(PURCHASE_STATUS[$etapa])) $etapa = '';
    $list = pc_list(['q' => $q, 'etapa' => $etapa, 'limit' => 100]);
    $sel = null; $selId = gi('sel');
    foreach ($list as $r) if ((int) $r['id'] === $selId) $sel = $r;
    $sel ??= $list[0] ?? null;
    $opts = ['' => 'Todas as etapas'] + array_map(fn($x) => $x[0], PURCHASE_STATUS);
    $filters = '<form class="toolbar filters" method="get" action="index.php" style="margin-bottom:16px"><input type="hidden" name="r" value="comp.solicitacoes">'
        . '<div class="field grow" style="max-width:360px"><label for="f-q">Buscar</label><input class="input" id="f-q" name="q" value="' . h($q) . '" placeholder="Número, item, setor ou solicitante"></div>'
        . select('etapa', 'Etapa', $opts, $etapa, '', '', '', '')
        . '<div class="field" style="justify-content:flex-end"><span aria-hidden="true">&nbsp;</span><button class="btn btn-secondary" type="submit">' . icon('search') . ' Filtrar</button></div></form>';
    $items = '';
    foreach ($list as $r) {
        $on = $sel && (int) $sel['id'] === (int) $r['id'];
        $items .= '<a class="pc-item' . ($on ? ' active' : '') . '" href="' . h(url('comp.solicitacoes', array_filter(['q' => $q, 'etapa' => $etapa, 'sel' => $r['id']]))) . '"' . ($on ? ' aria-current="true"' : '') . '>'
            . '<span class="pc-item-top"><span class="pc-code">' . purchase_code((int) $r['id']) . '</span>' . pc_badge($r['status']) . '</span>'
            . '<span class="cell-title">' . h(purchase_summary($r['first_item'], (int) $r['n_items'])) . '</span>'
            . '<span class="pc-item-foot"><span>' . h($r['sector']) . ' · ' . h($r['requester_name']) . '</span><span class="tnum">' . pc_value($r) . '</span></span></a>';
    }
    $panel = '';
    if ($sel) {
        [$txt, $act, $href] = pc_next($sel);
        $panel = '<section class="card pc-panel" aria-label="Solicitação selecionada"><div class="card-head"><div><p class="small muted">Aberta em ' . fmt_date($sel['created_at']) . ' por ' . h($sel['requester_name']) . ' · ' . h($sel['sector']) . '</p>'
            . '<h3 style="font-size:19px;margin-top:4px">' . h($sel['title']) . '</h3><p>' . h(purchase_summary($sel['first_item'], (int) $sel['n_items'])) . ' · ' . pc_value($sel) . '</p></div>' . pc_badge($sel['status']) . '</div>'
            . '<div class="card-body" style="display:flex;flex-direction:column;gap:18px">' . pc_stepper($sel['status'], true, pc_no_quote($sel))
            . '<div><p class="side-group-title" style="padding:0;color:var(--muted)">Próximo passo</p><div class="pc-next">' . h($txt) . '</div></div>'
            . '<div class="row" style="justify-content:flex-end;border-top:1px solid var(--line);padding-top:16px"><a class="btn btn-secondary" href="' . h(url('comp.solicitacao', ['id' => $sel['id']])) . '">Abrir ficha completa</a>'
            . ($act ? '<a class="btn btn-primary" href="' . h($href) . '">' . h($act) . '</a>' : '') . '</div></div></section>';
    }
    $new = can('purchase:request') ? '<a class="btn btn-primary" href="' . h(url('comp.nova')) . '">' . icon('plus') . ' Nova solicitação</a>' : '';
    $body = $list
        ? '<div class="pc-split"><div class="pc-list" aria-label="Lista de solicitações">' . $items . '</div>' . $panel . '</div>'
        : '<div class="card">' . empty_state('file-text', $q || $etapa ? 'Nada encontrado' : 'Nenhuma solicitação ainda', $q || $etapa ? 'Mude a busca ou a etapa.' : '') . '</div>';
    layout('Solicitações', page_head('Solicitações de compra', 'Escolha uma solicitação para ver o andamento sem sair da lista.', $new) . $filters . $body);
}

// ---------- Nova solicitação / rascunho ----------
/** Itens enviados pelo formulário (linhas vazias são ignoradas) */
function pc_items_from_post(): array {
    $out = [];
    foreach ((array) ($_POST['item'] ?? []) as $it) {
        if (!is_array($it)) continue;
        $d = trim(preg_replace('/\s+/', ' ', (string) ($it['description'] ?? '')));
        $qRaw = trim((string) ($it['quantity'] ?? '')); $vRaw = trim((string) ($it['value'] ?? ''));
        if ($d === '' && ($qRaw === '' || $qRaw === '1') && $vRaw === '') continue;
        $out[] = ['description' => $d, 'quantity' => ctype_digit($qRaw) ? (int) $qRaw : 0, 'unit' => (string) ($it['unit'] ?? 'un.'),
            'est_unit_cents' => $vRaw === '' ? null : (parse_money($vRaw) ?? -1), 'patrimonial' => !empty($it['patrimonial']) ? 1 : 0];
    }
    return $out;
}
function pc_place_payment_fields(array $v, string $prefix = ''): string {
    $places = ''; foreach (PURCHASE_PLACES as $pl) $places .= '<option' . ($pl === ($v['place'] ?? '') ? ' selected' : '') . '>' . h($pl) . '</option>';
    $pay = ''; foreach (PURCHASE_PAYMENTS as $k => $l) $pay .= '<label class="pc-choice"><input type="radio" name="payment" value="' . $k . '"' . ($k === ($v['payment'] ?? 'PIX') ? ' checked' : '') . ' required><span>' . h($l) . '</span></label>';
    $inst = ''; for ($i = 1; $i <= 12; $i++) $inst .= '<option value="' . $i . '"' . ($i === (int) ($v['installments'] ?? 1) ? ' selected' : '') . '>' . ($i === 1 ? '1x (à vista)' : $i . 'x') . '</option>';
    $online = implode('|', PURCHASE_ONLINE);
    return '<div class="form-grid cols-2">'
        . field('<label for="f-place">Local da compra</label>', '<select class="input" id="f-place" name="place" required><option value="" disabled' . (($v['place'] ?? '') === '' ? ' selected' : '') . '>Escolha</option>' . $places . '</select>')
        . '<div data-show-when="place=' . h($online) . '">' . input('link', 'Link do produto ou do carrinho <span class="muted">(opcional)</span>', $v['link'] ?? '', 'url', 'maxlength="500" placeholder="https://"') . '</div>'
        . '<div data-show-when="place=Outro">' . input('place_other', 'Qual local?', $v['place_other'] ?? '', 'text', 'maxlength="120" required placeholder="Nome da loja ou do fornecedor"') . '</div>'
        . '</div><fieldset class="pc-fieldset"><legend>Forma de pagamento</legend><div class="row">' . $pay . '</div></fieldset>'
        . '<div data-show-when="payment=CARTAO" style="max-width:260px">' . field('<label for="f-installments">Parcelas</label>', '<select class="input" id="f-installments" name="installments">' . $inst . '</select>') . '</div>';
}
function pc_place_payment_from_post(): array {
    $place = p('place');
    return ['place' => $place, 'place_other' => $place === 'Outro' ? mb_substr(p('place_other'), 0, 120) : null,
        'link' => in_array($place, PURCHASE_ONLINE, true) ? mb_substr(p('link'), 0, 500) : '', 'payment' => p('payment'),
        'installments' => p('payment') === 'CARTAO' ? max(1, pi_('installments')) : 1];
}
/** Setores da instituição (lista suspensa da solicitação) */
function pc_sectors(): array {
    $l = json_decode((string) setting('purchase_sectors:' . inst_id(), ''), true);
    return is_array($l) && $l ? $l : PURCHASE_DEFAULT_SECTORS;
}
/** Campo de valor em reais: digita só números e a vírgula entra sozinha */
function pc_money_input(string $name, string $id, ?int $cents, string $label, string $attrs = ''): string {
    $v = $cents !== null && $cents >= 0 ? number_format($cents / 100, 2, ',', '.') : '';
    return '<div class="pc-money"><span aria-hidden="true">R$</span><label class="sr-only" for="' . h($id) . '">' . $label . '</label><input class="input r" id="' . h($id) . '" name="' . h($name) . '" value="' . h($v) . '" inputmode="numeric" autocomplete="off" data-money placeholder="0,00" ' . $attrs . '></div>';
}
function route_comp__nova(): void {
    $u = require_perm('purchase:request');
    $id = gi('id'); $r = null; $items = [];
    if ($id) {
        $r = pc_find($id);
        if ($r['status'] !== 'RASCUNHO' || (int) $r['requester_id'] !== (int) $u['id']) abort(403, 'Só o rascunho de quem pediu pode ser editado.');
        $items = pc_items($id);
    }
    $sectors = pc_sectors();
    if ($r && !in_array($r['sector'], $sectors, true)) $sectors[] = $r['sector'];
    if (is_post()) {
        $in = ['sector' => p('sector'), 'justification' => mb_substr(p('justification'), 0, 2000), 'needed_by' => p('needed_by'), 'priority' => p('priority', 'NORMAL')] + pc_place_payment_from_post();
        $its = pc_items_from_post();
        $send = p('acao') === 'enviar';
        if ($err = validate_purchase_request($in, $its, today(), $sectors)) { flash('bad', h($err)); $_SESSION['pc_form'] = ['in' => $in, 'items' => $its]; go('comp.nova', $id ? ['id' => $id] : []); }
        $data = $in + ['needed_by' => $in['needed_by'] ?: null, 'link' => $in['link'] ?: null, 'status' => $send ? 'ENVIADA' : 'RASCUNHO', 'updated_at' => now()];
        $id = tx(function () use ($id, $data, $its, $u, $send) {
            if ($id) { update('purchase_requests', $data, 'id = ?', [$id]); q('DELETE FROM purchase_items WHERE request_id = ?', [$id]); }
            else {
                $id = insert('purchase_requests', $data + ['title' => 'Solicitação de Compra', 'institution_id' => inst_id(), 'requester_id' => $u['id'], 'created_at' => now()]);
                update('purchase_requests', ['title' => purchase_title($id)], 'id = ?', [$id]);
            }
            foreach ($its as $k => $it) insert('purchase_items', ['request_id' => $id, 'position' => $k + 1] + $it);
            pc_event($id, $send ? 'enviada' : 'criada', null, $send ? 'RASCUNHO' : null);
            return $id;
        });
        audit($send ? 'purchase.send' : 'purchase.draft', ($send ? 'Enviou para a direção a ' : 'Salvou o rascunho da ') . purchase_title($id), 'purchase_request', $id);
        flash('ok', $send ? purchase_title($id) . ' enviada para a direção. Você recebe um aviso a cada etapa.' : purchase_title($id) . ' salva como rascunho. Envie quando estiver pronta.');
        go('comp.solicitacao', ['id' => $id]);
    }
    $old = $_SESSION['pc_form'] ?? null; unset($_SESSION['pc_form']);
    $v = $old['in'] ?? ($r ?? ['priority' => 'NORMAL', 'payment' => 'PIX', 'installments' => 1, 'place' => '', 'sector' => '']);
    $its = $old['items'] ?? array_map(fn($i) => ['description' => $i['description'], 'quantity' => $i['quantity'], 'unit' => $i['unit'], 'est_unit_cents' => $i['est_unit_cents'] !== null ? (int) $i['est_unit_cents'] : null, 'patrimonial' => $i['patrimonial']], $items);
    if (!$its) $its = [['description' => '', 'quantity' => 1, 'unit' => 'un.', 'est_unit_cents' => null, 'patrimonial' => 0]];
    $row = function ($n, array $it) {
        $units = ''; foreach (PURCHASE_UNITS as $un) $units .= '<option' . ($un === $it['unit'] ? ' selected' : '') . '>' . h($un) . '</option>';
        return '<tr data-row><td><label class="sr-only" for="it-' . $n . '-d">Descrição do item</label><input class="input" id="it-' . $n . '-d" name="item[' . $n . '][description]" value="' . h($it['description']) . '" maxlength="255" placeholder="Descreva o item"></td>'
            . '<td><label class="sr-only" for="it-' . $n . '-q">Quantidade</label><input class="input r" id="it-' . $n . '-q" name="item[' . $n . '][quantity]" value="' . h((string) $it['quantity']) . '" inputmode="numeric" pattern="[0-9]*"></td>'
            . '<td><label class="sr-only" for="it-' . $n . '-u">Unidade</label><select class="input" id="it-' . $n . '-u" name="item[' . $n . '][unit]">' . $units . '</select></td>'
            . '<td>' . pc_money_input('item[' . $n . '][value]', 'it-' . $n . '-v', $it['est_unit_cents'] !== null && $it['est_unit_cents'] > 0 ? (int) $it['est_unit_cents'] : null, 'Valor estimado por unidade') . '</td>'
            . '<td><label class="check-inline"><input type="checkbox" name="item[' . $n . '][patrimonial]" value="1"' . ($it['patrimonial'] ? ' checked' : '') . '> Patrimoniável</label></td>'
            . '<td><button class="icon-btn" type="button" data-remove-row aria-label="Remover item">' . icon('trash-2') . '</button></td></tr>';
    };
    $rows = ''; foreach (array_values($its) as $k => $it) $rows .= $row($k, $it);
    $tpl = $row('__N__', ['description' => '', 'quantity' => 1, 'unit' => 'un.', 'est_unit_cents' => null, 'patrimonial' => 0]);
    $secOpts = '<option value="" disabled' . (($v['sector'] ?? '') === '' ? ' selected' : '') . '>Escolha o setor</option>';
    foreach ($sectors as $sc) $secOpts .= '<option' . ($sc === ($v['sector'] ?? '') ? ' selected' : '') . '>' . h($sc) . '</option>';
    $secHint = can('purchase:manage', 'purchase:approve') ? '<a class="small" href="' . h(url('comp.setores')) . '">Editar a lista de setores</a>' : '';
    $body = form_open('comp.nova', $id ? ['id' => $id] : [], 'class="pc-form" novalidate')
        . '<div class="card"><div class="card-body" style="padding-top:20px"><div class="form-grid cols-2">'
        . field('<label for="f-sector">Setor</label>', '<select class="input" id="f-sector" name="sector" required>' . $secOpts . '</select>' . $secHint)
        . '<div class="form-grid cols-2" style="gap:14px">' . input('needed_by', 'Precisa até <span class="muted">(opcional)</span>', $v['needed_by'] ?? '', 'date', 'min="' . today() . '"')
        . select('priority', 'Prioridade', ['NORMAL' => 'Normal', 'URGENTE' => 'Urgente'], $v['priority'] ?? 'NORMAL') . '</div>'
        . textarea('justification', 'Justificativa', $v['justification'] ?? '', 'maxlength="2000" rows="3" required placeholder="Por que esta compra é necessária?"', '', 'span-2')
        . '</div></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Itens</h3><p>Informe o valor estimado de cada item: a direção pode aprovar direto por ele. Marque como patrimoniável o que vira ativo (equipamentos, móveis).</p></div></div>'
        . '<div class="table-wrap"><table class="pc-items-table"><thead><tr><th>Descrição</th><th style="width:90px">Qtd.</th><th style="width:110px">Unid.</th><th style="width:170px">Valor estimado (un.)</th><th style="width:140px">Patrimônio</th><th style="width:52px"><span class="sr-only">Remover</span></th></tr></thead>'
        . '<tbody data-rows>' . $rows . '</tbody></table></div><template id="pc-item-tpl">' . $tpl . '</template>'
        . '<div class="card-body" style="padding-top:12px"><button class="btn btn-secondary" type="button" data-add-row="#pc-item-tpl">' . icon('plus') . ' Adicionar item</button></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Onde comprar e como pagar</h3><p>Sugestão de quem pede. Se a direção pedir cotação, as cotações podem trazer outras opções.</p></div></div><div class="card-body pc-stack">' . pc_place_payment_fields($v) . '</div></div>'
        . '<div class="row" style="justify-content:space-between"><span class="small muted">O número e o título são gerados ao salvar. Você recebe um aviso a cada etapa.</span><div class="row">'
        . '<button class="btn btn-secondary" type="submit" name="acao" value="rascunho" formnovalidate>Salvar rascunho</button><button class="btn btn-primary" type="submit" name="acao" value="enviar">' . icon('send') . ' Enviar para a direção</button></div></div></form>';
    layout($r ? 'Editar rascunho' : 'Nova solicitação', crumb('comp.solicitacoes', 'Solicitações') . page_head($r ? h($r['title']) : 'Nova solicitação de compra', 'Um diretor ou o presidente aprova direto ou pede cotação de preços.') . $body);
}

// ---------- Ficha da solicitação ----------
function pc_quote_tags(array $quotes): array {
    $tags = []; if (count($quotes) < 2) return $tags;
    $cheap = purchase_cheapest($quotes); if ($cheap) $tags[$cheap][] = 'Menor preço';
    $withDays = array_filter($quotes, fn($q) => $q['delivery_days'] !== null);
    if (count($withDays) > 1) { $fast = array_reduce($withDays, fn($a, $q) => $a === null || $q['delivery_days'] < $a['delivery_days'] ? $q : $a); $tags[$fast['id']][] = 'Menor prazo'; }
    return $tags;
}
function pc_quotes_cards(array $r, array $quotes, bool $edit): string {
    $tags = pc_quote_tags($quotes); $o = '';
    foreach ($quotes as $qt) {
        $win = (int) $r['winner_quote_id'] === (int) $qt['id'];
        $tg = ''; foreach ($tags[$qt['id']] ?? [] as $t) $tg .= badge(h($t), 'b-ok');
        if ($win) $tg = badge('Escolhida', 'b-info') . $tg;
        $o .= '<article class="card pc-quote' . ($win ? ' win' : '') . '"><div class="pc-quote-head"><strong>' . h($qt['supplier']) . '</strong><span class="row" style="gap:4px">' . $tg . '</span></div>'
            . '<p class="pc-quote-total tnum">' . money((int) $qt['total_cents']) . '</p>'
            . '<dl class="kv small"><dt>Onde</dt><dd>' . h($qt['place']) . ($qt['link'] ? ' · <a href="' . h($qt['link']) . '" target="_blank" rel="noopener noreferrer">ver anúncio</a>' : '') . '</dd>'
            . '<dt>Pagamento</dt><dd>' . h(purchase_payment_label($qt['payment'], (int) $qt['installments'])) . '</dd>'
            . '<dt>Entrega</dt><dd>' . ($qt['delivery_days'] !== null ? plural((int) $qt['delivery_days'], 'dia', 'dias') : 'Não informada') . '</dd>'
            . ((int) $qt['shipping_cents'] ? '<dt>Frete</dt><dd>' . money((int) $qt['shipping_cents']) . '</dd>' : '') . '</dl>'
            . ($qt['notes'] ? '<p class="small muted">' . h($qt['notes']) . '</p>' : '')
            . ($edit ? '<div class="row" style="margin-top:auto"><a class="btn btn-sm btn-secondary" href="' . h(url('comp.cotacao', ['id' => $r['id'], 'q' => $qt['id']])) . '">' . icon('pencil') . ' Editar</a>'
                . form_open('comp.solicitacao', ['id' => $r['id']], 'class="inline-form" data-confirm="Excluir a cotação de ' . h($qt['supplier']) . '?"') . '<input type="hidden" name="acao" value="excluir_cotacao"><input type="hidden" name="quote" value="' . (int) $qt['id'] . '"><button class="btn btn-sm btn-secondary" type="submit">' . icon('trash-2') . ' Excluir</button></form></div>' : '')
            . '</article>';
    }
    return '<div class="pc-quotes">' . $o . '</div>';
}
function pc_quotes_table(array $r, array $items, array $quotes): string {
    $head = ''; foreach ($quotes as $qt) $head .= '<th class="r' . ((int) $r['winner_quote_id'] === (int) $qt['id'] ? ' pc-win-col' : '') . '">' . h($qt['supplier']) . '</th>';
    $body = '';
    foreach ($items as $it) {
        $vals = array_map(fn($qt) => $qt['prices'][$it['id']] ?? null, $quotes);
        $nonNull = array_filter($vals, fn($v) => $v !== null);
        $min = $nonNull ? min($nonNull) : null;
        $tds = '';
        foreach ($quotes as $k => $qt) {
            $v = $vals[$k]; $best = $v !== null && $v === $min && count($nonNull) > 1;
            $tds .= '<td class="r tnum' . ((int) $r['winner_quote_id'] === (int) $qt['id'] ? ' pc-win-col' : '') . '">' . ($v === null ? '—' : '<span class="' . ($best ? 'pc-best' : '') . '">' . money($v * (int) $it['quantity']) . '</span><span class="cell-sub" style="display:block">' . money($v) . ' un.' . ($best ? ' · menor' : '') . '</span>') . '</td>';
        }
        $body .= '<tr><td><p class="cell-title">' . h($it['description']) . '</p><p class="cell-sub">' . (int) $it['quantity'] . ' ' . h($it['unit']) . '</p></td>' . $tds . '</tr>';
    }
    $row = fn(string $label, callable $f, string $cls = '') => '<tr class="' . $cls . '"><td>' . $label . '</td>' . implode('', array_map(fn($qt) => '<td class="r' . ((int) $r['winner_quote_id'] === (int) $qt['id'] ? ' pc-win-col' : '') . '">' . $f($qt) . '</td>', $quotes)) . '</tr>';
    $body .= $row('Frete', fn($qt) => (int) $qt['shipping_cents'] ? money((int) $qt['shipping_cents']) : '—')
        . $row('<strong>Total</strong>', fn($qt) => '<strong class="tnum">' . money((int) $qt['total_cents']) . '</strong>')
        . $row('<span class="muted">Onde, pagamento e entrega</span>', fn($qt) => h($qt['place']) . '<span class="cell-sub" style="display:block">' . h(purchase_payment_label($qt['payment'], (int) $qt['installments'])) . ($qt['delivery_days'] !== null ? ' · ' . plural((int) $qt['delivery_days'], 'dia', 'dias') : '') . '</span>');
    return '<div class="card"><div class="table-wrap"><table><thead><tr><th>Item</th>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div><p class="small muted" style="padding:12px 20px;border-top:1px solid var(--line)">Em verde, o menor preço de cada item.</p></div>';
}
/** Campo de senha para assinar a aprovação */
function pc_sign_field(): string {
    return '<div class="field"><label for="f-sign">Sua senha <span class="muted">(assina a aprovação)</span></label><input class="input" id="f-sign" type="password" name="password" autocomplete="current-password"></div>';
}
/** Direção decide uma solicitação recém-enviada: aprovar sem cotação, pedir cotação ou recusar */
function pc_direct_panel(array $r, int $est): string {
    return form_open('comp.solicitacao', ['id' => $r['id']], 'class="pc-stack"')
        . '<p>Valor estimado: <strong class="tnum">' . money($est) . '</strong></p>'
        . textarea('note', 'Observação <span class="muted">(obrigatória para recusar)</span>', '', 'maxlength="500" rows="3"')
        . pc_sign_field()
        . '<div class="pc-stack" style="gap:8px"><button class="btn btn-primary" type="submit" name="acao" value="aprovar_direto">' . icon('signature') . ' Assinar e aprovar sem cotação</button>'
        . '<button class="btn btn-secondary" type="submit" name="acao" value="pedir_cotacao">' . icon('clipboard-list') . ' Solicitar cotação de preços</button>'
        . '<button class="btn btn-secondary pc-danger" type="submit" name="acao" value="recusar">' . icon('x') . ' Recusar</button></div>'
        . '<p class="small muted">A senha só é pedida para aprovar. Ao pedir cotação, o solicitante registra até 3 orçamentos e a solicitação volta para a direção.</p></form>';
}
function pc_decision_panel(array $r, array $quotes): string {
    $cheap = purchase_cheapest($quotes); $o = '';
    foreach ($quotes as $qt) $o .= '<label class="pc-choice pc-choice-block"><input type="radio" name="quote" value="' . (int) $qt['id'] . '"' . ((int) $qt['id'] === $cheap ? ' checked' : '') . ' required><span><strong>' . h($qt['supplier']) . '</strong><span class="tnum">' . money((int) $qt['total_cents']) . '</span>'
        . '<span class="cell-sub">' . h($qt['place']) . ' · ' . h(purchase_payment_label($qt['payment'], (int) $qt['installments'])) . ((int) $qt['id'] === $cheap && count($quotes) > 1 ? ' · menor preço' : '') . '</span></span></label>';
    return form_open('comp.solicitacao', ['id' => $r['id']], 'class="pc-stack"') . '<fieldset class="pc-fieldset"><legend>Fornecedor escolhido</legend><div class="pc-stack" style="gap:8px">' . $o . '</div></fieldset>'
        . textarea('note', 'Observação <span class="muted">(obrigatória para recusar ou para escolher um que não seja o mais barato)</span>', '', 'maxlength="500" rows="3"')
        . pc_sign_field()
        . '<div class="pc-stack" style="gap:8px"><button class="btn btn-primary" type="submit" name="acao" value="aprovar">' . icon('signature') . ' Assinar e aprovar</button>'
        . '<button class="btn btn-secondary pc-danger" type="submit" name="acao" value="recusar">' . icon('x') . ' Recusar</button></div></form>';
}
/** Assinatura registrada na ficha */
function pc_signature(array $r): string {
    if (!$r['decider_name']) return '';
    return '<div class="pc-sign">' . icon('signature') . '<div><strong>' . h($r['decider_name']) . '</strong><span class="cell-sub" style="display:block">' . ($r['status'] === 'RECUSADA' ? 'Recusou' : 'Assinou a aprovação') . ' em ' . fmt_dt($r['decided_at']) . '</span></div></div>';
}
/** Voltar etapa e excluir (quem tem purchase:admin; o próprio rascunho pode ser excluído por quem pediu) */
function pc_admin_panel(array $r): string {
    $id = (int) $r['id']; $mine = (int) $r['requester_id'] === (int) current_user()['id'];
    $back = can('purchase:admin') ? pc_revert_target($id) : null; $o = '';
    if ($back) $o .= form_open('comp.solicitacao', ['id' => $id], 'class="pc-stack" data-confirm="Voltar para a etapa ' . h(PURCHASE_STATUS[$back][0]) . '?"') . '<input type="hidden" name="acao" value="voltar">'
        . textarea('note', 'Motivo para voltar a etapa', '', 'maxlength="500" rows="2" required')
        . '<button class="btn btn-secondary" type="submit">' . icon('undo-2') . ' Voltar para “' . h(PURCHASE_STATUS[$back][0]) . '”</button></form>';
    if (can('purchase:admin') || ($mine && $r['status'] === 'RASCUNHO')) $o .= form_open('comp.solicitacao', ['id' => $id], 'class="pc-stack" data-confirm="Excluir de vez a ' . h($r['title']) . '? Não dá para desfazer."') . '<input type="hidden" name="acao" value="excluir">'
        . ($r['status'] === 'RASCUNHO' && $mine ? '' : textarea('note', 'Motivo da exclusão', '', 'maxlength="500" rows="2" required'))
        . '<button class="btn btn-secondary pc-danger" type="submit">' . icon('trash-2') . ' Excluir solicitação</button></form>';
    return $o ? '<details class="pc-cancel"><summary>' . (can('purchase:admin') ? 'Voltar etapa ou excluir' : 'Excluir rascunho') . '</summary><div class="pc-stack" style="margin-top:10px">' . $o . '</div></details>' : '';
}
function pc_side_panel(array $r, array $quotes, int $est): string {
    $u = current_user(); $mine = (int) $r['requester_id'] === (int) $u['id']; $id = (int) $r['id']; $st = $r['status'];
    $act = fn(string $acao, string $label, string $cls = 'btn-secondary', string $ic = '', string $confirm = '') => form_open('comp.solicitacao', ['id' => $id], $confirm ? 'data-confirm="' . h($confirm) . '"' : '') . '<input type="hidden" name="acao" value="' . $acao . '"><button class="btn ' . $cls . '" type="submit" style="width:100%">' . ($ic ? icon($ic) . ' ' : '') . $label . '</button></form>';
    $cancel = ($mine || can('purchase:manage')) && in_array($st, ['ENVIADA', 'COTACAO'], true)
        ? '<details class="pc-cancel"><summary>Cancelar solicitação</summary>' . form_open('comp.solicitacao', ['id' => $id], 'class="pc-stack" style="margin-top:10px"') . '<input type="hidden" name="acao" value="cancelar">' . textarea('note', 'Motivo do cancelamento', '', 'maxlength="500" rows="2" required') . '<button class="btn btn-secondary pc-danger" type="submit">Cancelar solicitação</button></form></details>' : '';
    $h = match ($st) {
        'RASCUNHO' => $mine ? '<p class="small muted">Ainda não enviada. Só você vê este rascunho.</p><a class="btn btn-secondary" href="' . h(url('comp.nova', ['id' => $id])) . '">' . icon('pencil') . ' Continuar editando</a>' . $act('enviar', 'Enviar para a direção', 'btn-primary', 'send') : '',
        'ENVIADA' => can('purchase:approve') ? pc_direct_panel($r, $est) : '<p>Aguardando a direção. Um diretor ou o presidente aprova direto ou pede cotação de preços.</p><p class="small muted">Valor estimado: ' . money($est) . '</p>',
        'COTACAO' => pc_can_quote($r)
            ? '<p>A direção pediu cotação de preços.</p><p class="small muted">' . count($quotes) . ' de ' . PURCHASE_MAX_QUOTES . ' cotações registradas.</p>'
                . (count($quotes) < PURCHASE_MAX_QUOTES ? '<a class="btn ' . ($quotes ? 'btn-secondary' : 'btn-primary') . '" href="' . h(url('comp.cotacao', ['id' => $id])) . '">' . icon('plus') . ' Registrar cotação</a>' : '')
                . ($quotes ? $act('para_aprovacao', 'Enviar cotações para a direção', 'btn-primary', 'send') : '')
            : '<p class="small muted">A direção pediu cotação. O solicitante está registrando os orçamentos.</p>',
        'APROVACAO' => can('purchase:approve') ? pc_decision_panel($r, $quotes) : '<p class="small muted">Cotações enviadas. Aguardando a direção escolher e aprovar.</p>',
        'APROVADA' => '<p>Aprovada ' . ($r['winner_quote_id'] ? 'com a cotação escolhida' : 'sem cotação, pelo valor estimado') . ': <strong class="tnum">' . money((int) $r['approved_cents']) . '</strong>.</p>' . pc_signature($r) . ($r['decision_note'] ? '<p class="small muted">' . h($r['decision_note']) . '</p>' : '')
            . (can('purchase:manage') ? form_open('comp.solicitacao', ['id' => $id], 'class="pc-stack"') . '<input type="hidden" name="acao" value="comprar">' . input('purchase_ref', 'Nº do pedido ou da compra <span class="muted">(opcional)</span>', '', 'text', 'maxlength="60"') . input('purchased_on', 'Data da compra', today(), 'date', 'required max="' . today() . '"') . '<button class="btn btn-primary" type="submit">' . icon('shopping-cart') . ' Registrar compra</button></form>' : ''),
        'COMPRADA' => '<p>Comprada em ' . fmt_date($r['purchased_on']) . ($r['purchase_ref'] ? ' · pedido ' . h($r['purchase_ref']) : '') . '.</p>' . pc_signature($r) . (can('purchase:manage') ? '<a class="btn btn-primary" href="' . h(url('comp.receber', ['id' => $id])) . '">' . icon('package-check') . ' Registrar recebimento</a>' : ''),
        'RECEBIDA' => '<p>Recebida em ' . fmt_date($r['received_on']) . ($r['invoice_number'] ? ' · NF-e ' . h($r['invoice_number']) : '') . '.</p>' . pc_signature($r)
            . match ($r['patrimony_status']) {
                'ENVIADO' => alert('ok', 'Itens patrimoniáveis enviados ao Patrimônio, em Notas fiscais (rascunho).'),
                'PENDENTE' => can('purchase:manage') ? '<p class="small muted">Há itens patrimoniáveis ainda não enviados ao Patrimônio.</p>' . $act('patrimonio', 'Enviar ao Patrimônio', 'btn-primary', 'package') : '',
                'NAO' => can('purchase:manage') ? '<p class="small muted">Os itens patrimoniáveis não foram enviados ao Patrimônio.</p>' . $act('patrimonio', 'Enviar ao Patrimônio agora', 'btn-secondary', 'package') : '',
                default => '',
            },
        'RECUSADA' => '<p>Recusada.</p>' . pc_signature($r) . ($r['decision_note'] ? alert('bad', h($r['decision_note'])) : ''),
        default => '<p>Cancelada.</p>' . ($r['decision_note'] ? '<p class="small muted">' . h($r['decision_note']) . '</p>' : ''),
    };
    $title = in_array($st, ['ENVIADA', 'APROVACAO'], true) && can('purchase:approve') ? 'Decisão da direção' : 'Situação';
    return '<aside class="card pc-side"><div class="card-head"><h3>' . $title . '</h3>' . pc_badge($st) . '</div><div class="card-body pc-stack">' . $h . $cancel . pc_admin_panel($r) . '</div></aside>';
}
function route_comp__solicitacao(): void {
    $u = require_perm('purchase:request', 'purchase:manage', 'purchase:approve', 'purchase:read', 'purchase:admin');
    $id = gi('id'); $r = pc_find($id);
    if (is_post()) { pc_action($r); go('comp.solicitacao', ['id' => $id]); }
    $items = pc_items($id); $quotes = pc_quotes($id);
    $events = rows('SELECT e.*, u.name FROM purchase_events e LEFT JOIN users u ON u.id = e.actor_id WHERE e.request_id = ? ORDER BY e.created_at DESC, e.id DESC', [$id]);
    $ver = g('ver') === 'tabela' ? 'tabela' : 'cartoes';
    $editQuotes = $r['status'] === 'COTACAO' && pc_can_quote($r);
    $est = array_sum(array_map(fn($i) => (int) $i['quantity'] * (int) ($i['est_unit_cents'] ?? 0), $items));
    $tr = '';
    foreach ($items as $it) $tr .= '<tr><td><p class="cell-title">' . h($it['description']) . '</p></td><td class="r tnum">' . (int) $it['quantity'] . ' ' . h($it['unit']) . '</td><td class="r tnum">' . ($it['est_unit_cents'] !== null ? money((int) $it['est_unit_cents']) : '—') . '</td>'
        . '<td>' . ($it['patrimonial'] ? badge('Patrimoniável', 'b-warn') : badge('Consumo')) . '</td>' . ($r['status'] === 'RECEBIDA' ? '<td class="r tnum">' . (int) $it['received_qty'] . '</td>' : '') . '</tr>';
    $itemsCard = '<section class="card"><div class="card-head"><h3>Itens</h3><p>' . plural(count($items), 'item', 'itens') . ($est ? ' · estimativa ' . money($est) : '') . '</p></div><div class="table-wrap"><table><thead><tr><th>Descrição</th><th class="r">Qtd.</th><th class="r">Estimado (un.)</th><th>Tipo</th>' . ($r['status'] === 'RECEBIDA' ? '<th class="r">Recebido</th>' : '') . '</tr></thead><tbody>' . $tr . '</tbody></table></div>'
        . '<div class="card-body" style="padding-top:16px"><dl class="kv"><dt>Justificativa</dt><dd>' . nl2br(h($r['justification'])) . '</dd>'
        . '<dt>Sugestão de compra</dt><dd>' . h(purchase_place_label($r['place'], $r['place_other'])) . ($r['link'] ? ' · <a href="' . h($r['link']) . '" target="_blank" rel="noopener noreferrer">link</a>' : '') . ' · ' . h(purchase_payment_label($r['payment'], (int) $r['installments'])) . '</dd>'
        . ($r['needed_by'] ? '<dt>Precisa até</dt><dd>' . fmt_date($r['needed_by']) . '</dd>' : '') . '</dl></div></section>';
    $seg = '<div class="pc-seg" role="group" aria-label="Exibir cotações"><a href="' . h(url('comp.solicitacao', ['id' => $id])) . '"' . ($ver === 'cartoes' ? ' class="on" aria-current="true"' : '') . '>' . icon('layout-grid') . ' Cartões</a>'
        . '<a href="' . h(url('comp.solicitacao', ['id' => $id, 'ver' => 'tabela'])) . '"' . ($ver === 'tabela' ? ' class="on" aria-current="true"' : '') . '>' . icon('table') . ' Exibir em tabela</a></div>';
    $showQuotes = $quotes || $r['status'] === 'COTACAO';
    $quotesSec = !$showQuotes ? '' : '<section class="pc-stack"><div class="row" style="justify-content:space-between"><div><h2 style="font-size:16px">Cotações</h2><p class="small muted">' . count($quotes) . ' de ' . PURCHASE_MAX_QUOTES . ' registradas' . ($r['status'] === 'APROVACAO' ? ' · escolha a vencedora ao aprovar' : '') . '</p></div>' . ($quotes ? $seg : '') . '</div>'
        . ($quotes ? ($ver === 'tabela' ? pc_quotes_table($r, $items, $quotes) : pc_quotes_cards($r, $quotes, $editQuotes)) : '<div class="card">' . empty_state('clipboard-list', 'Nenhuma cotação ainda', $editQuotes ? 'A direção pediu cotação: registre até 3 orçamentos e envie para a direção.' : 'O solicitante registra os orçamentos dos fornecedores.') . '</div>') . '</section>';
    $hist = ''; foreach ($events as $e) $hist .= '<li><span class="pc-hdot" aria-hidden="true"></span><span><strong>' . h($e['name'] ?? 'Sistema') . '</strong> · ' . h(PC_EVENT_LABEL[$e['action']] ?? $e['action']) . ($e['note'] ? ': ' . h($e['note']) : '') . '<span class="cell-sub" style="display:block">' . fmt_dt($e['created_at']) . '</span></span></li>';
    $histCard = '<section class="card"><div class="card-head"><h3>Histórico</h3></div><div class="card-body"><ol class="pc-hist">' . $hist . '</ol></div></section>';
    $head = '<div class="page-head"><div><h1>' . h($r['title']) . '</h1><p>' . h($r['sector']) . ' · aberta por ' . h($r['requester_name']) . ' em ' . fmt_date($r['created_at']) . ($r['priority'] === 'URGENTE' ? ' · <strong style="color:var(--red)">Urgente</strong>' : '') . '</p></div><div class="row">' . pc_badge($r['status']) . '</div></div>';
    $modal = '';
    if (g('patrimonio') === '1' && $r['status'] === 'RECEBIDA' && $r['patrimony_status'] === 'PENDENTE' && can('purchase:manage')) {
        $pat = array_filter($items, fn($i) => $i['patrimonial'] && (int) $i['received_qty'] > 0);
        $li = ''; $units = 0; foreach ($pat as $i) { $units += (int) $i['received_qty']; $li .= '<li><strong>' . h($i['description']) . '</strong><span class="tnum">' . (int) $i['received_qty'] . ' ' . h($i['unit']) . '</span></li>'; }
        $modal = '<div class="modal-bg"><div class="modal" role="dialog" aria-modal="true" aria-labelledby="pc-pat-t"><div class="modal-head"><h3 id="pc-pat-t">Importar para o Patrimônio?</h3></div><div class="modal-body pc-stack">'
            . '<p>' . (count($pat) === 1 ? '1 item patrimoniável' : count($pat) . ' itens patrimoniáveis') . ' (' . plural($units, 'unidade', 'unidades') . ') deste recebimento pode' . (count($pat) === 1 ? '' : 'm') . ' ser cadastrado' . (count($pat) === 1 ? '' : 's') . ' como ativo.</p><ul class="pc-pat-list">' . $li . '</ul>'
            . '<p class="small muted">Os itens vão para o Patrimônio como uma nota fiscal em rascunho, com fornecedor, número da NF-e, quantidades e valores preenchidos. Lá, quem cuida do patrimônio define conta, local e número de cada ativo.</p></div>'
            . '<div class="modal-foot">' . form_open('comp.solicitacao', ['id' => $id]) . '<input type="hidden" name="acao" value="patrimonio_nao"><button class="btn btn-secondary" type="submit">Agora não</button></form>'
            . form_open('comp.solicitacao', ['id' => $id]) . '<input type="hidden" name="acao" value="patrimonio"><button class="btn btn-primary" type="submit" autofocus>' . icon('package') . ' Importar para o Patrimônio</button></form></div></div></div>';
    }
    layout(purchase_code($id), crumb('comp.solicitacoes', 'Solicitações') . $head . pc_stepper($r['status'], false, pc_no_quote($r, count($quotes)))
        . '<div class="pc-detail"><div class="pc-stack" style="gap:16px;min-width:0">' . $quotesSec . $itemsCard . $histCard . '</div>' . pc_side_panel($r, $quotes, $est) . '</div>' . $modal);
}

/** Confere a senha de quem assina a aprovação */
function pc_signature_ok(): bool {
    $hash = val('SELECT password_hash FROM users WHERE id = ?', [current_user()['id']]);
    return is_string($hash) && p('password') !== '' && password_verify(p('password'), $hash);
}
/** Muda a etapa e registra no histórico de onde veio (para poder voltar) */
function pc_move(int $id, string $from, string $to, string $action, ?string $note = null, array $extra = []): void {
    update('purchase_requests', ['status' => $to] + $extra, 'id = ?', [$id]);
    pc_event($id, $action, $note, $from);
}
/** Etapa para onde a solicitação volta: a anterior à última mudança ainda não desfeita */
function pc_revert_target(int $id): ?string {
    $from = val('SELECT from_status FROM purchase_events WHERE request_id = ? AND from_status IS NOT NULL AND reverted = 0 ORDER BY id DESC LIMIT 1', [$id]);
    return is_string($from) && isset(PURCHASE_STATUS[$from]) ? $from : null;
}
/** Ações da ficha (POST) */
function pc_action(array $r): void {
    $u = current_user(); $id = (int) $r['id']; $st = $r['status']; $acao = p('acao'); $mine = (int) $r['requester_id'] === (int) $u['id'];
    $code = purchase_code($id);
    $deny = function (string $msg = 'Esta ação não está disponível nesta etapa ou para o seu usuário.') { flash('bad', $msg); };
    $note = mb_substr(trim(p('note')), 0, 500);
    switch ($acao) {
        case 'enviar':
            if (!$mine || $st !== 'RASCUNHO') { $deny(); return; }
            $items = array_map(fn($i) => ['description' => $i['description'], 'quantity' => (int) $i['quantity'], 'unit' => $i['unit'], 'est_unit_cents' => $i['est_unit_cents'] !== null ? (int) $i['est_unit_cents'] : null, 'patrimonial' => (int) $i['patrimonial']], pc_items($id));
            if ($err = validate_purchase_request($r, $items, '0000-00-00')) { flash('bad', h($err) . ' Corrija o rascunho antes de enviar.'); return; }
            pc_move($id, $st, 'ENVIADA', 'enviada');
            audit('purchase.send', "Enviou para a direção a solicitação $code", 'purchase_request', $id);
            flash('ok', h($r['title']) . ' enviada para a direção.');
            return;
        case 'cancelar':
            if (!($mine || can('purchase:manage')) || !in_array($st, ['ENVIADA', 'COTACAO'], true)) { $deny(); return; }
            if (mb_strlen($note) < 3) { flash('bad', 'Informe o motivo do cancelamento.'); return; }
            pc_move($id, $st, 'CANCELADA', 'cancelada', $note, ['decision_note' => $note]);
            audit('purchase.cancel', "Cancelou a solicitação $code", 'purchase_request', $id, $note);
            flash('ok', "Solicitação $code cancelada.");
            return;
        case 'aprovar_direto':
        case 'pedir_cotacao':
            if (!can('purchase:approve') || $st !== 'ENVIADA') { $deny(); return; }
            if ($acao === 'pedir_cotacao') {
                pc_move($id, $st, 'COTACAO', 'pedido_cotacao', $note ?: null);
                audit('purchase.ask_quote', "Pediu cotação de preços na solicitação $code", 'purchase_request', $id, $note ?: null);
                flash('ok', 'Cotação pedida. O solicitante foi avisado para registrar até 3 orçamentos.');
                return;
            }
            if (!pc_signature_ok()) { flash('bad', 'Senha incorreta. A aprovação é assinada com a sua senha de acesso.'); return; }
            $est = (int) val('SELECT COALESCE(SUM(quantity * COALESCE(est_unit_cents, 0)), 0) FROM purchase_items WHERE request_id = ?', [$id]);
            pc_move($id, $st, 'APROVADA', 'aprovada_direto', money($est) . ($note ? ' · ' . $note : ''), ['winner_quote_id' => null, 'approved_cents' => $est, 'decided_by' => $u['id'], 'decided_at' => now(), 'decision_note' => $note ?: null]);
            audit('purchase.approve', "Assinou e aprovou sem cotação a solicitação $code (" . money($est) . ')', 'purchase_request', $id, $note ?: null);
            flash('ok', 'Compra aprovada e assinada, sem cotação. Compras já pode fazer o pedido.');
            return;
        case 'excluir_cotacao':
            if (!pc_can_quote($r) || $st !== 'COTACAO') { $deny(); return; }
            $q = row('SELECT * FROM purchase_quotes WHERE id = ? AND request_id = ?', [pi_('quote'), $id]);
            if (!$q) { $deny('Cotação não encontrada.'); return; }
            q('DELETE FROM purchase_quotes WHERE id = ?', [$q['id']]); pc_event($id, 'cotacao_excluida', $q['supplier']);
            audit('purchase.quote_delete', "Excluiu a cotação de {$q['supplier']} na solicitação $code", 'purchase_request', $id);
            flash('ok', 'Cotação excluída.');
            return;
        case 'para_aprovacao':
            if (!pc_can_quote($r) || $st !== 'COTACAO') { $deny(); return; }
            if (!val('SELECT COUNT(*) FROM purchase_quotes WHERE request_id = ?', [$id])) { flash('bad', 'Registre pelo menos uma cotação antes de enviar para a direção.'); return; }
            pc_move($id, $st, 'APROVACAO', 'para_aprovacao');
            audit('purchase.to_approval', "Enviou as cotações da solicitação $code para a direção", 'purchase_request', $id);
            flash('ok', 'Cotações enviadas para a direção.');
            return;
        case 'aprovar':
        case 'recusar':
            if (!can('purchase:approve') || !in_array($st, $acao === 'recusar' ? ['ENVIADA', 'APROVACAO'] : ['APROVACAO'], true)) { $deny(); return; }
            if ($acao === 'recusar') {
                if (mb_strlen($note) < 3) { flash('bad', 'Para recusar, escreva o motivo. Quem pediu vai ver.'); return; }
                pc_move($id, $st, 'RECUSADA', 'recusada', $note, ['decided_by' => $u['id'], 'decided_at' => now(), 'decision_note' => $note]);
                audit('purchase.reject', "Recusou a solicitação $code", 'purchase_request', $id, $note);
                flash('ok', "Solicitação $code recusada. Quem pediu foi avisado com o motivo.");
                return;
            }
            $quotes = pc_quotes($id);
            $q = null; foreach ($quotes as $x) if ((int) $x['id'] === pi_('quote')) $q = $x;
            if (!$q) { flash('bad', 'Escolha uma das cotações.'); return; }
            if ((int) $q['id'] !== purchase_cheapest($quotes) && mb_strlen($note) < 3) { flash('bad', 'A cotação escolhida não é a de menor preço. Escreva o motivo da escolha na observação.'); return; }
            if (!pc_signature_ok()) { flash('bad', 'Senha incorreta. A aprovação é assinada com a sua senha de acesso.'); return; }
            pc_move($id, $st, 'APROVADA', 'aprovada', $q['supplier'] . ' · ' . money((int) $q['total_cents']) . ($note ? ' · ' . $note : ''), ['winner_quote_id' => $q['id'], 'approved_cents' => $q['total_cents'], 'decided_by' => $u['id'], 'decided_at' => now(), 'decision_note' => $note ?: null]);
            audit('purchase.approve', "Assinou e aprovou a solicitação $code com {$q['supplier']} (" . money((int) $q['total_cents']) . ')', 'purchase_request', $id, $note ?: null);
            flash('ok', 'Compra aprovada e assinada com ' . h($q['supplier']) . '. Compras já pode fazer o pedido.');
            return;
        case 'comprar':
            if (!can('purchase:manage') || $st !== 'APROVADA') { $deny(); return; }
            $on = p('purchased_on');
            if (!valid_date($on) || $on > today()) { flash('bad', 'Informe a data da compra (hoje ou antes).'); return; }
            $ref = mb_substr(p('purchase_ref'), 0, 60);
            pc_move($id, $st, 'COMPRADA', 'comprada', $ref !== '' ? 'Pedido ' . $ref : null, ['purchase_ref' => $ref ?: null, 'purchased_on' => $on]);
            audit('purchase.buy', "Registrou a compra da solicitação $code", 'purchase_request', $id);
            flash('ok', 'Compra registrada. Quando a mercadoria chegar, registre o recebimento.');
            return;
        case 'patrimonio':
            if (!can('purchase:manage') || $st !== 'RECEBIDA' || !in_array($r['patrimony_status'], ['PENDENTE', 'NAO'], true)) { $deny(); return; }
            [$ok, $msg] = pc_send_to_patrimony($r);
            if (!$ok) { flash('bad', h($msg)); return; }
            update('purchase_requests', ['patrimony_status' => 'ENVIADO'], 'id = ?', [$id]); pc_event($id, 'patrimonio', $msg);
            audit('purchase.patrimony', "Enviou ao Patrimônio os itens da solicitação $code", 'purchase_request', $id);
            flash('ok', 'Itens enviados ao Patrimônio. Eles aparecem lá em Notas fiscais, como rascunho, para classificar e incorporar.');
            return;
        case 'patrimonio_nao':
            if (!can('purchase:manage') || $st !== 'RECEBIDA' || $r['patrimony_status'] !== 'PENDENTE') { $deny(); return; }
            update('purchase_requests', ['patrimony_status' => 'NAO'], 'id = ?', [$id]); pc_event($id, 'patrimonio_nao');
            flash('ok', 'Recebimento registrado. Você pode enviar os itens ao Patrimônio depois, por esta ficha.');
            return;
        case 'voltar':
            if (!can('purchase:admin') || !($to = pc_revert_target($id))) { $deny(); return; }
            if (mb_strlen($note) < 3) { flash('bad', 'Informe o motivo para voltar a etapa.'); return; }
            $clear = [];
            if (in_array($st, ['APROVADA', 'RECUSADA', 'CANCELADA'], true)) $clear += ['winner_quote_id' => null, 'approved_cents' => null, 'decided_by' => null, 'decided_at' => null, 'decision_note' => null];
            if ($st === 'COMPRADA') $clear += ['purchase_ref' => null, 'purchased_on' => null];
            $sentToPatrimony = $st === 'RECEBIDA' && $r['patrimony_status'] === 'ENVIADO';
            if ($st === 'RECEBIDA') $clear += ['invoice_number' => null, 'received_on' => null, 'received_by' => null, 'patrimony_status' => ''];
            tx(function () use ($id, $to, $clear, $st, $note) {
                update('purchase_requests', ['status' => $to] + $clear, 'id = ?', [$id]);
                if ($st === 'RECEBIDA') q('UPDATE purchase_items SET received_qty = NULL WHERE request_id = ?', [$id]);
                q('UPDATE purchase_events SET reverted = 1 WHERE id = (SELECT id FROM (SELECT id FROM purchase_events WHERE request_id = ? AND from_status IS NOT NULL AND reverted = 0 ORDER BY id DESC LIMIT 1) x)', [$id]);
                pc_event($id, 'revertida', PURCHASE_STATUS[$st][0] . ' → ' . PURCHASE_STATUS[$to][0] . ': ' . $note);
            });
            audit('purchase.revert', "Voltou a solicitação $code de " . PURCHASE_STATUS[$st][0] . ' para ' . PURCHASE_STATUS[$to][0], 'purchase_request', $id, $note);
            flash($sentToPatrimony ? 'warn' : 'ok', 'Solicitação voltou para “' . PURCHASE_STATUS[$to][0] . '”.' . ($sentToPatrimony ? ' Os itens já tinham sido enviados ao Patrimônio: se for o caso, exclua lá o rascunho da nota.' : ''));
            return;
        case 'excluir':
            $ownDraft = $mine && $st === 'RASCUNHO';
            if (!can('purchase:admin') && !$ownDraft) { $deny(); return; }
            if (!$ownDraft && mb_strlen($note) < 3) { flash('bad', 'Informe o motivo da exclusão.'); return; }
            tx(function () use ($id) {
                q('DELETE FROM purchase_quote_prices WHERE quote_id IN (SELECT id FROM purchase_quotes WHERE request_id = ?)', [$id]);
                foreach (['purchase_quotes', 'purchase_items', 'purchase_events'] as $t) q("DELETE FROM $t WHERE request_id = ?", [$id]);
                q('DELETE FROM purchase_requests WHERE id = ?', [$id]);
            });
            audit('purchase.delete', 'Excluiu a ' . $r['title'] . ' (' . PURCHASE_STATUS[$st][0] . ', ' . $r['sector'] . ')', 'purchase_request', $id, $note ?: null);
            flash('ok', h($r['title']) . ' excluída.');
            go('comp.solicitacoes');
        default:
            $deny('Ação desconhecida.');
    }
}

// ---------- Cotação ----------
function route_comp__cotacao(): void {
    $u = require_perm('purchase:request', 'purchase:manage');
    $id = gi('id'); $r = pc_find($id);
    if (!pc_can_quote($r)) abort(403, 'Só quem pediu (ou Compras) registra as cotações.');
    if ($r['status'] !== 'COTACAO') { flash('bad', 'As cotações são registradas quando a direção pede cotação de preços.'); go('comp.solicitacao', ['id' => $id]); }
    $items = pc_items($id); $qid = gi('q'); $q = null;
    if ($qid) { $q = row('SELECT * FROM purchase_quotes WHERE id = ? AND request_id = ?', [$qid, $id]); if (!$q) abort(404); }
    $count = (int) val('SELECT COUNT(*) FROM purchase_quotes WHERE request_id = ?', [$id]);
    if (!$q && $count >= PURCHASE_MAX_QUOTES) { flash('bad', 'Esta solicitação já tem ' . PURCHASE_MAX_QUOTES . ' cotações. Exclua uma para registrar outra.'); go('comp.solicitacao', ['id' => $id]); }
    if (is_post()) {
        $in = ['supplier' => mb_substr(preg_replace('/\s+/', ' ', p('supplier')), 0, 160)] + pc_place_payment_from_post();
        $days = p('delivery_days'); $ship = p('shipping');
        $err = mb_strlen($in['supplier']) < 2 ? 'Informe o fornecedor.' : validate_purchase_place_payment($in);
        if (!$err && $days !== '' && (!ctype_digit($days) || (int) $days > 365)) $err = 'O prazo de entrega deve ser de 0 a 365 dias.';
        $shipC = $ship === '' ? 0 : parse_money($ship);
        if (!$err && ($shipC === null || $shipC < 0)) $err = 'O frete é inválido.';
        $prices = [];
        foreach ($items as $it) {
            $raw = trim((string) ($_POST['price'][$it['id']] ?? ''));
            $c = parse_money($raw);
            if (!$err && ($raw === '' || $c === null || $c < 0)) $err = 'Informe o preço por unidade de "' . $it['description'] . '".';
            $prices[(int) $it['id']] = (int) $c;
        }
        if ($err) { flash('bad', h($err)); $_SESSION['pc_quote'] = $_POST; go('comp.cotacao', array_filter(['id' => $id, 'q' => $qid])); }
        $total = purchase_quote_total(array_column($items, 'quantity', 'id'), $prices, (int) $shipC);
        $place = $in['place'] === 'Outro' ? $in['place_other'] : $in['place'];
        $data = ['supplier' => $in['supplier'], 'place' => mb_substr((string) $place, 0, 60), 'link' => $in['link'] ?: null, 'payment' => $in['payment'], 'installments' => $in['installments'],
            'delivery_days' => $days === '' ? null : (int) $days, 'shipping_cents' => (int) $shipC, 'total_cents' => $total, 'notes' => mb_substr(p('notes'), 0, 255) ?: null];
        tx(function () use ($q, $id, $data, $prices, $u, $r) {
            if ($q) { update('purchase_quotes', $data, 'id = ?', [$q['id']]); $qId = (int) $q['id']; q('DELETE FROM purchase_quote_prices WHERE quote_id = ?', [$qId]); }
            else $qId = insert('purchase_quotes', $data + ['request_id' => $id, 'created_by' => $u['id'], 'created_at' => now()]);
            foreach ($prices as $itemId => $c) insert('purchase_quote_prices', ['quote_id' => $qId, 'item_id' => $itemId, 'unit_cents' => $c]);
            pc_event($id, $q ? 'cotacao_editada' : 'cotacao', $data['supplier'] . ' · ' . money($data['total_cents']));
        });
        audit('purchase.quote', ($q ? 'Alterou' : 'Registrou') . ' a cotação de ' . $data['supplier'] . ' na solicitação ' . purchase_code($id), 'purchase_request', $id);
        flash('ok', 'Cotação de ' . h($data['supplier']) . ' ' . ($q ? 'alterada' : 'registrada') . ': ' . money($total) . '.');
        go('comp.solicitacao', ['id' => $id]);
    }
    $old = $_SESSION['pc_quote'] ?? null; unset($_SESSION['pc_quote']);
    $prices = $q ? array_column(rows('SELECT item_id, unit_cents FROM purchase_quote_prices WHERE quote_id = ?', [$q['id']]), 'unit_cents', 'item_id') : [];
    $placeSel = $q ? (in_array($q['place'], PURCHASE_PLACES, true) ? $q['place'] : 'Outro') : $r['place'];
    $v = $old ? ['place' => $old['place'] ?? '', 'place_other' => $old['place_other'] ?? '', 'link' => $old['link'] ?? '', 'payment' => $old['payment'] ?? 'PIX', 'installments' => (int) ($old['installments'] ?? 1)]
        : ($q ? ['place' => $placeSel, 'place_other' => $placeSel === 'Outro' ? $q['place'] : '', 'link' => $q['link'], 'payment' => $q['payment'], 'installments' => $q['installments']]
            : ['place' => $r['place'], 'place_other' => $r['place_other'], 'link' => $r['link'], 'payment' => $r['payment'], 'installments' => $r['installments']]);
    $tr = '';
    foreach ($items as $it) {
        $val = $old['price'][$it['id']] ?? (isset($prices[$it['id']]) ? number_format($prices[$it['id']] / 100, 2, ',', '.') : '');
        $tr .= '<tr><td><p class="cell-title">' . h($it['description']) . '</p>' . ($it['est_unit_cents'] !== null ? '<p class="cell-sub">Estimado: ' . money((int) $it['est_unit_cents']) . ' un.</p>' : '') . '</td><td class="r tnum">' . (int) $it['quantity'] . ' ' . h($it['unit']) . '</td>'
            . '<td>' . pc_money_input('price[' . (int) $it['id'] . ']', 'pr-' . (int) $it['id'], parse_money((string) $val), 'Preço por unidade de ' . h($it['description']), 'required') . '</td></tr>';
    }
    $body = form_open('comp.cotacao', array_filter(['id' => $id, 'q' => $qid]), 'class="pc-form" novalidate')
        . '<div class="card"><div class="card-body pc-stack" style="padding-top:20px"><div class="form-grid cols-2">' . input('supplier', 'Fornecedor ou vendedor', $old['supplier'] ?? ($q['supplier'] ?? ''), 'text', 'maxlength="160" required placeholder="Ex.: Alfa Informática"', '', 'span-2') . '</div>'
        . pc_place_payment_fields($v)
        . '<div class="form-grid cols-2">' . input('delivery_days', 'Prazo de entrega em dias <span class="muted">(opcional)</span>', $old['delivery_days'] ?? ($q['delivery_days'] ?? ''), 'text', 'inputmode="numeric" maxlength="3"')
        . field('<label for="f-shipping">Frete <span class="muted">(opcional)</span></label>', pc_money_input('shipping', 'f-shipping', parse_money((string) ($old['shipping'] ?? ($q && $q['shipping_cents'] ? number_format($q['shipping_cents'] / 100, 2, ',', '.') : ''))), 'Frete')) . '</div>'
        . textarea('notes', 'Observação <span class="muted">(opcional)</span>', $old['notes'] ?? ($q['notes'] ?? ''), 'maxlength="255" rows="2" placeholder="Ex.: garantia de 2 anos no local"') . '</div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Preço de cada item</h3><p>Preço por unidade, sem o frete. O total é calculado sozinho.</p></div></div><div class="table-wrap"><table><thead><tr><th>Item</th><th class="r">Qtd.</th><th style="width:180px">Preço por unidade</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>'
        . '<div class="row" style="justify-content:flex-end"><a class="btn btn-secondary" href="' . h(url('comp.solicitacao', ['id' => $id])) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . icon('check') . ' ' . ($q ? 'Salvar cotação' : 'Registrar cotação') . '</button></div></form>';
    layout('Cotação', crumb('comp.solicitacao', purchase_code($id), ['id' => $id]) . page_head(($q ? 'Editar cotação' : 'Registrar cotação') . ' · ' . ($count + ($q ? 0 : 1)) . ' de ' . PURCHASE_MAX_QUOTES, h($r['title'])) . $body);
}

// ---------- Recebimento ----------
function route_comp__receber(): void {
    $u = require_perm('purchase:manage');
    $id = gi('id'); $r = pc_find($id);
    if ($r['status'] !== 'COMPRADA') { flash('bad', 'O recebimento é registrado depois da compra.'); go('comp.solicitacao', ['id' => $id]); }
    $items = pc_items($id);
    $winner = $r['winner_quote_id'] ? row('SELECT supplier FROM purchase_quotes WHERE id = ?', [$r['winner_quote_id']]) : null;
    if (is_post()) {
        $nf = mb_substr(preg_replace('/\s+/', ' ', p('invoice_number')), 0, 60); $on = p('received_on');
        $err = $nf === '' ? 'Informe o número da NF-e.' : (!valid_date($on) || $on > today() ? 'Informe a data do recebimento (hoje ou antes).' : null);
        $got = []; $pat = [];
        foreach ($items as $it) {
            $raw = trim((string) ($_POST['recv'][$it['id']] ?? ''));
            if (!$err && (!ctype_digit($raw) || (int) $raw > (int) $it['quantity'])) $err = 'A quantidade recebida de "' . $it['description'] . '" deve ser de 0 a ' . (int) $it['quantity'] . '.';
            $got[$it['id']] = (int) $raw; $pat[$it['id']] = !empty($_POST['pat'][$it['id']]) ? 1 : 0;
        }
        if (!$err && !array_sum($got)) $err = 'Informe a quantidade recebida de pelo menos um item.';
        if ($err) { flash('bad', h($err)); go('comp.receber', ['id' => $id]); }
        $pending = false; foreach ($items as $it) if ($pat[$it['id']] && $got[$it['id']] > 0) $pending = true;
        tx(function () use ($items, $got, $pat, $id, $nf, $on, $u, $pending) {
            foreach ($items as $it) update('purchase_items', ['received_qty' => $got[$it['id']], 'patrimonial' => $pat[$it['id']]], 'id = ?', [$it['id']]);
            update('purchase_requests', ['status' => 'RECEBIDA', 'invoice_number' => $nf, 'received_on' => $on, 'received_by' => $u['id'], 'patrimony_status' => $pending ? 'PENDENTE' : ''], 'id = ?', [$id]);
            $partial = array_filter($items, fn($it) => $got[$it['id']] < (int) $it['quantity']);
            pc_event($id, 'recebida', 'NF-e ' . $nf . ($partial ? ' · recebimento parcial' : ''), 'COMPRADA');
        });
        audit('purchase.receive', 'Registrou o recebimento da solicitação ' . purchase_code($id) . ' (NF-e ' . $nf . ')', 'purchase_request', $id);
        flash('ok', 'Recebimento registrado.');
        go('comp.solicitacao', $pending ? ['id' => $id, 'patrimonio' => 1] : ['id' => $id]);
    }
    $tr = '';
    foreach ($items as $it) $tr .= '<tr><td><p class="cell-title">' . h($it['description']) . '</p></td><td class="r tnum">' . (int) $it['quantity'] . ' ' . h($it['unit']) . '</td>'
        . '<td><label class="sr-only" for="rv-' . (int) $it['id'] . '">Quantidade recebida de ' . h($it['description']) . '</label><input class="input r" style="max-width:96px" id="rv-' . (int) $it['id'] . '" name="recv[' . (int) $it['id'] . ']" value="' . (int) $it['quantity'] . '" inputmode="numeric" pattern="[0-9]*" required></td>'
        . '<td><label class="check-inline"><input type="checkbox" name="pat[' . (int) $it['id'] . ']" value="1"' . ($it['patrimonial'] ? ' checked' : '') . '> Patrimoniável</label></td></tr>';
    $body = form_open('comp.receber', ['id' => $id], 'class="pc-form"')
        . '<div class="card"><div class="card-body" style="padding-top:20px"><div class="form-grid cols-2">' . input('invoice_number', 'Número da NF-e', '', 'text', 'maxlength="60" required placeholder="Ex.: 000184552"')
        . input('received_on', 'Data do recebimento', today(), 'date', 'required max="' . today() . '"') . '</div></div></div>'
        . '<div class="card"><div class="card-head"><div><h3>Itens recebidos</h3><p>Confira as quantidades e marque o que pode ir para o Patrimônio. Os itens marcados ao pedir já vêm marcados.</p></div></div><div class="table-wrap"><table><thead><tr><th>Item</th><th class="r">Pedido</th><th style="width:130px">Recebido</th><th>Patrimônio</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div>'
        . '<div class="row" style="justify-content:flex-end"><a class="btn btn-secondary" href="' . h(url('comp.solicitacao', ['id' => $id])) . '">Cancelar</a><button class="btn btn-primary" type="submit">' . icon('check') . ' Confirmar recebimento</button></div></form>';
    layout('Recebimento', crumb('comp.solicitacao', purchase_code($id), ['id' => $id]) . page_head('Registrar recebimento', purchase_code($id) . ' · ' . h($r['title']) . ($winner ? ' · ' . h($winner['supplier']) : '')) . pc_stepper('COMPRADA') . $body);
}

// ---------- Envio ao Patrimônio ----------
/** Pasta de entrada do Patrimônio na mesma hospedagem (patrimonio/entradas-compras), ou null se o Patrimônio não estiver no site */
function pc_patrimony_inbox(): ?string {
    $url = function_exists('patrimony_settings') ? patrimony_settings()['url'] : 'patrimonio/';
    $folder = basename(trim((string) (parse_url($url, PHP_URL_PATH) ?: 'patrimonio'), '/')); // endereço completo ou só a pasta
    if (!preg_match('#^[a-z0-9_-]+$#i', $folder)) $folder = 'patrimonio';
    $dir = dirname(__DIR__, 2) . '/' . $folder;
    if (!is_dir($dir) || !is_file($dir . '/index.php')) return null;
    $inbox = $dir . '/entradas-compras';
    if (!is_dir($inbox) && !@mkdir($inbox, 0755)) return null;
    if (!is_file($inbox . '/.htaccess')) @file_put_contents($inbox . '/.htaccess', "Require all denied\nDeny from all\n");
    if (!is_file($inbox . '/index.html')) @file_put_contents($inbox . '/index.html', '');
    return $inbox;
}
/** Grava a entrada para o Patrimônio: um arquivo por envio, que o Patrimônio importa como NF-e em rascunho */
function pc_send_to_patrimony(array $r): array {
    $inbox = pc_patrimony_inbox();
    if (!$inbox) return [false, 'O Patrimônio não foi encontrado neste site. Confira se ele está instalado na pasta patrimonio/.'];
    $items = array_values(array_filter(pc_items((int) $r['id']), fn($i) => $i['patrimonial'] && (int) $i['received_qty'] > 0));
    if (!$items) return [false, 'Nenhum item patrimoniável recebido.'];
    $q = $r['winner_quote_id'] ? row('SELECT * FROM purchase_quotes WHERE id = ?', [$r['winner_quote_id']]) : null;
    $prices = $q ? array_column(rows('SELECT item_id, unit_cents FROM purchase_quote_prices WHERE quote_id = ?', [$q['id']]), 'unit_cents', 'item_id') : [];
    $out = [];
    foreach ($items as $i) {
        $unit = (int) ($prices[$i['id']] ?? $i['est_unit_cents'] ?? 0);
        $out[] = ['descricao' => $i['description'], 'quantidade' => (int) $i['received_qty'], 'unidade' => $i['unit'], 'valor_unitario' => number_format($unit / 100, 2, '.', ''), 'total' => number_format($unit * (int) $i['received_qty'] / 100, 2, '.', '')];
    }
    $id = 'summa-' . inst_id() . '-' . (int) $r['id'] . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
    $payload = ['versao' => 1, 'id' => $id, 'origem' => 'Summa Compras', 'solicitacao' => purchase_code((int) $r['id']), 'titulo' => $r['title'], 'setor' => $r['sector'],
        'solicitante' => $r['requester_name'], 'aprovado_por' => $r['decider_name'], 'fornecedor' => $q['supplier'] ?? '', 'nf_numero' => (string) $r['invoice_number'],
        'recebido_em' => (string) $r['received_on'], 'enviado_por' => current_user()['name'], 'enviado_em' => date('c'), 'itens' => $out];
    $tmp = $inbox . '/.' . $id . '.tmp';
    if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false || !@rename($tmp, $inbox . '/' . $id . '.json')) {
        @unlink($tmp);
        return [false, 'Não foi possível gravar na pasta do Patrimônio. Confira a permissão de escrita da pasta patrimonio/entradas-compras.'];
    }
    return [true, plural(count($out), 'item', 'itens') . ' · ' . plural(array_sum(array_column($out, 'quantidade')), 'unidade', 'unidades')];
}

// ---------- Fornecedores e relatórios ----------
function route_comp__fornecedores(): void {
    require_perm('purchase:manage', 'purchase:approve', 'purchase:read');
    $rows = rows("SELECT q.supplier, MAX(q.place) AS place, COUNT(*) AS n,
        SUM(CASE WHEN r.winner_quote_id = q.id THEN 1 ELSE 0 END) AS wins,
        SUM(CASE WHEN r.winner_quote_id = q.id AND r.status IN ('COMPRADA','RECEBIDA') THEN r.approved_cents ELSE 0 END) AS bought,
        MAX(q.created_at) AS last
        FROM purchase_quotes q JOIN purchase_requests r ON r.id = q.request_id WHERE r.institution_id = ? GROUP BY q.supplier ORDER BY bought DESC, n DESC, q.supplier", [inst_id()]);
    $tr = '';
    foreach ($rows as $s) $tr .= '<tr><td><p class="cell-title">' . h($s['supplier']) . '</p><p class="cell-sub">' . h($s['place']) . '</p></td><td class="r tnum">' . (int) $s['n'] . '</td><td class="r tnum">' . (int) $s['wins'] . '</td><td class="r tnum">' . money((int) $s['bought']) . '</td><td class="tnum muted">' . fmt_date($s['last']) . '</td></tr>';
    layout('Fornecedores', page_head('Fornecedores', 'Montado a partir das cotações registradas.') . '<div class="card"><div class="table-wrap"><table><thead><tr><th>Fornecedor</th><th class="r">Cotações</th><th class="r">Escolhido</th><th class="r">Comprado</th><th>Última cotação</th></tr></thead><tbody>'
        . ($tr ?: '<tr><td colspan="5">' . empty_state('truck', 'Nenhum fornecedor ainda', 'Eles aparecem aqui quando Compras registra cotações.') . '</td></tr>') . '</tbody></table></div></div>');
}
function route_comp__relatorios(): void {
    require_perm('purchase:manage', 'purchase:approve', 'purchase:read');
    $i = inst_id();
    $done = "r.status IN ('COMPRADA','RECEBIDA')";
    $months = rows("SELECT substr(r.purchased_on, 1, 7) AS m, COUNT(*) AS n, SUM(r.approved_cents) AS v FROM purchase_requests r WHERE r.institution_id = ? AND $done GROUP BY substr(r.purchased_on, 1, 7) ORDER BY m DESC LIMIT 12", [$i]);
    $sectors = rows("SELECT r.sector, COUNT(*) AS n, SUM(r.approved_cents) AS v FROM purchase_requests r WHERE r.institution_id = ? AND $done GROUP BY r.sector ORDER BY v DESC", [$i]);
    $status = array_column(rows("SELECT status, COUNT(*) AS n FROM purchase_requests WHERE institution_id = ? AND status <> 'RASCUNHO' GROUP BY status", [$i]), 'n', 'status');
    $tbl = function (string $title, string $col, array $rows, callable $label) {
        $tr = ''; $t = 0; foreach ($rows as $x) { $t += (int) $x['v']; $tr .= '<tr><td>' . $label($x) . '</td><td class="r tnum">' . (int) $x['n'] . '</td><td class="r tnum">' . money((int) $x['v']) . '</td></tr>'; }
        return '<div class="card"><div class="card-head"><h3>' . $title . '</h3></div><div class="table-wrap"><table><thead><tr><th>' . $col . '</th><th class="r">Compras</th><th class="r">Valor</th></tr></thead><tbody>'
            . ($tr ?: '<tr><td colspan="3">' . empty_state('chart-column', 'Sem compras registradas') . '</td></tr>') . '</tbody>' . ($tr ? '<tfoot><tr><td><strong>Total</strong></td><td></td><td class="r tnum"><strong>' . money($t) . '</strong></td></tr></tfoot>' : '') . '</table></div></div>';
    };
    $st = ''; foreach (PURCHASE_STATUS as $k => [$l]) if ($k !== 'RASCUNHO') $st .= '<tr><td>' . pc_badge($k) . '</td><td class="r tnum">' . (int) ($status[$k] ?? 0) . '</td></tr>';
    layout('Relatórios de compras', page_head('Relatórios', 'Compras feitas (compradas ou recebidas) pelo valor aprovado.', '<button class="btn btn-secondary" type="button" data-print>' . icon('printer') . ' Imprimir</button>')
        . '<div class="grid g2" style="align-items:start">' . $tbl('Por mês', 'Mês', $months, fn($x) => month_label((string) $x['m'])) . $tbl('Por setor', 'Setor', $sectors, fn($x) => h($x['sector'])) . '</div>'
        . '<div class="card" style="margin-top:16px;max-width:520px"><div class="card-head"><h3>Solicitações por etapa</h3></div><div class="table-wrap"><table><tbody>' . $st . '</tbody></table></div></div>');
}

// ---------- Setores ----------
function route_comp__setores(): void {
    require_perm('purchase:manage', 'purchase:approve');
    if (is_post()) {
        $list = parse_purchase_sectors(p('sectors'));
        if (!$list) { flash('bad', 'Informe pelo menos um setor.'); go('comp.setores'); }
        set_setting('purchase_sectors:' . inst_id(), json_encode($list, JSON_UNESCAPED_UNICODE));
        audit('purchase.sectors', 'Atualizou a lista de setores de Compras (' . count($list) . ')', 'institution', inst_id());
        flash('ok', plural(count($list), 'setor salvo', 'setores salvos') . '. Eles aparecem na lista da nova solicitação.');
        go('comp.setores');
    }
    $used = array_column(rows('SELECT sector, COUNT(*) AS n FROM purchase_requests WHERE institution_id = ? GROUP BY sector', [inst_id()]), 'n', 'sector');
    $tr = ''; foreach (pc_sectors() as $sc) $tr .= '<tr><td>' . h($sc) . '</td><td class="r tnum">' . (int) ($used[$sc] ?? 0) . '</td></tr>';
    layout('Setores', page_head('Setores da instituição', 'Lista que aparece no campo Setor da solicitação de compra.')
        . '<div class="grid g2" style="align-items:start">' . form_open('comp.setores', [], 'class="card"') . '<div class="card-body pc-stack" style="padding-top:20px">'
        . textarea('sectors', 'Um setor por linha', implode("\n", pc_sectors()), 'rows="14" maxlength="6000"')
        . '<p class="small muted">Repetidos e linhas vazias são ignorados; a lista fica em ordem alfabética. Tirar um setor daqui não muda as solicitações já feitas.</p>'
        . '<div class="row" style="justify-content:flex-end"><button class="btn btn-primary" type="submit">' . icon('check') . ' Salvar setores</button></div></div></form>'
        . '<div class="card"><div class="card-head"><h3>Em uso</h3></div><div class="table-wrap"><table><thead><tr><th>Setor</th><th class="r">Solicitações</th></tr></thead><tbody>' . $tr . '</tbody></table></div></div></div>');
}

// ---------- Avisos do sino ----------
function purchase_notifications(array $u): array {
    if (!in_array('comp', $u['portals'], true)) return [];
    $out = []; $i = inst_id();
    $add = function (string $id, string $icon, string $title, string $desc, string $href) use (&$out) { $out[] = compact('id', 'icon', 'title', 'desc', 'href'); };
    if (in_array('purchase:approve', $u['perms'], true)) {
        $ids = col("SELECT id FROM purchase_requests WHERE institution_id = ? AND status IN ('ENVIADA','APROVACAO') ORDER BY id", [$i]);
        if ($ids) $add('pc-approve:' . implode('.', $ids), 'signature', plural(count($ids), 'compra aguardando a direção', 'compras aguardando a direção'), 'Aprove, peça cotação ou recuse', url('comp.inicio', ['etapa' => 'ENVIADA']));
    }
    $ids = col("SELECT id FROM purchase_requests WHERE institution_id = ? AND requester_id = ? AND status = 'COTACAO' ORDER BY id", [$i, $u['id']]);
    if ($ids) $add('pc-quote:' . implode('.', $ids), 'clipboard-list', 'A direção pediu cotação de preços', plural(count($ids), 'solicitação sua', 'solicitações suas') . ': registre até 3 orçamentos', count($ids) === 1 ? url('comp.solicitacao', ['id' => $ids[0]]) : url('comp.inicio', ['etapa' => 'COTACAO']));
    if (in_array('purchase:manage', $u['perms'], true)) {
        $ids = col("SELECT id FROM purchase_requests WHERE institution_id = ? AND status = 'APROVADA' ORDER BY id", [$i]);
        if ($ids) $add('pc-buy:' . implode('.', $ids), 'shopping-cart', plural(count($ids), 'compra aprovada para fazer o pedido', 'compras aprovadas para fazer o pedido'), 'Registre a compra na ficha', url('comp.inicio', ['etapa' => 'APROVADA']));
    }
    foreach (rows("SELECT id, title, status, updated_at FROM purchase_requests WHERE institution_id = ? AND requester_id = ? AND status IN ('APROVADA','RECUSADA','COMPRADA','RECEBIDA') AND updated_at >= ? ORDER BY updated_at DESC LIMIT 5", [$i, $u['id'], date('Y-m-d H:i:s', strtotime('-30 days'))]) as $r)
        $add('pc-mine:' . $r['id'] . ':' . $r['status'], 'shopping-cart', purchase_code((int) $r['id']) . ': ' . PURCHASE_STATUS[$r['status']][0], $r['title'], url('comp.solicitacao', ['id' => $r['id']]));
    return $out;
}
