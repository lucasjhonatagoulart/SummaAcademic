<?php

declare(strict_types=1);

function text_value(mixed $value, int $limit = 5000): string
{
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = trim((string)$value);
    if (strlen($value) > $limit) throw new RuntimeException('Campo acima do limite.');
    return $value;
}
function utf8_length(string $value): int { return strlen($value); }
function nfe_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}
require __DIR__ . '/../app/restore.php';
require __DIR__ . '/../app/actions.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
    $checks++;
    echo "OK - {$message}\n";
};
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (RuntimeException) { return true; }
    return false;
};

$legacyAsset = [
    'id' => 42,
    'sourceSheet' => 'Planilha patrimonial antiga',
    'legacyId' => 'PLAN-0042',
    'classification' => 'fixed',
    'name' => 'Computador histórico',
    'category' => 'Equipamentos históricos',
    'location' => 'Sala antiga',
    'patrimony' => 'PATR-ANTIGO-42',
    'acquiredAt' => '31/12/2020',
    'quantity' => '2.000',
    'value' => '1500.00',
    'usefulLife' => 5,
    'purchaseYear' => 2020,
];
$posted = [
    'classification' => 'small',
    'name' => 'Computador revisado',
    'category' => 'Tecnologia',
    'location' => 'Laboratório',
    'patrimony' => 'PATR-NOVO-42',
    'acquiredAt' => '31/12/2020',
    'quantity' => '2.000',
    'value' => '875,50',
    'usefulLife' => '3',
    'purchaseYear' => '2021',
    'brand' => 'Marca',
    'color' => 'Cinza',
    'type' => 'Desktop',
    'condition' => 'Usado',
    'notes' => 'Reclassificado após conferência.',
];
$fields = asset_edit_fields_from_post($posted, $legacyAsset);
$check($fields['classification'] === 'small', 'aceita a troca de Ativo Imobilizado para Bem de pequeno valor');
$check($fields['category'] === 'Tecnologia' && $fields['location'] === 'Laboratório', 'aceita a nova Conta Patrimonial e o Local selecionados');
$check($fields['patrimony'] === 'PATR-NOVO-42', 'aceita o novo código patrimonial do ativo legado');
$check($fields['acquiredAt'] === '31/12/2020', 'preserva a data histórica em formato legado quando não alterada');
$check($fields['quantity'] === 2.0 && $fields['value'] === 875.5, 'valida e converte quantidade e valor editáveis');
$check($fields['purchaseYear'] === 2021 && $fields['usefulLife'] === 3, 'valida ano da compra e vida útil');
$check($fields['name'] === 'Computador revisado' && $fields['condition'] === 'Usado' && $fields['notes'] === 'Reclassificado após conferência.', 'mantém os demais campos operacionais no conjunto permitido');
$newCategory = asset_category_edit_selection('Tecnologia Nova', ['Software', 'Móveis e Utensílios'], '', true);
$check($newCategory === ['value' => 'Tecnologia Nova', 'create' => true], 'gestor de catálogos pode informar uma nova Conta Patrimonial para inclusão transacional');
$check($throws(static fn() => asset_category_edit_selection('Tecnologia Nova', ['Software'], '', false)), 'perfil sem gestão de catálogos não pode criar uma conta fora das opções cadastradas');
$existingEquivalent = asset_category_edit_selection('moveis e utensilios', ['Móveis e Utensílios'], '', false);
$check($existingEquivalent === ['value' => 'Móveis e Utensílios', 'create' => false], 'grafia equivalente é direcionada à conta existente sem criar duplicação');
$currentUnlisted = asset_category_edit_selection('Conta Histórica', [], 'Conta Histórica', false);
$check($currentUnlisted === ['value' => 'Conta Histórica', 'create' => false], 'valor histórico atual fora do catálogo pode permanecer sem ser recriado');

$directAsset = array_merge($legacyAsset, [
    'sourceSheet' => 'Cadastro direto sem NF-e',
    'originalDescription' => 'Microscópio patrimonial',
    'invoiceNumber' => '',
    'invoiceId' => null,
]);
$directFields = asset_edit_fields_from_post($posted, $directAsset);
$check($directFields['name'] === 'Computador revisado' && $directFields['acquiredAt'] === '31/12/2020', 'ativo sem NF-e aceita editar descrição e data da aquisição');
$check($directFields['quantity'] === 2.0 && $directFields['value'] === 875.5, 'ativo sem NF-e aceita editar quantidade e valor de aquisição');
$withoutLocation = $posted;
$withoutLocation['location'] = '';
$emptyLocationFields = asset_edit_fields_from_post($withoutLocation, $legacyAsset);
$check($emptyLocationFields['location'] === '', 'edição aceita deixar Local vazio sem mudar a validação dos outros campos');

