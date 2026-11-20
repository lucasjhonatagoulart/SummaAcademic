<?php
// Página com menu lateral (layout) e página simples (login, troca de senha, verificação).

/** Tema escolhido (cookie): 'light', 'dark' ou '' = automático (segue o sistema) */
function theme_pref(): string { $t = $_COOKIE['academus_theme'] ?? ''; return in_array($t, ['light', 'dark'], true) ? $t : ''; }
/** Tamanho do texto (cookie): 'sm', 'lg' ou '' = médio (padrão) */
function size_pref(): string { $t = $_COOKIE['academus_size'] ?? ''; return in_array($t, ['sm', 'lg'], true) ? $t : ''; }
function size_switch(): string {
    $cur = size_pref() ?: 'md'; $o = '';
    foreach (['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande'] as $k => $l) $o .= '<button type="button" data-size-set="' . $k . '" aria-pressed="' . ($cur === $k ? 'true' : 'false') . '" title="Texto ' . mb_strtolower($l) . '"><b aria-hidden="true">A</b><span>' . $l . '</span></button>';
    return '<div class="size-switch" role="group" aria-label="Tamanho do texto">' . $o . '</div>';
}
function theme_switch(): string {
    $cur = theme_pref() ?: 'auto'; $o = '';
    foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Escuro'], 'auto' => ['monitor', 'Auto']] as $k => [$ic, $l]) $o .= '<button type="button" data-theme-set="' . $k . '" aria-pressed="' . ($cur === $k ? 'true' : 'false') . '" title="Tema ' . mb_strtolower($l) . '">' . icon($ic) . '<span>' . $l . '</span></button>';
    return '<div class="theme-switch" role="group" aria-label="Tema">' . $o . '</div>';
}
/** Botão de tema no topo: o ícone mostra o tema atual e abre as três opções */
function theme_button(): string {
    $cur = theme_pref() ?: 'auto'; $o = '';
    foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Escuro'], 'auto' => ['monitor', 'Automático']] as $k => [$ic, $l])
        $o .= '<button type="button" role="menuitemradio" data-theme-set="' . $k . '" aria-checked="' . ($cur === $k ? 'true' : 'false') . '" aria-pressed="' . ($cur === $k ? 'true' : 'false') . '">' . icon($ic) . ' <span class="grow">' . $l . '</span><span class="theme-check">' . icon('check') . '</span></button>';
    return '<div class="theme-wrap"><button class="appear-btn" type="button" data-theme-btn aria-haspopup="menu" aria-expanded="false" aria-label="Aparência: tema e tamanho do texto" title="Tema e tamanho do texto">'
        . icon('palette') . '<span>Aparência</span></button>'
        . '<div class="menu theme-menu appear-menu" role="menu" aria-label="Aparência" data-theme-menu hidden><div class="menu-label">Tamanho do texto</div>' . size_switch() . '<div class="menu-sep" role="separator"></div><div class="menu-label">Tema</div>' . $o . '<p class="theme-note">Automático segue o tema do seu computador ou celular. As escolhas ficam salvas neste navegador.</p></div></div>';
}
function head_html(string $title, string $suffix = '', string $extraHead = ''): string {
    $t = theme_pref(); $z = size_pref();
    return '<!doctype html><html lang="pt-BR"' . ($t ? ' data-theme="' . $t . '"' : '') . ($z ? ' data-size="' . $z . '"' : '') . '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark">'
        . '<title>' . h($title) . ' · ' . h($suffix ?: APP_NAME) . '</title><link rel="icon" href="assets/brand/favicon-64.png?v=' . APP_VERSION . '" type="image/png"><link rel="apple-touch-icon" href="assets/brand/apple-touch-icon.png?v=' . APP_VERSION . '"><meta name="theme-color" content="' . BRAND['color'] . '"><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/app.css?v=' . APP_VERSION . '"><link rel="stylesheet" href="assets/extra.css?v=' . APP_VERSION . '"><style>:root { --brand: ' . BRAND['color'] . '; }</style>' . $extraHead . '</head>';
}
function scripts_html(): string {
    return page_loader() . '<script src="https://unpkg.com/lucide@0.460.0/dist/umd/lucide.min.js"></script><script src="assets/app.js?v=' . APP_VERSION . '"></script>';
}
/** Logo da plataforma. $on: 'dark' = fundo escuro (versão branca), 'light' = fundo claro (colorida), 'auto' = conforme o tema */
function app_logo(string $on = 'auto', int $h = 28): string {
    $img = fn($v, $cls = '') => '<img class="app-logo' . $cls . '" src="assets/brand/logo-' . $v . '.png?v=' . APP_VERSION . '" alt="' . h(BRAND['full']) . '" style="height:' . $h . 'px">';
    return match ($on) { 'dark' => $img('fundo-escuro'), 'light' => $img('fundo-claro'), default => $img('fundo-claro', ' logo-on-light') . $img('fundo-escuro', ' logo-on-dark') };
}
/** Logo animada (tela de entrada, roda uma vez): o S aparece com o capelo, "umma" desliza de dentro do S,
 *  "EDUCAÇÃO" se abre do centro e o capelo sobe girando 360° e volta ao S. Sem as camadas da marca, mostra a logo comum. */
