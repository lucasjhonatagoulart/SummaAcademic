<?php

declare(strict_types=1);

function render_template(string $file, array $vars = []): string
{
    $path = dirname(__DIR__) . '/templates/' . $file;
    if (!is_file($path)) throw new RuntimeException('Tela não encontrada.');
    extract($vars, EXTR_SKIP);
    ob_start();
    require $path;
    return (string)ob_get_clean();
}

function render_page(): void
{
    $page = (string)($_GET['page'] ?? 'dashboard');
    if ($page === 'login') {
        if (is_logged_in()) redirect_to('dashboard');
        echo render_template('login.php', ['flash' => flash_take(), 'installed' => isset($_GET['installed'])]);
        return;
    }
    require_login();
    if ($page === 'reports') {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
    }
    $invoiceFormPage = in_array($page, ['new-invoice', 'invoice-edit'], true);
    if (!$invoiceFormPage || (string)($_GET['buffer'] ?? '') !== '1') invoice_import_buffer_clear();
    if (!$invoiceFormPage) unset($_SESSION['invoice_form_old']);
    $title = 'Visão geral';
    $content = '';
    switch ($page) {
        case 'dashboard':
            $title = 'Visão geral';
            import_summa_purchases(db());
            $content = render_template('dashboard.php', ['stats' => app_counts(), 'purchaseDrafts' => summa_purchase_drafts(db())]);
            break;
        case 'invoices':
            $title = 'Notas fiscais';
            $search = text_value($_GET['q'] ?? '', 160);
            $status = (string)($_GET['status'] ?? 'all');
            if (!in_array($status, ['all', 'draft', 'registered'], true)) $status = 'all';
            import_summa_purchases(db());
            $data = list_invoices($search, $status, max(1, (int)($_GET['p'] ?? 1)));
            $content = render_template('invoices.php', ['data' => $data, 'search' => $search, 'status' => $status, 'purchaseDrafts' => summa_purchase_drafts(db())]);
            break;
        case 'new-invoice':
            require_permission('edit_invoices');
            $title = 'Nova NF-e';
            $buffer = invoice_import_buffer_for(null);
            if (!$buffer && isset($_SESSION['invoice_import_buffer'])) invoice_import_buffer_clear();
            $old = invoice_form_old_take(null);
            $content = render_template('invoice-form.php', ['invoice' => $buffer['invoice'] ?? null, 'isEdit' => false, 'old' => $old]);
            break;
        case 'invoice-edit':
            require_permission('edit_invoices');
            $title = 'Revisar NF-e';
            $id = isset($_GET['id']) ? positive_int($_GET['id'], 'NF-e') : null;
            $invoice = $id ? get_invoice($id) : null;
            if ($id && !$invoice) throw new RuntimeException('NF-e não encontrada.');
            if ($invoice && $invoice['status'] === 'registered') redirect_to('invoice', ['id' => $id]);
            $buffer = invoice_import_buffer_for($id);
            if ($buffer) {
                $invoice = $buffer['invoice'];
                $invoice['id'] = $id;
            } elseif (isset($_SESSION['invoice_import_buffer'])) {
                invoice_import_buffer_clear();
            }
            $old = invoice_form_old_take($id);
            $content = render_template('invoice-form.php', ['invoice' => $invoice, 'isEdit' => (bool)$invoice, 'old' => $old]);
            break;
        case 'invoice':
            $title = 'Detalhes da NF-e';
            $invoice = get_invoice(positive_int($_GET['id'] ?? 0, 'NF-e'));
            if (!$invoice) throw new RuntimeException('NF-e não encontrada.');
            $content = render_template('invoice-detail.php', ['invoice' => $invoice]);
            break;
        case 'assets':
            $title = 'Ativos';
            $search = text_value($_GET['q'] ?? '', 160);
            $status = (string)($_GET['status'] ?? 'active');
            $classification = (string)($_GET['class'] ?? 'all');
            $category = text_value($_GET['category'] ?? '', 255);
            $location = text_value($_GET['location'] ?? '', 255);
            $data = list_assets($search, $status, $classification, $category, $location, max(1, (int)($_GET['p'] ?? 1)));
            // Guarda onde a pessoa está na lista: é para lá que ela volta depois de editar ou excluir um ativo
            $_SESSION['assets_list_query'] = array_filter(['q' => $search, 'status' => $status === 'active' ? '' : $status, 'class' => $classification === 'all' ? '' : $classification,
                'category' => $category, 'location' => $location, 'p' => (int)$data['page'] > 1 ? (int)$data['page'] : ''], static fn($v): bool => (string)$v !== '');
            $content = render_template('assets.php', ['data' => $data, 'search' => $search, 'status' => $status, 'classification' => $classification, 'category' => $category, 'location' => $location, 'catalogs' => get_catalogs()]);
            break;
        case 'new-asset':
            require_permission('manage_users');
            require_permission('edit_assets');
            $title = 'Cadastrar ativo sem NF-e';
            $content = render_template('asset-create-no-invoice.php', ['catalogs' => get_catalogs(false)]);
            break;
        case 'asset':
            $title = 'Detalhes do ativo';
            $asset = get_asset(positive_int($_GET['id'] ?? 0, 'Ativo'));
            if (!$asset) throw new RuntimeException('Ativo não encontrado.');
            $content = render_template('asset-detail.php', ['asset' => $asset]);
            break;
        case 'asset-edit':
            require_permission('edit_assets');
            $title = 'Editar ativo';
            $asset = get_asset(positive_int($_GET['id'] ?? 0, 'Ativo'));
            if (!$asset) throw new RuntimeException('Ativo não encontrado.');
            $content = render_template('asset-form.php', ['asset' => $asset, 'catalogs' => get_catalogs(false)]);
            break;
        case 'asset-deletions':
            require_permission('manage_users');
            require_permission('edit_assets');
            $title = 'Histórico de exclusões';
            $data = list_asset_deletion_audit(max(1, (int)($_GET['p'] ?? 1)));
            $content = render_template('asset-deletion-log.php', ['data' => $data]);
            break;
        case 'reports':
            $title = 'Relatórios';
            $reportAssets = can('export_data') ? all_assets() : all_assets_for_report_summary();
            $content = render_template('reports.php', ['assets' => $reportAssets]);
            break;
        case 'control-panel':
            if (!can('manage_catalogs') && !can('manage_users') && !can('export_data')) denied_response();
            $sectionPermissions = ['backup' => 'export_data', 'catalogs' => 'manage_catalogs', 'users' => 'manage_users'];
            $controlSection = $_GET['section'] ?? '';
            if (!is_string($controlSection)) redirect_to('control-panel');
            if ($controlSection !== '' && !isset($sectionPermissions[$controlSection])) redirect_to('control-panel');
            if ($controlSection !== '') require_permission($sectionPermissions[$controlSection]);
            $title = 'Painel de controle';
            $overview = $controlSection === '';
            $controlCatalogs = can('manage_catalogs') && ($overview || $controlSection === 'catalogs')
                ? get_catalogs(false)
                : ['category' => [], 'location' => []];
            $controlAdmins = can('manage_users') && ($overview || $controlSection === 'users') ? list_admins() : [];
            $content = render_template('control-panel.php', [
                'controlSection' => $controlSection,
                'catalogs' => $controlCatalogs,
                'admins' => $controlAdmins,
            ]);
            break;
        case 'catalogs':
            require_permission('manage_catalogs');
            redirect_to('control-panel', ['section' => 'catalogs']);
        case 'admins':
            require_permission('manage_users');
            redirect_to('control-panel', ['section' => 'users']);
        case 'restore':
            require_restore_permissions();
            $title = 'Importar backup';
            $content = render_template('restore.php');
            break;
        default:
            http_response_code(404);
            $title = 'Página não encontrada';
            $content = '<section class="panel"><div class="empty"><strong>Esta página não existe.</strong><a class="button" href="index.php">Voltar ao início</a></div></section>';
    }
    $flash = flash_take();
    echo render_template('layout.php', ['title' => $title, 'content' => $content, 'flash' => $flash, 'page' => $page]);
}

function render_uninstalled(string $detail = ''): never
{
    http_response_code(503);
    $message = $detail !== '' ? h($detail) : 'A base de dados ainda não foi instalada.';
    echo auth_page('Configurar', '<div><p class="eyebrow">Configuração necessária</p><h1>Preparar o sistema</h1></div><p>' . $message . '</p><a class="button primary" href="install.php">Abrir instalador</a><p class="tiny-note">Se o MySQL não conectar, confira host, usuário, senha e nome do banco no painel da InfinityFree e em config.php.</p>');
    exit;
}