$invalidClassification = $posted;
$invalidClassification['classification'] = 'other';
$check($throws(static fn() => asset_edit_fields_from_post($invalidClassification, $legacyAsset)), 'rejeita classes fora da allowlist');
$invalidDate = $posted;
$invalidDate['acquiredAt'] = '2021-02-30';
$check($throws(static fn() => asset_edit_fields_from_post($invalidDate, $legacyAsset)), 'rejeita datas novas inválidas');
$invalidQuantity = $posted;
$invalidQuantity['quantity'] = '1000000000';
$check($throws(static fn() => asset_edit_fields_from_post($invalidQuantity, $legacyAsset)), 'rejeita quantidade acima do limite do banco');
$invalidValue = $posted;
$invalidValue['value'] = '10000000000000';
$check($throws(static fn() => asset_edit_fields_from_post($invalidValue, $legacyAsset)), 'rejeita valor acima do limite decimal do banco');
$invalidYear = $posted;
$invalidYear['purchaseYear'] = '10000';
$check($throws(static fn() => asset_edit_fields_from_post($invalidYear, $legacyAsset)), 'rejeita ano fora do limite numérico');
$emptyOptionalValues = $posted;
$emptyOptionalValues['quantity'] = '';
$emptyOptionalValues['value'] = '';
$check(asset_edit_fields_from_post($emptyOptionalValues, $legacyAsset)['quantity'] === null && asset_edit_fields_from_post($emptyOptionalValues, $legacyAsset)['value'] === null, 'permite limpar quantidade e valor que não se aplicam');

$render = (string)file_get_contents(__DIR__ . '/../app/render.php');
preg_match("/case 'asset-edit':(.*?)break;/s", $render, $routeMatch);
$check(isset($routeMatch[1]) && str_contains($routeMatch[1], "require_permission('edit_assets')"), 'rota de edição de ativos, inclusive legados, exige edit_assets no servidor');
$actions = (string)file_get_contents(__DIR__ . '/../app/actions.php');
$assetForm = (string)file_get_contents(__DIR__ . '/../templates/asset-form.php');
$assetDetail = (string)file_get_contents(__DIR__ . '/../templates/asset-detail.php');
$assetList = (string)file_get_contents(__DIR__ . '/../templates/assets.php');
$repository = (string)file_get_contents(__DIR__ . '/../app/repository.php');
$check(str_contains($actions, "if (\$action === 'save-asset') { require_permission('edit_assets'); save_asset_action(); }"), 'POST de atualização continua exigindo edit_assets');
$check(str_contains($assetForm, 'name="acquiredAt"') && str_contains($assetForm, 'name="quantity"') && str_contains($assetForm, 'name="value"') && str_contains($assetForm, 'name="classification"') && str_contains($assetForm, 'name="category"') && str_contains($assetForm, 'name="location"') && str_contains($assetForm, 'name="patrimony"'), 'formulário oferece classe, conta, local, patrimônio, data, quantidade e valor para edição');
$check(str_contains($assetForm, 'Editar ativo sem NF-e') && str_contains($assetForm, 'Descrição do item') && str_contains($assetDetail, 'Editar ativo sem NF-e') && str_contains($assetList, 'Editar sem NF-e'), 'lista, detalhe e formulário identificam a edição do ativo sem NF-e');
$locationInput = preg_match('/<input name="location"[^>]*>/', $assetForm, $locationMatch) === 1 ? $locationMatch[0] : '';
$check($locationInput !== '' && !str_contains($locationInput, 'required') && str_contains($assetForm, 'Local (opcional)'), 'formulário não marca Local como obrigatório e informa que pode ficar em branco');
$check(str_contains($repository, 'invoiceNumber, invoiceId, acquiredAt') && str_contains($actions, "'sourceSheet'] ?? '') === 'Cadastro direto sem NF-e'"), 'origem sem NF-e é identificada explicitamente sem inferir dados fiscais');
$saveStart = strpos($actions, 'function save_asset_action(): never');
$saveEnd = $saveStart === false ? false : strpos($actions, 'function dispose_asset_action(): never', $saveStart);
$saveAction = $saveStart !== false && $saveEnd !== false ? substr($actions, $saveStart, $saveEnd - $saveStart) : '';
$check(str_contains($saveAction, "if (\$fields['name'] === '' || \$fields['category'] === '')") && str_contains($saveAction, "if (\$field === 'location' && \$fields[\$field] === '') continue;"), 'servidor permite Local vazio e mantém nome/Conta Patrimonial obrigatórios');
$check(str_contains($saveAction, "'classification=?,name=?,category=?,location=?,patrimony=?,brand=?,color=?,type=?,`condition`=?,acquiredAt=?,quantity=?,value=?,usefulLife=?,purchaseYear=?,notes=?'") && str_contains($saveAction, "prepare('UPDATE assets SET ' . \$updateColumns . ' WHERE id=?')"), 'UPDATE preparado grava classe, conta, patrimônio e todos os campos de aquisição');
$check(str_contains($saveAction, "if (\$isDirectNoInvoice) { \$updateColumns .= ',originalDescription=?'; \$updateValues[] = \$fields['name']; }") && !str_contains($saveAction, 'invoiceNumber=') && !str_contains($saveAction, 'invoiceId='), 'descrição editada é sincronizada só em cadastro direto e o vínculo fiscal não é alterado');
$check(str_contains($actions, 'isset($occupied[$token]) && !isset($currentTokens[$token])') && str_contains($actions, 'INSERT INTO asset_patrimony_registry'), 'mantém a proteção de patrimônio duplicado e sincroniza o registro');

printf("Total: %d verificações de edição de ativos aprovadas sem banco.\n", $checks);
