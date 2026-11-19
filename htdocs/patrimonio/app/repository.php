<?php

declare(strict_types=1);

function app_counts(): array
{
    $pdo = db();
    $assets = $pdo->query('SELECT COUNT(*) AS total, SUM(status = \'active\') AS active, SUM(status = \'disposed\') AS disposed, COALESCE(SUM(CASE WHEN status = \'active\' THEN value ELSE 0 END),0) AS value FROM assets')->fetch();
    $invoices = $pdo->query('SELECT COUNT(*) AS total, SUM(status = \'draft\') AS drafts, SUM(status = \'registered\') AS registered FROM invoices')->fetch();
    $categories = $pdo->query('SELECT category, COUNT(*) AS count, COALESCE(SUM(value),0) AS value FROM assets WHERE status = \'active\' GROUP BY category ORDER BY value DESC LIMIT 8')->fetchAll();
    $categoryCounts = $pdo->query("SELECT COALESCE(NULLIF(TRIM(category), ''), 'Sem conta patrimonial') AS category, COUNT(*) AS count FROM assets WHERE status = 'active' GROUP BY COALESCE(NULLIF(TRIM(category), ''), 'Sem conta patrimonial') ORDER BY count DESC, category ASC")->fetchAll();
    $recent = $pdo->query('SELECT id, name, category, location, patrimony, value, status, acquiredAt FROM assets ORDER BY id DESC LIMIT 6')->fetchAll();
    return ['assets' => $assets ?: [], 'invoices' => $invoices ?: [], 'categories' => $categories, 'categoryCounts' => $categoryCounts, 'recent' => $recent];
}

function list_admins(): array
{
    $admins = db()->query('SELECT id, username, created_at, last_login FROM admins ORDER BY id ASC')->fetchAll();
    foreach ($admins as &$admin) $admin['permissions'] = admin_permissions_for((int)$admin['id']);
    unset($admin);
    return $admins;
}

function admin_permissions_for(int $adminId): array
{
    $statement = db()->prepare('SELECT permission FROM admin_permissions WHERE admin_id = ? ORDER BY permission');
    $statement->execute([$adminId]);
    $allowed = admin_permission_definitions();
    return array_values(array_filter(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)), static fn(string $permission): bool => isset($allowed[$permission])));
}

function list_invoices(string $search = '', string $status = 'all', int $page = 1, int $pageSize = 25): array
{
    $pdo = db();
    $statusCounts = $pdo->query("SELECT COUNT(*) AS all_count, COALESCE(SUM(status = 'draft'), 0) AS drafts, COALESCE(SUM(status = 'registered'), 0) AS registered FROM invoices")->fetch() ?: [];
    $where = [];
    $params = [];
    if (in_array($status, ['draft', 'registered'], true)) { $where[] = 'i.status = ?'; $params[] = $status; }
    if ($search !== '') {
        $where[] = '(i.number LIKE ? OR i.supplier LIKE ? OR i.supplierCnpj LIKE ? OR i.accessKey LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM invoices i' . $clause);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $page = max(1, $page);
    $offset = ($page - 1) * $pageSize;
    $query = $pdo->prepare('SELECT i.*, (SELECT COUNT(*) FROM invoice_items it WHERE it.invoiceId = i.id) AS item_count FROM invoices i' . $clause . ' ORDER BY i.id DESC LIMIT ? OFFSET ?');
    foreach ($params as $index => $param) $query->bindValue($index + 1, $param, PDO::PARAM_STR);
    $query->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
    $query->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $query->execute();
    return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $pageSize)), 'counts' => ['all' => (int)($statusCounts['all_count'] ?? 0), 'draft' => (int)($statusCounts['drafts'] ?? 0), 'registered' => (int)($statusCounts['registered'] ?? 0)]];
}

function get_invoice(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM invoices WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $invoice = $statement->fetch();
    if (!$invoice) return null;
    $items = db()->prepare("SELECT * FROM invoice_items WHERE invoiceId = ? ORDER BY CASE WHEN id REGEXP '^[0-9]+$' THEN CAST(id AS UNSIGNED) WHEN id REGEXP '^item-[0-9]+$' THEN CAST(SUBSTRING(id, 6) AS UNSIGNED) ELSE 2147483647 END, id");
    $items->execute([$id]);
    $invoice['items'] = $items->fetchAll();
    return $invoice;
}