function app_logo_anim(string $on = 'auto', int $h = 28): string {
    $dir = __DIR__ . '/../assets/brand/';
    foreach (['anim-s', 'anim-s-inteiro', 'anim-umma', 'anim-capelo-escuro', 'anim-educacao-escuro'] as $f) if (!is_file($dir . $f . '.png')) return app_logo($on, $h);
    $src = fn(string $f) => 'assets/brand/' . $f . '.png?v=' . APP_VERSION;
    $one = fn(string $var, string $cls = '') => '<span class="logo-anim' . $cls . '" role="img" aria-label="' . h(BRAND['full']) . '" style="height:' . $h . 'px">'
        . '<span class="la-umma-clip"><img class="la-umma" src="' . $src('anim-umma') . '" alt=""></span>'
        . '<img class="la-s" src="' . $src('anim-s') . '" alt=""><img class="la-sfull" src="' . $src('anim-s-inteiro') . '" alt="">'
        . '<img class="la-edu" src="' . $src('anim-educacao-' . $var) . '" alt=""><img class="la-cap" src="' . $src('anim-capelo-' . $var) . '" alt=""></span>';
    return match ($on) { 'dark' => $one('escuro'), 'light' => $one('claro'), default => $one('claro', ' logo-on-light') . $one('escuro', ' logo-on-dark') };
}
/** Carregador: só o S com o capelo girando. Fica escondido e aparece quando uma página demora a abrir (app.js). */
function page_loader(): string {
    $dir = __DIR__ . '/../assets/brand/';
    if (!is_file($dir . 'load-s.png') || !is_file($dir . 'load-capelo-escuro.png')) return '<div class="page-loader pl-plain" data-page-loader hidden role="status" aria-live="polite"><span class="pl-spin"></span><span class="sr-only">Carregando…</span></div>';
    $src = fn(string $f) => 'assets/brand/' . $f . '.png?v=' . APP_VERSION;
    return '<div class="page-loader" data-page-loader hidden role="status" aria-live="polite"><span class="pl-mark" aria-hidden="true">'
        . '<img class="pl-s" src="' . $src('load-s') . '" alt=""><img class="pl-sfull" src="' . $src('load-s-inteiro') . '" alt="">'
        . '<img class="pl-cap logo-on-light" src="' . $src('load-capelo-claro') . '" alt=""><img class="pl-cap logo-on-dark" src="' . $src('load-capelo-escuro') . '" alt=""></span><span class="sr-only">Carregando…</span></div>';
}
function brand_mark(int $size = 32): string {
    $logo = institution()['logo_path'] ?? null;
    return $logo ? '<span class="logo-mark brand-logo" style="width:' . $size . 'px;height:' . $size . 'px"><img src="' . h($logo) . '" alt=""></span>'
        : '<span class="logo-mark" style="width:' . $size . 'px;height:' . $size . 'px">' . icon('graduation-cap') . '</span>';
}

