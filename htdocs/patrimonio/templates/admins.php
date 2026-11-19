<?php
$admins = is_array($admins ?? null) ? $admins : [];
$permissionLabels = admin_permission_definitions();
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);
?>
<p class="control-module-description">Escolha as capacidades de cada conta. Todas as pessoas autenticadas podem visualizar; sem permissões adicionais, a conta fica somente para leitura.</p>

<div class="grid two-col control-module-grid">
    <section class="panel control-panel-card">
        <div class="panel-head"><div><h3>Criar usuário</h3><p>O acesso começa somente para visualização.</p></div></div>
        <div class="panel-body">
            <form method="post" action="index.php" class="form-grid" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create-admin">
                <label class="field span-2"><span>Usuário</span><input type="text" name="username" required maxlength="80" autocomplete="username"></label>
                <label class="field"><span>Senha</span><input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password"><small>Use pelo menos 8 caracteres; recomendamos 12 ou mais.</small></label>
                <label class="field"><span>Confirmar senha</span><input type="password" name="password_confirm" required minlength="8" maxlength="72" autocomplete="new-password"></label>
                <fieldset class="permission-fieldset span-2">
                    <legend>Permissões adicionais</legend>
                    <p class="hint">Se nenhuma for marcada, o novo usuário poderá somente consultar as telas. Você só pode conceder capacidades que também possui.</p>
                    <div class="permission-grid">
                        <?php foreach ($permissionLabels as $key => $label): ?>
                            <?php if (can_grant_permission($key)): ?>
                                <label class="permission-option"><input type="checkbox" name="permissions[]" value="<?= h($key) ?>"><span><?= h($label) ?></span></label>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <div class="span-2 actions"><button class="button primary" type="submit">Criar usuário</button></div>
            </form>
        </div>
    </section>
    <section class="panel control-panel-card">
        <div class="panel-head"><div><h3>O que cada permissão libera</h3><p>As regras são conferidas no servidor em cada operação.</p></div></div>
        <div class="panel-body permission-help">
            <p><strong>Editar/incorporar NF-e:</strong> criar notas, importar XML e revisar/incorporar; incorporar itens patrimoniais também exige permissão para editar ativos.</p>
            <p><strong>Editar ativos:</strong> modificar dados e registrar baixas.</p>
            <p><strong>Gerenciar contas patrimoniais e locais:</strong> adicionar/remover padrões pelo Painel de controle. As telas de NF-e e ativos só aceitam padrões já cadastrados.</p>
            <p><strong>Gerenciar usuários:</strong> criar contas e definir capacidades, sem conceder a outra conta algo que o próprio gestor não tenha.</p>
            <p><strong>Exportar dados:</strong> baixar planilhas, CSV, XML e backup integral.</p>
            <p><strong>Restaurar backup:</strong> importar apenas em banco vazio; também exige editar NF-e, editar ativos e gerenciar padrões, pois a operação grava essas áreas.</p>
            <p class="notice">Não é possível alterar as permissões da própria conta por esta tela. O sistema também protege a última conta que pode administrar usuários.</p>
        </div>
    </section>
</div>

<section class="panel control-panel-card">
    <div class="panel-head"><div><h3>Contas cadastradas</h3><p><?= number_format(count($admins), 0, ',', '.') ?> usuário(s)</p></div></div>
    <?php if (!$admins): ?>
        <div class="empty"><strong>Nenhuma conta encontrada.</strong>As contas cadastradas aparecerão aqui.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Usuário</th><th>Acesso atual</th><th>Datas</th><th>Gerenciar</th></tr></thead>
                <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <?php $adminPermissions = is_array($admin['permissions'] ?? null) ? $admin['permissions'] : []; ?>
                        <tr>
                            <td><strong><?= h($admin['username'] ?? '') ?></strong><?= (int)$admin['id'] === $currentAdminId ? '<div class="hint">Sua conta</div>' : '' ?></td>
                            <td>
                                <?php if (!$adminPermissions): ?><span class="badge">Somente visualização</span><?php endif; ?>
                                <?php foreach ($adminPermissions as $permission): if (isset($permissionLabels[$permission])): ?><span class="permission-chip"><?= h($permissionLabels[$permission]) ?></span><?php endif; endforeach; ?>
                            </td>
                            <td><span class="hint">Criada: <?= h(date_br($admin['created_at'] ?? null)) ?></span><br><span class="hint">Último acesso: <?= h(date_br($admin['last_login'] ?? null)) ?></span></td>
                            <td>
                                <?php if ((int)$admin['id'] === $currentAdminId): ?>
                                    <span class="hint">Conta protegida contra autoalteração.</span>
                                <?php else: ?>
                                    <details class="permission-editor">
                                        <summary>Alterar permissões</summary>
                                        <form method="post" action="index.php" class="permission-grid compact">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update-admin-permissions">
                                            <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                            <?php foreach ($permissionLabels as $key => $label): ?>
                                                <?php $checked = in_array($key, $adminPermissions, true); ?>
                                                <label class="permission-option"><input type="checkbox" name="permissions[]" value="<?= h($key) ?>" <?= $checked ? 'checked' : '' ?> <?= can_grant_permission($key) ? '' : 'disabled' ?>><span><?= h($label) ?><?= can_grant_permission($key) ? '' : ' (sem permissão para alterar)' ?></span></label>
                                            <?php endforeach; ?>
                                            <button class="button small primary" type="submit">Salvar permissões</button>
                                        </form>
                                    </details>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<p class="footer-note">Esta versão permite ajustar permissões e criar contas; não remove nem redefine senhas de usuários.</p>
