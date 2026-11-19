<?php

declare(strict_types=1);

function utf8_length(string $value): int { return strlen($value); }
function text_value(mixed $value, int $limit = 5000): string
{
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = trim((string)$value);
    if (utf8_length($value) > $limit) throw new RuntimeException('Um campo ultrapassou o limite permitido.');
    return $value;
}

require_once __DIR__ . '/../app/nfe.php';
require_once __DIR__ . '/../app/actions.php';
require_once __DIR__ . '/../app/reporting.php';
require_once __DIR__ . '/../app/export_helpers.php';
require_once __DIR__ . '/../app/backup.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    $checks++;
    echo "OK - {$message}\n";
};
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (RuntimeException) { return true; }
    return false;
};
$catalogs = ['category' => ['Equipamentos'], 'location' => ['Laboratório']];
$valid = [
    'name' => 'Microscópio patrimonial', 'category' => 'Equipamentos', 'location' => 'Laboratório',
    'patrimony' => 'PAT-2026-001', 'classification' => 'fixed', 'acquiredAt' => '2024-08-17',
    'value' => '1.234,56', 'condition' => 'Em uso', 'usefulLife' => '8',
];
$fields = direct_asset_fields_from_post($valid, $catalogs);
$check($fields['invoiceNumber'] === '' && $fields['invoiceId'] === null && $fields['sourceSheet'] === 'Cadastro direto sem NF-e', 'ativo direto mantém NF-e e vínculo vazios, com origem explícita');
$check($fields['name'] === 'Microscópio patrimonial' && $fields['originalDescription'] === $fields['name'] && $fields['patrimony'] === 'PAT-2026-001', 'descrição e patrimônio são preservados no registro');
$check($fields['classification'] === 'fixed' && $fields['quantity'] === 1.0 && $fields['value'] === 1234.56, 'um patrimônio representa uma unidade e preserva o valor');
$check($fields['acquiredAt'] === '2024-08-17' && $fields['purchaseYear'] === 2024 && $fields['usefulLife'] === 8, 'data, ano de compra e vida útil são derivados/validados');
$check($fields['status'] === 'active' && $fields['condition'] === 'Em uso', 'cadastro direto cria ativo sem baixa');

$withoutOptional = $valid;
$withoutOptional['acquiredAt'] = '';
$withoutOptional['value'] = '';
$withoutOptional['usefulLife'] = '';
$optionalFields = direct_asset_fields_from_post($withoutOptional, $catalogs);
$check($optionalFields['acquiredAt'] === null && $optionalFields['purchaseYear'] === null && $optionalFields['value'] === null && $optionalFields['usefulLife'] === null, 'data, valor e vida útil podem permanecer desconhecidos');
$check($optionalFields['condition'] === 'Em uso', 'campos acessórios enviados são mantidos');

$missingPatrimony = $valid;
$missingPatrimony['patrimony'] = '';
$check($throws(static fn() => direct_asset_fields_from_post($missingPatrimony, $catalogs)), 'patrimônio é obrigatório no cadastro direto');
$multiplePatrimony = $valid;
$multiplePatrimony['patrimony'] = 'PAT-001/PAT-002';
$check($throws(static fn() => direct_asset_fields_from_post($multiplePatrimony, $catalogs)), 'um formulário não aceita múltiplos códigos para um único ativo');
$longPatrimony = $valid;
$longPatrimony['patrimony'] = str_repeat('P', 256);
$check($throws(static fn() => direct_asset_fields_from_post($longPatrimony, $catalogs)), 'código patrimonial respeita o limite do banco');
$unknownCategory = $valid;
$unknownCategory['category'] = 'Não cadastrada';
$check($throws(static fn() => direct_asset_fields_from_post($unknownCategory, $catalogs)), 'Conta Patrimonial precisa existir no catálogo');
$unknownLocation = $valid;
$unknownLocation['location'] = 'Não cadastrada';
$check($throws(static fn() => direct_asset_fields_from_post($unknownLocation, $catalogs)), 'Local precisa existir no catálogo');
$badClass = $valid;
$badClass['classification'] = 'skip';
$check($throws(static fn() => direct_asset_fields_from_post($badClass, $catalogs)), 'cadastro direto aceita apenas classes patrimoniais válidas');
$missingClass = $valid;
unset($missingClass['classification']);
$check($throws(static fn() => direct_asset_fields_from_post($missingClass, $catalogs)), 'classificação deve ser escolhida explicitamente');
$badDate = $valid;
$badDate['acquiredAt'] = '2024-02-31';
$check($throws(static fn() => direct_asset_fields_from_post($badDate, $catalogs)), 'data de aquisição inválida é rejeitada');
$badValue = $valid;
$badValue['value'] = '-2,00';
$check($throws(static fn() => direct_asset_fields_from_post($badValue, $catalogs)), 'valor negativo é rejeitado');
$overflowValue = $valid;
$overflowValue['value'] = '10000000000000,00';
$check($throws(static fn() => direct_asset_fields_from_post($overflowValue, $catalogs)), 'valor respeita o limite DECIMAL do banco');
$badLife = $valid;
$badLife['usefulLife'] = '-1';
$check($throws(static fn() => direct_asset_fields_from_post($badLife, $catalogs)), 'vida útil negativa é rejeitada');
$missingName = $valid;
$missingName['name'] = '';
$check($throws(static fn() => direct_asset_fields_from_post($missingName, $catalogs)), 'descrição do ativo é obrigatória');

