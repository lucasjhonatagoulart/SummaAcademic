<?php
$baseInvoice = is_array($invoice ?? null) ? $invoice : [];
$old = is_array($old ?? null) ? $old : [];
    $oldSource = (string)($old['source'] ?? $baseInvoice['source'] ?? 'manual');
$headerOverrides = $oldSource === 'xml' ? ['notes','source'] : ['accessKey','number','series','issuedAt','supplier','supplierCnpj','recipient','recipientCnpj','total','notes','source'];
foreach ($headerOverrides as $field) {
    if (array_key_exists($field, $old)) $baseInvoice[$field] = $old[$field];
}
if (array_key_exists('items', $old)) {
    if ($oldSource === 'xml' && is_array($baseInvoice['items'] ?? null)) {
        $oldItemsById = [];
        foreach ($old['items'] as $oldItem) if (is_array($oldItem) && isset($oldItem['id'])) $oldItemsById[(string)$oldItem['id']] = $oldItem;
        $xmlEditableFields = ['name','classification','category','location','patrimony','brand','color','type','condition','usefulLife','notes'];
        foreach ($baseInvoice['items'] as &$sourceItem) {
            $oldItem = $oldItemsById[(string)($sourceItem['id'] ?? '')] ?? null;
            if (!is_array($oldItem)) continue;
            foreach ($xmlEditableFields as $field) if (array_key_exists($field, $oldItem)) $sourceItem[$field] = $oldItem[$field];
        }
        unset($sourceItem);
    } else {
        $baseInvoice['items'] = $old['items'];
    }
}
$invoice = $baseInvoice ?: null;
$items = array_values(is_array($invoice['items'] ?? null) ? $invoice['items'] : []);
$source = in_array(($invoice['source'] ?? 'manual'), ['manual','xml'], true) ? (string)($invoice['source'] ?? 'manual') : 'manual';
$imported = $source === 'xml';
$readOnlyAttr = $imported ? 'readonly' : '';
$catalogs = get_catalogs(false);
$categoryOptions = $catalogs['category'];
$locationOptions = $catalogs['location'];
foreach ($items as $existingItem) {
    $existingCategory = trim((string)($existingItem['category'] ?? ''));
    if ($existingCategory !== '' && !in_array($existingCategory, $categoryOptions, true)) $categoryOptions[] = $existingCategory;
    $existingLocation = trim((string)($existingItem['location'] ?? ''));
    if ($existingLocation !== '' && !in_array($existingLocation, $locationOptions, true)) $locationOptions[] = $existingLocation;
}
$activeStep = max(1, min(3, (int)($old['current_step'] ?? ($_GET['step'] ?? 1))));
$invoiceId = isset($invoice['id']) ? (int)$invoice['id'] : null;
$formatValue = static fn(string $key, mixed $default = ''): mixed => $invoice[$key] ?? $default;
$sourceLabel = $imported ? 'XML importado' : 'Cadastro manual';
$totalItems = array_sum(array_map(static fn(array $item): float => (float)($item['total'] ?? 0), $items));
$includedCount = count(array_filter($items, static fn(array $item): bool => ($item['classification'] ?? 'fixed') !== 'skip'));
$skippedCount = count($items) - $includedCount;
?>
<section class="heading">
    <div>
        <p class="eyebrow"><?= $isEdit ? 'Revisão da nota' : 'Cada nota, no mesmo padrão' ?></p>
        <h1><?= $isEdit ? 'Revisar NF-e ' . h($formatValue('number', '')) : 'Nova nota fiscal' ?></h1>
        <p>Informe a origem fiscal, classifique os itens e confira os dados antes de incorporar ao patrimônio.</p>
    </div>
    <a class="button" href="<?= h(page_url('invoices')) ?>">Cancelar</a>
</section>

