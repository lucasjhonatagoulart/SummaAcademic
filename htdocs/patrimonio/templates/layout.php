<?php
// Página com menu lateral: marca e navegação à esquerda, cabeçalho com Aparência e menu do usuário.
$navGroups = [
    'Patrimônio' => [
        ['dashboard', 'Visão geral', 'layout-dashboard'],
        ['invoices', 'Notas fiscais', 'file-text'],
        ['assets', 'Ativos', 'package'],
        ['reports', 'Relatórios', 'bar-chart'],
    ],
];
if (can('manage_catalogs') || can('manage_users') || can('export_data')) $navGroups['Administração'] = [['control-panel', 'Painel de controle', 'sliders']];
$parentPage = [
    'admins' => 'control-panel', 'catalogs' => 'control-panel', 'restore' => 'control-panel',
    'invoice' => 'invoices', 'invoice-edit' => 'invoices', 'new-invoice' => 'invoices',
    'asset' => 'assets', 'asset-edit' => 'assets', 'new-asset' => 'assets', 'asset-deletions' => 'assets',
];
$currentPage = $parentPage[$page] ?? $page;
$userName = (string)($_SESSION['admin_name'] ?? 'Usuário');
$inSumma = summa_enabled();
$summaLogo = $inSumma ? summa_brand('logo-fundo-escuro.png') : '';
$summaIcon = $inSumma ? summa_brand('icone.png') : '';
$userAvatar = $inSumma ? (string)($_SESSION['admin_avatar'] ?? '') : '';
$appTitle = display_title();
?><!doctype html>
<html lang="pt-BR">
<head>
    <?= head_assets() ?>
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($title) ?> · <?= h($appTitle) ?></title>
</head>
<body>
<div class="shell" data-shell>
    <div class="scrim" data-close-side hidden></div>
    <aside class="sidebar" aria-label="Menu principal">
        <div class="side-head">
            <?php if ($inSumma): ?>
            <a class="brand" href="index.php"><?php if ($summaLogo): ?><span class="brand-name"><img class="brand-logo" src="<?= h($summaLogo) ?>?v=<?= APP_VERSION ?>" alt="Summa Educação"><small class="brand-module">Patrimônio</small></span><?php else: ?><span class="brand-name">Summa<small class="brand-module">Patrimônio</small></span><?php endif; ?></a>
            <?php else: ?>
            <a class="brand" href="index.php"><span class="brand-name"><?= h(APP_TITLE) ?></span></a>
            <?php endif; ?>
            <a class="side-icon<?= $summaIcon ? ' side-icon-brand' : '' ?>" href="index.php" title="<?= h($appTitle) ?>" aria-label="<?= h($appTitle) ?>"><?php if ($summaIcon): ?><img src="<?= h($summaIcon) ?>?v=<?= APP_VERSION ?>" alt=""><?php else: ?><?= icon('package') ?><?php endif; ?></a>
            <button class="side-toggle" type="button" data-toggle-collapse aria-label="Recolher ou expandir o menu" title="Recolher ou expandir o menu"><?= icon('panel-left-close') ?></button>
        </div>
        <nav class="side-nav" aria-label="Navegação principal">
            <?php if ($inSumma): ?>
                <a class="nav-link nav-modules" href="<?= h(summa_url('workspace')) ?>" title="Todos os módulos"><?= icon('layout-grid') ?><span>Todos os módulos</span></a>
            <?php endif; ?>
            <?php foreach ($navGroups as $group => $items): ?>
                <div>
                    <p class="side-group-title"><?= h($group) ?></p>
                    <?php foreach ($items as [$key, $label, $iconName]): $active = $currentPage === $key; ?>
                        <a class="nav-link<?= $active ? ' active' : '' ?>" href="<?= h(page_url($key)) ?>" title="<?= h($label) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= icon($iconName) ?><span><?= h($label) ?></span></a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="side-foot">v<?= APP_VERSION ?></div>
    </aside>
    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-toggle" type="button" data-open-side aria-label="Abrir menu"><?= icon('menu') ?></button>
            <span class="portal"><?= h($title) ?></span>
            <div class="top-actions">
                <?= appearance_button() ?>
                <div class="menu-wrap">
                    <button class="user-btn" type="button" data-menu-btn aria-haspopup="menu" aria-expanded="false"><span class="avatar" aria-hidden="true"><?php if ($userAvatar): ?><img src="<?= h($userAvatar) ?>" alt=""><?php else: ?><?= h(user_initials($userName)) ?><?php endif; ?></span><span class="name"><?= h($userName) ?></span><?= icon('chevron-down') ?></button>
                    <div class="menu" role="menu" data-menu hidden>
                        <div class="menu-head"><strong><?= h($userName) ?></strong><span><?= $inSumma ? 'Conectado pelo Summa' : 'Usuário conectado' ?></span></div>
                        <?php if ($inSumma): ?><a class="menu-link" role="menuitem" href="<?= h(summa_url('workspace')) ?>"><?= icon('layout-grid') ?> Todos os módulos</a><?php endif; ?>
                        <form method="post" action="index.php"><?= csrf_field() ?><input type="hidden" name="action" value="logout"><button class="danger" role="menuitem" type="submit"><?= icon('log-out') ?> Sair</button></form>
                    </div>
                </div>
            </div>
        </header>
        <main class="content" id="conteudo">
            <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'error' ? 'error' : 'success' ?>" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
            <?= $content ?>
        </main>
    </div>
</div>
<script src="<?= asset_url('shell.js') ?>" defer></script>
<script src="<?= asset_url('app.js') ?>" defer></script>
<?php if ((string)($_GET['page'] ?? '') === 'reports'): ?><script src="<?= asset_url('report.js') ?>" defer></script><?php endif; ?>
</body>
</html>
