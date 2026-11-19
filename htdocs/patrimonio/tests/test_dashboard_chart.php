<?php

declare(strict_types=1);

function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function can(string $permission): bool { return false; }
function page_url(string $page, array $params = []): string { return 'index.php?page=' . rawurlencode($page); }
function money_br(mixed $value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function status_label(string $status): string { return $status === 'disposed' ? 'Baixado' : 'Ativo'; }

$checks = 0;
function expect(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
    $checks++;
    echo "OK: $message\n";
}

$categories = [
    ['category'=>'Móveis e Utensílios','count'=>50,'value'=>50000],
    ['category'=>'','count'=>20,'value'=>30000],
    ['category'=>'Informática','count'=>10,'value'=>18000],
    ['category'=>'Laboratório','count'=>8,'value'=>12000],
    ['category'=>'Software','count'=>7,'value'=>10000],
    ['category'=>'Máquinas','count'=>5,'value'=>5000],
];
$stats = [
    'assets'=>['active'=>100,'disposed'=>2,'value'=>125000],
    'invoices'=>['registered'=>10,'drafts'=>1],
    'categories'=>$categories,
    'categoryCounts'=>$categories,
    'recent'=>[['id'=>1,'name'=>'Item sintético','category'=>'Software','patrimony'=>'TEST-1','value'=>1000,'status'=>'active']],
];
ob_start();
require __DIR__ . '/../templates/dashboard.php';
$html = (string)ob_get_clean();
$themeCss = (string)file_get_contents(__DIR__ . '/../assets/theme.css');
$layout = (string)file_get_contents(__DIR__ . '/../templates/layout.php');
expect(str_contains($html, 'conic-gradient('), 'renderiza o gráfico circular com gradiente por fatias');
expect(substr_count($html, 'var(--donut-gap)') === 5, 'separa visualmente as cinco fatias sem alterar o total');
expect(str_contains($html, 'var(--donut-5)') && !str_contains($html, 'var(--donut-6)'), 'usa exatamente cinco cores para até cinco fatias visíveis');
$palette = ['#8ECAE6', '#219EBC', '#023047', '#FFB703', '#FB8500'];
$paletteConfigured = true;
foreach ($palette as $index => $color) {
    $paletteConfigured = $paletteConfigured && str_contains($themeCss, '--donut-' . ($index + 1) . ': ' . $color . ';');
}
expect($paletteConfigured, 'configura na ordem aprovada as cinco cores do tema claro');
expect(substr_count($themeCss, '--donut-3: #023047;') === 2 && str_contains($themeCss, '--donut-gap: #172131;'), 'Móveis mantém o mesmo azul-marinho nos temas claro e escuro');
expect(str_contains($themeCss, '--brand: #172B4D;') && str_contains($themeCss, '--brand: #0B1424;') && str_contains((string)file_get_contents(__DIR__ . '/../assets/app.css'), 'background: var(--brand); color: #fff;') && str_contains((string)file_get_contents(__DIR__ . '/../templates/dashboard.php'), "'Outras contas patrimoniais' => 'var(--brand)'"), 'o fundo do menu e a fatia Outras usam a cor da marca nos dois temas');
expect(str_contains($layout, 'head_assets()') && str_contains((string)file_get_contents(__DIR__ . '/../app/ui.php'), "asset_url('theme.css')"), 'a folha de tema é carregada com a versão do aplicativo');
expect(str_contains($html, 'aria-label="100 ativos distribuídos em 6 contas patrimoniais"'), 'expõe resumo acessível da distribuição');
expect(str_contains($html, 'Móveis e Utensílios'), 'legenda inclui categorias visíveis');
expect(preg_match('/style="background:var\(--donut-3\)" aria-hidden="true"><\/span><span class="donut-name" title="Móveis e Utensílios">Móveis e Utensílios<\/span>/', $html) === 1, 'Móveis e Utensílios mantém a cor fixa na legenda');
expect(preg_match('/style="background:var\(--donut-5\)" aria-hidden="true"><\/span><span class="donut-name" title="Sem conta patrimonial">Sem conta patrimonial<\/span>/', $html) === 1, 'Sem conta patrimonial recebe cor fixa para categoria vazia');
expect(preg_match('/style="background:var\(--brand\)" aria-hidden="true"><\/span><span class="donut-name" title="Outras contas patrimoniais">Outras contas patrimoniais<\/span>/', $html) === 1, 'Outras contas patrimoniais usa a cor do menu');
expect(str_contains($html, 'var(--donut-3) 0.0000%') && str_contains($html, 'var(--donut-5)'), 'cores fixas também são usadas no gradiente das fatias');
expect(str_contains($html, 'var(--brand)'), 'a cor do menu também é usado na fatia agregada');
expect(str_contains($html, 'Outras contas patrimoniais'), 'agrupa categorias excedentes em “Outras contas patrimoniais”');
expect(str_contains($html, '50 · 50,0%'), 'legenda exibe contagens e percentuais');
expect(str_contains($html, 'Valor por conta patrimonial'), 'preserva também o gráfico de barras por valor');
expect(str_contains($html, 'Ativos recentes'), 'mantém a tabela de ativos recentes');

$stats['assets']['active'] = 0;
$stats['assets']['value'] = 0;
$stats['categoryCounts'] = [];
$stats['categories'] = [];
$stats['recent'] = [];
ob_start();
require __DIR__ . '/../templates/dashboard.php';
$emptyHtml = (string)ob_get_clean();
expect(str_contains($emptyHtml, 'A distribuição aparece após os primeiros registros.'), 'estado vazio informa quando a distribuição ainda não existe');
expect(str_contains($emptyHtml, 'Ainda não há ativos para mostrar no gráfico'), 'gráfico vazio tem descrição acessível');

echo "PASS: $checks verificações do dashboard\n";
