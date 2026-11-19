<?php

declare(strict_types=1);

function invoice_import_buffer_clear(): void
{
    unset($_SESSION['invoice_import_buffer']);
}

function invoice_import_buffer_store(?int $invoiceId, array $invoice, string $xml): void
{
    $_SESSION['invoice_import_buffer'] = [
        'adminId' => (int)($_SESSION['admin_id'] ?? 0),
        'invoiceId' => $invoiceId,
        'invoice' => $invoice,
        'xml' => $xml,
        'expiresAt' => time() + 7200,
    ];
}

function invoice_import_buffer_for(?int $invoiceId): ?array
{
    $buffer = $_SESSION['invoice_import_buffer'] ?? null;
    if (!is_array($buffer)) return null;
    if (!is_logged_in() || (int)($buffer['adminId'] ?? 0) !== (int)$_SESSION['admin_id'] || (int)($buffer['expiresAt'] ?? 0) < time()) {
        invoice_import_buffer_clear();
        return null;
    }
    $bufferId = isset($buffer['invoiceId']) ? (int)$buffer['invoiceId'] : null;
    if ($bufferId !== $invoiceId || !is_array($buffer['invoice'] ?? null) || !is_string($buffer['xml'] ?? null)) return null;
    return $buffer;
}

function invoice_import_buffer_matches_current_admin(): bool
{
    $buffer = $_SESSION['invoice_import_buffer'] ?? null;
    return is_array($buffer)
        && is_logged_in()
        && (int)($buffer['adminId'] ?? 0) === (int)$_SESSION['admin_id']
        && (int)($buffer['expiresAt'] ?? 0) >= time();
}

function invoice_snapshot_text(mixed $value, int $limit): string
{
    if (!is_string($value) && !is_numeric($value)) return '';
    $text = trim((string)$value);
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($characters) && count($characters) > $limit) return implode('', array_slice($characters, 0, $limit));
    if (!is_array($characters) && strlen($text) > $limit * 4) return substr($text, 0, $limit * 4);
    return $text;
}

function invoice_workflow_text(mixed $value, int $limit): string
{
    if (function_exists('text_value')) return text_value($value, $limit);
    if (!is_string($value) && !is_numeric($value)) return '';
    $text = trim((string)$value);
    $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($characters) && count($characters) > $limit) throw new RuntimeException('Um campo ultrapassou o limite permitido.');
    return $text;
}

function invoice_form_old_store(array $post): void
{
    $fieldLimits = [
        'accessKey' => 60, 'number' => 32, 'series' => 16, 'issuedAt' => 40,
        'supplier' => 320, 'supplierCnpj' => 32, 'recipient' => 320,
        'recipientCnpj' => 32, 'total' => 64, 'notes' => 10000,
    ];
    $data = [];
    $data['source'] = in_array(($post['source'] ?? ''), ['manual', 'xml'], true) ? $post['source'] : 'manual';
    foreach ($fieldLimits as $field => $limit) {
        if ($data['source'] !== 'xml' || $field === 'notes') $data[$field] = invoice_snapshot_text($post[$field] ?? '', $limit);
    }
    $data['current_step'] = max(1, min(3, (int)($post['current_step'] ?? 1)));
    $itemLimits = [
        'id' => 96, 'originalDescription' => 5000, 'name' => 5000, 'quantity' => 64,
        'unit' => 32, 'unitValue' => 64, 'total' => 64, 'ncm' => 32, 'cfop' => 32,
        'classification' => 16, 'category' => 255, 'location' => 255, 'patrimony' => 5000,
        'brand' => 255, 'color' => 255, 'type' => 255, 'condition' => 64,
        'usefulLife' => 32, 'notes' => 5000,
    ];
    $data['items'] = [];
    $postedItems = is_array($post['items'] ?? null) ? array_slice($post['items'], 0, 200, true) : [];
    foreach ($postedItems as $item) {
        if (!is_array($item)) continue;
        $snapshot = [];
        foreach ($itemLimits as $field => $limit) {
            if ($data['source'] !== 'xml' || in_array($field, ['id','name','classification','category','location','patrimony','brand','color','type','condition','usefulLife','notes'], true)) {
                $snapshot[$field] = invoice_snapshot_text($item[$field] ?? '', $limit);
            }
        }
        $data['items'][] = $snapshot;
    }
    $rawId = $post['id'] ?? null;
    $invoiceId = is_scalar($rawId) && ctype_digit((string)$rawId) && (int)$rawId > 0 ? (int)$rawId : null;
    $_SESSION['invoice_form_old'] = [
        'adminId' => (int)($_SESSION['admin_id'] ?? 0),
        'invoiceId' => $invoiceId,
        'data' => $data,
        'expiresAt' => time() + 1800,
    ];
}

function invoice_form_old_take(?int $invoiceId): ?array
{
    $old = $_SESSION['invoice_form_old'] ?? null;
    if (!is_array($old)) return null;
    if (!is_logged_in() || (int)($old['adminId'] ?? 0) !== (int)$_SESSION['admin_id'] || (int)($old['expiresAt'] ?? 0) < time()) {
        unset($_SESSION['invoice_form_old']);
        return null;
    }
    $oldId = isset($old['invoiceId']) ? (int)$old['invoiceId'] : null;
    if ($oldId !== $invoiceId || !is_array($old['data'] ?? null)) {
        unset($_SESSION['invoice_form_old']);
        return null;
    }
    unset($_SESSION['invoice_form_old']);
    return $old['data'];
}

function invoice_items_from_xml_form(array $sourceItems, mixed $postedItems): array
{
    if (!is_array($postedItems)) throw new RuntimeException('Formato dos itens inválido.');
    $rows = [];
    foreach ($postedItems as $row) {
        if (!is_array($row)) continue;
        $id = invoice_workflow_text($row['id'] ?? '', 96);
        if ($id === '' || isset($rows[$id])) throw new RuntimeException('A lista de itens XML foi alterada. Recarregue o rascunho e tente novamente.');
        $rows[$id] = $row;
    }
    if (count($rows) !== count($sourceItems)) throw new RuntimeException('Os itens fiscais do XML não podem ser adicionados ou removidos.');

    $result = [];
    $editableLimits = [
        'name' => 5000, 'category' => 255, 'location' => 255, 'patrimony' => 5000,
        'brand' => 255, 'color' => 255, 'type' => 255, 'condition' => 64, 'notes' => 5000,
    ];
    foreach ($sourceItems as $source) {
        $id = (string)($source['id'] ?? '');
        if ($id === '' || !isset($rows[$id])) throw new RuntimeException('Um item XML não corresponde ao documento original. Recarregue o XML.');
        $row = $rows[$id];
        $classification = (string)($row['classification'] ?? 'fixed');
        if (!in_array($classification, ['fixed', 'small', 'skip'], true)) $classification = 'fixed';
        $item = $source;
        $item['id'] = $id;
        foreach ($editableLimits as $field => $limit) $item[$field] = invoice_workflow_text($row[$field] ?? ($field === 'condition' ? 'Novo' : ''), $limit);
        $item['classification'] = $classification;
        $item['usefulLife'] = max(0, (int)($row['usefulLife'] ?? 5));
        $result[] = $item;
    }
    return $result;
}
