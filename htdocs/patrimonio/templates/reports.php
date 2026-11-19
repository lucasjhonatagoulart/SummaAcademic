<?php
$filters = report_filters_from_input($_GET);
$from = (string)$filters['from'];
$to = (string)$filters['to'];
$status = (string)$filters['status'];
$assetClass = (string)$filters['asset_class'];
$orientationPreference = (string)$filters['orientation'];
$filterError = report_filter_error($filters);
$reportRequested = (string)($_GET['report'] ?? '') === '1';
$definitions = report_asset_field_definitions();
$fieldOptions = array_map(static fn(array $definition): string => (string)$definition['label'], $definitions);
$rawFields = $_GET['fields'] ?? [];
$selectedFieldKeys = report_selected_field_keys($reportRequested ? $rawFields : [], !$reportRequested);
$selectedFieldCount = count($selectedFieldKeys);
$resolvedOrientation = $orientationPreference === 'auto'
    ? ($selectedFieldCount <= 5 ? 'portrait' : 'landscape')
    : $orientationPreference;
$printFontPt = max(5.0, min(9.0, 9.0 - max(0, $selectedFieldCount - 6) * 0.22));
$totalWidthWeight = array_sum(array_map(static fn(string $key): float => (float)$definitions[$key]['weight'], $selectedFieldKeys));
$canExport = can('export_data');
$filtered = report_filter_assets($assets, $filters);
$totalCents = report_total_cents($filtered);
$statusLabels = ['active' => 'Ativos', 'disposed' => 'Baixas', 'all' => 'Todos'];
$classLabels = ['fixed' => 'Ativo imobilizado', 'small' => 'Bem de pequeno valor', 'all' => 'Todos'];
$orientationLabels = ['portrait' => 'Retrato', 'landscape' => 'Paisagem'];
$dateFromLabel = $from !== '' ? report_format_date($from) : 'Sem limite inicial';
$dateToLabel = $to !== '' ? report_format_date($to) : 'Sem limite final';
$generatedAtLabel = date('d/m/Y H:i');
$groups = [];
foreach ($filtered as $asset) {
    $name = (string)(($asset['category'] ?? '') ?: 'Sem conta patrimonial');
    if (!isset($groups[$name])) $groups[$name] = ['count' => 0, 'cents' => 0];
    $groups[$name]['count']++;
    $groups[$name]['cents'] += report_value_to_cents($asset['value'] ?? null);
}
uasort($groups, static fn(array $a, array $b): int => $b['cents'] <=> $a['cents']);
$summaryGroupCount = count($groups);
$summaryAvailableRowHeightMm = $resolvedOrientation === 'portrait' ? 210.0 : 110.0;
$summaryMaxRowHeightMm = $resolvedOrientation === 'portrait' ? 28.0 : 15.0;
$summaryRowHeightMm = min($summaryMaxRowHeightMm, $summaryAvailableRowHeightMm / max(1, $summaryGroupCount));
$summaryRowFontPt = max(5.0, min($resolvedOrientation === 'portrait' ? 15.5 : 13.0, $summaryRowHeightMm * 0.72));
$summaryRowPaddingMm = max(0.15, min($resolvedOrientation === 'portrait' ? 10.5 : 6.5, ($summaryRowHeightMm - $summaryRowFontPt * 0.42) / 2));
?>
<section class="heading">
    <div>
        <p class="eyebrow">Consolidação patrimonial</p>
        <h1>Relatórios</h1>
        <p>Consulte o Resumo patrimonial e, abaixo, monte o relatório detalhado.</p>
    </div>
</section>

