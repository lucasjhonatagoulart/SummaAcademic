<?php
// Regras do sistema, sem acesso ao banco (testadas em tests/run.php).

// ---------- Permissões ----------
/** Permissões efetivas = (perfis ∪ liberadas) − bloqueadas */
function effective_perms(array $rolePerms, array $overrides): array {
    $set = array_fill_keys($rolePerms, true);
    foreach ($overrides as $code => $eff) if ($eff === 'GRANT') $set[$code] = true;
    foreach ($overrides as $code => $eff) if ($eff === 'DENY') unset($set[$code]);
    return array_keys($set);
}
/** Lista marcada → ajustes em relação ao perfil (marcada fora do perfil = GRANT; desmarcada do perfil = DENY) */
function overrides_from_selection(array $rolePerms, array $selected): array {
    $out = [];
    foreach (array_keys(perm_catalog()) as $code) {
        $sel = in_array($code, $selected, true); $base = in_array($code, $rolePerms, true);
        if ($sel && !$base) $out[$code] = 'GRANT';
        if (!$sel && $base) $out[$code] = 'DENY';
    }
    return $out;
}
/** Travas contra perder o acesso. $before/$after: ['id','roles','active','perms'] */
function access_change_error(int $actorId, array $before, array $after, int $activeAdmins): ?string {
    $self = $actorId === (int) $before['id'];
    if (!$after['perms']) return 'Marque pelo menos uma permissão.';
    if ($self && !$after['active']) return 'Você não pode bloquear a própria conta.';
    if ($self && !in_array('users:manage', $after['perms'], true)) return 'Você não pode tirar de si mesmo o acesso ao gerenciamento de usuários.';
    $wasAdmin = $before['active'] && in_array('ADMIN', $before['roles'], true);
    $staysAdmin = $after['active'] && in_array('ADMIN', $after['roles'], true);
    if ($wasAdmin && !$staysAdmin && $activeAdmins <= 1) return 'A instituição precisa de pelo menos um administrador ativo.';
    return null;
}

// ---------- Senhas ----------
const TEMP_PASSWORD_DAYS = 7;
/** 12 caracteres, com maiúscula, minúscula, número e símbolo; sem caracteres ambíguos (0/O, 1/l/I) */
function gen_temp_password(int $len = 12): string {
    $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '@#$%&*!?'];
    $all = implode('', $sets);
    $c = array_map(fn($s) => $s[random_int(0, strlen($s) - 1)], $sets);
    while (count($c) < $len) $c[] = $all[random_int(0, strlen($all) - 1)];
    for ($i = count($c) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$c[$i], $c[$j]] = [$c[$j], $c[$i]]; }
    return implode('', $c);
}
function validate_new_password(string $pw, string $current, string $email, string $confirm): ?string {
    if (mb_strlen($pw) < 8) return 'A nova senha precisa ter pelo menos 8 caracteres.';
    if (mb_strlen($pw) > 128) return 'A nova senha é longa demais.';
    if (!preg_match('/\p{L}/u', $pw) || !preg_match('/\d/', $pw)) return 'Use letras e números na nova senha.';
    if ($pw === $current) return 'A nova senha precisa ser diferente da atual.';
    $local = strtolower(strstr($email, '@', true) ?: $email);
    if ($local !== '' && str_contains(strtolower($pw), $local)) return 'A nova senha não pode conter o seu e-mail.';
    if ($pw !== $confirm) return 'A confirmação não confere com a nova senha.';
    return null;
}

