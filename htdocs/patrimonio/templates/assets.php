<?php $canDelete = can('manage_users') && can('edit_assets'); ?>
<section class="heading">
    <div><p class="eyebrow">Registro patrimonial</p><h1>Ativos</h1><p>Pesquise e mantenha Ativo Fixo, bens de pequeno valor e baixas sem perder o histórico.</p></div>
    <div class="actions"><?php if (can('manage_users') && can('edit_assets')): ?><a class="button primary" href="<?= h(page_url('new-asset')) ?>">Cadastrar ativo sem NF-e</a><a class="button" href="<?= h(page_url('asset-deletions')) ?>">Histórico de exclusões</a><?php endif; ?><?php if (can('export_data')): ?><a class="button" href="<?= h(page_url('download',['kind'=>'assets'])) ?>">Exportar Excel</a><?php endif; ?><a class="button" href="<?= h(page_url('reports')) ?>">Relatórios</a></div>
</section>
<form class="filters" method="get" action="index.php">
    <input type="hidden" name="page" value="assets">
    <label class="field search-field"><span>Busca</span><input type="search" name="q" value="<?= h($search) ?>" placeholder="Item, patrimônio, nota, conta patrimonial ou local"></label>
    <label class="field"><span>Classe</span><select name="class"><option value="all">Todas</option><option value="fixed" <?= $classification==='fixed'?'selected':'' ?>>Ativo Fixo</option><option value="small" <?= $classification==='small'?'selected':'' ?>>Pequeno valor</option></select></label>
    <label class="field"><span>Situação</span><select name="status"><option value="all" <?= $status==='all'?'selected':'' ?>>Todos</option><option value="active" <?= $status==='active'?'selected':'' ?>>Ativos</option><option value="disposed" <?= $status==='disposed'?'selected':'' ?>>Baixas</option></select></label>
    <label class="field"><span>Conta Patrimonial</span><select name="category"><option value="">Todas</option><?php foreach ($catalogs['category'] as $value): ?><option value="<?= h($value) ?>" <?= $category===$value?'selected':'' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Local</span><select name="location"><option value="">Todos</option><?php foreach ($catalogs['location'] as $value): ?><option value="<?= h($value) ?>" <?= $location===$value?'selected':'' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
    <button class="button" type="submit">Filtrar</button>
</form>
<section class="panel">
    <div class="panel-head"><div><h2><?= number_format($data['total'],0,',','.') ?> registro(s)</h2><p>A edição não altera os dados de origem ou a NF-e vinculada.</p></div></div>
    <?php if (!$data['rows']): ?><div class="empty"><strong>Nenhum registro encontrado.</strong>Limpe alguns filtros ou importe o backup anterior.</div><?php else: ?>
        <div class="table-wrap"><table class="table"><thead><tr><th>Ativo</th><th>Classe</th><th>Conta Patrimonial / local</th><th>Patrimônio</th><th>NF-e</th><th>Valor histórico</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($data['rows'] as $row): ?><tr>
                <td><a href="<?= h(page_url('asset',['id'=>(int)$row['id']])) ?>"><strong><?= h($row['name']) ?></strong></a><div class="hint"><?= h($row['originalDescription']) ?></div></td>
                <td><?= $row['classification']==='small'?'Pequeno valor':'Ativo Fixo' ?></td><td><?= h($row['category'] ?: '—') ?><div class="hint"><?= h($row['location']) ?></div></td>
                <td><?= h($row['patrimony'] ?: '—') ?></td><td><?= h(asset_invoice_display_label($row)) ?></td><td class="money"><?= h(money_br($row['value'])) ?></td>
                <td><span class="badge <?= $row['status']==='disposed'?'red':'green' ?>"><?= h(status_label($row['status'])) ?></span></td>
                <?php $editLabel = trim((string)($row['invoiceNumber'] ?? '')) === '' && empty($row['invoiceId']) ? 'Editar sem NF-e' : 'Editar'; ?>
                <td><div class="actions">
                    <a class="button small icon-only" href="<?= h(page_url('asset',['id'=>(int)$row['id']])) ?>" title="Abrir" aria-label="Abrir <?= h($row['name']) ?>"><?= icon('eye') ?></a>
                    <?php if (can('edit_assets')): ?><a class="button small icon-only" href="<?= h(page_url('asset-edit',['id'=>(int)$row['id']])) ?>" title="<?= $editLabel ?>" aria-label="<?= $editLabel ?> <?= h($row['name']) ?>"><?= icon('pencil') ?></a><?php endif; ?>
                    <?php if ($canDelete): ?><a class="button small icon-only danger" href="<?= h(page_url('asset',['id'=>(int)$row['id']])) ?>#excluir" data-delete-asset="<?= (int)$row['id'] ?>" data-asset-name="<?= h($row['name']) ?>" title="Excluir" aria-label="Excluir <?= h($row['name']) ?>"><?= icon('trash-2') ?></a><?php endif; ?>
                </div></td>
            </tr><?php endforeach; ?>
        </tbody></table></div>
        <div class="pagination"><span>Página <?= $data['page'] ?> de <?= $data['pages'] ?></span><span class="actions"><?php if ($data['page']>1): ?><a href="<?= h(page_url('assets',['q'=>$search,'status'=>$status,'class'=>$classification,'category'=>$category,'location'=>$location,'p'=>$data['page']-1])) ?>">Anterior</a><?php endif; ?><?php if ($data['page']<$data['pages']): ?><a href="<?= h(page_url('assets',['q'=>$search,'status'=>$status,'class'=>$classification,'category'=>$category,'location'=>$location,'p'=>$data['page']+1])) ?>">Próxima</a><?php endif; ?></span></div>
    <?php endif; ?>
</section>
<?php if ($canDelete): ?>
<dialog class="dialog" data-delete-dialog aria-labelledby="delete-asset-title">
    <form method="post" action="index.php" class="dialog-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete-asset"><input type="hidden" name="id" value="" data-delete-id>
        <h2 id="delete-asset-title">Excluir ativo</h2>
        <p><strong data-delete-name></strong></p>
        <p class="hint">A exclusão é permanente. O registro sai das listas e relatórios; a NF-e, seus itens e o XML original são preservados, e o motivo fica no histórico de exclusões.</p>
        <label class="field"><span>Motivo da exclusão</span><textarea name="reason" required maxlength="2000" rows="4"></textarea></label>
        <div class="actions dialog-actions"><button class="button" type="button" data-delete-cancel>Cancelar</button><button class="button danger" type="submit">Excluir ativo</button></div>
    </form>
</dialog>
<?php endif; ?>
