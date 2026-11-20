<section class="heading">
    <div><p class="eyebrow">Do documento ao patrimônio</p><h1>Notas fiscais</h1><p>Importe ou cadastre notas, revise os itens e incorpore bens em um padrão único.</p></div>
</section>
<?php if (!empty($purchaseDrafts)): ?><div class="notice purchase-notice" role="status"><strong><?= (int)$purchaseDrafts === 1 ? '1 nota veio de Compras do Summa' : (int)$purchaseDrafts . ' notas vieram de Compras do Summa' ?></strong> e aguarda<?= (int)$purchaseDrafts === 1 ? '' : 'm' ?> classificação: defina conta patrimonial, local e patrimônio dos itens e incorpore. </div><?php endif; ?>
<?php if (can('edit_invoices') || can('export_data')): ?><div class="actions invoice-page-actions" aria-label="Ações de notas fiscais">
    <?php if (can('edit_invoices')): ?><a class="button primary" href="<?= h(page_url('new-invoice')) ?>">Cadastrar / Importar NF-e</a><?php endif; ?>
    <?php if (can('export_data')): ?><a class="button" href="<?= h(page_url('download', ['kind' => 'invoices'])) ?>">Exportar NF-es (.xlsx)</a><?php endif; ?>
</div><?php endif; ?>
<section class="panel invoice-list-panel">
    <nav class="invoice-tabs" role="tablist" aria-label="Situação das notas fiscais">
        <?php foreach ([
            'all' => ['Todas as notas', $data['counts']['all'] ?? 0],
            'draft' => ['Rascunhos', $data['counts']['draft'] ?? 0],
            'registered' => ['Incorporadas', $data['counts']['registered'] ?? 0],
        ] as $tabKey => [$tabLabel, $tabCount]): ?>
            <a class="invoice-tab <?= $status === $tabKey ? 'is-active' : '' ?>" role="tab" aria-selected="<?= $status === $tabKey ? 'true' : 'false' ?>" href="<?= h(page_url('invoices', ['status' => $tabKey, 'q' => $search])) ?>"><span><?= h($tabLabel) ?></span><span class="invoice-tab__count"><?= number_format((int)$tabCount, 0, ',', '.') ?></span></a>
        <?php endforeach; ?>
    </nav>
    <form class="filters invoice-search" method="get" action="index.php">
        <input type="hidden" name="page" value="invoices"><input type="hidden" name="status" value="<?= h($status) ?>">
        <label class="field search-field"><span>Buscar por número, fornecedor, CNPJ ou chave</span><input type="search" name="q" value="<?= h($search) ?>" placeholder="Digite para pesquisar"></label>
        <button class="button" type="submit">Buscar</button>
        <?php if ($search !== '' || $status !== 'all'): ?><a class="button" href="<?= h(page_url('invoices')) ?>">Limpar</a><?php endif; ?>
    </form>
    <div class="invoice-list-summary"><strong><?= number_format((int)$data['total'], 0, ',', '.') ?> nota(s)</strong><span>Notas manuais e XML originais permanecem preservados.</span></div>
    <?php if (!$data['rows']): ?>
        <div class="empty invoice-list-empty"><strong><?= $search !== '' || $status !== 'all' ? 'Nenhuma nota encontrada.' : 'Uma nota. Um cadastro organizado.' ?></strong><span><?= $search !== '' || $status !== 'all' ? 'Tente outro termo ou consulte outra situação.' : 'Importe um XML para preencher os dados fiscais automaticamente, ou comece um cadastro manual.' ?></span></div>
    <?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>NF-e</th><th>Fornecedor</th><th>Emissão</th><th>Itens</th><th>Total</th><th>Status</th><th>Ações</th></tr></thead><tbody>
            <?php foreach ($data['rows'] as $row): ?><tr>
                <td><a href="<?= h(page_url('invoice', ['id' => (int)$row['id']])) ?>"><strong>NF-e <?= h($row['number'] ?: 'sem número') ?></strong></a><div class="hint">Série <?= h($row['series'] ?: '—') ?> · <?= $row['source'] === 'xml' ? 'Importada por XML' : 'Cadastro manual' ?></div></td>
                <td><?= h($row['supplier'] ?: 'Fornecedor pendente') ?><div class="hint"><?= h($row['supplierCnpj'] ?: '') ?></div></td>
                <td><?= h(date_br($row['issuedAt'])) ?></td><td><?= number_format((int)$row['item_count'], 0, ',', '.') ?></td>
                <td class="money"><?= h(money_br($row['total'])) ?></td><td><span class="badge <?= $row['status'] === 'registered' ? 'green' : 'amber' ?>"><?= h(status_label($row['status'])) ?></span></td>
                <td><?php if ($row['status'] === 'draft' && can('edit_invoices')): ?><a class="button small" href="<?= h(page_url('invoice-edit', ['id' => (int)$row['id']])) ?>">Revisar</a><?php else: ?><a class="button small" href="<?= h(page_url('invoice', ['id' => (int)$row['id']])) ?>">Abrir</a><?php endif; ?></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="pagination"><span><?= number_format((int)$data['total'], 0, ',', '.') ?> nota(s) encontrada(s) · página <?= (int)$data['page'] ?> de <?= (int)$data['pages'] ?></span><span class="actions"><?php if ($data['page'] > 1): ?><a href="<?= h(page_url('invoices', ['q' => $search, 'status' => $status, 'p' => $data['page'] - 1])) ?>">Anterior</a><?php endif; ?><?php if ($data['page'] < $data['pages']): ?><a href="<?= h(page_url('invoices', ['q' => $search, 'status' => $status, 'p' => $data['page'] + 1])) ?>">Próxima</a><?php endif; ?></span></div>
    <?php endif; ?>
</section>
<div class="notice invoice-history-note">As notas cadastradas aqui são os novos lançamentos. Números da planilha histórica continuam nos respectivos ativos e não são tratados como XMLs importados.</div>
