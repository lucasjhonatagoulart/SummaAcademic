<?php
// Workspace da plataforma Summa (tela A1): sem menu lateral; barra superior azul-marinho de 64 px;
// um card por módulo liberado para o usuário, com as áreas que ele acessa e o botão "Abrir módulo".

/** Áreas do módulo que o usuário acessa (rótulos), a partir das permissões e dos cadastros */
function module_user_areas(array $u, string $mod): array {
    // Módulos com rótulos por permissão (Diretoria, Painel de controle) mostram o que a pessoa vê lá dentro
    if (!empty(platform_modules()[$mod]['labels'])) return array_values(array_filter(platform_modules()[$mod]['labels'], fn($l, $c) => in_array('ADMIN', $u['roles'], true) || in_array($c, $u['perms'], true), ARRAY_FILTER_USE_BOTH));
    $areas = platform_modules()[$mod]['areas'];
    if (in_array('ADMIN', $u['roles'], true)) return array_values(array_intersect_key(module_labels(), array_flip(array_diff($areas, ['classroom', 'student']))));
    $on = [];
    foreach ($u['perms'] as $c) { $a = perm_catalog()[$c][0] ?? null; if ($a && in_array($a, $areas, true)) $on[$a] = true; }
    if (in_array('prof', $u['portals'], true) && in_array('classroom', $areas, true)) $on['classroom'] = true;
    if (in_array('aluno', $u['portals'], true) && in_array('student', $areas, true)) $on['student'] = true;
    return array_values(array_intersect_key(module_labels(), $on));
}

function route_workspace(): void {
    $u = current_user();
    $mine = user_modules($u);
    if (!$mine) go(home_route($u));
    $inst = institution(); $term = active_term();
    $cards = ''; $rest = '';
    foreach (platform_modules() as $k => $m) {
        $style = '--mc:' . $m['stripe'] . ';--mi:' . $m['ink'] . ';--ms:' . $m['soft'];
        if (in_array($k, $mine, true)) {
            $areas = module_user_areas($u, $k);
            $cards .= '<article class="ws-card" style="' . $style . '"><div class="ws-card-top"><span class="ws-ic" aria-hidden="true">' . icon($m['icon']) . '</span><div><h2>' . h($m['name']) . '</h2><p>' . h($m['desc']) . '</p></div></div>'
                . ($areas ? '<div class="ws-areas"><span class="ws-areas-label">Suas áreas</span><ul>' . implode('', array_map(fn($a) => '<li>' . h($a) . '</li>', $areas)) . '</ul></div>' : '')
                . '<div class="ws-card-foot"><a class="btn btn-primary ws-open" href="' . h(url((string) module_entry($u, $k))) . '">Abrir módulo ' . icon('arrow-right') . '</a></div></article>';
        } elseif (can('users:manage')) {
            // Quem administra vê os demais módulos numa linha discreta
            $rest .= '<li class="ws-locked" style="' . $style . '"><span class="ws-ic sm" aria-hidden="true">' . icon($m['icon']) . '</span><span class="grow"><strong>' . h($m['name']) . '</strong> <span class="muted">' . (!empty($m['soon']) ? '· em breve na plataforma' : '· não liberado para você') . '</span></span>' . icon(!empty($m['soon']) ? 'clock' : 'lock') . '</li>';
        }
    }
    $first = explode(' ', trim($u['name']))[0];
    echo head_html('Módulos', APP_NAME, '<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">'),
        '<body class="ws-body"><div id="app"><header class="ws-top"><a class="ws-brand" href="', h(url('workspace')), '" aria-label="', h(BRAND['full']), ' — início">', app_logo('dark', 36), '</a>',
        '<span class="ws-inst">', icon('building-2'), h($inst['short_name']), $term ? ' · ' . h($term['name']) : '', '</span>', top_actions($u), '</header>',
        '<main class="ws-main" id="conteudo">', flashes(),
        '<div class="ws-hello"><h1>Olá, ', h($first), '</h1><p>', count($mine) > 1 ? 'Escolha um módulo para começar.' : 'Este é o módulo liberado para você.', '</p></div>',
        '<div class="ws-grid">', $cards, '</div>',
        $rest ? '<section class="ws-rest" aria-label="Outros módulos"><h2>Outros módulos</h2><ul>' . $rest . '</ul>' . (can('users:manage') ? '<p class="small muted">A liberação de módulos e áreas para cada usuário fica em <a href="' . h(url('admin.usuarios')) . '">Gerenciamento de usuários</a>.</p>' : '') . '</section>' : '',
        '</main></div>', scripts_html(), '</body></html>';
}