<div class="invoice-editor" data-invoice-editor data-active-step="<?= $activeStep ?>">
    <header class="invoice-editor__header">
        <div>
            <span class="eyebrow">NF-e e patrimônio</span>
            <h2><?= $isEdit ? 'Revisão do lançamento' : 'Lançamento de NF-e' ?></h2>
            <p>Os dados fiscais permanecem vinculados ao documento recebido. Os dados patrimoniais seguem o padrão cadastrado.</p>
        </div>
        <div class="invoice-editor__header-status">
            <span class="badge <?= $imported ? 'green' : '' ?>" data-source-label><?= h($sourceLabel) ?></span>
            <span class="invoice-editor__header-count"><span data-item-count><?= count($items) ?></span> item(ns)</span>
        </div>
    </header>

    <nav class="invoice-stepper" aria-label="Etapas da nota fiscal">
        <?php foreach ([1 => 'Dados da nota', 2 => 'Classificação dos itens', 3 => 'Revisão'] as $step => $label): ?>
            <button type="button" class="invoice-step <?= $activeStep === $step ? 'is-active' : '' ?> <?= $activeStep > $step ? 'is-complete' : '' ?>" data-step-target="<?= $step ?>" aria-current="<?= $activeStep === $step ? 'step' : 'false' ?>">
                <span class="invoice-step__number"><?= $activeStep > $step ? '✓' : $step ?></span>
                <span><?= h($label) ?></span>
            </button>
            <?php if ($step < 3): ?><span class="invoice-step__line" aria-hidden="true"></span><?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <form method="post" action="index.php" enctype="multipart/form-data" id="invoice-form" class="invoice-editor__form" data-invoice-form novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save-invoice">
        <input type="hidden" name="intent" value="draft">
        <input type="hidden" name="source" value="<?= h($source) ?>" data-invoice-source>
        <input type="hidden" name="current_step" value="<?= $activeStep ?>" data-current-step>
        <?php if ($invoiceId): ?><input type="hidden" name="id" value="<?= $invoiceId ?>"><?php endif; ?>

        <section class="invoice-step-panel" data-step-panel="1" <?= $activeStep !== 1 ? 'hidden' : '' ?>>
            <section class="panel invoice-source-panel">
                <div class="panel-head">
                    <div><span class="eyebrow">Origem dos dados</span><h2>Como esta nota será cadastrada?</h2><p>Escolha o preenchimento manual ou analise o XML para carregar os dados fiscais.</p></div>
                </div>
                <div class="panel-body invoice-source-grid">
                    <article class="invoice-source-option <?= !$imported ? 'is-selected' : '' ?>" data-source-option="manual">
                        <div class="invoice-source-option__title"><span aria-hidden="true">✎</span><div><strong>Cadastro manual</strong><small>Preencha os dados fiscais e os itens diretamente.</small></div></div>
                        <button type="button" class="button" data-set-invoice-source="manual" <?= !$imported ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>><?= !$imported ? 'Selecionado' : 'Usar cadastro manual' ?></button>
                    </article>
                    <article class="invoice-source-option <?= $imported ? 'is-selected' : '' ?>" data-source-option="xml">
                        <div class="invoice-source-option__title"><span aria-hidden="true">⇧</span><div><strong>Importar XML da NF-e</strong><small>O arquivo preenche a nota; campos fiscais ficam protegidos.</small></div></div>
                        <label class="invoice-dropzone" data-xml-dropzone>
                            <input type="file" name="xml_file" accept=".xml,text/xml,application/xml" data-xml-file aria-label="Selecionar arquivo XML da NF-e">
                            <span class="invoice-dropzone__icon" aria-hidden="true">⇧</span>
                            <strong data-xml-file-name>Clique para selecionar ou arraste o XML</strong>
                            <small>Somente XML · limite de 2 MB</small>
                        </label>
                        <button type="submit" class="button primary invoice-import-button" name="submit_action" value="import-xml" data-import-xml><?= $imported ? 'Substituir XML' : 'Analisar XML' ?></button>
                        <?php if ($imported): ?><p class="invoice-xml-preserved">✓ XML original preservado nesta revisão</p><?php endif; ?>
                        <p class="invoice-upload-error" data-xml-error hidden></p>
                    </article>
                </div>
            </section>

            <section class="panel invoice-form-section">
                <div class="panel-head">
                    <div><span class="eyebrow">Etapa 1 · Dados fiscais</span><h2>Identificação da nota</h2><p><?= $imported ? 'Os campos fiscais abaixo vêm do XML original.' : 'Informe os dados da NF-e. Uma chave digitada não consulta a SEFAZ.' ?></p></div>
                    <span class="badge <?= $imported ? 'green' : '' ?>" data-fiscal-status><?= $imported ? 'Campos do XML protegidos' : 'Cadastro manual' ?></span>
                </div>
                <?php if ($imported): ?><div class="panel-body invoice-readonly-note" data-readonly-note>Dados fiscais importados são somente leitura. A descrição original e os valores do XML também são preservados.</div><?php endif; ?>
                <div class="panel-body"><div class="form-grid invoice-header-grid">
                    <label class="field span-2"><span>Chave de acesso (44 dígitos)</span><input name="accessKey" value="<?= h($formatValue('accessKey', '')) ?>" <?= $readOnlyAttr ?> maxlength="44" data-invoice-field="accessKey" autocomplete="off"></label>
                    <label class="field"><span>Número</span><input name="number" value="<?= h($formatValue('number', '')) ?>" <?= $readOnlyAttr ?> maxlength="32" data-invoice-field="number"></label>
                    <label class="field"><span>Série</span><input name="series" value="<?= h($formatValue('series', '')) ?>" <?= $readOnlyAttr ?> maxlength="16" data-invoice-field="series"></label>
                    <label class="field"><span>Data de emissão</span><input type="date" name="issuedAt" value="<?= h(substr((string)$formatValue('issuedAt', ''), 0, 10)) ?>" <?= $readOnlyAttr ?> data-invoice-field="issuedAt"></label>
                    <label class="field span-2"><span>Fornecedor</span><input name="supplier" value="<?= h($formatValue('supplier', '')) ?>" <?= $readOnlyAttr ?> maxlength="320" data-invoice-field="supplier"></label>
                    <label class="field"><span>CNPJ do fornecedor</span><input name="supplierCnpj" value="<?= h($formatValue('supplierCnpj', '')) ?>" <?= $readOnlyAttr ?> maxlength="32" inputmode="numeric" data-invoice-field="supplierCnpj"></label>
                    <label class="field span-2"><span>Destinatário</span><input name="recipient" value="<?= h($formatValue('recipient', '')) ?>" <?= $readOnlyAttr ?> maxlength="320" data-invoice-field="recipient"></label>
                    <label class="field"><span>CNPJ do destinatário</span><input name="recipientCnpj" value="<?= h($formatValue('recipientCnpj', '')) ?>" <?= $readOnlyAttr ?> maxlength="32" inputmode="numeric" data-invoice-field="recipientCnpj"></label>
                    <label class="field"><span>Total da NF-e (R$)</span><input name="total" inputmode="decimal" value="<?= h($formatValue('total', '')) ?>" <?= $readOnlyAttr ?> data-invoice-field="total"></label>
                    <label class="field span-2"><span>Observações da NF-e</span><textarea name="notes" maxlength="10000" rows="3" data-invoice-field="notes"><?= h($formatValue('notes', '')) ?></textarea><small>Opcional. Use para registrar contexto sem alterar os dados fiscais.</small></label>
                </div></div>
            </section>
        </section>

        <section class="invoice-step-panel" data-step-panel="2" <?= $activeStep !== 2 ? 'hidden' : '' ?>>
            <section class="invoice-step-intro">
                <div><span class="eyebrow">Etapa 2 · Controle patrimonial</span><h2>Classifique cada item</h2><p>Preserve o produto original e informe apenas os dados que identificam o bem no seu acervo.</p></div>
                <div class="invoice-items-count"><strong data-item-count><?= count($items) ?></strong><span>itens na nota</span></div>
            </section>
            <section class="panel invoice-bulk-apply" data-bulk-apply <?= !$items ? 'hidden' : '' ?>>
                    <div><span class="eyebrow">Preenchimento rápido</span><strong>Aplicar a todos os itens incorporados</strong><small>Itens marcados como “Não incorporar” não serão alterados.</small></div>
                    <div class="invoice-bulk-controls">
                        <label class="field"><span>Conta Patrimonial</span><select data-bulk-category><option value="">Selecione</option><?php foreach ($categoryOptions as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
                        <label class="field"><span>Local</span><select data-bulk-location><option value="">Selecione</option><?php foreach ($locationOptions as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
                        <button class="button small" type="button" data-apply-category>Aplicar conta patrimonial</button>
                        <button class="button small" type="button" data-apply-location>Aplicar local</button>
                    </div>
            </section>
            <div id="items-list" class="invoice-items-list">
                <?php foreach ($items as $index => $item): ?>
                    <?php $itemClass = (string)($item['classification'] ?? 'fixed'); $itemSkipped = $itemClass === 'skip'; ?>
                    <article class="item-row invoice-item-card <?= $itemSkipped ? 'is-skipped' : '' ?>" data-item-card>
                        <div class="invoice-item-heading">
                            <div class="invoice-item-index"><?= str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT) ?></div>
                            <div class="invoice-item-heading__text"><span class="eyebrow">Item <?= (int)$index + 1 ?> · <?= $imported ? 'do XML' : 'manual' ?></span><strong data-item-title><?= h($item['name'] ?? 'Item sem nome padronizado') ?></strong></div>
                            <span class="badge <?= $itemSkipped ? 'amber' : '' ?>" data-classification-label><?= $itemSkipped ? 'Não incorporar' : ($itemClass === 'small' ? 'Pequeno valor' : 'Ativo fixo') ?></span>
                            <button class="button small danger invoice-remove-item" type="button" data-remove-item data-manual-only <?= $imported ? 'hidden' : '' ?>>Remover</button>
                        </div>
                        <div class="invoice-original-line"><span>Descrição original</span><strong><?= h($item['originalDescription'] ?? '') ?: '—' ?></strong><span class="hint">preservada</span></div>
                        <input type="hidden" name="items[<?= (int)$index ?>][id]" value="<?= h($item['id'] ?? ('item-' . ($index + 1))) ?>" data-item-field="id">
                        <input type="hidden" name="items[<?= (int)$index ?>][originalDescription]" value="<?= h($item['originalDescription'] ?? '') ?>" data-item-field="originalDescription">
                        <div class="panel-body form-grid invoice-item-grid">
                            <label class="field span-2"><span>Nome padronizado</span><input name="items[<?= (int)$index ?>][name]" value="<?= h($item['name'] ?? '') ?>" data-item-field="name" data-item-name></label>
                            <label class="field"><span>Classificação</span><select name="items[<?= (int)$index ?>][classification]" data-item-field="classification"><option value="fixed" <?= $itemClass === 'fixed' ? 'selected' : '' ?>>Ativo fixo</option><option value="small" <?= $itemClass === 'small' ? 'selected' : '' ?>>Bem de pequeno valor</option><option value="skip" <?= $itemClass === 'skip' ? 'selected' : '' ?>>Não incorporar</option></select></label>
                            <label class="field"><span>Quantidade</span><input type="number" name="items[<?= (int)$index ?>][quantity]" step="any" min="0" value="<?= h($item['quantity'] ?? '') ?>" <?= $readOnlyAttr ?> data-item-field="quantity"></label>
                            <label class="field"><span>Unidade</span><input name="items[<?= (int)$index ?>][unit]" value="<?= h($item['unit'] ?? '') ?>" maxlength="32" <?= $readOnlyAttr ?> data-item-field="unit"></label>
                            <label class="field"><span>Valor unitário (R$)</span><input type="number" name="items[<?= (int)$index ?>][unitValue]" step="0.01" min="0" value="<?= h($item['unitValue'] ?? '') ?>" <?= $readOnlyAttr ?> data-item-field="unitValue"></label>
                            <div class="field invoice-item-total"><span>Total do item</span><input type="hidden" name="items[<?= (int)$index ?>][total]" value="<?= h($item['total'] ?? '') ?>" data-item-field="total" data-item-total><strong data-item-total-display><?= h(money_br($item['total'] ?? 0)) ?></strong></div>
                            <label class="field"><span>NCM</span><input name="items[<?= (int)$index ?>][ncm]" value="<?= h($item['ncm'] ?? '') ?>" <?= $readOnlyAttr ?> data-item-field="ncm"></label>
                            <label class="field"><span>CFOP</span><input name="items[<?= (int)$index ?>][cfop]" value="<?= h($item['cfop'] ?? '') ?>" <?= $readOnlyAttr ?> data-item-field="cfop"></label>
                            <label class="field"><span>Conta Patrimonial</span><select name="items[<?= (int)$index ?>][category]" data-item-field="category"><option value="">Selecione</option><?php foreach ($categoryOptions as $value): ?><option value="<?= h($value) ?>" <?= ($item['category'] ?? '') === $value ? 'selected' : '' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
                            <label class="field"><span>Local <small data-xml-optional <?= $imported ? '' : 'hidden' ?>>opcional na importação XML</small></span><select name="items[<?= (int)$index ?>][location]" data-item-field="location"><option value="">Selecione</option><?php foreach ($locationOptions as $value): ?><option value="<?= h($value) ?>" <?= ($item['location'] ?? '') === $value ? 'selected' : '' ?>><?= h($value) ?></option><?php endforeach; ?></select></label>
                            <label class="field span-2"><span>Patrimônio por unidade <small data-xml-optional <?= $imported ? '' : 'hidden' ?>>opcional na importação XML</small></span><textarea name="items[<?= (int)$index ?>][patrimony]" rows="2" placeholder="Um por unidade; separe por /, vírgula ou linha" data-item-field="patrimony"><?= h($item['patrimony'] ?? '') ?></textarea></label>
                            <label class="field"><span>Vida útil (anos)</span><input type="number" min="0" name="items[<?= (int)$index ?>][usefulLife]" value="<?= h($item['usefulLife'] ?? 5) ?>" data-item-field="usefulLife"></label>
                            <label class="field"><span>Marca</span><input name="items[<?= (int)$index ?>][brand]" value="<?= h($item['brand'] ?? '') ?>" data-item-field="brand"></label>
                            <label class="field"><span>Cor</span><input name="items[<?= (int)$index ?>][color]" value="<?= h($item['color'] ?? '') ?>" data-item-field="color"></label>
                            <label class="field"><span>Tipo</span><input name="items[<?= (int)$index ?>][type]" value="<?= h($item['type'] ?? '') ?>" data-item-field="type"></label>
                            <label class="field"><span>Estado</span><input name="items[<?= (int)$index ?>][condition]" value="<?= h($item['condition'] ?? 'Novo') ?>" data-item-field="condition"></label>
                            <label class="field span-2"><span>Observações do item</span><textarea name="items[<?= (int)$index ?>][notes]" rows="2" data-item-field="notes"><?= h($item['notes'] ?? '') ?></textarea></label>
                        </div>
                        <p class="invoice-skip-note" data-skip-note <?= $itemSkipped ? '' : 'hidden' ?>>O item permanece na nota, mas não gera patrimônio.</p>
                        <?php if ($imported): ?><div class="invoice-item-source-note">NCM <?= h($item['ncm'] ?: 'não informado') ?> · CFOP <?= h($item['cfop'] ?: 'não informado') ?> · dados do XML protegidos</div><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
            <div class="invoice-empty-items <?= $items ? 'is-empty' : '' ?>" data-empty-items><strong>Nenhum item adicionado</strong><span>Importe um XML ou inclua um item manualmente para continuar.</span></div>
            <button class="button invoice-add-item" type="button" data-add-item data-manual-only <?= $imported ? 'hidden' : '' ?>>+ Adicionar item manual</button>
            <div class="notice" data-xml-optional-note <?= $imported ? '' : 'hidden' ?>>Rascunhos podem ficar incompletos. Na importação XML, Local e Patrimônio são opcionais; se informar patrimônio, use um código por unidade. A conta patrimonial continua necessária para registrar. O código e o Local podem ser completados depois na edição do ativo.</div>
            <div class="notice" data-manual-note <?= $imported ? 'hidden' : '' ?>>Rascunhos podem ficar incompletos. Use contas patrimoniais e locais cadastrados no Painel de controle. Para registrar manualmente, os dados patrimoniais serão validados no servidor; cada unidade exige um patrimônio.</div>
        </section>

        <section class="invoice-step-panel" data-step-panel="3" <?= $activeStep !== 3 ? 'hidden' : '' ?>>
            <section class="invoice-step-intro invoice-review-heading">
                <div><span class="eyebrow">Etapa 3 · Conferência</span><h2>Revise antes de incorporar</h2><p>Confira a origem fiscal, os totais e a classificação dos itens antes do registro.</p></div>
                <span class="badge amber">Validação final no servidor</span>
            </section>
            <section class="panel invoice-review-summary">
                <div class="invoice-review-summary__top"><div><span class="eyebrow" data-review-source><?= h($sourceLabel) ?></span><h3>NF-e <span data-review-field="number"><?= h($formatValue('number', '') ?: 'Não informado') ?></span> · série <span data-review-field="series"><?= h($formatValue('series', '') ?: 'Não informado') ?></span></h3><span data-review-field="supplier"><?= h($formatValue('supplier', '') ?: 'Fornecedor não informado') ?></span></div><div class="invoice-review-key"><span>Chave de acesso</span><strong data-review-field="accessKey"><?= h($formatValue('accessKey', '') ?: 'Não informada') ?></strong></div></div>
                <div class="invoice-review-meta"><div><span>Emissão</span><strong data-review-field="issuedAt"><?= h($formatValue('issuedAt', '') ?: 'Não informada') ?></strong></div><div><span>CNPJ fornecedor</span><strong data-review-field="supplierCnpj"><?= h($formatValue('supplierCnpj', '') ?: 'Não informado') ?></strong></div><div><span>Destinatário</span><strong data-review-field="recipient"><?= h($formatValue('recipient', '') ?: 'Não informado') ?></strong></div><div><span>CNPJ destinatário</span><strong data-review-field="recipientCnpj"><?= h($formatValue('recipientCnpj', '') ?: 'Não informado') ?></strong></div></div>
            </section>
            <section class="invoice-review-totals"><div class="panel invoice-review-total"><span>Total fiscal da nota</span><strong data-review-field="total"><?= h(money_br($formatValue('total', 0))) ?></strong><small>Valor informado no cabeçalho</small></div><div class="panel invoice-review-total"><span>Soma dos itens</span><strong data-review-items-total><?= h(money_br($totalItems)) ?></strong><small><span data-item-count><?= count($items) ?></span> item(ns) · frete/impostos podem diferir</small></div></section>
            <p class="invoice-review-note">Os totais são exibidos separadamente: frete, impostos ou descontos podem fazer a soma dos itens diferir do total fiscal.</p>
            <section class="panel invoice-review-items"><div class="panel-head"><div><span class="eyebrow">Patrimônio</span><h3>Itens classificados</h3></div><span class="hint"><strong data-review-included><?= $includedCount ?></strong> incorporar · <strong data-review-skipped><?= $skippedCount ?></strong> ignorar</span></div><div class="invoice-review-list" data-review-items>
                <?php foreach ($items as $item): ?>
                    <?php $class = (string)($item['classification'] ?? 'fixed'); $skip = $class === 'skip'; $patrimonyCount = count(split_patrimony((string)($item['patrimony'] ?? ''))); ?>
                    <div class="invoice-review-item"><div><strong><?= h($item['name'] ?: 'Item sem nome padronizado') ?></strong><span><?= h($item['originalDescription'] ?: 'Descrição original não informada') ?></span><span><?= h($item['quantity'] ?? '') ?> <?= h($item['unit'] ?? '') ?> · unitário <?= h(money_br($item['unitValue'] ?? 0)) ?> · NCM <?= h($item['ncm'] ?: '—') ?> · CFOP <?= h($item['cfop'] ?: '—') ?></span></div><div class="invoice-review-item__data"><span class="badge <?= $skip ? 'amber' : '' ?>"><?= $skip ? 'Não incorporar' : ($class === 'small' ? 'Pequeno valor' : 'Ativo fixo') ?></span><?php if (!$skip): ?><span><?= h($item['category'] ?: 'Conta patrimonial pendente') ?></span><span><?= h($item['location'] ?: 'Local pendente') ?></span><span><?= $patrimonyCount ?> patrimônio(s)</span><?php endif; ?></div><strong class="money"><?= h(money_br($item['total'] ?? 0)) ?></strong></div>
                <?php endforeach; ?>
            </div></section>
            <section class="panel invoice-review-notes" data-review-notes <?= $formatValue('notes', '') !== '' ? '' : 'hidden' ?>><span class="eyebrow">Observações da nota</span><p data-review-field="notes"><?= nl2br(h($formatValue('notes', ''))) ?></p></section>
            <div class="notice invoice-protocol-note">XML só pode ser incorporado quando contém protocolo com código de autorização 100 ou 150. O sistema não consulta a SEFAZ; a situação exibida vem do documento recebido.</div>
        </section>

        <footer class="invoice-editor__footer">
            <a class="button" href="<?= h(page_url('invoices')) ?>">Cancelar</a>
            <div class="invoice-footer-actions">
                <button class="button" type="submit" data-save-draft>Salvar rascunho</button>
                <button class="button" type="button" data-step-back <?= $activeStep <= 1 ? 'hidden' : '' ?>>Voltar</button>
                <button class="button primary" type="button" data-step-next <?= $activeStep >= 3 ? 'hidden' : '' ?>>Avançar</button>
                <?php if (can('edit_assets')): ?><button class="button primary" type="submit" name="submit_intent" value="register" data-register <?= $activeStep !== 3 ? 'hidden' : '' ?>>Registrar e incorporar</button><?php else: ?><span class="hint" data-register-hint <?= $activeStep !== 3 ? 'hidden' : '' ?>>Para incorporar ativos, solicite a permissão “Editar ativos”.</span><?php endif; ?>
            </div>
        </footer>
    </form>
</div>

<template id="item-template">
    <article class="item-row invoice-item-card" data-item-card>
        <div class="invoice-item-heading"><div class="invoice-item-index">__NUMBER__</div><div class="invoice-item-heading__text"><span class="eyebrow">Item __NUMBER__ · manual</span><strong data-item-title>Item sem nome padronizado</strong></div><span class="badge" data-classification-label>Ativo fixo</span><button class="button small danger invoice-remove-item" type="button" data-remove-item data-manual-only>Remover</button></div>
        <div class="invoice-original-line"><span>Descrição original</span><strong>—</strong><span class="hint">manual</span></div>
        <input type="hidden" name="items[__INDEX__][id]" value="item-__INDEX__" data-item-field="id">
        <div class="panel-body form-grid invoice-item-grid">
            <label class="field span-2"><span>Descrição original do XML</span><input name="items[__INDEX__][originalDescription]" data-item-field="originalDescription"></label>
            <label class="field span-2"><span>Nome padronizado</span><input name="items[__INDEX__][name]" data-item-field="name" data-item-name></label>
            <label class="field"><span>Classificação</span><select name="items[__INDEX__][classification]" data-item-field="classification"><option value="fixed">Ativo fixo</option><option value="small">Bem de pequeno valor</option><option value="skip">Não incorporar</option></select></label>
            <label class="field"><span>Quantidade</span><input type="number" name="items[__INDEX__][quantity]" step="any" min="0" value="1" data-item-field="quantity"></label>
            <label class="field"><span>Unidade</span><input name="items[__INDEX__][unit]" data-item-field="unit"></label>
            <label class="field"><span>Valor unitário (R$)</span><input type="number" name="items[__INDEX__][unitValue]" step="0.01" min="0" value="0" data-item-field="unitValue"></label>
            <div class="field invoice-item-total"><span>Total do item</span><input type="hidden" name="items[__INDEX__][total]" value="0" data-item-field="total" data-item-total><strong data-item-total-display>R$ 0,00</strong></div>
            <label class="field"><span>NCM</span><input name="items[__INDEX__][ncm]" data-item-field="ncm"></label><label class="field"><span>CFOP</span><input name="items[__INDEX__][cfop]" data-item-field="cfop"></label>
            <label class="field"><span>Conta Patrimonial</span><select name="items[__INDEX__][category]" data-item-field="category"><option value="">Selecione</option><?php foreach ($categoryOptions as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Local</span><select name="items[__INDEX__][location]" data-item-field="location"><option value="">Selecione</option><?php foreach ($locationOptions as $value): ?><option value="<?= h($value) ?>"><?= h($value) ?></option><?php endforeach; ?></select></label>
            <label class="field span-2"><span>Patrimônio por unidade</span><textarea name="items[__INDEX__][patrimony]" rows="2" placeholder="Um por unidade; separe por /, vírgula ou linha" data-item-field="patrimony"></textarea></label>
            <label class="field"><span>Vida útil (anos)</span><input type="number" min="0" name="items[__INDEX__][usefulLife]" value="5" data-item-field="usefulLife"></label>
            <label class="field"><span>Marca</span><input name="items[__INDEX__][brand]" data-item-field="brand"></label><label class="field"><span>Cor</span><input name="items[__INDEX__][color]" data-item-field="color"></label><label class="field"><span>Tipo</span><input name="items[__INDEX__][type]" data-item-field="type"></label><label class="field"><span>Estado</span><input name="items[__INDEX__][condition]" value="Novo" data-item-field="condition"></label>
            <label class="field span-2"><span>Observações do item</span><textarea name="items[__INDEX__][notes]" rows="2" data-item-field="notes"></textarea></label>
        </div>
        <p class="invoice-skip-note" data-skip-note hidden>O item permanece na nota, mas não gera patrimônio.</p>
    </article>
</template>
