<?php

declare(strict_types=1);

function utf8_length(string $value): int
{
    $count = preg_match_all('/./us', $value);
    return $count === false ? strlen($value) : $count;
}
function text_value(mixed $value, int $limit = 5000): string
{
    if (!is_string($value) && !is_numeric($value)) return '';
    $value = trim((string)$value);
    if (utf8_length($value) > $limit) throw new RuntimeException('Campo acima do limite.');
    return $value;
}
function can(string $permission): bool { return in_array($permission, $GLOBALS['test_permissions'] ?? [], true); }
function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function page_url(string $page, array $extra = []): string { return 'index.php?' . http_build_query(['page' => $page] + $extra); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="token">'; }
function status_label(string $status): string { return $status === 'disposed' ? 'Baixado' : 'Ativo'; }
function asset_invoice_display_label(array $asset): string { return trim((string)($asset['invoiceNumber'] ?? '')) === '' ? 'Sem NF-e' : (string)$asset['invoiceNumber']; }
function money_br(mixed $value): string { return 'R$ ' . number_format((float)$value, 2, ',', '.'); }
function date_br(?string $value): string { return $value ? $value : '—'; }

require __DIR__ . '/../app/import_util.php';
require __DIR__ . '/../app/restore.php';
require __DIR__ . '/../app/actions.php';

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
    $checks++;
    echo "OK - {$message}\n";
};
$throws = static function (callable $callback): bool {
    try { $callback(); } catch (RuntimeException) { return true; }
    return false;
};

$asset = [
    'id' => 44, 'invoiceId' => 9, 'name' => 'Notebook <teste>', 'classification' => 'fixed',
    'category' => 'Tecnologia', 'location' => 'Sala 2', 'patrimony' => 'PAT-44',
    'invoiceNumber' => 'NF-100', 'status' => 'active', 'original' => '<xml>não deve ser copiado</xml>',
];
$audit = asset_deletion_audit_row($asset, 7, 'gestor.teste', 'Duplicidade confirmada no inventário.');
$check($audit['reason'] === 'Duplicidade confirmada no inventário.' && $audit['deletedByAdminId'] === 7 && $audit['deletedByUsername'] === 'gestor.teste', 'registro de auditoria conserva motivo, administrador e usuário identificado');
$check($audit['assetId'] === 44 && $audit['assetName'] === 'Notebook <teste>' && $audit['invoiceNumber'] === 'NF-100' && !array_key_exists('original', $audit), 'snapshot identifica o ativo e sua NF-e sem copiar o XML original');
$check($throws(static fn() => asset_deletion_audit_row($asset, 7, 'gestor.teste', '')), 'não permite registrar exclusão sem motivo');

$restoredAudit = normalize_restore_asset_deletion_audits([[
    'id' => 2, 'assetId' => 44, 'invoiceId' => 9, 'assetName' => 'Notebook teste', 'classification' => 'fixed',
    'category' => 'Tecnologia', 'location' => 'Sala 2', 'patrimony' => 'PAT-44', 'invoiceNumber' => 'NF-100',
    'assetStatus' => 'active', 'reason' => 'Duplicidade confirmada.', 'deletedByAdminId' => 7,
    'deletedByUsername' => 'gestor.teste', 'deletedAt' => '2026-11-10 15:30:00',
]]);
$check(count($restoredAudit) === 1 && $restoredAudit[0]['assetId'] === 44 && $restoredAudit[0]['reason'] === 'Duplicidade confirmada.', 'normalizador aceita um registro de auditoria consistente para restauração');
$badAudit = [[
    'id' => 2, 'assetId' => 44, 'classification' => 'fixed', 'category' => 'Tecnologia', 'location' => '',
    'patrimony' => 'PAT-44', 'invoiceNumber' => '', 'assetStatus' => 'active', 'reason' => 'Motivo',
    'deletedByUsername' => 'gestor.teste', 'deletedAt' => 'data inválida',
]];
$check($throws(static fn() => normalize_restore_asset_deletion_audits($badAudit)), 'restauração rejeita timestamps inválidos no histórico');

$render = (string)file_get_contents(__DIR__ . '/../app/render.php');
$actions = (string)file_get_contents(__DIR__ . '/../app/actions.php');
$detailTemplate = (string)file_get_contents(__DIR__ . '/../templates/asset-detail.php');
$historyTemplate = (string)file_get_contents(__DIR__ . '/../templates/asset-deletion-log.php');
$index = (string)file_get_contents(__DIR__ . '/../index.php');
$dispatcherCheck = str_contains($actions, "if (\$action === 'delete-asset') { require_permission('manage_users'); require_permission('edit_assets'); delete_asset_action(); }")
    && str_contains($actions, 'function handle_post_action(): void')
    && str_contains($actions, '        verify_csrf();');
$check($dispatcherCheck, 'POST de exclusão exige manage_users, edit_assets e passa pelo controle CSRF global');
preg_match("/case 'asset-deletions':(.*?)break;/s", $render, $historyRoute);
$check(isset($historyRoute[1]) && str_contains($historyRoute[1], "require_permission('manage_users')") && str_contains($historyRoute[1], "require_permission('edit_assets')"), 'rota do histórico valida as duas permissões no servidor');

