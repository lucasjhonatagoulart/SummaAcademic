<?php

declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

try {
    $pdo = db();
    $firstAdmin = $pdo->query('SELECT id FROM admins LIMIT 1')->fetchColumn();
    if (!$firstAdmin) render_uninstalled('A conta administradora ainda não foi criada. Faça a instalação inicial para ativar o login.');
} catch (Throwable) {
    render_uninstalled('Não foi possível acessar as tabelas do sistema. Confira os dados MySQL e execute a instalação inicial.');
}

if (isset($_GET['sso']) && is_string($_GET['sso'])) {
    try {
        summa_sso_login($_GET['sso']);
        redirect_to('dashboard');
    } catch (Throwable $e) {
        http_response_code(403);
        echo auth_page('Acesso pelo Summa', '<div><p class="eyebrow">' . h(SUMMA_MODULE_NAME) . '</p><h1>Não foi possível entrar</h1></div><p>' . h($e->getMessage()) . '</p><a class="button primary" href="' . h(summa_url('patrimonio')) . '">Abrir pelo Summa</a>');
        exit;
    }
}

if (isset($_GET['download'])) {
    try {
        require_permission('export_data');
        $kind = (string)$_GET['download'];
        if ($kind === 'backup') download_backup();
        if ($kind === 'report-xlsx') download_report_xlsx();
        if ($kind === 'xml') download_invoice_xml(positive_int($_GET['id'] ?? 0, 'NF-e'));
        if (str_starts_with($kind, 'csv-')) download_csv(substr($kind, 4));
        if ($kind === 'assets' || $kind === 'invoices') download_xlsx($kind);
        throw new RuntimeException('Exportação desconhecida.');
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        redirect_to('reports');
    }
}

handle_post_action();

try {
    render_page();
} catch (Throwable $e) {
    http_response_code(500);
    $message = h($e->getMessage());
    if (is_logged_in()) {
        echo render_template('layout.php', ['title'=>'Erro','page'=>'error','flash'=>['type'=>'error','message'=>$e->getMessage()],'content'=>'<section class="panel"><div class="panel-body"><h1>Não foi possível abrir esta tela</h1><p class="footer-note">' . $message . '</p><a class="button" href="index.php">Voltar ao painel</a></div></section>']);
    } else {
        echo auth_page('Erro', '<div><p class="eyebrow">Algo deu errado</p><h1>Erro ao abrir o sistema</h1></div><p>' . $message . '</p><a class="button primary" href="index.php?page=login">Voltar ao login</a>');
    }
}
