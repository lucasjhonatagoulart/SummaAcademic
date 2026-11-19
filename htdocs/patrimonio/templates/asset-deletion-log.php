<section class="heading">
    <div><p class="eyebrow">Auditoria administrativa</p><h1>Histórico de exclusões</h1><p>Motivos e responsáveis ficam disponíveis apenas nesta tela administrativa; eles não fazem parte dos relatórios patrimoniais.</p></div>
    <a class="button" href="<?= h(page_url('assets')) ?>">Voltar aos ativos</a>
</section>
<section class="panel">
    <div class="panel-head"><div><h2><?= number_format((int)$data['total'], 0, ',', '.') ?> registro(s)</h2><p>A NF-e, seus itens fiscais e o XML original não são excluídos junto com o ativo.</p></div></div>
    <?php if (!$data['rows']): ?>
        <div class="empty"><strong>Nenhuma exclusão registrada.</strong>Quando um administrador excluir um ativo, o motivo e a autoria aparecerão aqui.</div>
    <?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Data</th><th>Ativo</th><th>Conta Patrimonial / local</th><th>Patrimônio</th><th>NF-e</th><th>Motivo</th><th>Excluído por</th></tr></thead><tbody>
            <?php foreach ($data['rows'] as $row): $timestamp = strtotime((string)$row['deletedAt']); ?>
                <tr>
                    <td><?= $timestamp === false ? h($row['deletedAt']) : h(date('d/m/Y H:i', $timestamp)) ?></td>
                    <td><strong><?= h($row['assetName']) ?></strong><div class="hint">Registro #<?= (int)$row['assetId'] ?></div></td>
                    <td><?= h($row['category'] ?: '—') ?><div class="hint"><?= h($row['location'] ?: '—') ?></div></td>
                    <td><?= nl2br(h($row['patrimony'] ?: '—')) ?></td>
                    <td><?= h($row['invoiceNumber'] ?: 'Sem NF-e') ?></td>
                    <td><?= nl2br(h($row['reason'])) ?></td>
                    <td><?= h($row['deletedByUsername']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody></table></div>
        <?php if ($data['pages'] > 1): ?><div class="pagination"><span>Página <?= (int)$data['page'] ?> de <?= (int)$data['pages'] ?></span><span class="actions"><?php if ($data['page'] > 1): ?><a href="<?= h(page_url('asset-deletions', ['p' => $data['page'] - 1])) ?>">Anterior</a><?php endif; ?><?php if ($data['page'] < $data['pages']): ?><a href="<?= h(page_url('asset-deletions', ['p' => $data['page'] + 1])) ?>">Próxima</a><?php endif; ?></span></div><?php endif; ?>
    <?php endif; ?>
</section>