// ---------- Comunicados e quadro de avisos ----------
const AUDIENCE_LABEL = ['ALL' => 'Toda a comunidade', 'STUDENTS' => 'Alunos', 'PROFESSORS' => 'Professores', 'STAFF' => 'Colaboradores'];
const BOARD_CATEGORY = [
    'ACADEMIC' => ['Acadêmico', 'graduation-cap', '#2878D0', '#E8F1FB', '#1F68B8'],
    'EVENT' => ['Evento', 'calendar-days', '#F0B44D', '#FDF4E3', '#7A5310'],
    'CAMPAIGN' => ['Campanha', 'sparkles', '#172B4D', '#E9EDF3', '#172B4D'],
    'FINANCE' => ['Financeiro', 'wallet', '#1F845A', '#E6F4EE', '#1F845A'],
    'INSTITUTIONAL' => ['Institucional', 'landmark', '#8D9BB3', '#F1F3F7', '#44546F'],
];
/** Colaborador = acesso total ou qualquer permissão fora dos portais de professor e aluno */
function is_staff(array $roles, array $perms): bool {
    if (in_array('ADMIN', $roles, true)) return true;
    foreach ($perms as $c) if (!in_array(perm_catalog()[$c][0] ?? 'student', ['classroom', 'student'], true)) return true;
    return false;
}
function audiences_for(array $roles, array $perms = []): array {
    $a = ['ALL'];
    if (in_array('STUDENT', $roles, true)) $a[] = 'STUDENTS';
    if (in_array('PROFESSOR', $roles, true)) $a[] = 'PROFESSORS';
    if (is_staff($roles, $perms)) $a[] = 'STAFF';
    return $a;
}
/** Setor que assina comunicados e avisos, a partir do acesso do usuário */
function sector_for(array $roles, array $perms = []): string {
    $has = fn(array $c) => (bool) array_intersect($c, $perms);
    if (in_array('ADMIN', $roles, true)) return 'Administração';
    if ($has(['students:manage', 'professors:manage', 'courses:manage', 'terms:manage', 'classes:manage', 'transcripts:issue', 'announcements:manage'])) return 'Secretaria Acadêmica';
    if ($has(['reports:institutional'])) return 'Diretoria';
    if ($has(['finance:manage', 'payroll:manage'])) return 'Financeiro';
    if (in_array('PROFESSOR', $roles, true)) return 'Docentes';
    return 'Instituição';
}
function announcement_state(string $pub, ?string $exp, string $today): string {
    if ($pub > $today) return 'SCHEDULED';
    if ($exp && $exp < $today) return 'EXPIRED';
    return 'PUBLISHED';
}
function can_edit_announcement(array $perms, array $roles, array $a): bool {
    if (in_array('announcements:manage', $perms, true)) return true;
    if ($a['kind'] === 'COMM') return false;
    return in_array('board:manage', $perms, true) && $a['author_label'] === sector_for($roles, $perms);
}
function validate_announcement(array $i, string $today): ?string {
    $max = $i['kind'] === 'BOARD' ? 280 : 2000;
    if (mb_strlen(trim($i['title'])) < 5) return 'Informe um título com pelo menos 5 caracteres.';
    if (mb_strlen(trim($i['body'])) < 10) return 'Escreva o texto (pelo menos 10 caracteres).';
    if (mb_strlen($i['body']) > $max) return "O texto passou de $max caracteres.";
    if ($i['kind'] === 'BOARD' && !isset(BOARD_CATEGORY[$i['category'] ?? ''])) return 'Escolha a categoria do aviso.';
    if (!isset(AUDIENCE_LABEL[$i['audience'] ?? ''])) return 'Selecione o público.';
    if (!valid_date($i['published_on'])) return 'Informe a data de publicação.';
    if ($i['expires_on']) {
        if (!valid_date($i['expires_on'])) return 'Data “Exibir até” inválida.';
        if ($i['expires_on'] < $i['published_on']) return '“Exibir até” não pode ser antes da data de publicação.';
        if ($i['expires_on'] < $today) return '“Exibir até” já passou: o conteúdo não apareceria para ninguém.';
    }
    return null;
}

// ---------- Horários ----------
const WEEKDAYS = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
const WEEKDAY_SHORT = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'];
const STANDARD_SLOTS = [['07:30', '09:10'], ['09:20', '11:00'], ['13:30', '15:10'], ['15:20', '17:00'], ['19:00', '20:40'], ['20:50', '22:30']];
function slots_overlap(array $a, array $b): bool {
    return (int) $a['weekday'] === (int) $b['weekday'] && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'];
}
function slot_label(array $s): string { return WEEKDAY_SHORT[(int) $s['weekday']] . ' ' . $s['start_time'] . '–' . $s['end_time']; }
function valid_time(string $t): bool { return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t); }

