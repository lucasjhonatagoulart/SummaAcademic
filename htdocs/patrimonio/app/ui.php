<?php

declare(strict_types=1);

// Peças de interface compartilhadas pelo layout, pela tela de entrada e pelo instalador (padrão Summa).
// Este arquivo não depende do banco nem da sessão: o instalador o carrega sozinho.

/** Versão do aplicativo: também invalida o cache de CSS e JavaScript a cada atualização. */
const APP_VERSION = '1.9.2';

function ui_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Caminho de um arquivo de assets/ com a versão do aplicativo. */
function asset_url(string $file): string
{
    return 'assets/' . $file . '?v=' . APP_VERSION;
}

/** Ícone de traço (desenhos do conjunto Lucide, licença ISC) embutido na página, sem depender de CDN. */
function icon(string $name): string
{
    static $paths = [
        'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
        'file-text' => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
        'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'bar-chart' => '<path d="M3 3v18h18"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
        'sliders' => '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
        'menu' => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'layout-grid' => '<rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/>',
        'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
        'moon' => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
        'monitor' => '<rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'palette' => '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>',
        'eye' => '<path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/>',
        'pencil' => '<path d="M21.17 6.81a1 1 0 0 0-3.98-3.98L3.84 16.17a2 2 0 0 0-.5.83l-1.32 4.35a.5.5 0 0 0 .62.62l4.35-1.32a2 2 0 0 0 .83-.5z"/><path d="m15 5 4 4"/>',
        'trash-2' => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6"/><path d="M14 11v6"/>',
        'panel-left-close' => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/>',
    ];
    $body = $paths[$name] ?? '';
    return '<svg class="ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $body . '</svg>';
}

/** Conteúdo comum do <head>: tema aplicado antes dos estilos, fonte Inter e folhas de estilo. */
function head_assets(): string
{
    return '<meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark">'
        . '<meta name="robots" content="noindex,nofollow">'
        . '<meta name="theme-color" content="#172B4D">'
        . (function_exists('summa_brand') && ($fav = summa_brand('favicon-64.png')) !== ''
            ? '<link rel="icon" href="' . h($fav) . '?v=' . APP_VERSION . '" type="image/png">'
            : '<link rel="icon" href="assets/favicon.svg?v=' . APP_VERSION . '" type="image/svg+xml">')
        . '<script src="' . asset_url('theme.js') . '"></script>'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="' . asset_url('theme.css') . '">'
        . '<link rel="stylesheet" href="' . asset_url('app.css') . '">'
        . '<link rel="stylesheet" href="' . asset_url('permissions.css') . '">';
}

/** Seletor segmentado do tamanho do texto: pequeno, médio (padrão) e grande. */
function size_switch(): string
{
    $buttons = '';
    foreach (['sm' => 'Pequeno', 'md' => 'Médio', 'lg' => 'Grande'] as $key => $label) {
        $buttons .= '<button type="button" data-size-set="' . $key . '" aria-pressed="' . ($key === 'md' ? 'true' : 'false') . '" title="Texto: ' . $label . '"><b aria-hidden="true">A</b><span>' . $label . '</span></button>';
    }
    return '<div class="size-switch" role="group" aria-label="Tamanho do texto">' . $buttons . '</div>';
}

/** Seletor segmentado do tema, usado nas telas sem menu (entrada e instalação). */
function theme_switch(): string
{
    $buttons = '';
    foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Escuro'], 'auto' => ['monitor', 'Auto']] as $key => [$iconName, $label]) {
        $buttons .= '<button type="button" data-theme-set="' . $key . '" aria-pressed="' . ($key === 'auto' ? 'true' : 'false') . '" title="Tema: ' . $label . '">' . icon($iconName) . '<span>' . $label . '</span></button>';
    }
    return '<div class="theme-switch" role="group" aria-label="Tema">' . $buttons . '</div>';
}