$deleteStart = strpos($actions, 'function delete_asset_action(): never');
$deleteEnd = $deleteStart === false ? false : strpos($actions, 'function catalog_action(', $deleteStart);
$deleteBlock = $deleteStart !== false && $deleteEnd !== false ? substr($actions, $deleteStart, $deleteEnd - $deleteStart) : '';
$check(str_contains($deleteBlock, 'beginTransaction()') && str_contains($deleteBlock, 'commit()') && str_contains($deleteBlock, 'rollBack()'), 'exclusão e gravação do motivo usam uma transação com rollback em erro');
$check(str_contains($deleteBlock, 'INSERT INTO asset_deletion_audit') && str_contains($deleteBlock, 'DELETE FROM asset_patrimony_registry') && str_contains($deleteBlock, 'DELETE FROM assets WHERE id = ?'), 'auditoria é gravada antes da remoção do ativo e da reserva patrimonial');
$check(!str_contains($deleteBlock, 'DELETE FROM invoices') && !str_contains($deleteBlock, 'DELETE FROM invoice_items'), 'excluir ativo não apaga a NF-e, seus itens fiscais ou XML');
$check(str_contains($detailTemplate, 'value="delete-asset"') && str_contains($detailTemplate, 'name="reason" required') && str_contains($detailTemplate, 'data-confirm-submit'), 'formulário pede motivo e confirmação explícita');

$viewAsset = [
    'id' => 44, 'classification' => 'fixed', 'status' => 'active', 'name' => 'Notebook teste',
    'category' => 'Tecnologia', 'location' => 'Sala 2', 'patrimony' => 'PAT-44', 'invoiceNumber' => 'NF-100',
    'invoiceId' => 9, 'condition' => 'Novo', 'brand' => 'Marca', 'type' => 'Notebook', 'color' => 'Preto',
    'acquiredAt' => '2026-03-01', 'quantity' => '1.000', 'value' => '1200.00', 'usefulLife' => 5,
    'purchaseYear' => 2026, 'originalDescription' => 'Descrição original', 'notes' => '', 'disposalDate' => '', 'disposalReason' => '',
];
$GLOBALS['asset'] = $viewAsset;
$GLOBALS['test_permissions'] = [];
ob_start(); require __DIR__ . '/../templates/asset-detail.php'; $nonAdminDetail = (string)ob_get_clean();
$check(!str_contains($nonAdminDetail, 'value="delete-asset"'), 'usuário sem perfil administrativo não vê o formulário de exclusão');
$GLOBALS['test_permissions'] = ['manage_users', 'edit_assets'];
ob_start(); require __DIR__ . '/../templates/asset-detail.php'; $adminDetail = (string)ob_get_clean();
$check(str_contains($adminDetail, 'value="delete-asset"') && str_contains($adminDetail, 'name="reason" required'), 'administrador com edição de ativos vê o campo obrigatório de motivo');

$GLOBALS['data'] = ['total' => 1, 'page' => 1, 'pages' => 1, 'rows' => [[
    'id' => 2, 'assetId' => 44, 'assetName' => '<b>Notebook</b>', 'classification' => 'fixed', 'category' => 'Tecnologia',
    'location' => 'Sala 2', 'patrimony' => 'PAT-44', 'invoiceNumber' => 'NF-100', 'assetStatus' => 'active',
    'reason' => '<script>alert(1)</script>', 'deletedByUsername' => '<gestor>', 'deletedAt' => '2026-11-10 15:30:00',
]]];
ob_start(); require __DIR__ . '/../templates/asset-deletion-log.php'; $historyHtml = (string)ob_get_clean();
$check(str_contains($historyHtml, '&lt;script&gt;alert(1)&lt;/script&gt;') && !str_contains($historyHtml, '<script>alert(1)</script>') && str_contains($historyHtml, '&lt;gestor&gt;'), 'página administrativa escapa motivo e usuário antes de exibi-los');

$schema = (string)file_get_contents(__DIR__ . '/../schema.sql');
$migrationPath = __DIR__ . '/../upgrade-asset-deletion-audit.sql';
$migration = is_file($migrationPath) ? (string)file_get_contents($migrationPath) : '';
$check(str_contains($schema, 'CREATE TABLE IF NOT EXISTS asset_deletion_audit') && str_contains($migration, 'CREATE TABLE IF NOT EXISTS asset_deletion_audit'), 'schema de instalação e migração local criam a tabela de auditoria sem alterar ativos existentes');
$htaccess = (string)file_get_contents(__DIR__ . '/../.htaccess');
$check(str_contains($htaccess, 'upgrade-[A-Za-z0-9_-]+\\.sql'), 'servidor bloqueia o download HTTP direto de migrações SQL');
$reporting = (string)file_get_contents(__DIR__ . '/../app/reporting.php');
$backup = (string)file_get_contents(__DIR__ . '/../app/backup.php');
$restore = (string)file_get_contents(__DIR__ . '/../app/restore.php');
$restoreJs = (string)file_get_contents(__DIR__ . '/../assets/restore.js');
$check(!str_contains($reporting, 'asset_deletion_audit') && !str_contains($reporting, 'deletionReason'), 'construtor de relatórios não consulta nem oferece motivo de exclusão');
$check(str_contains($backup, 'dados/historico-exclusoes-ativos.json') && str_contains($restore, "'deletions'") && str_contains($restoreJs, 'optionalJsonEntry(zip, \'dados/historico-exclusoes-ativos.json\', [])'), 'backup preserva a auditoria e a restauração aceita o arquivo opcional');
$csvStart = strpos($backup, 'function asset_csv_rows');
$csvEnd = $csvStart === false ? false : strpos($backup, 'function invoice_csv_rows', $csvStart);
$assetCsvCode = $csvStart !== false && $csvEnd !== false ? substr($backup, $csvStart, $csvEnd - $csvStart) : '';
$check(!str_contains($assetCsvCode, 'asset_deletion_audit') && !str_contains($assetCsvCode, 'deletedByUsername'), 'exportações patrimoniais legíveis não incluem trilha de auditoria');

printf("Total: %d verificações de exclusão auditada aprovadas sem banco.\n", $checks);