function layout(string $title, string $content): void {
    $u = current_user();
    $portal = current_portal();
    $route = $GLOBALS['ROUTE'] ?? '';
    $inst = institution();
    $term = active_term();
    $mod = current_module(); $mods = user_modules($u);
    $nav = '';
    foreach (nav_for($mod === 'controle' ? 'controle' : $portal) as $group => $items) {
        $html = '';
        foreach ($items as $it) {
            [$label, $r, $ic] = $it;
            $perms = $it[3] ?? [];
            if ($perms && !can(...$perms)) continue;
            if (str_contains($r, '.') && !in_array(explode('.', $r)[0], $u['portals'], true) && explode('.', $r)[0] !== $portal) continue;
            $active = $route === $r || in_array($route, $it[4] ?? [], true) || ($r === 'mural' && in_array($route, ['mural', 'mural.form'], true));
            $abbr = mb_substr($label, 0, 2);
            $html .= '<a class="nav-item' . ($active ? ' active' : '') . '" href="' . h(url($r)) . '" title="' . h($label) . '"' . ($active ? ' aria-current="page"' : '') . '>' . icon($ic) . '<b class="nav-abbr" aria-hidden="true">' . h($abbr) . '</b><span>' . h($label) . '</span></a>';
        }
        if ($html) $nav .= '<div><p class="side-group-title">' . h($group) . '</p>' . $html . '</div>';
    }
    $wsLink = count($mods) > 1 ? '<a class="nav-item ws-back" href="' . h(url('workspace')) . '" title="Todos os módulos">' . icon('layout-grid') . '<b class="nav-abbr" aria-hidden="true">Mó</b><span>Todos os módulos</span></a>' : '';
    $here = $mod === 'controle' ? platform_modules()[$mod]['name'] : PORTAL_LABEL[$portal];
    echo head_html($title, $mod === 'controle' ? APP_NAME : APP_NAME . ' ' . platform_modules()[$mod]['name']), '<body><div id="app"><div class="shell" data-shell>',
    '<div class="scrim" data-close-side hidden></div>',
    '<aside class="sidebar" aria-label="Menu principal"><div class="side-head"><img class="side-icon" src="assets/brand/icone.png?v=', APP_VERSION, '" alt="', h(BRAND['full']), '"><div class="side-brand">', app_logo('dark', BRAND['side_logo_h']), '<small>', h(platform_modules()[$mod]['name']), ' · ', h($inst['short_name']), $term ? ' · ' . h($term['name']) : '', '</small></div>',
    '<button class="side-toggle" data-toggle-collapse aria-label="Recolher menu">', icon('panel-left-close'), '</button></div>',
    '<nav class="side-nav">', $wsLink, $nav, '</nav><div class="side-foot"><span>', h($here), '</span></div></aside>',
    '<div class="main-col"><header class="topbar"><button class="icon-btn menu-toggle" data-open-side aria-label="Abrir menu">', icon('menu'), '</button>',
    '<span class="portal">', h($here), '</span>', top_actions($u, $portal, $mod), '</header>',
    '<main class="content" id="conteudo">', flashes(), (can('users:manage') && is_dir(dirname(__DIR__) . '/install') && empty(CONFIG['debug']) ? alert('warn', 'A pasta <strong>install/</strong> ainda está no servidor. Apague-a pelo FileZilla para ninguém reinstalar o sistema.', 'Segurança') : ''), $content, '</main></div></div></div>', scripts_html(), '</body></html>';
}