/** Botão Aparência do cabeçalho: abre o tamanho do texto e as três opções de tema. */
function appearance_button(): string
{
    $options = '';
    foreach (['light' => ['sun', 'Claro'], 'dark' => ['moon', 'Escuro'], 'auto' => ['monitor', 'Automático']] as $key => [$iconName, $label]) {
        $checked = $key === 'auto' ? 'true' : 'false';
        $options .= '<button type="button" role="menuitemradio" data-theme-set="' . $key . '" aria-checked="' . $checked . '" aria-pressed="' . $checked . '">' . icon($iconName) . '<span class="grow">' . $label . '</span><span class="theme-check">' . icon('check') . '</span></button>';
    }
    return '<div class="menu-wrap">'
        . '<button class="appear-btn" type="button" data-appear-btn aria-haspopup="menu" aria-expanded="false" aria-label="Aparência: tema e tamanho do texto" title="Tema e tamanho do texto">' . icon('palette') . '<span>Aparência</span></button>'
        . '<div class="menu appear-menu" role="menu" aria-label="Aparência" data-appear-menu hidden>'
        . '<div class="menu-label">Tamanho do texto</div>' . size_switch()
        . '<div class="menu-sep" role="separator"></div><div class="menu-label">Tema</div>' . $options
        . '<p class="theme-note">Automático segue o tema do seu computador ou celular. As escolhas ficam salvas neste navegador.</p>'
        . '</div></div>';
}

/** Bloco Aparência das telas de entrada e instalação. */
function appearance_panel(): string
{
    return '<div class="login-theme"><p>Aparência</p>' . theme_switch() . size_switch() . '</div>';
}

/** Nome do sistema exibido no menu, nos títulos e nas telas de entrada. */
const APP_TITLE = 'Controle Patrimonial';

/** Nome exibido: "Summa Patrimônio" quando integrado ao Summa, senão "Controle Patrimonial". */
function display_title(): string
{
    return function_exists('summa_enabled') && summa_enabled() ? SUMMA_MODULE_NAME : APP_TITLE;
}

/** Painel azul-marinho à esquerda nas telas de entrada e instalação: só texto, sem logo. */
function auth_brand_panel(): string
{
    $features = '';
    foreach (['Notas fiscais e XML em um só lugar', 'Ativos, baixas e histórico preservados', 'Relatórios patrimoniais prontos para imprimir'] as $text) {
        $features .= '<li>' . icon('check') . '<span>' . $text . '</span></li>';
    }
    return '<section class="login-brand">'
        . '<p class="login-brand-name">' . display_title() . '</p>'
        . '<div><h1>Controle patrimonial em um só lugar.</h1><p>Notas fiscais, ativos e baixas organizados no mesmo padrão, com histórico preservado.</p><ul class="login-feats">' . $features . '</ul></div>'
        . '<small>' . display_title() . ' · versão ' . APP_VERSION . '</small></section>';
}

/** Nome do sistema no topo do formulário em telas estreitas, onde o painel azul não aparece. */
function auth_mobile_logo(): string
{
    return '<p class="login-mobile-head">' . display_title() . '</p>';
}

/** Página simples (aviso, erro, instalação pendente) no mesmo formato da tela de entrada. $inner já vem em HTML seguro. */
function auth_page(string $title, string $inner): string
{
    return '<!doctype html><html lang="pt-BR"><head>' . head_assets() . '<title>' . ui_escape($title) . ' · ' . display_title() . '</title></head><body>'
        . '<div class="login">' . auth_brand_panel() . '<section class="login-form-wrap"><main class="login-box">'
        . auth_mobile_logo() . $inner . appearance_panel() . '</main></section></div></body></html>';
}

/** Iniciais do usuário para o círculo do cabeçalho (funciona sem a extensão mbstring). */
function user_initials(string $name): string
{
    $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$words) return '?';
    $letters = static function (string $word, int $count): string {
        return preg_match('/^.{1,' . $count . '}/us', $word, $match) ? $match[0] : '';
    };
    return count($words) > 1 ? $letters($words[0], 1) . $letters($words[count($words) - 1], 1) : $letters($words[0], 2);
}
