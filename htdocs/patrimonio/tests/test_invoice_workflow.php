<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/invoice_workflow.php';

function is_logged_in(): bool { return isset($_SESSION['admin_id']); }
function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="test">'; }
function page_url(string $page, array $extra = []): string { return 'index.php?page=' . rawurlencode($page); }
function get_catalogs(bool $includeDefaults = true): array { return ['category' => ['Equipamentos'], 'location' => ['Depósito']]; }
function money_br(mixed $value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function split_patrimony(string $value): array { return array_values(array_filter(array_map('trim', preg_split('/[\/,\n\r]+/u', $value) ?: []))); }
function can(string $permission): bool { return $permission === 'edit_assets'; }

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    echo "OK - {$message}\n";
};
$assertThrows = static function (callable $callback, string $message): void {
    try { $callback(); } catch (RuntimeException) { echo "OK - {$message}\n"; return; }
    throw new RuntimeException('Falhou: ' . $message);
};

$sourceItems = [
    ['id' => '1', 'originalDescription' => 'DESCRICAO ORIGINAL A', 'name' => 'DESCRICAO ORIGINAL A', 'quantity' => 2.0, 'unit' => 'UN', 'unitValue' => 10.0, 'total' => 20.0, 'ncm' => '84145990', 'cfop' => '5102', 'classification' => 'fixed', 'category' => '', 'location' => '', 'patrimony' => '', 'brand' => '', 'color' => '', 'type' => '', 'condition' => 'Novo', 'usefulLife' => 5, 'notes' => ''],
    ['id' => '2', 'originalDescription' => 'DESCRICAO ORIGINAL B', 'name' => 'DESCRICAO ORIGINAL B', 'quantity' => 1.0, 'unit' => 'PC', 'unitValue' => 35.0, 'total' => 35.0, 'ncm' => '84713012', 'cfop' => '6106', 'classification' => 'fixed', 'category' => '', 'location' => '', 'patrimony' => '', 'brand' => '', 'color' => '', 'type' => '', 'condition' => 'Novo', 'usefulLife' => 5, 'notes' => ''],
];
$posted = [
    ['id' => '2', 'originalDescription' => 'TAMPER B', 'name' => 'Notebook padronizado', 'quantity' => '999', 'unit' => 'HACK', 'unitValue' => '0', 'total' => '0', 'ncm' => 'INVALIDO', 'cfop' => '9999', 'classification' => 'small', 'category' => 'Equipamentos', 'location' => 'Depósito', 'patrimony' => 'PAT-02', 'brand' => 'Marca', 'color' => 'Preto', 'type' => 'Notebook', 'condition' => 'Novo', 'usefulLife' => '5', 'notes' => ''],
    ['id' => '1', 'originalDescription' => 'TAMPER A', 'name' => 'Ventilador padronizado', 'quantity' => '0', 'unit' => 'HACK', 'unitValue' => '0', 'total' => '0', 'ncm' => 'INVALIDO', 'cfop' => '9999', 'classification' => 'skip', 'category' => 'Equipamentos', 'location' => 'Depósito', 'patrimony' => '', 'brand' => '', 'color' => '', 'type' => '', 'condition' => 'Novo', 'usefulLife' => '5', 'notes' => 'Não incorporar'],
];
$canonical = invoice_items_from_xml_form($sourceItems, $posted);
$assert($canonical[0]['id'] === '1' && $canonical[1]['id'] === '2', 'reordena a submissão pelo identificador original do item XML');
$assert($canonical[0]['quantity'] === 2.0 && $canonical[0]['unit'] === 'UN' && $canonical[0]['unitValue'] === 10.0 && $canonical[0]['total'] === 20.0, 'ignora alterações enviadas a quantidade, unidade e valores do XML');
$assert($canonical[0]['originalDescription'] === 'DESCRICAO ORIGINAL A' && $canonical[0]['ncm'] === '84145990' && $canonical[0]['cfop'] === '5102', 'preserva descrição original, NCM e CFOP do documento');
$assert($canonical[0]['classification'] === 'skip' && $canonical[1]['name'] === 'Notebook padronizado' && $canonical[1]['category'] === 'Equipamentos', 'aceita somente classificação e dados patrimoniais editáveis');
$assertThrows(static fn(): array => invoice_items_from_xml_form($sourceItems, [$posted[0]]), 'impede remover item fiscal do XML');
$assertThrows(static fn(): array => invoice_items_from_xml_form($sourceItems, [...$posted, ['id' => '3']]), 'impede adicionar item fiscal ao XML');
$assertThrows(static fn(): array => invoice_items_from_xml_form($sourceItems, [['id' => 'outra-chave'], $posted[1]]), 'rejeita identificador de item que não pertence ao XML');