$asset = [
    'id' => 1, 'sourceSheet' => 'Cadastro direto sem NF-e', 'sourceRow' => null, 'legacyId' => null,
    'classification' => 'fixed', 'name' => 'Microscópio patrimonial', 'originalDescription' => 'Microscópio patrimonial',
    'category' => 'Equipamentos', 'location' => 'Laboratório', 'patrimony' => 'PAT-2026-001', 'brand' => '',
    'color' => '', 'type' => '', 'condition' => 'Em uso', 'invoiceNumber' => '', 'invoiceId' => null,
    'acquiredAt' => '2024-08-17', 'quantity' => 1, 'value' => 1234.56, 'usefulLife' => 8,
    'purchaseYear' => 2024, 'status' => 'active', 'disposalDate' => null, 'disposalReason' => '',
    'notes' => '', 'original' => null,
];
$check(asset_invoice_display_label($asset) === 'Sem NF-e', 'campo de nota vazio recebe fallback legível sem alterar o ativo');
$check(report_field_display_value($asset, 'invoiceNumber') === 'Sem NF-e', 'prévia e impressão do relatório exibem Sem NF-e');
$check(report_field_excel_value($asset, 'invoiceNumber') === 'Sem NF-e', 'XLSX do relatório exibe Sem NF-e mesmo com campo interno vazio');
$filters = report_filters_from_input(['status' => 'active', 'asset_class' => 'fixed']);
$check(count(report_filter_assets([$asset], $filters)) === 1 && report_total_cents([$asset]) === 123456, 'ativo sem NF-e participa dos filtros e do valor total normalmente');
$asset['invoiceNumber'] = 'NF-55';
$check(asset_invoice_display_label($asset) === 'NF-55', 'número fiscal existente permanece inalterado');
$asset['invoiceNumber'] = '';
$workbook = asset_workbook_rows([$asset]);
$check($workbook['Ativo Fixo'][1][7] === 'Sem NF-e', 'planilha legível de ativos exibe Sem NF-e');
$csv = asset_csv_rows([$asset]);
$check($csv[1][7] === 'Sem NF-e', 'CSV legível de ativos exibe Sem NF-e');
$backup = backup_asset_json($asset);
$check(($backup['invoiceNumber'] ?? null) === '' && ($backup['invoiceId'] ?? null) === null && !str_contains(json_encode($backup, JSON_UNESCAPED_UNICODE), 'Sem NF-e'), 'backup JSON mantém os campos internos vazios/nulos para restauração fiel');

$root = dirname(__DIR__);
$assetsTemplate = file_get_contents($root . '/templates/assets.php');
$render = file_get_contents($root . '/app/render.php');
$actions = file_get_contents($root . '/app/actions.php');
$directTemplate = file_get_contents($root . '/templates/asset-create-no-invoice.php');
$check(str_contains($assetsTemplate, "can('manage_users') && can('edit_assets')") && str_contains($assetsTemplate, "page_url('new-asset')"), 'atalho só aparece para contas administradoras com edição de ativos');
$getStart = strpos($render, "case 'new-asset':");
$getEnd = $getStart === false ? false : strpos($render, "case 'asset':", $getStart);
$getRoute = $getStart !== false && $getEnd !== false ? substr($render, $getStart, $getEnd - $getStart) : '';
$check(str_contains($getRoute, "require_permission('manage_users');") && str_contains($getRoute, "require_permission('edit_assets');") && str_contains($getRoute, "render_template('asset-create-no-invoice.php'"), 'rota GET do formulário exige ambas as permissões no servidor');
$check(str_contains($actions, "if (\$action === 'create-asset-no-invoice')") && str_contains($actions, "require_permission('manage_users'); require_permission('edit_assets'); create_asset_without_invoice_action();"), 'POST direto exige gestão de usuários e edição de ativos');
$check(str_contains($actions, "'invoiceNumber' => ''") && str_contains($actions, "'invoiceId' => null") && str_contains($actions, 'INSERT INTO asset_patrimony_registry'), 'novo ativo reserva o patrimônio sem inserir NF-e');
$actionStart = strpos($actions, 'function create_asset_without_invoice_action(): never');
$actionEnd = $actionStart === false ? false : strpos($actions, 'function save_invoice_action(): never', $actionStart);
$directAction = $actionStart !== false && $actionEnd !== false ? substr($actions, $actionStart, $actionEnd - $actionStart) : '';
$check(!str_contains($directAction, 'INSERT INTO invoices') && !str_contains($directAction, 'INSERT INTO invoice_items'), 'cadastro direto não grava cabeçalho nem itens fiscais');
$insertStart = strpos($directAction, 'INSERT INTO assets (');
$columnsStart = $insertStart === false ? false : $insertStart + strlen('INSERT INTO assets (');
$columnsEnd = $columnsStart === false ? false : strpos($directAction, ') VALUES (', $columnsStart);
$valuesStart = $columnsEnd === false ? false : $columnsEnd + strlen(') VALUES (');
$valuesEnd = $valuesStart === false ? false : strpos($directAction, "');", $valuesStart);
$insertMatches = $columnsStart !== false && $columnsEnd !== false && $valuesStart !== false && $valuesEnd !== false;
$columns = $insertMatches ? substr($directAction, $columnsStart, $columnsEnd - $columnsStart) : '';
$values = $insertMatches ? substr($directAction, $valuesStart, $valuesEnd - $valuesStart) : '';
$check($insertMatches && count(explode(',', $columns)) === 25 && substr_count($values, '?') === 25, 'INSERT de ativo tem 25 colunas e 25 parâmetros correspondentes');
$check(!str_contains($directTemplate, 'name="invoiceNumber"') && !str_contains($directTemplate, 'name="invoiceId"'), 'formulário não solicita nem cria vínculo fiscal');

echo "Total: {$checks} verificações do cadastro direto sem NF-e aprovadas.\n";
