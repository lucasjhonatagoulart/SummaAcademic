<?php

declare(strict_types=1);

function canonical_restore_category(mixed $value): string
{
    $original = (string)($value ?? '');
    $collapsed = preg_replace('/\\s+/u', ' ', trim($original));
    $key = strtolower($collapsed === null ? trim($original) : $collapsed);
    return match ($key) {
        'energia fotovoltaico', 'sistema fotovotaico' => 'Sistema Fotovoltaico',
        default => $original,
    };
}

function normalize_restore_records(string $kind, array $records): array
{
    foreach ($records as &$record) {
        if (!is_array($record)) continue;
        if ($kind === 'assets' && array_key_exists('category', $record)) {
            $record['category'] = canonical_restore_category($record['category']);
        } elseif ($kind === 'invoices' && is_array($record['items'] ?? null)) {
            foreach ($record['items'] as &$item) {
                if (is_array($item) && array_key_exists('category', $item)) $item['category'] = canonical_restore_category($item['category']);
            }
            unset($item);
        } elseif ($kind === 'catalogs' && ($record['kind'] ?? '') === 'category' && array_key_exists('value', $record)) {
            $record['value'] = canonical_restore_category($record['value']);
        }
    }
    unset($record);
    return $records;
}

function deduplicate_restore_catalogs(array $catalogs): array
{
    $unique = [];
    $seen = [];
    foreach ($catalogs as $row) {
        if (!is_array($row)) { $unique[] = $row; continue; }
        $key = (string)($row['kind'] ?? '') . "\0" . (string)($row['value'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $unique[] = $row;
    }
    return $unique;
}

function restore_patrimony_tokens(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\/,\n\r]+/u', $value) ?: []), static fn(string $token): bool => $token !== ''));
}

function build_restore_patrimony_registry_rows(array $assets): array
{
    $owners = [];
    $rows = [];
    foreach ($assets as $asset) {
        if (!is_array($asset) || !isset($asset['id']) || (int)$asset['id'] < 1) throw new RuntimeException('O backup precisa trazer um identificador válido para cada ativo.');
        foreach (restore_patrimony_tokens((string)($asset['patrimony'] ?? '')) as $token) {
            if (isset($owners[$token])) continue;
            $owners[$token] = (int)$asset['id'];
            $rows[] = ['patrimony' => $token, 'assetId' => (int)$asset['id']];
        }
    }
    return $rows;
}

function normalize_restore_asset_deletion_audits(array $records): array
{
    $normalized = [];
    foreach ($records as $record) {
        if (!is_array($record)) throw new RuntimeException('Registro do histórico de exclusões inválido no backup.');
        $id = filter_var($record['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $assetId = filter_var($record['assetId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $assetId === false) throw new RuntimeException('O histórico de exclusões precisa trazer identificadores válidos.');
        $classification = (string)($record['classification'] ?? '');
        $assetStatus = (string)($record['assetStatus'] ?? '');
        if (!in_array($classification, ['fixed', 'small'], true) || !in_array($assetStatus, ['active', 'disposed'], true)) throw new RuntimeException('Classe ou status inválido no histórico de exclusões.');
        $reason = text_value($record['reason'] ?? '', 2000);
        if ($reason === '') throw new RuntimeException('O histórico de exclusões contém um motivo vazio.');
        $deletedAt = normalize_mysql_datetime($record['deletedAt'] ?? null);
        $deletedAtObject = $deletedAt === null ? false : DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $deletedAt);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$deletedAtObject || $deletedAtObject->format('Y-m-d H:i:s') !== $deletedAt || ($dateErrors !== false && ((int)$dateErrors['warning_count'] > 0 || (int)$dateErrors['error_count'] > 0))) throw new RuntimeException('O histórico de exclusões contém uma data inválida.');
        $invoiceId = null;
        if (($record['invoiceId'] ?? null) !== null && ($record['invoiceId'] ?? '') !== '') {
            $invoiceId = filter_var($record['invoiceId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($invoiceId === false) throw new RuntimeException('O histórico de exclusões contém um vínculo de NF-e inválido.');
        }
        $deletedByAdminId = null;
        if (($record['deletedByAdminId'] ?? null) !== null && ($record['deletedByAdminId'] ?? '') !== '') {
            $deletedByAdminId = filter_var($record['deletedByAdminId'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($deletedByAdminId === false) throw new RuntimeException('O histórico de exclusões contém um administrador inválido.');
        }
        $deletedByUsername = text_value($record['deletedByUsername'] ?? '', 80);
        if ($deletedByUsername === '') throw new RuntimeException('O histórico de exclusões não identifica quem realizou a ação.');
        $normalized[] = [
            'id' => (int)$id,
            'assetId' => (int)$assetId,
            'invoiceId' => $invoiceId === null ? null : (int)$invoiceId,
            'assetName' => text_value($record['assetName'] ?? '', 5000),
            'classification' => $classification,
            'category' => text_value($record['category'] ?? '', 255),
            'location' => text_value($record['location'] ?? '', 255),
            'patrimony' => text_value($record['patrimony'] ?? '', 5000),
            'invoiceNumber' => text_value($record['invoiceNumber'] ?? '', 64),
            'assetStatus' => $assetStatus,
            'reason' => $reason,
            'deletedByAdminId' => $deletedByAdminId === null ? null : (int)$deletedByAdminId,
            'deletedByUsername' => $deletedByUsername,
            'deletedAt' => $deletedAt,
        ];
    }
    return $normalized;
}

function restore_status(): array
{
    $pdo = db();
    $assets = (int)$pdo->query('SELECT COUNT(*) FROM assets')->fetchColumn();
    $invoices = (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
    $catalogs = (int)$pdo->query('SELECT COUNT(*) FROM catalogs')->fetchColumn();
    $imports = (int)$pdo->query('SELECT COUNT(*) FROM source_imports')->fetchColumn();
    $registry = (int)$pdo->query('SELECT COUNT(*) FROM asset_patrimony_registry')->fetchColumn();
    $deletionAudits = (int)$pdo->query('SELECT COUNT(*) FROM asset_deletion_audit')->fetchColumn();
    return ['empty' => $assets === 0 && $invoices === 0 && $catalogs === 0 && $imports === 0 && $registry === 0 && $deletionAudits === 0, 'assets' => $assets, 'invoices' => $invoices, 'catalogs' => $catalogs, 'patrimonyRegistry' => $registry, 'assetDeletionAudits' => $deletionAudits];
}

function restore_json_input(): array
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 2_000_000) throw new RuntimeException('O bloco recebido ultrapassou o tamanho permitido.');
    $raw = file_get_contents('php://input');
    if (($_SERVER['HTTP_CONTENT_ENCODING'] ?? '') === 'gzip') {
        if (!function_exists('gzdecode')) throw new RuntimeException('Descompactação não disponível nesta hospedagem.');
        $raw = gzdecode($raw ?: '');
    }
    $data = json_decode((string)$raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new RuntimeException('Conteúdo JSON inválido.');
    return $data;
}

function restore_api(string $action): never
{
    require_restore_permissions();
    if ($action === 'restore-check') {
        require_post();
        verify_csrf_header_or_form();
        $status = restore_status();
        json_response(['ok' => true, 'status' => $status]);
    }
    if ($action === 'restore-start') {
        require_post();
        verify_csrf_header_or_form();
        $data = restore_json_input();
        $status = restore_status();
        if (!$status['empty']) json_response(['error' => 'A restauração só funciona quando não há ativos, notas, padrões ou histórico de exclusões cadastrados.'], 409);
        $counts = $data['counts'] ?? null;
        if (!is_array($counts) || (int)($counts['assets'] ?? -1) < 0 || (int)($counts['invoices'] ?? -1) < 0 || (int)($counts['assetDeletionAudits'] ?? 0) < 0) json_response(['error' => 'O manifesto do backup não contém contagens válidas.'], 422);
        $previousBatch = (string)($_SESSION['restore_batch'] ?? '');
        if ($previousBatch !== '') db()->prepare('DELETE FROM restore_stage WHERE batchId = ?')->execute([$previousBatch]);
        db()->exec('DELETE FROM restore_stage WHERE createdAt < DATE_SUB(NOW(), INTERVAL 1 DAY)');
        $batch = bin2hex(random_bytes(16));
        $source = preg_replace('/[^a-zA-Z0-9_.-]+/', '-', (string)($data['source'] ?? 'backup')) ?: 'backup';
        $_SESSION['restore_batch'] = $batch;
        $_SESSION['restore_source'] = substr($source, 0, 120);
        $_SESSION['restore_counts'] = [
            'assets' => (int)$counts['assets'],
            'invoices' => (int)$counts['invoices'],
            'invoiceItems' => (int)($counts['invoiceItems'] ?? 0),
            'categories' => (int)($counts['categories'] ?? 0),
            'locations' => (int)($counts['locations'] ?? 0),
            'assetDeletionAudits' => (int)($counts['assetDeletionAudits'] ?? 0),
        ];
        db()->prepare('DELETE FROM restore_stage WHERE batchId = ?')->execute([$batch]);
        json_response(['ok' => true, 'token' => $batch, 'chunkSize' => 100]);
    }
    if ($action === 'restore-chunk') {
        require_post();
        verify_csrf_header_or_form();
        $data = restore_json_input();
        $batch = (string)($_SESSION['restore_batch'] ?? '');
        if (!$batch || !hash_equals($batch, (string)($data['token'] ?? ''))) json_response(['error' => 'Sessão de restauração inválida. Recomece o processo.'], 403);
        $kind = (string)($data['kind'] ?? '');
        $chunkNo = filter_var($data['chunkNo'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $records = $data['records'] ?? null;
        if (!in_array($kind, ['assets', 'invoices', 'catalogs', 'deletions'], true) || $chunkNo === false || !is_array($records) || count($records) > 100) json_response(['error' => 'Bloco de dados inválido.'], 422);
        $records = normalize_restore_records($kind, $records);
        $payload = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        if (strlen($payload) > 1_500_000) json_response(['error' => 'Um bloco ultrapassou o limite de tamanho.'], 413);
        $statement = db()->prepare('INSERT INTO restore_stage (batchId, kind, chunkNo, payload) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload)');
        $statement->execute([$batch, $kind, $chunkNo, $payload]);
        json_response(['ok' => true, 'kind' => $kind, 'chunkNo' => (int)$chunkNo, 'count' => count($records)]);
    }
    if ($action === 'restore-finish') {
        require_post();
        verify_csrf_header_or_form();
        $data = restore_json_input();
        $batch = (string)($_SESSION['restore_batch'] ?? '');
        if (!$batch || !hash_equals($batch, (string)($data['token'] ?? ''))) json_response(['error' => 'Sessão de restauração inválida. Recomece o processo.'], 403);
        $expected = $_SESSION['restore_counts'] ?? [];
        $pdo = db();
        $status = restore_status();
        if (!$status['empty']) json_response(['error' => 'O banco deixou de estar vazio, inclusive no histórico de exclusões; nenhum registro foi importado.'], 409);
        $query = $pdo->prepare('SELECT kind, chunkNo, payload FROM restore_stage WHERE batchId = ? ORDER BY kind, chunkNo');
        $query->execute([$batch]);
        $staged = ['assets' => [], 'invoices' => [], 'catalogs' => [], 'deletions' => []];
        while ($row = $query->fetch()) {
            $records = json_decode((string)$row['payload'], true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($records)) throw new RuntimeException('Bloco temporário inválido.');
            array_push($staged[$row['kind']], ...$records);
        }
        $staged['assets'] = normalize_restore_records('assets', $staged['assets']);
        $staged['invoices'] = normalize_restore_records('invoices', $staged['invoices']);
        $staged['catalogs'] = normalize_restore_records('catalogs', $staged['catalogs']);
        $staged['deletions'] = normalize_restore_asset_deletion_audits($staged['deletions']);
        $receivedCatalogEntries = count($staged['catalogs']);
        $staged['catalogs'] = deduplicate_restore_catalogs($staged['catalogs']);
        $mergedCatalogEntries = $receivedCatalogEntries - count($staged['catalogs']);
        $itemCount = 0;
        foreach ($staged['invoices'] as $invoice) $itemCount += count(is_array($invoice['items'] ?? null) ? $invoice['items'] : []);
        $categoryCount = count(array_filter($staged['catalogs'], static fn($r) => ($r['kind'] ?? '') === 'category'));
        $locationCount = count(array_filter($staged['catalogs'], static fn($r) => ($r['kind'] ?? '') === 'location'));
        if (count($staged['assets']) !== (int)($expected['assets'] ?? -1) || count($staged['invoices']) !== (int)($expected['invoices'] ?? -1)) json_response(['error' => 'A quantidade recebida difere do manifesto; nada foi aplicado.'], 422);
        if (count($staged['deletions']) !== (int)($expected['assetDeletionAudits'] ?? 0)) json_response(['error' => 'A quantidade do histórico de exclusões difere do manifesto; nada foi aplicado.'], 422);
        if (isset($expected['invoiceItems']) && $itemCount !== (int)$expected['invoiceItems']) json_response(['error' => 'A quantidade de itens das notas difere do manifesto; nada foi aplicado.'], 422);
        if ($categoryCount !== (int)($expected['categories'] ?? $categoryCount) || $locationCount !== (int)($expected['locations'] ?? $locationCount)) json_response(['error' => 'Os padrões recebidos diferem do manifesto; nada foi aplicado.'], 422);

        $registryRows = build_restore_patrimony_registry_rows($staged['assets']);
        try {
            $pdo->beginTransaction();
            $assetColumns = ['id','sourceSheet','sourceRow','legacyId','classification','name','originalDescription','category','location','patrimony','brand','color','type','condition','invoiceNumber','invoiceId','acquiredAt','quantity','value','usefulLife','purchaseYear','status','disposalDate','disposalReason','notes','original','createdAt','updatedAt'];
            foreach (array_chunk($staged['assets'], 100) as $chunk) {
                $rows = [];
                foreach ($chunk as $asset) {
                    if (!is_array($asset) || !in_array(($asset['classification'] ?? ''), ['fixed','small'], true)) throw new RuntimeException('Classe de ativo inválida no backup.');
                    $row = [];
                    foreach ($assetColumns as $col) {
                        if ($col === 'id' && (!isset($asset['id']) || (int)$asset['id'] < 1)) continue;
                        $row[$col] = match ($col) {
                            'sourceRow','invoiceId','usefulLife','purchaseYear' => isset($asset[$col]) && $asset[$col] !== '' ? (int)$asset[$col] : null,
                            'quantity','value' => isset($asset[$col]) && $asset[$col] !== '' ? (string)$asset[$col] : null,
                            'original' => json_preserve($asset[$col] ?? null),
                            'createdAt','updatedAt' => normalize_mysql_datetime($asset[$col] ?? null),
                            default => isset($asset[$col]) ? (string)$asset[$col] : '',
                        };
                    }
                    $row['classification'] = (string)$asset['classification'];
                    $row['status'] = in_array(($asset['status'] ?? ''), ['active','disposed'], true) ? $asset['status'] : 'active';
                    $row['invoiceId'] = isset($asset['invoiceId']) && (int)$asset['invoiceId'] > 0 ? (int)$asset['invoiceId'] : null;
                    $row['sourceRow'] = isset($asset['sourceRow']) && (int)$asset['sourceRow'] > 0 ? (int)$asset['sourceRow'] : null;
                    $row['usefulLife'] = isset($asset['usefulLife']) && $asset['usefulLife'] !== '' ? (int)$asset['usefulLife'] : null;
                    $row['purchaseYear'] = isset($asset['purchaseYear']) && $asset['purchaseYear'] !== '' ? (int)$asset['purchaseYear'] : null;
                    $row['createdAt'] = $row['createdAt'] ?: date('Y-m-d H:i:s');
                    $row['updatedAt'] = $row['updatedAt'] ?: $row['createdAt'];
                    $rows[] = $row;
                }
                pdo_insert_many($pdo, 'assets', $rows, $assetColumns);
            }
            foreach (array_chunk($registryRows, 100) as $chunk) pdo_insert_many($pdo, 'asset_patrimony_registry', $chunk, ['patrimony','assetId'], true);

            $invoiceColumns = ['id','accessKey','number','series','issuedAt','supplier','supplierCnpj','recipient','recipientCnpj','total','status','source','xmlStatus','xml','notes','createdAt','updatedAt'];
            $itemColumns = ['invoiceId','id','originalDescription','name','quantity','unit','unitValue','total','ncm','cfop','classification','category','location','patrimony','brand','color','type','condition','usefulLife','notes'];
            foreach ($staged['invoices'] as $invoice) {
                if (!is_array($invoice)) throw new RuntimeException('NF-e inválida no backup.');
                $invoiceId = isset($invoice['id']) && (int)$invoice['id'] > 0 ? (int)$invoice['id'] : null;
                if ($invoiceId === null) throw new RuntimeException('O backup precisa trazer o identificador das NF-es para preservar os vínculos.');
                $header = [];
                foreach ($invoiceColumns as $col) {
                    if ($col === 'id') { $header[$col] = $invoiceId; continue; }
                    $header[$col] = match ($col) {
                        'accessKey' => !empty($invoice[$col]) ? substr(nfe_digits((string)$invoice[$col]), 0, 44) : null,
                        'total' => number_format((float)($invoice[$col] ?? 0), 2, '.', ''),
                        'source' => ($invoice[$col] ?? '') === 'xml' ? 'xml' : 'manual',
                        'status' => ($invoice[$col] ?? '') === 'registered' ? 'registered' : 'draft',
                        'createdAt','updatedAt' => normalize_mysql_datetime($invoice[$col] ?? null),
                        default => isset($invoice[$col]) ? (string)$invoice[$col] : '',
                    };
                }
                $header['createdAt'] = $header['createdAt'] ?: date('Y-m-d H:i:s');
                $header['updatedAt'] = $header['updatedAt'] ?: $header['createdAt'];
                pdo_insert_many($pdo, 'invoices', [$header], $invoiceColumns);
                $items = is_array($invoice['items'] ?? null) ? $invoice['items'] : [];
                $itemRows = [];
                foreach ($items as $item) {
                    if (!is_array($item)) throw new RuntimeException('Item de NF-e inválido no backup.');
                    $row = ['invoiceId' => $invoiceId];
                    foreach (array_slice($itemColumns, 1) as $col) {
                        $row[$col] = match ($col) {
                            'quantity' => number_format((float)($item[$col] ?? 0), 3, '.', ''),
                            'unitValue' => number_format((float)($item[$col] ?? 0), 6, '.', ''),
                            'total' => number_format((float)($item[$col] ?? 0), 2, '.', ''),
                            'usefulLife' => (int)($item[$col] ?? 5),
                            'classification' => in_array(($item[$col] ?? ''), ['fixed','small','skip'], true) ? $item[$col] : 'fixed',
                            default => isset($item[$col]) ? (string)$item[$col] : '',
                        };
                    }
                    $row['id'] = (string)($item['id'] ?? '1');
                    $itemRows[] = $row;
                }
                if ($itemRows) pdo_insert_many($pdo, 'invoice_items', $itemRows, $itemColumns);
            }
            foreach (array_chunk($staged['catalogs'], 100) as $chunk) {
                $rows = [];
                foreach ($chunk as $catalog) {
                    if (!is_array($catalog) || !in_array(($catalog['kind'] ?? ''), ['category','location'], true)) throw new RuntimeException('Padrão de cadastro inválido no backup.');
                    $rows[] = ['kind' => $catalog['kind'], 'value' => trim((string)($catalog['value'] ?? ''))];
                }
                if ($rows) pdo_insert_many($pdo, 'catalogs', $rows, ['kind','value'], true);
            }
            $deletionColumns = ['id','assetId','invoiceId','assetName','classification','category','location','patrimony','invoiceNumber','assetStatus','reason','deletedByAdminId','deletedByUsername','deletedAt'];
            foreach (array_chunk($staged['deletions'], 100) as $chunk) pdo_insert_many($pdo, 'asset_deletion_audit', $chunk, $deletionColumns);
            $pdo->prepare('INSERT INTO source_imports (sourceFile) VALUES (?)')->execute([(string)($_SESSION['restore_source'] ?? 'backup Ativa')]);
            $pdo->prepare('DELETE FROM restore_stage WHERE batchId = ?')->execute([$batch]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION['restore_batch'], $_SESSION['restore_source'], $_SESSION['restore_counts']);
        json_response(['ok' => true, 'assets' => count($staged['assets']), 'invoices' => count($staged['invoices']), 'invoiceItems' => $itemCount, 'categories' => $categoryCount, 'locations' => $locationCount, 'assetDeletionAudits' => count($staged['deletions']), 'mergedCatalogEntries' => $mergedCatalogEntries]);
    }
    json_response(['error' => 'Ação de restauração desconhecida.'], 404);
}

function pdo_insert_many(PDO $pdo, string $table, array $rows, array $columns, bool $ignore = false): void
{
    $allowed = ['assets','invoices','invoice_items','catalogs','asset_patrimony_registry','asset_deletion_audit'];
    if (!in_array($table, $allowed, true) || !$rows) return;
    foreach ($columns as $column) if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $column)) throw new RuntimeException('Campo de banco inválido.');
    foreach (array_chunk($rows, 80) as $chunk) {
        $quoted = implode(',', array_map(static fn(string $col): string => '`' . $col . '`', $columns));
        $tuple = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $sql = 'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO `' . $table . '` (' . $quoted . ') VALUES ' . implode(',', array_fill(0, count($chunk), $tuple));
        $values = [];
        foreach ($chunk as $row) foreach ($columns as $column) $values[] = $row[$column] ?? null;
        $pdo->prepare($sql)->execute($values);
    }
}
