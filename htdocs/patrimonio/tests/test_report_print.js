const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const js = fs.readFileSync(path.join(root, 'assets/report.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8');
const template = fs.readFileSync(path.join(root, 'templates/reports.php'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'templates/layout.php'), 'utf8');
const renderer = fs.readFileSync(path.join(root, 'app/render.php'), 'utf8');
const repository = fs.readFileSync(path.join(root, 'app/repository.php'), 'utf8');
const reporting = fs.readFileSync(path.join(root, 'app/reporting.php'), 'utf8');
const index = fs.readFileSync(path.join(root, 'index.php'), 'utf8');
const controlPanel = fs.readFileSync(path.join(root, 'templates/control-panel.php'), 'utf8');
const summaryHeaderMarkup = template.split('class="report-summary-header"')[1]?.split('<?php if (!$groups)')[0] ?? '';
const firstPageHeaderMarkup = template.split('class="report-print-header report-summary-print-header"')[1]?.split('</header>')[0] ?? '';
let checks = 0;
function verify(condition, message) {
  assert.ok(condition, message);
  checks += 1;
  console.log(`OK - ${message}`);
}

verify(js.includes('window.print()') && js.includes("'beforeprint'") && js.includes("'afterprint'"), 'imprimir/PDF usa o diálogo nativo e restaura o estado depois da impressão');
verify(js.includes('[data-report-select-all]') && js.includes('[data-report-clear]') && js.includes('countSelected() > 0'), 'a seleção de campos pode ser marcada, limpa e não aceita relatório vazio');
verify(js.includes('Math.max(760, columnCount * 110)'), 'a tabela extensa tem prévia horizontal rolável em tela');
verify(!js.includes('https://') && !js.includes('cdn.'), 'a interface não depende de CDN ou serviço externo');

verify(/@page\s*\{\s*size:\s*A4 landscape;\s*margin:\s*8mm;\s*\}/.test(css), 'há fallback de impressão A4 paisagem com margens definidas');
verify(css.includes('@page reportPortrait { size: A4 portrait; margin: 12mm 10mm; }') && css.includes('@page reportLandscape { size: A4 landscape; margin: 8mm; }'), 'A4 retrato e paisagem usam margens próprias');
verify(css.includes('.report-table-wrap { max-height: min(60vh, 560px); overflow: auto;'), 'a caixa de prévia limita a altura e rola internamente');
verify(css.includes('max-height: none !important; overflow: visible !important;'), 'a impressão remove o limite de rolagem e imprime todas as linhas');
verify(css.includes('display: table-header-group') && css.includes('break-inside: avoid'), 'cabeçalho da tabela repete e tenta manter cada linha inteira entre páginas');
verify(css.includes('width: 100%') && css.includes('table-layout: fixed') && css.includes('overflow-wrap: anywhere'), 'a tabela se ajusta à folha e quebra dados longos dentro das células');
verify(css.includes('body.report-print-mode .report-export-actions') && css.includes('display: none !important;'), 'os controles de tela não aparecem no PDF/impresso');

verify(reporting.includes("'asset_class' =>") && reporting.includes("'fixed', 'small', 'all'"), 'a allowlist de classificação inclui ativo imobilizado, bem de pequeno valor e todos');
verify(template.includes('name="asset_class"') && template.includes('value="fixed"') && template.includes('value="small"'), 'a tela oferece seleção de ativo imobilizado e bem de pequeno valor');
verify(reporting.includes("'name' => ['label' => 'Item', 'weight' => 3.2") && reporting.includes("'patrimony' => ['label' => 'Patrimônio', 'weight' => 0.95"), 'o campo Item recebe mais espaço que Patrimônio');
verify(template.includes('<thead><tr>') && template.includes('<tbody>') && template.includes('data-report-table') && !template.includes('report-record-card'), 'todas as saídas continuam em uma tabela/lista com cabeçalho e itens abaixo');
verify(template.includes('data-report-total') && template.includes('report_total_cents($filtered)'), 'o valor total soma todos os itens filtrados, ainda que Valor não seja uma coluna');
verify(reporting.includes('report_filter_assets(all_assets(), $filters)') && reporting.includes('report_xlsx_rows($assets, $fields, $filters)'), 'a planilha aplica os mesmos filtros e apenas os campos enviados');
verify(reporting.includes('report_cents_to_float(report_total_cents($assets))') && reporting.includes('Valor total de aquisição após filtros'), 'o Excel termina com o total numérico dos registros filtrados');
verify(index.includes("$kind === 'report-xlsx'"), 'o roteador oferece o download XLSX específico do relatório');
verify(template.includes('>Imprimir / Visualizar</button>') && !template.includes('>Exportar para PDF</button>') && !template.includes('>Exportar em Excel</a>') && !template.includes('data-report-export-excel'), 'Relatórios exibe somente Imprimir / Visualizar, sem opções de PDF ou Excel');
verify(!template.includes("download=backup") && controlPanel.includes('index.php?download=backup'), 'o backup é exportado pelo Painel de controle e não pela página Relatórios');

verify(template.indexOf('<h2>Resumo patrimonial</h2>') < template.indexOf('class="panel report-filter-panel"') && template.indexOf('class="panel report-filter-panel"') < template.indexOf('data-report-builder') && template.indexOf('data-report-builder') < template.indexOf('data-report-table') && template.indexOf('data-report-table') < template.indexOf('data-report-export-section'), 'Resumo patrimonial precede o construtor e o relatório detalhado');
verify(template.includes('class="report-summary-table"') && template.includes('<th scope="col">Conta Patrimonial</th>') && template.includes('<th scope="col">Registros</th>') && template.includes('<th scope="col">Valor Total (R$)</th>') && !template.includes('report-category-grid') && !template.includes('class="bar report-category-track"'), 'resumo usa tabela semântica com conta, registros e valor, sem gráfico de barras');
verify(css.includes('.report-summary-table { width: 100%;') && css.includes('border-collapse: collapse;') && css.includes('border-left: 0; border-right: 0;') && css.includes('border-bottom: 1px solid var(--line);'), 'tabela do resumo mostra linhas horizontais e remove bordas verticais');
verify(css.includes('body.report-print-mode .report-summary-table thead { display: table-header-group; }') && css.includes('break-inside: avoid;') && css.includes('overflow-x: auto;'), 'cabeçalho da tabela repete na impressão e a versão de tela admite rolagem horizontal');
verify(layout.includes('head_assets()') && layout.includes("asset_url('report.js')") && layout.includes("$_GET['page'] ?? '') === 'reports'"), 'cache dos arquivos atualizados é invalidado e o JS carrega somente em Relatórios');
verify(renderer.includes("!can('manage_catalogs') && !can('manage_users') && !can('export_data')") && layout.includes("can('manage_catalogs') || can('manage_users') || can('export_data')"), 'contas com somente export_data acessam o Painel sem liberar administração');
verify(controlPanel.includes("if (can('export_data'))") && controlPanel.includes('Backup completo'), 'o painel exibe o backup apenas às contas autorizadas');
verify(renderer.includes("header('Cache-Control: private, no-store, max-age=0')") && repository.includes('SELECT category, value, status, acquiredAt FROM assets') && !renderer.includes('all_invoices()'), 'Relatórios permanecem privados e não carregam dados NF-e para essa tela');

verify(reporting.includes("'category' => ['label' => 'Conta Patrimonial'") && template.includes('<span><?= h($label) ?></span>') && template.includes('<th scope="col"><?= h($fieldOptions[$key]) ?></th>') && !template.includes('>Categoria<'), 'o seletor de campos e o cabeçalho da tabela exibem Conta Patrimonial sem renomear a chave técnica');
verify(template.includes('class="panel report-print-summary"') && template.includes('data-page-layout="<?= h($resolvedOrientation) ?>"'), 'o resumo possui seletor de impressão e orientação A4 compatível com o detalhado');
verify(template.includes('Valor Total (R$)') && template.includes('Total de registros') && template.includes('class="report-summary-count"') && template.includes('class="report-summary-amount"'), 'totais ficam no topo e cada conta mostra quantidade e valor em colunas da tabela');
verify(!template.includes('report-summary-filter-context') && !summaryHeaderMarkup.includes('$statusLabels') && !summaryHeaderMarkup.includes('$dateFromLabel') && !css.includes('.report-summary-filter-context'), 'a linha descritiva dos filtros não aparece no resumo');
verify(css.includes('.report-summary-kpis { display: flex; width: 100%; flex-wrap: nowrap; justify-content: space-between;') && css.includes('.report-summary-kpi { display: flex; flex: 0 0 auto;') && css.includes('text-transform: none; white-space: nowrap;') && css.includes('@media (max-width: 520px)') && css.includes('.report-summary-kpis { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);') && css.includes('body.report-print-mode .report-summary-kpis { display: flex; width: 100%; flex-wrap: nowrap; justify-content: space-between;'), 'KPIs seguem lado a lado com rótulo/valor inline no desktop, dois grupos na mesma linha no celular e a mesma disposição na impressão');
verify(css.includes('.report-print-summary > .panel-head { border-bottom: 0 !important; }'), 'o cabeçalho do resumo não desenha divisor sob o título');
verify(css.includes('body.report-print-mode .content > *:not(.report-print-document):not(.report-print-summary)') && css.includes('order: 1') && css.includes('order: 2') && css.includes('break-before: page'), 'a impressão ordena o resumo na primeira página e começa o detalhado em uma nova página');
verify(template.includes('$summaryGroupCount = count($groups)') && template.includes('$summaryAvailableRowHeightMm / max(1, $summaryGroupCount)') && template.includes('report_filter_assets($assets, $filters)') && template.includes('--report-summary-row-font:') && template.includes('--report-summary-row-padding:'), 'a densidade da tabela considera somente os grupos vindos dos registros filtrados');
verify(css.includes('body.report-print-mode .report-print-document > .report-print-header { display: none !important; }') && css.includes('body.report-print-mode .report-summary-print-header { display: flex !important;'), 'o cabeçalho do detalhamento não se repete na página 2; o cabeçalho da página 1 permanece visível');
verify(css.includes('body.report-print-mode .report-summary-table {') && css.includes('font-size: var(--report-summary-row-font, 10pt)') && css.includes('padding: var(--report-summary-row-padding, 2mm)') && css.includes('body.report-print-mode .report-summary-print-header h2 {') && css.includes('font-size: 18pt !important;') && template.includes('style="--report-print-font:'), 'a primeira página amplia seu cabeçalho e ajusta as linhas da tabela sem mudar a tipografia do relatório detalhado na página 2');
verify(template.includes('class="report-print-header report-summary-print-header"') && template.includes('data-report-summary-print-header') && template.includes('Controle Patrimonial') && template.includes('Status: <?= h($statusLabels[$status]) ?>') && template.includes('Folha A4 <?= h($orientationLabels[$resolvedOrientation]) ?>'), 'a primeira página recebe o cabeçalho do relatório com filtros, período, orientação e data');
verify(!firstPageHeaderMarkup.includes('ativos no filtro') && template.includes('Total de registros'), 'a primeira página não duplica o total de registros no cabeçalho');
verify(css.includes('.report-summary-print-header { display: none; }') && css.includes('body.report-print-mode .report-summary-print-header { display: flex !important;') && css.includes('body.report-print-mode .report-print-summary > .panel-head { display: none !important;'), 'na impressão o cabeçalho do relatório substitui o título Resumo patrimonial');
verify(!template.includes('Este relatório contém') && !template.includes('report-wide-print-warning') && !css.includes('report-wide-print-warning'), 'o aviso que informa a quantidade de colunas foi removido do relatório');
console.log(`PASS: ${checks} verificações do relatório filtrado, exportação e impressão`);
