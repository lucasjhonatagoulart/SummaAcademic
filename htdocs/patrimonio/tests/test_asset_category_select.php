<?php

declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function page_url(string $page, array $params = []): string
{
    return 'index.php?page=' . rawurlencode($page) . ($params ? '&' . http_build_query($params) : '');
}
function csrf_field(): string { return '<input type="hidden" name="csrf" value="test">'; }
function status_label(string $status): string { return $status === 'active' ? 'Ativo' : $status; }
function can(string $capability): bool { return in_array($capability, $GLOBALS['test_permissions'] ?? [], true); }
function nfe_valid_date(string $value): bool { return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1; }

function render_asset_form(string $current, array $categories, string $classification = 'fixed', bool $manageCatalogs = false): string
{
    $GLOBALS['test_permissions'] = $manageCatalogs ? ['manage_catalogs'] : [];
    $asset = [
        'id' => 12, 'sourceSheet' => 'Planilha histórica', 'legacyId' => 'LEGADO-12', 'name' => 'Item de teste', 'classification' => $classification,
        'status' => 'active', 'category' => $current, 'location' => 'Local teste',
        'patrimony' => 'PATR-TESTE', 'usefulLife' => 5, 'brand' => '',
        'color' => '', 'type' => '', 'condition' => 'Novo', 'notes' => '',
        'acquiredAt' => '2026-03-01', 'quantity' => '1.000', 'value' => '1250.00', 'purchaseYear' => 2026,
    ];
    $catalogs = ['category' => $categories, 'location' => ['Local teste']];
    ob_start();
    require __DIR__ . '/../templates/asset-form.php';
    return (string)ob_get_clean();
}

function render_asset_list_filter(): string
{
    $data = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    $search = '';
    $status = 'all';
    $classification = 'all';
    $category = '';
    $location = '';
    $catalogs = ['category' => ['Conta de teste'], 'location' => ['Local teste']];
    ob_start();
    require __DIR__ . '/../templates/assets.php';
    return (string)ob_get_clean();
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
    echo "OK - {$message}\n";
}

$html = render_asset_form('Software', ['Móveis e Utensílios', 'Software']);
check(str_contains($html, '<span>Conta Patrimonial</span>') && !str_contains($html, '<span>Categoria</span>'), 'o formulário usa Conta Patrimonial como rótulo visível');
check((bool)preg_match('/<select name="category"[^>]*>/', $html), 'a conta patrimonial continua em um menu suspenso com o nome técnico preservado');
check(str_contains($html, '<option value="Móveis e Utensílios"'), 'mostra a primeira conta patrimonial cadastrada');
check(str_contains($html, '<option value="Software" selected>Software</option>'), 'seleciona por padrão a conta patrimonial atual');
check(!preg_match('/<input[^>]+name="category"/', $html), 'não depende de digitação para abrir a lista');
check(str_contains($html, '<select name="classification" required>') && str_contains($html, '<option value="fixed" selected>Ativo Fixo</option>'), 'permite editar a classificação atual do ativo');
check(str_contains($html, 'name="patrimony"') && str_contains($html, 'name="acquiredAt"') && str_contains($html, 'name="quantity"') && str_contains($html, 'name="value"'), 'expõe patrimônio, aquisição, quantidade e valor para edição');
check(str_contains($html, 'ativos importados da planilha'), 'o mesmo formulário é oferecido para registros históricos da planilha');

$htmlSmall = render_asset_form('Software', ['Móveis e Utensílios', 'Software'], 'small');
check(str_contains($htmlSmall, '<option value="small" selected>Bem de pequeno valor</option>'), 'permite alternar um ativo existente para Bem de pequeno valor');

$htmlWithLegacy = render_asset_form('Conta histórica fora do catálogo', ['Móveis e Utensílios']);
check(str_contains($htmlWithLegacy, '<option value="Conta histórica fora do catálogo" selected>'), 'mantém a conta atual visível mesmo se ainda não estiver no catálogo');
check(str_contains($htmlWithLegacy, 'Clique para ver as contas patrimoniais cadastradas.'), 'orienta onde administrar novas contas patrimoniais');

$htmlAdmin = render_asset_form('Software', ['Móveis e Utensílios', 'Software'], 'fixed', true);
check((bool)preg_match('/<select name="category"[^>]*>/', $htmlAdmin) && !preg_match('/<input[^>]+name="category"[^_]/', $htmlAdmin), 'gestor de catálogos também recebe a lista completa, sem campo de texto que filtra as opções');
check(substr_count($htmlAdmin, '<option value="Móveis e Utensílios"') === 1 && str_contains($htmlAdmin, '<option value="Software" selected>Software</option>'), 'gestor vê todas as contas, com a atual selecionada');
check(str_contains($htmlAdmin, '<option value="__new__">+ Nova conta patrimonial…</option>') && str_contains($htmlAdmin, 'name="category_new"') && str_contains($htmlAdmin, 'data-category-new hidden'), 'gestor pode criar uma conta nova por uma opção separada');
check(!str_contains($html, '__new__') && !str_contains($html, 'category_new'), 'quem não gerencia catálogos não vê a opção de conta nova');
check(!str_contains($htmlAdmin, 'categories-list'), 'a lista de sugestões que escondia as opções foi removida');
$listFilter = render_asset_list_filter();
check(str_contains($listFilter, '<span>Conta Patrimonial</span><select name="category">') && !str_contains($listFilter, '<span>Categorias</span>'), 'o filtro da lista de ativos também exibe Conta Patrimonial e preserva o nome técnico');
$assetListSource = (string)file_get_contents(__DIR__ . '/../templates/assets.php');
check(str_contains($assetListSource, "page_url('asset-edit'") && str_contains($assetListSource, "if (can('edit_assets'))"), 'a lista oferece atalho Editar apenas para usuários com edit_assets');

printf("Total: 18 verificações do formulário e seletor de Conta Patrimonial aprovadas.\n");
