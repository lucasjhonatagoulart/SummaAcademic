<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/security.php';
function is_logged_in(): bool { return false; }

$check = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    echo "OK - {$message}\n";
};
$check(array_keys(admin_permission_definitions()) === ['edit_invoices','edit_assets','manage_catalogs','manage_users','export_data','restore_backup'], 'a allowlist contém exatamente seis capacidades explícitas');
$check(validate_permission_selection([]) === [], 'a lista vazia representa modo somente visualização');
$check((function (): bool { try { validate_permission_dependencies(['restore_backup']); } catch (RuntimeException) { return true; } return false; })(), 'restaurar exige também as três capacidades de gravação envolvidas');
$check((function (): bool { try { validate_permission_dependencies(['restore_backup','edit_assets','edit_invoices','manage_catalogs']); return true; } catch (RuntimeException) { return false; } })(), 'a combinação completa libera a capacidade de restaurar');
$check(!is_logged_in() && !can('manage_users') && !can('edit_assets'), 'sem sessão autenticada não há capacidades de escrita/gestão');
$check(!can('unknown_permission'), 'capacidades desconhecidas são negadas por padrão');
foreach ([['unknown_permission'], 'edit_assets'] as $invalid) {
    try {
        validate_permission_selection($invalid);
        throw new RuntimeException('Falhou: entrada de permissão inválida deveria ser negada.');
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), 'Falhou:')) throw $error;
        echo "OK - entrada de permissão inválida é rejeitada\n";
    }
}
echo "Total: 8 verificações da política de capacidades aprovadas.\n";
