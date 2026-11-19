<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/reporting.php';
require_once __DIR__ . '/../app/xlsx.php';

$GLOBALS['report_test_export_allowed'] = false;

function report_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL - {$message}\n");
        exit(1);
    }
    echo "OK - {$message}\n";
}

function can(string $permission): bool
{
    return $permission === 'export_data' && (bool)$GLOBALS['report_test_export_allowed'];
}

function can_restore_backup(): bool
{
    return false;
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money_br(mixed $value): string
{
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function page_url(string $page, array $params = []): string
{
    return 'index.php?' . http_build_query(array_merge(['page' => $page], $params));
}

function render_report_template(array $query, array $assets, bool $exportAllowed): string
{
    $GLOBALS['report_test_export_allowed'] = $exportAllowed;
    $_GET = array_merge(['page' => 'reports'], $query);
    ob_start();
    include __DIR__ . '/../templates/reports.php';
    return (string)ob_get_clean();
}

$syntheticAssets = [
    [
        'id' => 1, 'name' => '<script>Equipamento sintético</script>', 'originalDescription' => 'Descrição original sintética',
        'classification' => 'fixed', 'category' => 'Máquinas', 'location' => 'Depósito A', 'patrimony' => 'PAT-SINT-001',
        'brand' => 'Marca teste', 'color' => 'Cinza', 'type' => 'Notebook', 'condition' => 'Novo', 'invoiceNumber' => 'NF-1001',
        'acquiredAt' => '2026-03-01', 'quantity' => '1.000', 'value' => '1250.00', 'usefulLife' => 5,
        'status' => 'active', 'disposalDate' => null, 'disposalReason' => '', 'notes' => 'Observação sintética',
        'purchaseYear' => 2026, 'legacyId' => 'HIST-01', 'sourceSheet' => 'Base sintética', 'sourceRow' => 10,
    ],
    [
        'id' => 2, 'name' => 'Ativo baixado sintético', 'originalDescription' => 'Descrição baixada',
        'classification' => 'small', 'category' => 'Móveis', 'location' => 'Depósito B', 'patrimony' => 'PAT-SINT-002',
        'brand' => '', 'color' => '', 'type' => '', 'condition' => 'Usado', 'invoiceNumber' => 'NF-0900',
        'acquiredAt' => '2024-08-10', 'quantity' => '2.000', 'value' => '50.00', 'usefulLife' => 3,
        'status' => 'disposed', 'disposalDate' => '2025-12-20', 'disposalReason' => 'Fim de uso sintético', 'notes' => '',
        'purchaseYear' => 2024, 'legacyId' => 'HIST-02', 'sourceSheet' => 'Base sintética', 'sourceRow' => 11,
    ],
    [
        'id' => 3, 'name' => 'Ativo fora do período', 'originalDescription' => '',
        'classification' => 'fixed', 'category' => 'Máquinas', 'location' => 'Depósito A', 'patrimony' => 'PAT-SINT-003',
        'brand' => '', 'color' => '', 'type' => '', 'condition' => 'Novo', 'invoiceNumber' => '',
        'acquiredAt' => '2025-02-01', 'quantity' => '1.000', 'value' => '200.00', 'usefulLife' => 5,
        'status' => 'active', 'disposalDate' => null, 'disposalReason' => '', 'notes' => '',
        'purchaseYear' => 2025, 'legacyId' => '', 'sourceSheet' => 'Base sintética', 'sourceRow' => 12,
    ],
];

$selected = render_report_template([
    'report' => '1', 'fields' => ['name', 'value', 'nao-permitido'], 'from' => '2026-01-01', 'to' => '2026-12-31', 'scope' => 'active',
], $syntheticAssets, true);
report_test_assert(str_contains($selected, 'Relatório patrimonial') && str_contains($selected, 'data-page-layout="portrait"'), 'seleção parcial gera prévia A4 retrato com título e metadados');
report_test_assert(preg_match('~<table class="table report-data-table"[^>]*>(.*?)</table>~s', $selected, $tableMatch) === 1, 'prévia detalhada contém uma tabela imprimível');
$table = $tableMatch[1] ?? '';
report_test_assert(str_contains($table, '<th scope="col">Item</th>') && str_contains($table, '<th scope="col">Valor de aquisição</th>'), 'tabela contém apenas os campos permitidos selecionados');
report_test_assert(substr_count($table, '<th scope="col">') === 2 && !str_contains($table, '<th scope="col">Conta Patrimonial</th>'), 'campos não selecionados não aparecem como colunas');
report_test_assert(str_contains($table, 'Equipamento sintético') && !str_contains($table, '<script>'), 'valores do ativo são escapados como texto e não como HTML executável');
report_test_assert(str_contains($table, 'R$ 1.250,00') && !str_contains($table, 'Ativo baixado sintético') && !str_contains($table, 'Ativo fora do período'), 'prévia respeita período, status e valores formatados');
$order = [strpos($selected, '<h2>Resumo patrimonial</h2>'), strpos($selected, 'class="panel report-filter-panel"'), strpos($selected, 'data-report-builder'), strpos($selected, 'data-report-table'), strpos($selected, 'data-report-total'), strpos($selected, 'data-report-export-section')];
report_test_assert(!in_array(false, $order, true) && $order === array_values($order) && $order === (function (array $values): array { $sorted = $values; sort($sorted); return $sorted; })($order), 'Resumo patrimonial vem antes dos filtros e do relatório detalhado na tela');
preg_match('~<table class="report-summary-table"[^>]*>(.*?)</table>~s', $selected, $summaryMatch);
$summaryTable = $summaryMatch[1] ?? '';
report_test_assert(str_contains($summaryTable, '<th scope="col">Conta Patrimonial</th>') && str_contains($summaryTable, '<th scope="col">Registros</th>') && str_contains($summaryTable, '<th scope="col">Valor Total (R$)</th>') && str_contains($summaryTable, '<th scope="row">Máquinas</th>') && str_contains($summaryTable, 'class="report-summary-count">1</td>') && str_contains($summaryTable, 'class="report-summary-amount">R$ 1.250,00</td>') && !str_contains($selected, 'report-category-row'), 'resumo tabular soma apenas os ativos filtrados e não renderiza barras');
report_test_assert(strpos($selected, 'class="report-print-header report-summary-print-header"') < strpos($selected, '<h2>Resumo patrimonial</h2>') && str_contains($selected, 'Controle Patrimonial') && str_contains($selected, 'Status: Ativos') && str_contains($selected, 'Classificação: Todos') && str_contains($selected, 'Gerado em ') && str_contains($selected, 'ativos no filtro'), 'o cabeçalho do relatório com os filtros aplicados precede a tabela resumo na página inicial impressa');
preg_match('~<header class="report-print-header report-summary-print-header".*?</header>~s', $selected, $firstPageHeaderMatch);
report_test_assert(isset($firstPageHeaderMatch[0]) && !str_contains($firstPageHeaderMatch[0], 'ativos no filtro'), 'a primeira página não repete o contador, pois o resumo já mostra Total de registros');
report_test_assert(!str_contains($selected, 'Este relatório contém') && !str_contains($selected, 'report-wide-print-warning'), 'a prévia detalhada não imprime o aviso sobre número de colunas');

$manyFields = render_report_template([
    'report' => '1', 'fields' => ['name', 'originalDescription', 'category', 'location', 'patrimony', 'invoiceNumber', 'acquiredAt', 'value'],
    'from' => '2026-01-01', 'to' => '2026-12-31', 'scope' => 'active', 'orientation' => 'auto',
], $syntheticAssets, true);
report_test_assert(str_contains($manyFields, 'data-page-layout="landscape"'), 'orientação automática escolhe paisagem quando há mais de cinco colunas');
$portraitOverride = render_report_template([
    'report' => '1', 'fields' => ['name', 'value', 'status'], 'scope' => 'all', 'orientation' => 'landscape',
], $syntheticAssets, true);
report_test_assert(str_contains($portraitOverride, 'data-page-layout="landscape"') && str_contains($portraitOverride, 'Ativo baixado sintético'), 'orientação manual é respeitada e o status Todos inclui baixas');

$allFields = render_report_template(['report' => '1', 'fields' => array_keys([
    'name'=>1,'originalDescription'=>1,'classification'=>1,'category'=>1,'location'=>1,'patrimony'=>1,'brand'=>1,'color'=>1,'type'=>1,'condition'=>1,'invoiceNumber'=>1,'acquiredAt'=>1,'quantity'=>1,'value'=>1,'usefulLife'=>1,'status'=>1,'disposalDate'=>1,'disposalReason'=>1,'notes'=>1,'purchaseYear'=>1,'legacyId'=>1,'sourceSheet'=>1,'sourceRow'=>1,
]), 'scope' => 'all'], $syntheticAssets, true);
report_test_assert(str_contains($allFields, 'Aba de origem') && str_contains($allFields, 'Linha de origem') && str_contains($allFields, 'data-page-layout="landscape"') && str_contains($allFields, 'data-report-table') && !str_contains($allFields, 'data-report-records'), 'mesmo todos os 23 campos permanecem em uma tabela A4 paisagem');
preg_match('~<table class="table report-data-table"[^>]*>(.*?)</table>~s', $allFields, $allTableMatch);
$allTable = $allTableMatch[1] ?? '';
report_test_assert(substr_count($allTable, '<th scope="col">') === 23 && str_contains($allTable, '<th scope="col">Aba de origem</th>') && str_contains($allTable, '<th scope="col">Linha de origem</th>'), 'os 23 nomes de campo ficam no cabeçalho da tabela');
report_test_assert(substr_count($allTable, '<tr>') === 4 && str_contains($allTable, 'Equipamento sintético') && str_contains($allTable, 'Ativo baixado sintético') && str_contains($allTable, 'Ativo fora do período'), 'cada ativo aparece em uma linha abaixo do cabeçalho');
$nameOnly = render_report_template(['report' => '1', 'fields' => ['name'], 'scope' => 'all'], $syntheticAssets, true);
preg_match('~<section class="panel report-print-document".*?</section>~s', $nameOnly, $nameOnlyDocument);
report_test_assert(isset($nameOnlyDocument[0]) && !str_contains($nameOnlyDocument[0], '<th scope="col">Valor de aquisição</th>') && str_contains($nameOnlyDocument[0], 'R$ 1.500,00'), 'total de todos os registros filtrados aparece mesmo sem a coluna Valor');

$noneSelected = render_report_template(['report' => '1', 'fields' => [], 'scope' => 'active'], $syntheticAssets, true);
report_test_assert(str_contains($noneSelected, 'Selecione pelo menos um campo') && !str_contains($noneSelected, 'data-report-table'), 'nenhum campo impede a emissão da prévia');
$noRows = render_report_template(['report' => '1', 'fields' => ['name'], 'from' => '2030-01-01', 'to' => '2030-12-31', 'scope' => 'active'], $syntheticAssets, true);
report_test_assert(str_contains($noRows, 'Nenhum ativo corresponde aos filtros selecionados') && str_contains($noRows, '<th scope="col">Item</th>'), 'período sem resultados mantém cabeçalho e estado vazio');
$badDateRange = render_report_template(['report' => '1', 'fields' => ['name'], 'from' => '2026-12-31', 'to' => '2026-01-01', 'scope' => 'active'], $syntheticAssets, true);
report_test_assert(str_contains($badDateRange, 'A data inicial deve ser anterior') && !str_contains($badDateRange, 'data-report-table'), 'período invertido é rejeitado também no relatório personalizado');

$readOnly = render_report_template(['report' => '1', 'fields' => ['name', 'value'], 'scope' => 'all'], $syntheticAssets, false);
report_test_assert(!str_contains($readOnly, 'data-report-builder') && !str_contains($readOnly, 'data-report-table') && !str_contains($readOnly, 'data-report-records') && !str_contains($readOnly, 'data-report-export-section') && !str_contains($readOnly, 'Equipamento sintético'), 'conta sem export_data não recebe campos, dados detalhados nem opções de exportação no HTML');
$defaultOptions = render_report_template([], $syntheticAssets, true);
report_test_assert(str_contains($defaultOptions, 'name="fields[]" value="name" data-report-field checked') && str_contains($defaultOptions, 'data-report-select-all'), 'o construtor mostra campos padrão marcados e controle para selecionar todos');

$fixedFilters = report_filters_from_input(['from' => '2026-01-01', 'to' => '2026-12-31', 'status' => 'all', 'asset_class' => 'fixed']);
$fixedAssets = report_filter_assets($syntheticAssets, $fixedFilters);
report_test_assert(count($fixedAssets) === 1 && $fixedAssets[0]['id'] === 1, 'filtro Ativo imobilizado respeita classe, status e período juntos');
$smallFilters = report_filters_from_input(['status' => 'all', 'asset_class' => 'small']);
$smallAssets = report_filter_assets($syntheticAssets, $smallFilters);
report_test_assert(count($smallAssets) === 1 && $smallAssets[0]['status'] === 'disposed' && report_total_cents($smallAssets) === 5000, 'filtro Bem de pequeno valor inclui somente itens da classe escolhida e soma os valores');
report_test_assert(report_field_display_value($syntheticAssets[1], 'classification') === 'Bem de pequeno valor', 'a coluna Classificação usa o rótulo patrimonial em português');

$xlsxFilters = report_filters_from_input(['from' => '2026-01-01', 'to' => '2026-12-31', 'status' => 'active', 'asset_class' => 'fixed']);
$xlsxAssets = report_filter_assets($syntheticAssets, $xlsxFilters);
$xlsxRows = report_xlsx_rows($xlsxAssets, ['name', 'patrimony', 'value', 'campo-inválido'], $xlsxFilters);
report_test_assert($xlsxRows[3] === ['Item', 'Patrimônio', 'Valor de aquisição'] && count($xlsxRows[4]) === 3, 'Excel inclui apenas os cabeçalhos permitidos e selecionados');
report_test_assert($xlsxRows[4][0] === '<script>Equipamento sintético</script>' && $xlsxRows[4][1] === 'PAT-SINT-001' && is_float($xlsxRows[4][2]), 'Excel preserva o patrimônio como texto e valores monetários como número');
report_test_assert($xlsxRows[6][0] === 'Valor total de aquisição após filtros' && $xlsxRows[6][1] === 1250.0, 'a planilha inclui total numérico após o conjunto filtrado');
$xlsxXml = xlsx_sheet_xml($xlsxRows);
report_test_assert(str_contains($xlsxXml, 'PAT-SINT-001') && str_contains($xlsxXml, 't="n"><v>1250</v>') && !str_contains($xlsxXml, 'Ativo baixado sintético'), 'XML da planilha contém somente linhas filtradas e células numéricas para valores');

echo "Total: 29 verificações do construtor de relatórios aprovadas.\n";