function list_assets(string $search, string $status, string $classification, string $category, string $location, int $page, int $pageSize = 50): array
{
    $pdo = db();
    $where = [];
    $params = [];
    if (in_array($status, ['active', 'disposed'], true)) { $where[] = 'status = ?'; $params[] = $status; }
    if (in_array($classification, ['fixed', 'small'], true)) { $where[] = 'classification = ?'; $params[] = $classification; }
    if ($category !== '') { $where[] = 'category = ?'; $params[] = $category; }
    if ($location !== '') { $where[] = 'location = ?'; $params[] = $location; }
    if ($search !== '') {
        $where[] = '(name LIKE ? OR originalDescription LIKE ? OR patrimony LIKE ? OR category LIKE ? OR location LIKE ? OR invoiceNumber LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like, $like, $like);
    }
    $clause = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) FROM assets' . $clause);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $page = max(1, $page);
    $query = $pdo->prepare('SELECT id, classification, name, originalDescription, category, location, patrimony, invoiceNumber, invoiceId, acquiredAt, value, status, disposalDate FROM assets' . $clause . ' ORDER BY id DESC LIMIT ? OFFSET ?');
    foreach ($params as $index => $param) $query->bindValue($index + 1, $param, PDO::PARAM_STR);
    $query->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
    $query->bindValue(count($params) + 2, ($page - 1) * $pageSize, PDO::PARAM_INT);
    $query->execute();
    return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => max(1, (int)ceil($total / $pageSize))];
}

function get_asset(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM assets WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row ?: null;
}

function get_catalogs(bool $withFallback = true): array
{
    $rows = db()->query('SELECT kind, value FROM catalogs ORDER BY kind, value')->fetchAll();
    $result = ['category' => [], 'location' => []];
    foreach ($rows as $row) if (isset($result[$row['kind']])) $result[$row['kind']][] = (string)$row['value'];
    if (!$result['category'] && $withFallback) $result['category'] = ['Móveis e Utensílios', 'Equip.Info e Periféricos', 'Máquinas e Equipamentos', 'Software', 'Laboratório', 'Sistema Fotovoltaico'];
    return $result;
}

function all_assets(?string $status = null): array
{
    if ($status === 'active' || $status === 'disposed') {
        $statement = db()->prepare('SELECT * FROM assets WHERE status = ? ORDER BY id');
        $statement->execute([$status]);
        return $statement->fetchAll();
    }
    return db()->query('SELECT * FROM assets ORDER BY id')->fetchAll();
}

function all_assets_for_report_summary(): array
{
    return db()->query('SELECT category, value, status, acquiredAt FROM assets ORDER BY id')->fetchAll();
}

function list_asset_deletion_audit(int $page = 1, int $pageSize = 50): array
{
    $pdo = db();
    $total = (int)$pdo->query('SELECT COUNT(*) FROM asset_deletion_audit')->fetchColumn();
    $pageSize = max(1, min(200, $pageSize));
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = min(max(1, $page), $pages);
    $offset = ($page - 1) * $pageSize;
    $query = $pdo->prepare('SELECT id, assetId, invoiceId, assetName, classification, category, location, patrimony, invoiceNumber, assetStatus, reason, deletedByAdminId, deletedByUsername, deletedAt FROM asset_deletion_audit ORDER BY id DESC LIMIT ? OFFSET ?');
    $query->bindValue(1, $pageSize, PDO::PARAM_INT);
    $query->bindValue(2, $offset, PDO::PARAM_INT);
    $query->execute();
    return ['rows' => $query->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function all_asset_deletion_audits(): array
{
    return db()->query('SELECT id, assetId, invoiceId, assetName, classification, category, location, patrimony, invoiceNumber, assetStatus, reason, deletedByAdminId, deletedByUsername, deletedAt FROM asset_deletion_audit ORDER BY id')->fetchAll();
}

function all_invoices(): array
{
    $invoices = db()->query('SELECT * FROM invoices ORDER BY id')->fetchAll();
    if (!$invoices) return [];
    $items = db()->query('SELECT * FROM invoice_items ORDER BY invoiceId, id')->fetchAll();
    $byInvoice = [];
    foreach ($items as $item) { $byInvoice[(string)$item['invoiceId']][] = $item; }
    foreach ($invoices as &$invoice) $invoice['items'] = $byInvoice[(string)$invoice['id']] ?? [];
    unset($invoice);
    return $invoices;
}
