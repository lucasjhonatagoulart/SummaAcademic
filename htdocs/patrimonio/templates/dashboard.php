<?php
$a = $stats['assets'];
$i = $stats['invoices'];
$active = (int)($a['active'] ?? 0);
$disposed = (int)($a['disposed'] ?? 0);
$drafts = (int)($i['drafts'] ?? 0);
$assetValue = (float)($a['value'] ?? 0);
$chartColors = ['var(--donut-1)', 'var(--donut-2)', 'var(--donut-3)', 'var(--donut-4)', 'var(--donut-5)'];
$categoryColorMap = [
    'Móveis e Utensílios' => 'var(--donut-3)',
    'Sem conta patrimonial' => 'var(--donut-5)',
    'Outras contas patrimoniais' => 'var(--brand)',
];
$categoryColorFor = static function (array $row, int $index) use ($categoryColorMap, $chartColors): string {
    $category = trim((string)($row['category'] ?? ''));
    $label = $category !== '' ? $category : 'Sem conta patrimonial';
    return $categoryColorMap[$label] ?? $chartColors[$index % count($chartColors)];
};
$categoryCounts = array_values($stats['categoryCounts'] ?? []);
$displayCategories = $categoryCounts;
if (count($categoryCounts) > 5) {
    $displayCategories = array_slice($categoryCounts, 0, 4);
    $otherCount = array_sum(array_map(static fn(array $row): int => (int)$row['count'], array_slice($categoryCounts, 4)));
    $displayCategories[] = ['category' => 'Outras contas patrimoniais', 'count' => $otherCount];
}
$chartTotal = array_sum(array_map(static fn(array $row): int => (int)$row['count'], $categoryCounts));
$gradientStops = [];
$cursor = 0.0;
$segmentCount = count($displayCategories);
$gapPercent = $segmentCount > 1 ? 0.35 : 0.0;
$coloredPercent = max(0.0, 100.0 - ($gapPercent * $segmentCount));
if ($chartTotal > 0) {
    foreach ($displayCategories as $index => $row) {
        $count = (int)$row['count'];
        $start = $cursor;
        $end = min(100.0, $start + ($count / $chartTotal * $coloredPercent));
        $color = $categoryColorFor($row, $index);
        $gradientStops[] = $color . ' ' . number_format($start, 4, '.', '') . '% ' . number_format($end, 4, '.', '') . '%';
        if ($gapPercent > 0) {
            $gapEnd = min(100.0, $end + $gapPercent);
            $gradientStops[] = 'var(--donut-gap) ' . number_format($end, 4, '.', '') . '% ' . number_format($gapEnd, 4, '.', '') . '%';
            $cursor = $gapEnd;
        } else {
            $cursor = $end;
        }
    }
}
$donutBackground = $gradientStops ? 'conic-gradient(' . implode(', ', $gradientStops) . ')' : 'var(--line)';
$donutLabel = $chartTotal > 0
    ? number_format($chartTotal, 0, ',', '.') . ' ativos distribuídos em ' . count($categoryCounts) . ' contas patrimoniais'
    : 'Ainda não há ativos para mostrar no gráfico';
?>
<section class="heading">
    <div><p class="eyebrow">Resumo patrimonial</p><h1>Visão geral</h1><p>Notas fiscais, ativos e baixas em um só lugar, no mesmo padrão.</p></div>
    <?php if (can('edit_invoices')): ?><div class="actions"><a class="button" href="<?= h(page_url('new-invoice')) ?>">+ Cadastrar NF-e</a><a class="button primary" href="<?= h(page_url('new-invoice')) ?>#xml">Importar XML</a></div><?php endif; ?>
</section>
<div class="grid stats">
    <article class="stat"><div class="stat-label">Ativos em controle</div><div class="stat-value"><?= number_format($active,0,',','.') ?></div><div class="stat-foot">Fixo e pequeno valor</div></article>
    <article class="stat"><div class="stat-label">Valor histórico ativo</div><div class="stat-value"><?= h(money_br($assetValue)) ?></div><div class="stat-foot">Sem cálculo de depreciação</div></article>
    <article class="stat"><div class="stat-label">Notas incorporadas</div><div class="stat-value"><?= number_format((int)($i['registered'] ?? 0),0,',','.') ?></div><div class="stat-foot">NF-es registradas</div></article>
    <article class="stat"><div class="stat-label">Rascunhos pendentes</div><div class="stat-value"><?= number_format($drafts,0,',','.') ?></div><div class="stat-foot">Revisar antes de incorporar</div></article>
