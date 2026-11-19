<?php
// Notificações do sino: comunicados novos e avisos de cada área, conforme as permissões do usuário.
// Cada item tem um id que muda quando a situação muda (ex.: "3 parcelas vencidas" → "4 parcelas vencidas"),
// assim o número vermelho mostra só o que é novo desde a última vez que o usuário abriu o sino.

/** @return array<int, array{id:string, icon:string, title:string, desc:string, href:string}> */
function notifications_for(array $u): array {
    $out = []; $i = inst_id(); $t = today(); $term = active_term();
    $add = function (string $id, string $icon, string $title, string $desc, string $href) use (&$out) { $out[] = compact('id', 'icon', 'title', 'desc', 'href'); };

    // Aluno: notas publicadas e mensalidades
    if ($u['student'] && in_array('aluno', $u['portals'], true)) {
        $sid = (int) $u['student']['id'];
        if ($term) foreach (rows("SELECT a.id, a.name, sub.name AS subject, se.id AS section_id FROM assessments a JOIN sections se ON se.id = a.section_id JOIN subjects sub ON sub.id = se.subject_id JOIN enrollments e ON e.section_id = se.id
            WHERE e.student_id = ? AND e.status = 'CURSANDO' AND se.term_id = ? AND a.published = 1 ORDER BY a.id DESC LIMIT 5", [$sid, $term['id']]) as $a)
            $add('grade:' . $a['id'], 'clipboard-check', 'Nota publicada: ' . $a['name'], $a['subject'], url('aluno.disciplina', ['id' => $a['section_id'], 'aba' => 'notas']));
        $over = row("SELECT COUNT(*) AS n, MIN(due_on) AS d FROM charges WHERE student_id = ? AND status = 'ABERTA' AND due_on < ?", [$sid, $t]);
        if ($over && $over['n']) $add('fin-over:' . $over['n'] . ':' . $over['d'], 'wallet', plural((int) $over['n'], 'mensalidade vencida', 'mensalidades vencidas'), 'A mais antiga venceu em ' . fmt_date($over['d']), url('aluno.financeiro'));
        $soon = row("SELECT id, due_on FROM charges WHERE student_id = ? AND status = 'ABERTA' AND due_on >= ? AND due_on <= ? ORDER BY due_on LIMIT 1", [$sid, $t, date('Y-m-d', strtotime('+5 days'))]);
        if ($soon) $add('fin-soon:' . $soon['id'], 'calendar-clock', 'Mensalidade vence em breve', 'Vencimento em ' . fmt_date($soon['due_on']), url('aluno.financeiro'));
    }

    // Professor: prazo de notas e avaliações sem notas lançadas
    if ($u['professor'] && in_array('prof', $u['portals'], true) && $term) {
        $pid = (int) $u['professor']['id'];
        if (!empty($term['grades_deadline']) && $term['grades_deadline'] >= $t && $term['grades_deadline'] <= date('Y-m-d', strtotime('+10 days')))
            $add('deadline:' . $term['id'] . ':' . $term['grades_deadline'], 'alarm-clock', 'Prazo de lançamento de notas', 'Até ' . fmt_date($term['grades_deadline']) . ' · período ' . $term['name'], url('prof.turmas'));
        foreach (rows("SELECT a.id, a.name, sub.name AS subject, se.id AS section_id,
                (SELECT COUNT(*) FROM enrollments e WHERE e.section_id = se.id AND e.status = 'CURSANDO') AS n,
                (SELECT COUNT(*) FROM grades g WHERE g.assessment_id = a.id) AS done
            FROM assessments a JOIN sections se ON se.id = a.section_id JOIN subjects sub ON sub.id = se.subject_id JOIN section_professors sp ON sp.section_id = se.id
            WHERE sp.professor_id = ? AND se.term_id = ? AND a.due_on IS NOT NULL AND a.due_on < ? ORDER BY a.due_on DESC LIMIT 20", [$pid, $term['id'], $t]) as $a)
            if ($a['n'] > $a['done']) $add('pending-grades:' . $a['id'] . ':' . $a['done'], 'pencil-line', 'Notas pendentes: ' . $a['name'], $a['subject'] . ' · ' . ($a['n'] - $a['done']) . ' de ' . $a['n'] . ' alunos sem nota', url('prof.turma', ['id' => $a['section_id'], 'aba' => 'notas']));
    }

    // Financeiro: parcelas vencidas e a vencer
    if (in_array('finance:manage', $u['perms'], true)) {
        $n = (int) val("SELECT COUNT(*) FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on < ?", [$i, $t]);
        if ($n) $add('fin-overdue:' . $n, 'triangle-alert', plural($n, 'parcela vencida', 'parcelas vencidas'), 'Veja quem está em atraso e envie avisos', url('fin.cobrancas', ['situacao' => 'VENCIDA']));
        $s = (int) val("SELECT COUNT(*) FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on >= ? AND due_on <= ?", [$i, $t, date('Y-m-d', strtotime('+3 days'))]);
        if ($s) $add('fin-due:' . $t . ':' . $s, 'calendar-clock', plural($s, 'parcela vence', 'parcelas vencem') . ' em até 3 dias', 'Mensalidades em aberto', url('fin.cobrancas', ['situacao' => 'ABERTA']));
    } elseif (in_array('reports:finance', $u['perms'], true)) {
        $n = (int) val("SELECT COUNT(DISTINCT student_id) FROM charges WHERE institution_id = ? AND status = 'ABERTA' AND due_on < ?", [$i, $t]);
        if ($n) $add('dir-debt:' . $n, 'wallet', plural($n, 'aluno inadimplente', 'alunos inadimplentes'), 'Veja o resumo em Financeiro', url('dir.financeiro'));
    }

    // Secretaria / administração: ofertas sem professor e senhas provisórias vencidas
    if ($term && array_intersect(['classes:manage', 'reports:institutional'], $u['perms'])) {
        $n = (int) val('SELECT COUNT(*) FROM sections se WHERE se.term_id = ? AND NOT EXISTS (SELECT 1 FROM section_professors sp WHERE sp.section_id = se.id)', [$term['id']]);
        if ($n) $add('no-prof:' . $term['id'] . ':' . $n, 'user-x', 'Turmas sem professor', plural($n, 'oferta aguardando', 'ofertas aguardando') . ' professor em ' . $term['name'], in_array('classes:manage', $u['perms'], true) ? url('admin.ofertas') : url('dir.indicadores'));
    }
    if (in_array('users:manage', $u['perms'], true)) {
        $n = (int) val("SELECT COUNT(*) FROM users u WHERE " . user_in_inst() . " AND u.status = 'ACTIVE' AND u.must_change_password = 1 AND u.password_expires_at IS NOT NULL AND u.password_expires_at < ?", array_merge(inst_params(), [now()]));
        if ($n) $add('temp-expired:' . $n, 'key-round', plural($n, 'senha provisória venceu', 'senhas provisórias venceram'), 'Usuários que não fizeram o primeiro acesso a tempo', url('admin.usuarios'));
    }
    // Por último, comunicados e avisos publicados nos últimos 30 dias para o público do usuário
    $aud = audiences_for($u['roles'], $u['perms']);
    $from = date('Y-m-d', strtotime('-30 days'));
    foreach (rows('SELECT id, kind, title, published_on, author_label FROM announcements WHERE institution_id = ? AND published_on <= ? AND published_on >= ? AND (expires_on IS NULL OR expires_on >= ?) AND audience IN (' . in_list($aud) . ') ORDER BY published_on DESC, id DESC LIMIT 5',
        array_merge([$i, $t, $from, $t], $aud)) as $a)
        $add('ann:' . $a['id'], $a['kind'] === 'BOARD' ? 'pin' : 'megaphone', $a['title'], ($a['kind'] === 'BOARD' ? 'Quadro de avisos' : 'Comunicado') . ' · ' . ($a['author_label'] ? $a['author_label'] . ' · ' : '') . fmt_date($a['published_on']), url(current_portal() . '.inicio'));

    return $out;
}
function notif_seen(int $uid): array {
    $raw = (string) val('SELECT notif_seen FROM users WHERE id = ?', [$uid]);
    $v = json_decode($raw ?: '[]', true);
    return is_array($v) ? $v : [];
}
/** Sino do topo: número vermelho com o que é novo; ao abrir, marca tudo como lido */
function notif_bell(): string {
    $u = current_user();
    $items = notifications_for($u);
    $seen = array_flip(notif_seen((int) $u['id']));
    $new = array_values(array_filter($items, fn($n) => !isset($seen[$n['id']])));
    $list = '';
    foreach ($items as $n) $list .= '<a role="menuitem" class="notif' . (isset($seen[$n['id']]) ? '' : ' is-new') . '" href="' . h($n['href']) . '"><span class="notif-ic">' . icon($n['icon']) . '</span><span class="notif-txt"><strong>' . h($n['title']) . '</strong><span>' . h($n['desc']) . '</span></span></a>';
    $cnt = count($new);
    return '<div class="notif-wrap"><button class="icon-btn" type="button" data-notif-btn aria-haspopup="menu" aria-expanded="false" aria-label="Notificações' . ($cnt ? ': ' . $cnt . ' nova' . ($cnt > 1 ? 's' : '') : '') . '" title="Notificações" data-ids="' . h(json_encode(array_column($items, 'id'))) . '" data-csrf="' . h(csrf_token()) . '" data-url="' . h(url('notif.lidas')) . '">'
        . icon('bell') . ($cnt ? '<span class="dot" data-notif-count>' . ($cnt > 9 ? '9+' : $cnt) . '</span>' : '') . '</button>'
        . '<div class="menu notif-menu" role="menu" aria-label="Notificações" data-notif-menu hidden><div class="menu-head"><strong>Notificações</strong>' . ($cnt ? '<span class="small muted">' . plural($cnt, 'nova', 'novas') . '</span>' : '') . '</div>'
        . '<div class="notif-list">' . ($list ?: '<div class="notif notif-empty"><span class="notif-ic">' . icon('bell-off') . '</span><span class="notif-txt"><strong>Tudo em dia</strong><span>Nenhuma notificação por enquanto.</span></span></div>') . '</div></div></div>';
}
/** POST ao abrir o sino: guarda os ids vistos (só os que existem agora, para a lista não crescer sem fim) */
function route_notif__lidas(): void {
    if (!is_post()) abort(405);
    $u = current_user();
    $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
    $valid = array_column(notifications_for($u), 'id');
    $ids = is_array($ids) ? array_values(array_intersect($valid, array_map('strval', $ids))) : [];
    update('users', ['notif_seen' => json_encode(array_values(array_unique(array_merge(array_intersect(notif_seen((int) $u['id']), $valid), $ids))))], 'id = ?', [$u['id']]);
    header('Content-Type: application/json');
    echo '{"ok":true}';
    exit;
}