// ---------- Notas e frequência ----------
/** Média ponderada das avaliações com nota; null sem notas */
function weighted_average(array $items): ?float {
    $sw = 0; $s = 0;
    foreach ($items as $it) { if ($it['score'] === null) continue; $sw += (float) $it['weight']; $s += (float) $it['score'] * (float) $it['weight']; }
    return $sw > 0 ? round($s / $sw, 2) : null;
}
function attendance_pct(int $present, int $total): ?float { return $total > 0 ? round($present * 100 / $total, 1) : null; }
/** Situação final: frequência só conta se a disciplina registra frequência */
function final_status(?float $avg, ?float $att, float $minGrade, float $minAtt, bool $tracks): string {
    if ($tracks && $att !== null && $att < $minAtt) return 'REP_FREQ';
    if ($avg === null) return 'REPROVADO';
    return $avg >= $minGrade ? 'APROVADO' : 'REPROVADO';
}
function at_risk(?float $avg, ?float $att, float $minGrade, float $minAtt, bool $tracks): bool {
    return ($avg !== null && $avg < $minGrade) || ($tracks && $att !== null && $att < $minAtt);
}
const STATUS_LABEL = ['CURSANDO' => 'Cursando', 'APROVADO' => 'Aprovado', 'REPROVADO' => 'Reprovado', 'REP_FREQ' => 'Reprovado por frequência', 'TRANCADO' => 'Trancado', 'APROVEITADO' => 'Aproveitado'];
const STATUS_TONE = ['CURSANDO' => 'b-info', 'APROVADO' => 'b-ok', 'REPROVADO' => 'b-bad', 'REP_FREQ' => 'b-bad', 'TRANCADO' => 'b-neutral', 'APROVEITADO' => 'b-ok'];
const STUDENT_STATUS = ['ATIVO' => 'Ativo', 'TRANCADO' => 'Trancado', 'FORMADO' => 'Formado', 'CANCELADO' => 'Cancelado'];

/** Coeficiente de rendimento: média das notas finais ponderada pela carga horária */
function weighted_cr(array $done): ?float {
    $w = 0; $s = 0;
    foreach ($done as $d) if ($d['final_grade'] !== null && in_array($d['status'], ['APROVADO', 'REPROVADO', 'REP_FREQ'], true)) { $w += $d['workload']; $s += $d['final_grade'] * $d['workload']; }
    return $w ? round($s / $w, 2) : null;
}
function transcript_code(): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $c = ''; for ($i = 0; $i < 12; $i++) $c .= $a[random_int(0, strlen($a) - 1)];
    return substr($c, 0, 4) . '-' . substr($c, 4, 4) . '-' . substr($c, 8, 4);
}

// ---------- Financeiro ----------
function charge_state(array $c, string $today): string {
    if ($c['status'] === 'ABERTA' && $c['due_on'] < $today) return 'VENCIDA';
    return $c['status'];
}
const CHARGE_LABEL = ['ABERTA' => 'Em aberto', 'VENCIDA' => 'Vencida', 'PAGA' => 'Paga', 'CANCELADA' => 'Cancelada'];
const CHARGE_TONE = ['ABERTA' => 'b-info', 'VENCIDA' => 'b-bad', 'PAGA' => 'b-ok', 'CANCELADA' => 'b-neutral'];
function parse_money(string $s): ?int {
    $s = trim(str_replace(['R$', ' '], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
    if (!is_numeric($s) || (float) $s < 0) return null;
    return (int) round((float) $s * 100);
}

// ---------- Pagamento de professores ----------
/** Divide um valor em centavos em $n partes que somam exatamente o total (os centavos que sobram vão para as primeiras). */
function split_cents(int $total, int $n): array {
    $n = max(1, $n); $base = intdiv($total, $n); $rest = $total - $base * $n;
    $out = [];
    for ($k = 0; $k < $n; $k++) $out[] = $base + ($k < $rest ? 1 : 0);
    return $out;
}
/** Valor de uma aula: por aula (encontro) ou por hora-aula × horas registradas */
function lesson_value(int $rate, string $unit, int $hours): int { return $unit === 'HORA' ? $rate * max(1, $hours) : $rate; }


// ---------- Documentos e validações ----------
function valid_date(?string $d): bool { return $d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)); }
function only_digits(?string $s): string { return preg_replace('/\D/', '', (string) $s); }
function valid_cpf(?string $cpf): bool {
    $c = only_digits($cpf);
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    for ($t = 9; $t < 11; $t++) { $d = 0; for ($i = 0; $i < $t; $i++) $d += (int) $c[$i] * (($t + 1) - $i); $d = ((10 * $d) % 11) % 10; if ((int) $c[$t] !== $d) return false; }
    return true;
}
function valid_cnpj(?string $cnpj): bool {
    $c = only_digits($cnpj);
    if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $n) {
        $w = $n === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $s = 0; for ($i = 0; $i < $n; $i++) $s += (int) $c[$i] * $w[$i];
        $d = $s % 11 < 2 ? 0 : 11 - $s % 11;
        if ((int) $c[$n] !== $d) return false;
    }
    return true;
}
function fold(string $s): string {
    $s = mb_strtolower($s);
    return strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'î' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c']);
}