<section class="panel report-print-summary" data-report-summary data-page-layout="<?= h($resolvedOrientation) ?>" data-summary-groups="<?= $summaryGroupCount ?>" style="--report-summary-row-font:<?= h(number_format($summaryRowFontPt, 2, '.', '')) ?>pt; --report-summary-row-padding:<?= h(number_format($summaryRowPaddingMm, 2, '.', '')) ?>mm;">
    <header class="report-print-header report-summary-print-header" data-report-summary-print-header>
        <div>
            <p class="eyebrow">Controle Patrimonial</p>
            <h2>Relatório patrimonial</h2>
            <p>Status: <?= h($statusLabels[$status]) ?> · Classificação: <?= h($classLabels[$assetClass]) ?></p>
            <p>Aquisição: <?= h($dateFromLabel) ?> a <?= h($dateToLabel) ?></p>
            <p>Gerado em <?= h($generatedAtLabel) ?> · Folha A4 <?= h($orientationLabels[$resolvedOrientation]) ?> · <?= $selectedFieldCount ?> campos</p>
        </div>
    </header>
    <div class="panel-head"><div><h2>Resumo patrimonial</h2><p>Resumo por Conta Patrimonial com os filtros atuais de status, classificação e período aplicados ao relatório. Os valores são históricos de aquisição; não representam depreciação.</p></div></div>
    <?php if ($filterError !== ''): ?>
        <p class="alert error" role="alert"><?= h($filterError) ?></p>
    <?php else: ?>
        <div class="panel-body">
            <div class="report-summary-header">
                <div class="report-summary-kpis">
                    <div class="report-summary-kpi"><span class="report-summary-kpi-label">Total de registros</span><strong class="stat-value report-summary-kpi-value"><?= number_format(count($filtered), 0, ',', '.') ?></strong></div>
                    <div class="report-summary-kpi"><span class="report-summary-kpi-label">Valor Total (R$)</span><strong class="stat-value report-summary-kpi-value"><?= h(report_format_cents($totalCents)) ?></strong></div>
                </div>
            </div>
            <?php if (!$groups): ?>
                <div class="empty"><strong>Nenhum registro neste filtro.</strong>Registros sem data não entram quando há limite de período.</div>
            <?php else: ?>
                <div class="report-summary-table-wrap">
                    <table class="report-summary-table" data-report-summary-table aria-label="Resumo patrimonial por Conta Patrimonial">
                        <thead><tr><th scope="col">Conta Patrimonial</th><th scope="col">Registros</th><th scope="col">Valor Total (R$)</th></tr></thead>
                        <tbody>
                            <?php foreach ($groups as $name => $group): ?>
                                <tr><th scope="row"><?= h($name) ?></th><td class="report-summary-count"><?= number_format((int)$group['count'], 0, ',', '.') ?></td><td class="report-summary-amount"><?= h(report_format_cents($group['cents'])) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<section class="panel report-filter-panel" aria-labelledby="report-title">
    <div class="panel-head">
        <div>
            <h2 id="report-title">Relatório patrimonial</h2>
            <p>Escolha classe e filtros; marque as colunas que deseja e gere uma prévia em tabela/lista.</p>
        </div>
    </div>
    <?php if (!$canExport): ?>
        <div class="panel-body"><p class="notice">A prévia detalhada e suas exportações exigem a permissão <code>export_data</code>. Sua conta pode consultar o resumo por conta patrimonial abaixo.</p></div>
    <?php else: ?>
        <form class="report-builder-form" method="get" action="index.php" data-report-builder>
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report" value="1">
            <div class="report-filter-grid">
                <label class="field"><span>De</span><input type="date" name="from" value="<?= h($from) ?>"></label>
                <label class="field"><span>Até</span><input type="date" name="to" value="<?= h($to) ?>"></label>
                <label class="field"><span>Status</span><select name="status"><option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ativos</option><option value="disposed" <?= $status === 'disposed' ? 'selected' : '' ?>>Baixas</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Todos</option></select></label>
                <label class="field"><span>Classificação do ativo</span><select name="asset_class"><option value="all" <?= $assetClass === 'all' ? 'selected' : '' ?>>Todos</option><option value="fixed" <?= $assetClass === 'fixed' ? 'selected' : '' ?>>Ativo imobilizado</option><option value="small" <?= $assetClass === 'small' ? 'selected' : '' ?>>Bem de pequeno valor</option></select></label>
            </div>
            <?php if ($filterError !== ''): ?><p class="alert error" role="alert"><?= h($filterError) ?></p><?php endif; ?>
            <div class="report-builder-toolbar">
                <div class="report-field-selection-tools" data-no-print>
                    <button class="button small" type="button" data-report-select-all>Selecionar todos</button>
                    <button class="button small" type="button" data-report-clear>Limpar seleção</button>
                    <span><strong data-report-selected-count><?= $selectedFieldCount ?></strong> campos selecionados</span>
                </div>
                <label class="field report-orientation-field">
                    <span>Orientação da folha</span>
                    <select name="orientation" data-report-orientation>
                        <option value="auto" <?= $orientationPreference === 'auto' ? 'selected' : '' ?>>Automática</option>
                        <option value="portrait" <?= $orientationPreference === 'portrait' ? 'selected' : '' ?>>Retrato</option>
                        <option value="landscape" <?= $orientationPreference === 'landscape' ? 'selected' : '' ?>>Paisagem</option>
                    </select>
                </label>
                <p class="hint report-orientation-hint" data-report-orientation-hint>Automática: retrato até 5 campos; paisagem acima.</p>
            </div>
            <fieldset class="report-field-grid">
                <legend>Campos que aparecerão no cabeçalho da tabela</legend>
                <?php foreach ($fieldOptions as $key => $label): ?>
                    <label class="report-field-choice" for="report-field-<?= h($key) ?>">
                        <input id="report-field-<?= h($key) ?>" type="checkbox" name="fields[]" value="<?= h($key) ?>" data-report-field <?= in_array($key, $selectedFieldKeys, true) ? 'checked' : '' ?>>
                        <span><?= h($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>
            <p class="hint report-wide-columns" data-report-wide-hint hidden>Todos os campos selecionados permanecem na tabela. Com mais de 8 colunas, a impressão compacta a fonte; para leitura mais confortável, recomendamos escolher até 8 campos.</p>
            <?php if ($reportRequested && !$selectedFieldKeys): ?><p class="alert error" role="alert">Selecione pelo menos um campo para gerar o relatório.</p><?php endif; ?>
            <div class="report-builder-footer">
                <p class="hint">O valor total soma todos os ativos filtrados, mesmo se “Valor de aquisição” não estiver marcado. A opção de impressão fica no final do relatório.</p>
                <button class="button primary" type="submit">Gerar prévia</button>
            </div>
        </form>
    <?php endif; ?>
</section>

<?php if ($reportRequested && $canExport && $selectedFieldKeys && $filterError === ''): ?>
    <section class="panel report-print-document" data-report-print-document data-page-layout="<?= h($resolvedOrientation) ?>" style="--report-print-font:<?= h(number_format($printFontPt, 2, '.', '')) ?>pt">
        <header class="report-print-header">
            <div>
                <p class="eyebrow">Controle Patrimonial</p>
                <h2>Relatório patrimonial</h2>
                <p>Status: <?= h($statusLabels[$status]) ?> · Classificação: <?= h($classLabels[$assetClass]) ?></p>
                <p>Aquisição: <?= h($dateFromLabel) ?> a <?= h($dateToLabel) ?></p>
                <p>Gerado em <?= h($generatedAtLabel) ?> · Folha A4 <?= h($orientationLabels[$resolvedOrientation]) ?> · <?= $selectedFieldCount ?> campos</p>
            </div>
            <div class="report-print-record-count"><strong><?= number_format(count($filtered), 0, ',', '.') ?></strong><span>ativos no filtro</span></div>
        </header>
        <div class="report-table-wrap" data-report-scroll>
            <table class="table report-data-table" data-report-table aria-label="Relatório patrimonial selecionado">
                <colgroup>
                    <?php foreach ($selectedFieldKeys as $key): ?>
                        <?php $weight = (float)$definitions[$key]['weight']; $percent = $totalWidthWeight > 0 ? 100 * $weight / $totalWidthWeight : 100 / max(1, $selectedFieldCount); ?>
                        <col style="width:<?= h(number_format($percent, 4, '.', '')) ?>%">
                    <?php endforeach; ?>
                </colgroup>
                <thead><tr><?php foreach ($selectedFieldKeys as $key): ?><th scope="col"><?= h($fieldOptions[$key]) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php if (!$filtered): ?>
                        <tr><td colspan="<?= $selectedFieldCount ?>">Nenhum ativo corresponde aos filtros selecionados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($filtered as $asset): ?>
                            <tr><?php foreach ($selectedFieldKeys as $key): ?><td class="<?= in_array($key, ['originalDescription', 'disposalReason', 'notes'], true) ? 'report-cell-long' : '' ?>"><?= h(report_field_display_value($asset, $key)) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="report-preview-total" data-report-total>
                <span>Valor total de aquisição dos ativos filtrados</span>
                <strong><?= h(report_format_cents($totalCents)) ?></strong>
            </div>
        </div>
        <p class="report-print-footnote">Os valores são históricos de aquisição; o sistema não calcula depreciação. Campos sem dado permanecem vazios.</p>
        <div class="report-export-actions" data-report-export-section>
            <button class="button" type="button" data-report-print>Imprimir / Visualizar</button>
        </div>
    </section>
<?php endif; ?>

<?php if (can_restore_backup()): ?>
    <section class="panel">
        <div class="panel-head"><div><h2>Importação de backup</h2><p>A restauração exige banco vazio e permissões completas de escrita.</p></div><a class="button" href="<?= h(page_url('restore')) ?>">Importar backup anterior</a></div>
    </section>
<?php endif; ?>

<p class="footer-note">Os campos vazios permanecem vazios; o sistema não inventa dados fiscais ou patrimoniais.</p>