</div>
<div class="grid columns dashboard-columns">
    <section class="panel">
        <div class="panel-head"><div><h2>Ativos recentes</h2><p><?= number_format($active,0,',','.') ?> ativos · <?= number_format($disposed,0,',','.') ?> baixas</p></div><a class="button small" href="<?= h(page_url('assets')) ?>">Ver ativos</a></div>
        <?php if (!$stats['recent']): ?><div class="empty"><strong>Seu controle ainda está vazio.</strong>Cadastre uma NF-e ou importe o backup da versão anterior.</div><?php else: ?>
            <div class="table-wrap"><table class="table"><thead><tr><th>Ativo</th><th>Conta Patrimonial</th><th>Patrimônio</th><th>Valor</th><th>Situação</th></tr></thead><tbody>
            <?php foreach ($stats['recent'] as $row): ?><tr><td><a href="<?= h(page_url('asset',['id'=>(int)$row['id']])) ?>"><strong><?= h($row['name']) ?></strong></a></td><td><?= h($row['category'] ?: '—') ?></td><td><?= h($row['patrimony'] ?: '—') ?></td><td class="money"><?= h(money_br($row['value'])) ?></td><td><span class="badge <?= $row['status']==='disposed'?'red':'green' ?>"><?= h(status_label($row['status'])) ?></span></td></tr><?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </section>
    <div class="dashboard-charts">
        <section class="panel">
            <div class="panel-head"><div><h2>Ativos por conta patrimonial</h2><p>Distribuição dos registros ativos</p></div></div>
            <div class="panel-body donut-panel-body">
                <div class="donut-chart" role="img" aria-label="<?= h($donutLabel) ?>" style="background:<?= h($donutBackground) ?>">
                    <div class="donut-hole"><strong><?= number_format($chartTotal,0,',','.') ?></strong><span>ativos</span></div>
                </div>
                <?php if ($displayCategories): ?><ul class="donut-legend">
                    <?php foreach ($displayCategories as $index => $row): $count = (int)$row['count']; $share = $chartTotal > 0 ? ($count / $chartTotal * 100) : 0; $color = $categoryColorFor($row, $index); ?>
                        <li class="donut-legend-row"><span class="donut-swatch" style="background:<?= h($color) ?>" aria-hidden="true"></span><span class="donut-name" title="<?= h($row['category'] ?: 'Sem conta patrimonial') ?>"><?= h($row['category'] ?: 'Sem conta patrimonial') ?></span><span class="donut-value"><?= number_format($count,0,',','.') ?> · <?= number_format($share,1,',','.') ?>%</span></li>
                    <?php endforeach; ?>
                </ul><?php else: ?><p class="muted donut-empty">A distribuição aparece após os primeiros registros.</p><?php endif; ?>
                <p class="footer-note chart-note">Distribuição por quantidade de registros, não por unidades.</p>
            </div>
        </section>
        <section class="panel">
            <div class="panel-head"><div><h2>Valor por conta patrimonial</h2><p>Valores históricos em ativos</p></div></div>
            <div class="panel-body"><?php $max = max(array_map(static fn($r)=>(float)$r['value'], $stats['categories'] ?: [['value'=>0]])); if (!$stats['categories']): ?><p class="muted">Os totais aparecerão depois dos primeiros registros.</p><?php else: foreach ($stats['categories'] as $row): $pct = $max > 0 ? max(2,(float)$row['value']/$max*100) : 0; ?><div class="chart-row"><span title="<?= h($row['category'] ?: 'Sem conta patrimonial') ?>"><?= h($row['category'] ?: 'Sem conta patrimonial') ?></span><div class="bar"><span style="width:<?= h(number_format($pct,1,'.','')) ?>%"></span></div><strong><?= h(money_br($row['value'])) ?></strong></div><?php endforeach; endif; ?><p class="footer-note">Rascunhos de NF-e permanecem fora do patrimônio até serem revisados e incorporados.</p></div>
        </section>
    </div>
</div>
<div class="panel"><div class="panel-head"><div><h2>Avisos importantes</h2><p>Este sistema organiza dados patrimoniais, não emite nota fiscal.</p></div></div><div class="panel-body"><p class="notice">O status de autorização mostrado em um XML é o que veio no próprio arquivo. O sistema não consulta a SEFAZ. Os valores são históricos de aquisição e não representam depreciação ou valor contábil líquido.</p></div></div>