// ---------- Compras ----------
/** Etapas da solicitação: código => [rótulo, classe do selo] */
const PURCHASE_STATUS = [
    'RASCUNHO' => ['Rascunho', 'b-neutral'], 'ENVIADA' => ['Aguardando a direção', 'b-warn'], 'COTACAO' => ['Em cotação', 'b-info'],
    'APROVACAO' => ['Cotação para aprovar', 'b-warn'], 'APROVADA' => ['Aprovada', 'b-teal'], 'COMPRADA' => ['Comprada', 'b-violet'],
    'RECEBIDA' => ['Recebida', 'b-ok'], 'RECUSADA' => ['Recusada', 'b-bad'], 'CANCELADA' => ['Cancelada', 'b-neutral'],
];
/** Passos do andamento mostrados na ficha (a cotação só acontece quando a direção pede) */
const PURCHASE_STEPS = ['Enviada', 'Cotação', 'Aprovação', 'Compra', 'Recebimento'];
/** Setores sugeridos enquanto a instituição não cadastra os seus */
const PURCHASE_DEFAULT_SECTORS = ['Administração', 'Biblioteca', 'Coordenação', 'Diretoria', 'Financeiro', 'Laboratórios', 'Manutenção', 'Secretaria', 'TI'];
const PURCHASE_PLACES = ['Mercado Livre', 'Magalu', 'Amazon', 'Shopee', 'Site do fornecedor', 'Fornecedor local ou loja física', 'Outro'];
/** Locais que são sites: pedem link do produto */
const PURCHASE_ONLINE = ['Mercado Livre', 'Magalu', 'Amazon', 'Shopee', 'Site do fornecedor'];
const PURCHASE_PAYMENTS = ['PIX' => 'Pix', 'BOLETO' => 'Boleto', 'CARTAO' => 'Cartão de crédito'];
const PURCHASE_UNITS = ['un.', 'cx.', 'pct.', 'resma', 'kg', 'l', 'm', 'par', 'jogo', 'kit'];
const PURCHASE_MAX_QUOTES = 3;