/** Lado direito da barra superior: Aparência, notificações e menu do usuário (módulos e o Workspace usam o mesmo) */
function top_actions(array $u, string $portal = '', string $curMod = ''): string {
    $mods = user_modules($u);
    $areaIcon = ['admin' => 'shield', 'dir' => 'landmark', 'fin' => 'wallet', 'comp' => 'shopping-cart', 'prof' => 'presentation', 'aluno' => 'graduation-cap'];
    $others = '';
    // Outras áreas do mesmo módulo ficam no menu do perfil; os outros módulos ficam no Workspace
    if ($portal && $curMod === 'academico') foreach ($u['portals'] as $pp) if ($pp !== $portal && module_of_portal($pp) === $curMod && ($pp !== 'admin' || module_entry($u, 'academico') === 'admin.inicio')) $others .= '<a role="menuitem" href="' . h(url($pp . '.inicio')) . '">' . icon($areaIcon[$pp] ?? 'layout-grid') . ' ' . h(PORTAL_LABEL[$pp]) . '</a>';
    if ($others) $others = '<div class="menu-sec" role="presentation">Outras áreas do ' . h(platform_modules()[$curMod]['name']) . '</div>' . $others;
    $modLinks = '';
    if (count($mods) > 1) {
        $modLinks = '<div class="menu-sec" role="presentation">Módulos</div><a role="menuitem" href="' . h(url('workspace')) . '">' . icon('layout-grid') . ' Todos os módulos</a>';
        foreach ($mods as $m) if ($m !== $curMod) $modLinks .= '<a role="menuitem" href="' . h(url((string) module_entry($u, $m))) . '">' . icon(platform_modules()[$m]['icon']) . ' ' . h(platform_modules()[$m]['name']) . '</a>';
    }
    $extra = $others . $modLinks;
    return '<div class="top-actions">' . theme_button() . notif_bell() . '<div style="position:relative">'
        . '<button class="user-btn" data-menu-btn aria-haspopup="menu" aria-expanded="false">' . avatar($u['avatar_path'], $u['name']) . '<span class="name">' . h($u['name']) . '</span>' . icon('chevron-down') . '</button>'
        . '<div class="menu" role="menu" data-menu hidden><div class="menu-head"><strong>' . h($u['name']) . '</strong><span>' . h($u['email']) . ' · ' . h(role_names($u['roles'], $u['perms'])) . '</span></div>'
        . inst_switch($u)
        . '<a role="menuitem" href="' . h(url('perfil')) . '">' . icon('user-round') . ' Meu perfil</a>'
        . '<a role="menuitem" href="' . h(url('senha')) . '">' . icon('key-round') . ' Alterar senha</a>' . ($extra ? '<div class="menu-sep" role="separator"></div>' . $extra : '') . '<div class="menu-sep" role="separator"></div>'
        . form_open('logout') . '<button role="menuitem" class="danger" type="submit">' . icon('log-out') . ' Sair</button></form></div></div></div>';
}

/** Menu do perfil: instituição em uso (lista suspensa quando o usuário acessa mais de uma) */
function inst_switch(array $u): string {
    $ids = accessible_institution_ids((int) $u['id']);
    $list = $ids ? rows('SELECT id, name, short_name FROM institutions WHERE id IN (' . in_list($ids) . ') ORDER BY name', $ids) : [];
    $cur = inst_id();
    if (count($list) <= 1) return '<div class="menu-inst"><span class="menu-label" style="padding:0">Instituição</span><p>' . h(institution()['name']) . '</p></div><div class="menu-sep" role="separator"></div>';
    $opts = '';
    foreach ($list as $i) $opts .= '<option value="' . $i['id'] . '"' . ((int) $i['id'] === $cur ? ' selected' : '') . '>' . h($i['short_name'] . ' — ' . $i['name']) . '</option>';
    return form_open('instituicao.trocar', [], 'class="menu-inst"') . '<label class="menu-label" for="inst-sel" style="padding:0">Instituição em uso</label>'
        . '<select class="input" id="inst-sel" name="id" data-autosubmit aria-describedby="inst-sel-h">' . $opts . '</select><small id="inst-sel-h">' . plural(count($list), 'instituição liberada', 'instituições liberadas') . ' para você</small>'
        . '<noscript><button class="btn btn-sm btn-secondary" type="submit">Trocar</button></noscript></form><div class="menu-sep" role="separator"></div>';
}

function bare_page(string $title, string $content): void {
    echo head_html($title), '<body><div id="app">', $content, '</div>', scripts_html(), '</body></html>';
}

function active_term(): ?array {
    static $t = false;
    if ($t !== false) return $t;
    return $t = row('SELECT * FROM terms WHERE institution_id = ? AND active = 1 ORDER BY starts_on DESC LIMIT 1', [inst_id()]);
}
