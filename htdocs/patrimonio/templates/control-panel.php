<?php
$controlCatalogs = is_array($catalogs ?? null) ? $catalogs : [];
$controlAdmins = is_array($admins ?? null) ? $admins : [];
$controlSection = is_string($controlSection ?? null) ? $controlSection : '';
?>
<section class="heading">
    <div>
        <p class="eyebrow">Configuração do sistema</p>
        <h1>Painel de controle</h1>
        <p>Selecione uma área para ver somente as opções relacionadas a ela.</p>
    </div>
</section>

<?php if ($controlSection === ''): ?>
    <nav class="control-panel-access-grid" aria-label="Seções do Painel de controle">
        <?php if (can('export_data')): ?>
            <a class="control-access-card" href="<?= h(page_url('control-panel', ['section' => 'backup'])) ?>">
                <span class="control-access-card-top">
                    <span class="control-access-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M12 3v11m0 0 4-4m-4 4-4-4M5 16v4h14v-4"/></svg></span>
                    <span><span class="control-access-kicker">Proteção dos dados</span><strong class="control-access-title">Backup completo</strong></span>
                </span>
                <span class="control-access-description">Baixe uma cópia dos dados patrimoniais e fiscais para guardar em local restrito.</span>
                <span class="control-access-meta"><strong>ZIP</strong><span>Ativos, NF-e, XML e padrões cadastrados</span></span>
                <span class="control-access-cta">Acessar seção <span aria-hidden="true">→</span></span>
            </a>
        <?php endif; ?>

        <?php if (can('manage_catalogs')): ?>
            <?php $categoryCount = count($controlCatalogs['category'] ?? []); $locationCount = count($controlCatalogs['location'] ?? []); ?>
            <a class="control-access-card" href="<?= h(page_url('control-panel', ['section' => 'catalogs'])) ?>">
                <span class="control-access-card-top">
                    <span class="control-access-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 5.5 12 2l8 3.5v13L12 22l-8-3.5v-13ZM4 5.5l8 3.7 8-3.7M12 9.2V22"/></svg></span>
                    <span><span class="control-access-kicker">Padrões de cadastro</span><strong class="control-access-title">Contas Patrimoniais e locais</strong></span>
                </span>
                <span class="control-access-description">Mantenha as opções usadas na classificação de NF-e e no cadastro de ativos.</span>
                <span class="control-access-meta"><strong><?= number_format($categoryCount, 0, ',', '.') ?> · <?= number_format($locationCount, 0, ',', '.') ?></strong><span>contas patrimoniais · locais</span></span>
                <span class="control-access-cta">Acessar seção <span aria-hidden="true">→</span></span>
            </a>
        <?php endif; ?>

        <?php if (can('manage_users')): ?>
            <a class="control-access-card" href="<?= h(page_url('control-panel', ['section' => 'users'])) ?>">
                <span class="control-access-card-top">
                    <span class="control-access-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                    <span><span class="control-access-kicker">Acessos do sistema</span><strong class="control-access-title">Usuários e permissões</strong></span>
                </span>
                <span class="control-access-description"><?= summa_manages_users() ? 'Veja quem já entrou no módulo. Contas e permissões são definidas no Summa.' : 'Crie contas e ajuste as capacidades de cada usuário do sistema.' ?></span>
                <span class="control-access-meta"><strong><?= number_format(count($controlAdmins), 0, ',', '.') ?></strong><span>usuário(s) cadastrado(s)</span></span>
                <span class="control-access-cta">Acessar seção <span aria-hidden="true">→</span></span>
            </a>
        <?php endif; ?>
    </nav>
<?php elseif ($controlSection === 'backup' && can('export_data')): ?>
    <section class="control-section-heading">
        <div><p class="eyebrow">Painel de controle · Proteção dos dados</p><h2>Backup completo</h2><p>Baixe e guarde uma cópia dos dados em local restrito.</p></div>
        <a class="button" href="<?= h(page_url('control-panel')) ?>">← Voltar</a>
    </section>
    <section class="panel control-panel-card">
        <div class="panel-body">
            <div class="control-backup-actions"><p class="control-module-description">Inclui os dados patrimoniais, fiscais e os padrões cadastrados.</p><div class="actions"><a class="button primary" href="index.php?download=backup">Exportar backup (.zip)</a><?php if (can_restore_backup()): ?><a class="button" href="<?= h(page_url('restore')) ?>">Importar backup</a><?php endif; ?></div></div>
            <p class="notice">O arquivo inclui dados sensíveis e não é criptografado. Não o compartilhe por link público; guarde também uma cópia do banco MySQL no painel da hospedagem.</p>
        </div>
    </section>
<?php elseif ($controlSection === 'catalogs' && can('manage_catalogs')): ?>
    <section class="control-section-heading">
        <div><p class="eyebrow">Painel de controle · Padrões de cadastro</p><h2>Contas Patrimoniais e locais</h2><p>Opções usadas na classificação de NF-e e no cadastro de ativos.</p></div>
        <a class="button" href="<?= h(page_url('control-panel')) ?>">← Voltar</a>
    </section>
    <?= render_template('catalogs.php', ['catalogs' => $controlCatalogs]) ?>
<?php elseif ($controlSection === 'users' && can('manage_users')): ?>
    <section class="control-section-heading">
        <div><p class="eyebrow">Painel de controle · Acessos do sistema</p><h2>Usuários e permissões</h2><p>Crie contas e ajuste as capacidades de cada usuário.</p></div>
        <a class="button" href="<?= h(page_url('control-panel')) ?>">← Voltar</a>
    </section>
    <?php if (summa_manages_users()): ?>
    <section class="panel">
        <div class="panel-body">
            <p class="notice">Este módulo faz parte do Summa. Para liberar o Patrimônio a alguém ou mudar as permissões, use no Summa: <strong>Painel de controle › Usuários › (usuário) › Módulos e áreas › Patrimônio</strong>. As permissões abaixo são atualizadas a cada entrada.</p>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>Usuário</th><th>Permissões no Patrimônio</th><th>Último acesso</th></tr></thead>
                <tbody>
                <?php $definitions = admin_permission_definitions(); foreach ($controlAdmins as $admin): ?>
                    <tr><td><?= h($admin['username']) ?></td><td><?= $admin['permissions'] ? h(implode(' · ', array_map(static fn($p) => $definitions[$p] ?? $p, $admin['permissions']))) : 'Somente consulta' ?></td><td><?= date_br($admin['last_login'] ?? null) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <a class="button" href="<?= h(summa_url('workspace')) ?>">Abrir o Summa</a>
        </div>
    </section>
    <?php else: ?>
    <?= render_template('admins.php', ['admins' => $controlAdmins]) ?>
    <?php endif; ?>
<?php endif; ?>