function purchase_code(int $id): string { return 'Nº ' . str_pad((string) $id, 4, '0', STR_PAD_LEFT); }
/** Título gerado: "Solicitação de Compra Nº 0007" */
function purchase_title(int $id): string { return 'Solicitação de Compra Nº ' . str_pad((string) $id, 4, '0', STR_PAD_LEFT); }
/** Resumo dos itens para as listas: "Notebook 15 polegadas + 2 itens" */
function purchase_summary(?string $first, int $count): string {
    $first = trim((string) $first);
    if ($first === '') return 'Sem itens';
    return $count > 1 ? $first . ' + ' . ($count - 1) . ' ' . ($count - 1 === 1 ? 'item' : 'itens') : $first;
}
/** Índice do passo atual no andamento (5 = concluída). Enviada já espera a direção (passo Aprovação). */
function purchase_step(string $status): int {
    return ['RASCUNHO' => 0, 'ENVIADA' => 2, 'COTACAO' => 1, 'APROVACAO' => 2, 'RECUSADA' => 2, 'APROVADA' => 3, 'COMPRADA' => 4, 'RECEBIDA' => 5, 'CANCELADA' => 0][$status] ?? 0;
}
function purchase_payment_label(string $payment, int $installments): string {
    $l = PURCHASE_PAYMENTS[$payment] ?? $payment;
    return $payment === 'CARTAO' ? $l . ' · ' . ($installments > 1 ? $installments . 'x' : 'à vista') : $l;
}
function purchase_place_label(string $place, ?string $other): string { return $place === 'Outro' && trim((string) $other) !== '' ? trim((string) $other) : $place; }
/** Total de uma cotação: soma de quantidade × preço de cada item, mais o frete */
function purchase_quote_total(array $qtyByItem, array $unitByItem, int $shipping = 0): int {
    $t = $shipping;
    foreach ($qtyByItem as $id => $q) $t += (int) $q * (int) ($unitByItem[$id] ?? 0);
    return $t;
}
/** Cotação de menor total (empate: a registrada primeiro) */
function purchase_cheapest(array $quotes): ?int {
    $best = null;
    foreach ($quotes as $q) if ($best === null || (int) $q['total_cents'] < (int) $best['total_cents']) $best = $q;
    return $best ? (int) $best['id'] : null;
}
/** Setores digitados um por linha: sem vazios, sem repetidos, em ordem alfabética */
function parse_purchase_sectors(string $text): array {
    $out = [];
    foreach (preg_split('/\R/u', $text) as $l) { $l = trim(preg_replace('/\s+/u', ' ', $l)); if ($l !== '' && mb_strlen($l) <= 80) $out[mb_strtolower($l)] ??= $l; }
    $out = array_values($out); sort($out, SORT_LOCALE_STRING);
    return array_slice($out, 0, 100);
}
function valid_http_url(string $u): bool { return (bool) preg_match('#^https?://[^\s<>"]{3,}$#i', $u) && filter_var($u, FILTER_VALIDATE_URL) !== false; }
/** Confere local e forma de pagamento (pedido e cotação usam as mesmas regras) */
function validate_purchase_place_payment(array $i): ?string {
    if (!in_array($i['place'] ?? '', PURCHASE_PLACES, true)) return 'Escolha o local da compra.';
    if (($i['place'] ?? '') === 'Outro' && trim((string) ($i['place_other'] ?? '')) === '') return 'Informe qual é o local da compra.';
    if (($i['link'] ?? '') !== '' && !valid_http_url($i['link'])) return 'O link precisa começar com http:// ou https://.';
    if (!isset(PURCHASE_PAYMENTS[$i['payment'] ?? ''])) return 'Escolha a forma de pagamento.';
    $n = (int) ($i['installments'] ?? 1);
    if (($i['payment'] ?? '') === 'CARTAO' && ($n < 1 || $n > 12)) return 'Escolha de 1 a 12 parcelas.';
    return null;
}
/** Confere uma solicitação antes de salvar; $items: [['description','quantity','unit','est_unit_cents','patrimonial']]; $sectors: setores aceitos */
function validate_purchase_request(array $i, array $items, string $today, array $sectors = []): ?string {
    if (trim((string) ($i['sector'] ?? '')) === '' || mb_strlen((string) $i['sector']) > 80 || ($sectors && !in_array($i['sector'], $sectors, true))) return 'Escolha o setor.';
    if (mb_strlen(trim((string) ($i['justification'] ?? ''))) < 10) return 'Explique em uma frase por que a compra é necessária.';
    if (($i['needed_by'] ?? '') !== '' && (!valid_date($i['needed_by']) || $i['needed_by'] < $today)) return 'A data "Precisa até" deve ser hoje ou depois.';
    if (!in_array($i['priority'] ?? '', ['NORMAL', 'URGENTE'], true)) return 'Escolha a prioridade.';
    if ($e = validate_purchase_place_payment($i)) return $e;
    if (!$items) return 'Inclua pelo menos um item.';
    if (count($items) > 50) return 'Uma solicitação pode ter até 50 itens.';
    foreach ($items as $k => $it) {
        $n = $k + 1;
        if (mb_strlen($it['description']) < 2 || mb_strlen($it['description']) > 255) return "Descreva o item $n.";
        if ($it['quantity'] < 1 || $it['quantity'] > 100000) return "A quantidade do item $n deve ser de 1 a 100.000.";
        if (!in_array($it['unit'], PURCHASE_UNITS, true)) return "Escolha a unidade do item $n.";
        if ($it['est_unit_cents'] === null || $it['est_unit_cents'] <= 0) return "Informe o valor estimado do item $n (a direção pode aprovar sem cotação).";
    }
    return null;
}
