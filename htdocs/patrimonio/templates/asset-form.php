<?php
$currentCategory = trim((string)($asset['category'] ?? ''));
$currentClassification = (string)($asset['classification'] ?? 'fixed');
$currentAcquiredAt = trim((string)($asset['acquiredAt'] ?? ''));
$isDirectNoInvoice = (string)($asset['sourceSheet'] ?? '') === 'Cadastro direto sem NF-e'
    && trim((string)($asset['invoiceNumber'] ?? '')) === ''
    && empty($asset['invoiceId']);
$acquiredAtUsesDateInput = $currentAcquiredAt === '' || nfe_valid_date($currentAcquiredAt);
$categoryOptions = array_values(array_unique(array_filter(array_map(
    static fn($value): string => trim((string)$value),
    $catalogs['category'] ?? []
), static fn(string $value): bool => $value !== '')));
if ($currentCategory !== '' && !in_array($currentCategory, $categoryOptions, true)) {
    array_unshift($categoryOptions, $currentCategory);
}
?>
<section class="heading">
    <div><p class="eyebrow">Dados patrimoniais</p><h1><?= $isDirectNoInvoice ? 'Editar ativo sem NF-e' : 'Editar ativo' ?></h1><p><?= $isDirectNoInvoice ? 'Altere todos os dados deste cadastro, incluindo descrição, classificação, Conta Patrimonial, Local, patrimônio, data, quantidade e valor. Nenhuma NF-e será criada.' : 'Disponível também para ativos importados da planilha. A NF-e e a descrição original permanecem preservadas; as alterações valem para o registro patrimonial.' ?></p></div>
    <a class="button" href="<?= h(page_url('asset',['id'=>(int)$asset['id']])) ?>">Cancelar</a>
</section>
<section class="panel">
    <div class="panel-head"><div><h2><?= h($asset['name']) ?></h2><p>Classe: <?= $asset['classification']==='small'?'Bem de pequeno valor':'Ativo Fixo' ?> · Status: <?= h(status_label($asset['status'])) ?><?= $isDirectNoInvoice ? ' · Sem NF-e' : '' ?></p></div></div>
    <form method="post" action="index.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="save-asset"><input type="hidden" name="id" value="<?= (int)$asset['id'] ?>">
        <div class="panel-body form-grid">
            <?php if ($isDirectNoInvoice): ?><p class="notice span-2">Edite abaixo os dados operacionais informados na inclusão. A descrição será atualizada no registro, e a NF-e continuará sem vínculo.</p><?php endif; ?>
            <label class="field span-2"><span><?= $isDirectNoInvoice ? 'Descrição do item' : 'Nome padronizado' ?></span><input name="name" value="<?= h($asset['name']) ?>" required></label>
            <label class="field"><span>Classificação</span><select name="classification" required><option value="fixed" <?= $currentClassification === 'fixed' ? 'selected' : '' ?>>Ativo Fixo</option><option value="small" <?= $currentClassification === 'small' ? 'selected' : '' ?>>Bem de pequeno valor</option></select></label>
            <?php $canAddCategory = can('manage_catalogs'); ?>
            <label class="field"><span>Conta Patrimonial</span>
                <select name="category" required aria-describedby="category-help" data-category-select><option value="" disabled <?= $currentCategory === '' ? 'selected' : '' ?>>Selecione uma conta patrimonial</option><?php foreach ($categoryOptions as $value): ?><option value="<?= h($value) ?>" <?= $value === $currentCategory ? 'selected' : '' ?>><?= h($value) ?></option><?php endforeach; ?><?php if ($canAddCategory): ?><option value="__new__">+ Nova conta patrimonial…</option><?php endif; ?></select>
                <small id="category-help" class="hint"><?= $canAddCategory ? 'Clique para ver todas as contas cadastradas. Para criar outra, escolha “+ Nova conta patrimonial…”; ela será incluída ao salvar.' : 'Clique para ver as contas patrimoniais cadastradas. Para incluir outra, solicite acesso ao Painel de controle.' ?></small>
            </label>
            <?php if ($canAddCategory): ?>
            <label class="field" data-category-new hidden><span>Nome da nova conta patrimonial</span><input name="category_new" maxlength="255" autocomplete="off" disabled></label>
            <?php endif; ?>
            <label class="field"><span>Local (opcional)</span><input name="location" value="<?= h($asset['location']) ?>" list="locations-list"><small class="hint">Pode ficar em branco ou ser removido durante a edição.</small></label>
            <label class="field"><span>Número(s) de patrimônio</span><input name="patrimony" maxlength="5000" value="<?= h($asset['patrimony']) ?>"></label>
            <label class="field"><span>Data de aquisição</span><input type="<?= $acquiredAtUsesDateInput ? 'date' : 'text' ?>" name="acquiredAt" maxlength="40" value="<?= h($currentAcquiredAt) ?>" <?= $acquiredAtUsesDateInput ? '' : 'placeholder="AAAA-MM-DD"' ?>><small class="hint">Datas históricas fora do formato ISO podem ser mantidas como estão.</small></label>
            <label class="field"><span>Quantidade</span><input type="number" min="0" max="999999999.999" step="0.001" name="quantity" value="<?= h($asset['quantity']) ?>"></label>
            <label class="field"><span>Valor de aquisição (R$)</span><input type="number" min="0" max="9999999999999.99" step="0.01" name="value" value="<?= h($asset['value']) ?>"></label>
            <label class="field"><span>Ano da compra</span><input type="number" min="0" max="9999" step="1" name="purchaseYear" value="<?= h($asset['purchaseYear']) ?>"></label>
            <label class="field"><span>Vida útil (anos)</span><input type="number" min="0" max="2147483647" step="1" name="usefulLife" value="<?= h($asset['usefulLife']) ?>"></label>
            <label class="field"><span>Marca</span><input name="brand" value="<?= h($asset['brand']) ?>"></label>
            <label class="field"><span>Cor</span><input name="color" value="<?= h($asset['color']) ?>"></label>
            <label class="field"><span>Tipo</span><input name="type" value="<?= h($asset['type']) ?>"></label>
            <label class="field"><span>Estado</span><input name="condition" value="<?= h($asset['condition']) ?>"></label>
            <label class="field span-2"><span>Observações</span><textarea name="notes"><?= h($asset['notes']) ?></textarea></label>
            <div class="span-2 actions"><button class="button primary" type="submit">Salvar alterações</button><a class="button" href="<?= h(page_url('asset',['id'=>(int)$asset['id']])) ?>">Voltar</a></div>
        </div>
    </form>
</section>
<datalist id="locations-list"><?php foreach ($catalogs['location'] as $value): ?><option value="<?= h($value) ?>"><?php endforeach; ?></datalist>
