<section class="heading">
    <div><p class="eyebrow">Cadastro administrativo · Sem NF-e</p><h1>Cadastrar ativo sem NF-e</h1><p>Registre o ativo sem criar uma nota fictícia. Uma unidade e um código patrimonial serão vinculados ao cadastro.</p></div>
    <a class="button" href="<?= h(page_url('assets')) ?>">Cancelar</a>
</section>
<section class="panel">
    <div class="panel-head"><div><h2>Dados patrimoniais</h2><p>A nota fiscal permanecerá sem vínculo; relatórios legíveis exibirão “Sem NF-e”.</p></div></div>
    <form method="post" action="index.php">
        <?= csrf_field() ?><input type="hidden" name="action" value="create-asset-no-invoice">
        <div class="panel-body form-grid">
            <?php if (empty($catalogs['category']) || empty($catalogs['location'])): ?><p class="notice span-2">Antes de cadastrar, inclua uma Conta Patrimonial e um Local no Painel de controle.<?php if (can('manage_catalogs')): ?> <a href="<?= h(page_url('control-panel', ['section' => 'catalogs'])) ?>">Abrir Contas Patrimoniais/Locais</a>.<?php endif; ?></p><?php endif; ?>
            <label class="field span-2"><span>Descrição do item</span><input name="name" maxlength="5000" required></label>
            <label class="field"><span>Conta Patrimonial</span><select name="category" required><option value="" selected disabled>Selecione</option><?php foreach ($catalogs['category'] ?? [] as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Local</span><select name="location" required><option value="" selected disabled>Selecione</option><?php foreach ($catalogs['location'] ?? [] as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Patrimônio</span><input name="patrimony" maxlength="255" required></label>
            <label class="field"><span>Classificação</span><select name="classification" required><option value="" selected disabled>Selecione a classificação</option><option value="fixed">Ativo imobilizado</option><option value="small">Bem de pequeno valor</option></select></label>
            <label class="field"><span>Data de aquisição (opcional)</span><input type="date" name="acquiredAt"></label>
            <label class="field"><span>Valor de aquisição (opcional)</span><input type="number" name="value" min="0" step="0.01" inputmode="decimal"></label>
            <label class="field"><span>Marca (opcional)</span><input name="brand" maxlength="255"></label>
            <label class="field"><span>Tipo (opcional)</span><input name="type" maxlength="255"></label>
            <label class="field"><span>Cor (opcional)</span><input name="color" maxlength="255"></label>
            <label class="field"><span>Estado (opcional)</span><input name="condition" maxlength="64" placeholder="Ex.: Em uso"></label>
            <label class="field"><span>Vida útil em anos (opcional)</span><input type="number" name="usefulLife" min="0" max="1000" step="1"></label>
            <label class="field span-2"><span>Observações (opcional)</span><textarea name="notes" maxlength="10000" rows="3"></textarea></label>
            <p class="notice span-2">Este registro não cria linha em NF-e nem inventa número fiscal. Patrimônio precisa ser único; quantidade será registrada como uma unidade.</p>
            <div class="span-2 actions"><button class="button primary" type="submit">Cadastrar ativo</button><a class="button" href="<?= h(page_url('assets')) ?>">Cancelar</a></div>
        </div>
    </form>
</section>
