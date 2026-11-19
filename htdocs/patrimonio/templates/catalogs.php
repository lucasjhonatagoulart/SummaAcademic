<p class="control-module-description">Cadastre e edite os padrões usados ao revisar NF-es e editar ativos.</p>
<?php $catalogEditIndex = 0; ?>
<div class="grid two-col control-module-grid">
    <?php foreach (['category'=>'Contas Patrimoniais','location'=>'Locais'] as $kind=>$label): ?>
        <?php $singular = $kind === 'category' ? 'conta patrimonial' : 'local'; ?>
        <section class="panel control-panel-card">
            <div class="panel-head"><div><h3><?= h($label) ?></h3><p><?= number_format(count($catalogs[$kind]),0,',','.') ?> opção(ões)</p></div></div>
            <div class="panel-body">
                <form method="post" action="index.php" class="actions-row catalog-add-form">
                    <?= csrf_field() ?><input type="hidden" name="action" value="catalog-add"><input type="hidden" name="kind" value="<?= h($kind) ?>">
                    <label class="field"><span>Nova <?= h($singular) ?></span><input name="value" maxlength="255" required></label>
                    <button class="button primary" type="submit">Adicionar</button>
                </form>
                <?php if ($kind === 'category'): ?><p class="hint">Variações de acentos, caixa e pontuação são conferidas. Contas patrimoniais aprovadas com grafia alternativa são padronizadas ao nome canônico.</p><?php endif; ?>
            </div>
            <?php if (!$catalogs[$kind]): ?><div class="empty"><strong>Nenhuma opção cadastrada.</strong>Use o formulário acima para adicionar a primeira <?= h($singular) ?>.</div>
            <?php else: ?><div class="table-wrap catalog-list-scroll" role="region" aria-label="<?= h('Lista de ' . strtolower($label)) ?>" tabindex="0"><table class="table"><thead><tr><th>Nome</th><th>Ações</th></tr></thead><tbody>
                <?php foreach ($catalogs[$kind] as $value): $editFormId = 'catalog-edit-' . $kind . '-' . (++$catalogEditIndex); ?><tr data-catalog-edit-row>
                    <td>
                        <div class="catalog-edit-display" data-catalog-edit-display>
                            <span class="catalog-edit-name"><?= h($value) ?></span>
                            <button class="button small" type="button" data-catalog-edit-open aria-expanded="false" aria-controls="<?= h($editFormId) ?>" aria-label="<?= h('Editar ' . $singular . ': ' . $value) ?>">Editar</button>
                        </div>
                        <form id="<?= h($editFormId) ?>" method="post" action="index.php" class="catalog-edit-form" data-catalog-edit-form data-original-value="<?= h($value) ?>" hidden>
                            <?= csrf_field() ?><input type="hidden" name="action" value="catalog-edit"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="old_value" value="<?= h($value) ?>">
                            <input class="catalog-edit-input" name="new_value" value="<?= h($value) ?>" maxlength="255" required aria-label="<?= h('Novo nome para ' . $singular . ' ' . $value) ?>">
                            <button class="button small primary" type="submit">Salvar</button>
                            <button class="button small" type="button" data-catalog-edit-cancel>Cancelar</button>
                        </form>
                    </td>
                    <td><form method="post" action="index.php" onsubmit="return confirm('Remover este padrão? Os registros existentes não serão alterados.');"><?= csrf_field() ?><input type="hidden" name="action" value="catalog-delete"><input type="hidden" name="kind" value="<?= h($kind) ?>"><input type="hidden" name="value" value="<?= h($value) ?>"><button class="button small danger" type="submit">Remover</button></form></td>
                </tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
<p class="footer-note">Remover um padrão em uso continua bloqueado. Ao salvar um novo nome, os ativos e itens de NF-e vinculados são atualizados juntos; a origem bruta do XML permanece preservada.</p>