$_SESSION = ['admin_id' => 7];
invoice_form_old_store([
    'id' => '', 'source' => 'xml', 'accessKey' => 'CHAVE-TAMPER', 'number' => '999', 'notes' => 'observação retida', 'current_step' => '3',
    'items' => [['id' => '1', 'name' => 'Nome retido', 'classification' => 'small', 'quantity' => '999', 'unit' => 'HACK', 'ncm' => 'INVALIDO', 'category' => 'Equipamentos']],
]);
$old = $_SESSION['invoice_form_old']['data'];
$assert(!array_key_exists('accessKey', $old) && $old['notes'] === 'observação retida', 'snapshot de erro XML não guarda valores fiscais alterados');
$assert(!array_key_exists('quantity', $old['items'][0]) && $old['items'][0]['name'] === 'Nome retido', 'snapshot XML conserva classificação patrimonial, não campos fiscais');

$_GET = ['step' => 2];
$xmlOptionalItem = $canonical[0];
$xmlOptionalItem['classification'] = 'fixed';
$xmlOptionalItem['location'] = '';
$xmlOptionalItem['patrimony'] = '';
$invoice = [
    'id' => 12, 'source' => 'xml', 'number' => '123', 'series' => '1', 'issuedAt' => '2026-11-04', 'accessKey' => '1234567890',
    'supplier' => 'FORNECEDOR DE TESTE', 'supplierCnpj' => '98765432000198', 'recipient' => 'DESTINO DE TESTE', 'recipientCnpj' => '98765432000198', 'total' => 20,
    'notes' => '', 'xml' => '<xml>conteúdo sintético</xml>', 'items' => [$xmlOptionalItem],
];
$isEdit = true;
ob_start();
require __DIR__ . '/../templates/invoice-form.php';
$html = (string)ob_get_clean();
$assert(str_contains($html, 'Dados da nota') && str_contains($html, 'Classificação dos itens') && str_contains($html, 'Etapa 3 · Conferência'), 'template contém as três etapas da origem React');
$assert(str_contains($html, 'data-xml-dropzone') && str_contains($html, 'limite de 2 MB'), 'template oferece dropzone e limite de upload visível');
$assert((bool)preg_match('/name="accessKey"[^>]*readonly/', $html) && (bool)preg_match('/name="items\[0\]\[quantity\]"[^>]*readonly/', $html), 'campos fiscais de cabeçalho e item XML renderizam como somente leitura');
$assert((bool)preg_match('/name="accessKey"[^>]*value="1234567890"[^>]*readonly/', $html) && (bool)preg_match('/name="items\[0\]\[quantity\]"[^>]*value="2"[^>]*readonly/', $html), 'reabertura após erro mantém os valores fiscais canônicos do XML');
$assert(str_contains($html, 'Local <small data-xml-optional') && str_contains($html, 'Patrimônio por unidade <small data-xml-optional') && str_contains($html, 'Na importação XML, Local e Patrimônio são opcionais'), 'wizard identifica Local e Patrimônio como opcionais no XML');
$locationMarkup = [];
$patrimonyMarkup = [];
$xmlOptionalControls = preg_match('/<select name="items\[0\]\[location\]"([^>]*)>/', $html, $locationMarkup) === 1
    && preg_match('/<textarea name="items\[0\]\[patrimony\]"([^>]*)>/', $html, $patrimonyMarkup) === 1;
$assert($xmlOptionalControls && !str_contains($locationMarkup[1], 'required') && !str_contains($patrimonyMarkup[1], 'required'), 'controles XML sem Local/Patrimônio não têm bloqueio required no navegador');
$assert(str_contains($html, 'O código e o Local podem ser completados depois na edição do ativo.'), 'wizard explica que os dados opcionais podem ser completados depois');
$assert(substr_count($html, '<form ') === 1 && str_contains($html, 'data-review-items'), 'upload e salvamento compartilham um formulário seguro com painel de revisão');

$invoicesTemplate = file_get_contents(__DIR__ . '/../templates/invoices.php');
$invoiceActionsPosition = is_string($invoicesTemplate) ? strpos($invoicesTemplate, 'invoice-page-actions') : false;
$invoiceListPosition = is_string($invoicesTemplate) ? strpos($invoicesTemplate, '<section class="panel invoice-list-panel"') : false;
$assert(is_string($invoicesTemplate) && str_contains($invoicesTemplate, 'Rascunhos') && str_contains($invoicesTemplate, 'Incorporadas') && str_contains($invoicesTemplate, 'Cadastrar / Importar NF-e') && str_contains($invoicesTemplate, 'Exportar NF-es (.xlsx)') && $invoiceActionsPosition !== false && $invoiceListPosition !== false && $invoiceActionsPosition < $invoiceListPosition, 'lista contém abas e ações de cadastro/importação e exportação acima das NF-e');
