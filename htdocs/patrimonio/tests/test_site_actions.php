<?php

declare(strict_types=1);

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
    $checks++;
    echo "OK - {$message}\n";
};
$root = dirname(__DIR__);
$layout = (string)file_get_contents($root . '/templates/layout.php');
$invoices = (string)file_get_contents($root . '/templates/invoices.php');
$controlPanel = (string)file_get_contents($root . '/templates/control-panel.php');
$panelCss = (string)file_get_contents($root . '/assets/permissions.css');
$appCss = (string)file_get_contents($root . '/assets/app.css');

preg_match('~<header class="topbar">(.*?)</header>~s', $layout, $topbarMatch);
$topbar = (string)($topbarMatch[1] ?? '');
$check($topbar !== '' && substr_count($topbar, 'appearance_button()') === 1, 'topo global mantém exatamente um controle de aparência');
$check(!str_contains($topbar, 'new-invoice') && !str_contains($topbar, 'Importar NF-e') && !str_contains($topbar, 'Importar backup'), 'topo global não mostra atalhos de NF-e nem de backup');
$ui = (string)file_get_contents($root . '/app/ui.php');
$check(str_contains($layout, 'head_assets()') && str_contains($ui, "asset_url('app.css')") && str_contains($ui, "asset_url('permissions.css')") && str_contains($ui, "'?v=' . APP_VERSION"), 'cabeçalho carrega os estilos com a versão do aplicativo');
$check(!is_dir($root . '/assets/brand') && !is_file($root . '/assets/ativa-mark.svg'), 'a logo e o nome antigos foram removidos dos assets');
preg_match_all('~<a class="brand" href="index.php">(.*?)</a>~s', $layout, $brandMatch);
$brandMarkup = implode("\n", $brandMatch[1] ?? []);
$standalone = (string)($brandMatch[1][1] ?? '');
$check(count($brandMatch[1] ?? []) === 2 && str_contains($standalone, 'APP_TITLE') && !str_contains($standalone, '<img') && !str_contains($layout, 'assets/brand'), 'sozinho, o menu lateral mostra só o texto Controle Patrimonial, sem logo própria');
$asModule = (string)($brandMatch[1][0] ?? '');
$check(str_contains($asModule, '$summaLogo') && str_contains($asModule, 'alt="Summa Educação"') && str_contains($asModule, 'brand-module') && str_contains($asModule, '<span class="brand-name">Summa<small')
    && str_contains($layout, "summa_brand('logo-fundo-escuro.png')") && str_contains($layout, "summa_brand('icone.png')"), 'como módulo, o menu usa a logo e o ícone do Summa, com o texto de reserva se o arquivo não existir');
$check(str_contains($layout, "\$_SESSION['admin_avatar']") && str_contains($layout, 'user_initials($userName)'), 'cabeçalho mostra a foto que veio do Summa ou, sem ela, as iniciais');
$check(!preg_match('/Ativa\\b/', $layout . (string)file_get_contents($root . '/templates/login.php') . (string)file_get_contents($root . '/app/ui.php')), 'o nome Ativa não aparece mais no layout, no login nem nas peças de interface');

preg_match('~<section class="heading">(.*?)</section>~s', $invoices, $invoiceHeadingMatch);
$invoiceHeading = (string)($invoiceHeadingMatch[1] ?? '');
$check($invoiceHeading !== '' && !str_contains($invoiceHeading, 'new-invoice') && !str_contains($invoiceHeading, 'Importar XML') && !str_contains($invoiceHeading, '+ Nova NF-e'), 'cabeçalho de Notas Fiscais não duplica ações no topo');
$filterStart = strpos($invoices, '<form class="filters invoice-search"');
$filterEnd = $filterStart === false ? false : strpos($invoices, '</form>', $filterStart);
$filterHtml = $filterStart === false || $filterEnd === false ? '' : substr($invoices, $filterStart, $filterEnd - $filterStart);
$check($filterHtml !== '' && !str_contains($filterHtml, 'Exportar NF-es'), 'exportação não ocupa a área dos filtros');
$actionsPosition = strpos($invoices, 'invoice-page-actions');
$listPosition = strpos($invoices, '<section class="panel invoice-list-panel"');
$exportPosition = strpos($invoices, 'Exportar NF-es (.xlsx)', $actionsPosition === false ? 0 : $actionsPosition);
$check($actionsPosition !== false && $listPosition !== false && $actionsPosition < $listPosition && $exportPosition > $actionsPosition && $exportPosition < $listPosition, 'cadastro/importação e exportação aparecem antes da listagem de NF-e');
$check(str_contains($invoices, "if (can('export_data'))") && str_contains($invoices, "if (can('edit_invoices'))"), 'exportar e cadastrar/importar continuam respeitando suas permissões');
$check(str_contains($invoices, 'Cadastrar / Importar NF-e') && str_contains($invoices, 'new-invoice'), 'ações de criação/importação permanecem acessíveis no rodapé em um único botão');
$check(str_contains($invoices, 'download') && str_contains($invoices, "'kind' => 'invoices'"), 'ação final mantém o download da planilha de notas');

$backupActions = (string)preg_replace('~.*(control-backup-actions).*~s', '$1', $controlPanel);
$check(str_contains($controlPanel, 'if (can_restore_backup())') && str_contains($controlPanel, 'page_url(\'restore\')'), 'restaurar backup saiu do cabeçalho e permanece no Painel sob a permissão composta');
$check(str_contains($controlPanel, 'index.php?download=backup'), 'exportação do backup continua disponível no Painel');
$check(str_contains($panelCss, '.control-module-grid{align-items:stretch;grid-auto-rows:1fr}'), 'grade de catálogos dimensiona igualmente as linhas dos dois cartões');
$check(str_contains($panelCss, '.control-panel-card{height:100%;min-height:0;display:flex;flex-direction:column}') && str_contains($panelCss, '.control-panel-card>.catalog-list-scroll{flex:1 1 auto'), 'cartões de Contas Patrimoniais e Locais preenchem a mesma altura mantendo a rolagem');
$check(str_contains($panelCss, '@media(max-width:760px){.control-module-grid{grid-template-columns:minmax(0,1fr);grid-auto-rows:1fr}'), 'as seções pareadas continuam uma coluna e de mesma altura em telas estreitas');
$check(str_contains($appCss, '.invoice-page-actions{display:flex') && str_contains($appCss, 'margin:0 0 18px') && str_contains($appCss, '@media(max-width:760px){.invoice-page-actions{justify-content:stretch}'), 'ações antes da listagem mantêm espaçamento e adaptação ao celular');

printf("Total: %d verificações de navegação, ações de NF-e e cartões pareados aprovadas.\n", $checks);
