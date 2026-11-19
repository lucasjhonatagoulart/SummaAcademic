<?php

declare(strict_types=1);

function parse_decimal_input(mixed $value, string $label): float
{
    $text = trim((string)$value);
    if ($text === '') return 0.0;
    $text = str_replace(['R$', ' '], '', $text);
    if (str_contains($text, ',')) $text = str_replace(',', '.', str_replace('.', '', $text));
    if (!is_numeric($text)) throw new RuntimeException($label . ' inválido.');
    $number = (float)$text;
    if (!is_finite($number) || $number < 0) throw new RuntimeException($label . ' deve ser zero ou maior.');
    return $number;
}

function split_patrimony(string $value): array
{
    return array_values(array_filter(array_map('trim', preg_split('/[\/,\n\r]+/u', $value) ?: []), static fn(string $v): bool => $v !== ''));
}

function invoice_registration_item_tokens(array $item, int $index, bool $xmlImport): array
{
    if (trim((string)($item['name'] ?? '')) === '') throw new RuntimeException('Item ' . $index . ' exige nome.');
    if (trim((string)($item['category'] ?? '')) === '') {
        $required = $xmlImport ? 'conta patrimonial' : 'conta patrimonial e local';
        throw new RuntimeException('Item ' . $index . ' exige ' . $required . '.');
    }
    if (!$xmlImport && trim((string)($item['location'] ?? '')) === '') throw new RuntimeException('Item ' . $index . ' exige conta patrimonial e local.');

    $tokens = split_patrimony((string)($item['patrimony'] ?? ''));
    if ($xmlImport && !$tokens) return [];
    if (count($tokens) !== (int)($item['quantity'] ?? 0)) throw new RuntimeException('Informe exatamente um patrimônio por unidade no item ' . $index . '.');
    foreach ($tokens as $token) if (utf8_length($token) > 255) throw new RuntimeException('Número de patrimônio longo demais.');
    return $tokens;
}

function invoice_asset_allocations(array $item, bool $allowUnnumbered): array
{
    $tokens = split_patrimony((string)($item['patrimony'] ?? ''));
    $totalCents = (int)round((float)($item['total'] ?? 0) * 100);
    if (!$tokens) {
        $lineQuantity = (float)($item['quantity'] ?? 0);
        if (!$allowUnnumbered || $lineQuantity <= 0 || floor($lineQuantity) !== $lineQuantity) throw new RuntimeException('Informe exatamente um patrimônio por unidade.');
        return [['token' => '', 'quantity' => $lineQuantity, 'valueCents' => $totalCents]];
    }

    $unitCount = count($tokens);
    $baseCents = intdiv($totalCents, $unitCount);
    $allocations = [];
    foreach ($tokens as $unitIndex => $token) {
        $allocations[] = [
            'token' => $token,
            'quantity' => 1.0,
            'valueCents' => $baseCents + ($unitIndex === $unitCount - 1 ? $totalCents - $baseCents * $unitCount : 0),
        ];
    }
    return $allocations;
}

function create_admin_action(): never
{
    $credentials = validate_admin_credentials($_POST['username'] ?? '', $_POST['password'] ?? '', $_POST['password_confirm'] ?? '');
    $permissions = validate_permission_selection($_POST['permissions'] ?? []);
    $password = $credentials['password'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) throw new RuntimeException('Não foi possível proteger a senha do administrador.');
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
        $statement->execute([$credentials['username'], $passwordHash]);
        $adminId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO admin_permission_seeded (admin_id) VALUES (?)')->execute([$adminId]);
        $grant = $pdo->prepare('INSERT INTO admin_permissions (admin_id, permission) VALUES (?, ?)');
        foreach ($permissions as $permission) $grant->execute([$adminId, $permission]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $sqlState = (string)($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($sqlState === '23000' && ($driverCode === 0 || $driverCode === 1062)) throw new RuntimeException('Este nome de usuário já está em uso. Escolha outro.');
        throw new RuntimeException('Não foi possível criar o administrador.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    flash_set('success', $permissions ? 'Usuário criado com as permissões selecionadas.' : 'Usuário criado em modo somente visualização.');
    redirect_to('control-panel', ['section' => 'users']);
}

function update_admin_permissions_action(): never
{
    $targetId = positive_int($_POST['admin_id'] ?? 0, 'Usuário');
    if ($targetId === (int)($_SESSION['admin_id'] ?? 0)) throw new RuntimeException('Por segurança, esta tela não permite alterar as próprias permissões.');
    $requested = validate_permission_selection($_POST['permissions'] ?? []);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->query('SELECT id FROM admins ORDER BY id FOR UPDATE')->fetchAll();
        $exists = $pdo->prepare('SELECT id FROM admins WHERE id = ? LIMIT 1');
        $exists->execute([$targetId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('Usuário não encontrado.');

        $oldPermissions = admin_permissions_for($targetId);
        foreach ($oldPermissions as $permission) {
            if (!can($permission) && !in_array($permission, $requested, true)) $requested[] = $permission;
        }
        if (in_array('manage_users', $oldPermissions, true) && !in_array('manage_users', $requested, true)) {
            $managerCount = (int)$pdo->query("SELECT COUNT(DISTINCT admin_id) FROM admin_permissions WHERE permission = 'manage_users'")->fetchColumn();
            if ($managerCount <= 1) throw new RuntimeException('Não é possível remover a permissão do último usuário que administra contas.');
        }

        $pdo->prepare('DELETE FROM admin_permissions WHERE admin_id = ?')->execute([$targetId]);
        $grant = $pdo->prepare('INSERT INTO admin_permissions (admin_id, permission) VALUES (?, ?)');
        foreach ($requested as $permission) $grant->execute([$targetId, $permission]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    flash_set('success', 'Permissões do usuário atualizadas.');
    redirect_to('control-panel', ['section' => 'users']);
}

function validate_invoice_registration(array $invoice, array $items, ?int $excludeId = null): array
{
    $key = nfe_digits((string)($invoice['accessKey'] ?? ''));
    if (!nfe_valid_key($key)) throw new RuntimeException('O registro exige uma chave de acesso válida com 44 dígitos.');
    if (substr($key, 20, 2) !== '55') throw new RuntimeException('A chave precisa ser de NF-e modelo 55.');
    if (!preg_match('/^\d+$/', (string)($invoice['number'] ?? '')) || (int)$invoice['number'] !== (int)substr($key, 25, 9)) throw new RuntimeException('Número fiscal não confere com a chave.');
    if (!preg_match('/^\d+$/', (string)($invoice['series'] ?? '')) || (int)$invoice['series'] !== (int)substr($key, 22, 3)) throw new RuntimeException('Série fiscal não confere com a chave.');
    if (!nfe_valid_date((string)($invoice['issuedAt'] ?? ''))) throw new RuntimeException('Data de emissão inválida.');
    if (substr((string)$invoice['issuedAt'], 2, 2) . substr((string)$invoice['issuedAt'], 5, 2) !== substr($key, 2, 4)) throw new RuntimeException('Mês de emissão não confere com a chave.');
    if (!nfe_valid_cnpj((string)($invoice['supplierCnpj'] ?? '')) || nfe_digits((string)$invoice['supplierCnpj']) !== substr($key, 6, 14)) throw new RuntimeException('CNPJ do fornecedor inválido ou divergente da chave.');
    if (trim((string)($invoice['supplier'] ?? '')) === '') throw new RuntimeException('Informe o fornecedor.');
    if (!$items) throw new RuntimeException('A NF-e precisa ter ao menos um item.');
    if (($invoice['source'] ?? '') === 'xml') {
        if (empty($invoice['xml'])) throw new RuntimeException('O XML original desta nota não está disponível.');
        $parsed = nfe_parse_xml((string)$invoice['xml']);
        if (!in_array($parsed['protocolCode'], ['100','150'], true)) throw new RuntimeException('O XML não contém protocolo de autorização com cStat 100 ou 150; a nota deve permanecer como rascunho.');
    }

    $tokenOwners = [];
    $insertTokens = [];
    foreach ($items as $index => $item) {
        $quantity = (float)($item['quantity'] ?? 0);
        $unitValue = (float)($item['unitValue'] ?? 0);
        $total = (float)($item['total'] ?? 0);
        if (!is_finite($quantity) || !is_finite($unitValue) || !is_finite($total) || $quantity < 0 || $unitValue < 0 || $total < 0) throw new RuntimeException('Quantidade ou valores de item inválidos.');
        if (!in_array(($item['classification'] ?? ''), ['fixed','small','skip'], true)) throw new RuntimeException('Classificação de item inválida.');
        if ($quantity <= 0 || floor($quantity) !== $quantity) throw new RuntimeException('Itens registrados exigem quantidade inteira maior que zero.');
        if (abs($quantity * $unitValue - $total) > max(0.01, $quantity * 0.01)) throw new RuntimeException('O total do item ' . ($index + 1) . ' não confere com quantidade e valor unitário.');
        if ($item['classification'] === 'skip') continue;
        $tokens = invoice_registration_item_tokens($item, $index + 1, ($invoice['source'] ?? '') === 'xml');
        foreach ($tokens as $token) {
            if (isset($tokenOwners[$token])) throw new RuntimeException('Patrimônio repetido dentro da NF-e: ' . $token);
            $tokenOwners[$token] = true;
            $insertTokens[] = $token;
        }
    }
    if ($insertTokens) {
        $occupied = [];
        foreach (db()->query('SELECT id, patrimony FROM assets')->fetchAll() as $asset) {
            if ($excludeId !== null && (int)$asset['id'] === $excludeId) continue;
            foreach (split_patrimony((string)$asset['patrimony']) as $token) $occupied[$token] = true;
        }
        foreach (db()->query('SELECT patrimony FROM asset_patrimony_registry')->fetchAll(PDO::FETCH_COLUMN) as $token) $occupied[(string)$token] = true;
        foreach ($insertTokens as $token) if (isset($occupied[$token])) throw new RuntimeException('Patrimônio já utilizado: ' . $token);
    }
    $duplicate = db()->prepare('SELECT id FROM invoices WHERE accessKey = ?' . ($excludeId ? ' AND id <> ?' : '') . ' LIMIT 1');
    $duplicate->execute($excludeId ? [$key, $excludeId] : [$key]);
    if ($duplicate->fetch()) throw new RuntimeException('Já existe uma NF-e com essa chave de acesso.');
    return $insertTokens;
}

function catalog_value_exists(string $kind, string $value): bool
{
    $statement = db()->prepare('SELECT 1 FROM catalogs WHERE kind = ? AND value = ? LIMIT 1');
    $statement->execute([$kind, $value]);
    return (bool)$statement->fetchColumn();
}

function require_existing_invoice_catalog_values(array $items): void
{
    foreach ($items as $item) {
        foreach (['category' => 'conta patrimonial', 'location' => 'local'] as $field => $label) {
            $value = trim((string)($item[$field] ?? ''));
            if ($value !== '' && !catalog_value_exists($field, $value)) throw new RuntimeException('A ' . $label . ' ainda não está cadastrada. Use o Painel de controle ou deixe o campo em branco no rascunho.');
        }
    }
}

function form_invoice_items(): array
{
    $posted = $_POST['items'] ?? [];
    if (!is_array($posted)) throw new RuntimeException('Formato dos itens inválido.');
    $items = [];
    foreach ($posted as $index => $row) {
        if (!is_array($row)) continue;
        $name = text_value($row['name'] ?? '', 5000);
        $original = text_value($row['originalDescription'] ?? '', 5000);
        $quantityText = trim((string)($row['quantity'] ?? ''));
        $unitValueText = trim((string)($row['unitValue'] ?? ''));
        $totalText = trim((string)($row['total'] ?? ''));
        $class = (string)($row['classification'] ?? 'fixed');
        $patrimony = text_value($row['patrimony'] ?? '', 5000);
        if ($name === '' && $original === '' && $quantityText === '' && $unitValueText === '' && $totalText === '' && $patrimony === '') continue;
        if (!in_array($class, ['fixed','small','skip'], true)) $class = 'fixed';
        $items[] = [
            'id' => substr(text_value($row['id'] ?? ('item-' . ((int)$index + 1)), 96), 0, 96),
            'originalDescription' => $original !== '' ? $original : $name,
            'name' => $name,
            'quantity' => $quantityText === '' ? 0 : parse_decimal_input($quantityText, 'Quantidade'),
            'unit' => text_value($row['unit'] ?? '', 32),
            'unitValue' => parse_decimal_input($unitValueText, 'Valor unitário'),
            'total' => parse_decimal_input($totalText, 'Total do item'),
            'ncm' => text_value($row['ncm'] ?? '', 32),
            'cfop' => text_value($row['cfop'] ?? '', 32),
            'classification' => $class,
            'category' => text_value($row['category'] ?? '', 255),
            'location' => text_value($row['location'] ?? '', 255),
            'patrimony' => $patrimony,
            'brand' => text_value($row['brand'] ?? '', 255),
            'color' => text_value($row['color'] ?? '', 255),
            'type' => text_value($row['type'] ?? '', 255),
            'condition' => text_value($row['condition'] ?? 'Novo', 64) ?: 'Novo',
            'usefulLife' => max(0, (int)($row['usefulLife'] ?? 5)),
            'notes' => text_value($row['notes'] ?? '', 5000),
        ];
    }
    return $items;
}

function insert_invoice_items(PDO $pdo, int $invoiceId, array $items): void
{
    $statement = $pdo->prepare('INSERT INTO invoice_items (invoiceId,id,originalDescription,name,quantity,unit,unitValue,total,ncm,cfop,classification,category,location,patrimony,brand,color,type,`condition`,usefulLife,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($items as $item) $statement->execute([$invoiceId,$item['id'],$item['originalDescription'],$item['name'],number_format((float)$item['quantity'],3,'.',''),$item['unit'],number_format((float)$item['unitValue'],6,'.',''),number_format((float)$item['total'],2,'.',''),$item['ncm'],$item['cfop'],$item['classification'],$item['category'],$item['location'],$item['patrimony'],$item['brand'],$item['color'],$item['type'],$item['condition'],$item['usefulLife'],$item['notes']]);
}

function create_assets_from_invoice(PDO $pdo, int $invoiceId, array $invoice, array $items): void
{
    $insert = $pdo->prepare('INSERT INTO assets (sourceSheet,sourceRow,classification,name,originalDescription,category,location,patrimony,brand,color,type,`condition`,invoiceNumber,invoiceId,acquiredAt,quantity,value,usefulLife,purchaseYear,status,disposalReason,notes,original) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $registry = $pdo->prepare('INSERT INTO asset_patrimony_registry (patrimony, assetId) VALUES (?, ?)');
    foreach ($items as $index => $item) {
        if ($item['classification'] === 'skip') continue;
        $allocations = invoice_asset_allocations($item, ($invoice['source'] ?? '') === 'xml');
        foreach ($allocations as $allocation) {
            $token = $allocation['token'];
            $insert->execute(['NF-e', $index + 1, $item['classification'], $item['name'], $item['originalDescription'], $item['category'], $item['location'], $token, $item['brand'], $item['color'], $item['type'], $item['condition'], $invoice['number'], $invoiceId, $invoice['issuedAt'], number_format((float)$allocation['quantity'], 3, '.', ''), number_format(((int)$allocation['valueCents']) / 100, 2, '.', ''), $item['usefulLife'], substr((string)$invoice['issuedAt'], 0, 4), 'active', '', $item['notes'], json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $assetId = (int)$pdo->lastInsertId();
            if ($token !== '') $registry->execute([$token, $assetId]);
        }
    }
}

function direct_asset_fields_from_post(array $input, array $catalogs): array
{
    $name = text_value($input['name'] ?? '', 5000);
    $category = text_value($input['category'] ?? '', 255);
    $location = text_value($input['location'] ?? '', 255);
    if ($name === '') throw new RuntimeException('Informe a descrição do ativo.');
    if ($category === '' || !in_array($category, array_map('strval', $catalogs['category'] ?? []), true)) throw new RuntimeException('Selecione uma Conta Patrimonial cadastrada.');
    if ($location === '' || !in_array($location, array_map('strval', $catalogs['location'] ?? []), true)) throw new RuntimeException('Selecione um Local cadastrado.');

    $patrimonyText = text_value($input['patrimony'] ?? '', 1000);
    $tokens = split_patrimony($patrimonyText);
    if (count($tokens) !== 1) throw new RuntimeException('Informe exatamente um código de patrimônio para este ativo.');
    $patrimony = $tokens[0];
    if (utf8_length($patrimony) > 255) throw new RuntimeException('Número de patrimônio longo demais.');

    $classification = text_value($input['classification'] ?? '', 16);
    if (!in_array($classification, ['fixed','small'], true)) throw new RuntimeException('Classificação de ativo inválida.');
    $acquiredAt = text_value($input['acquiredAt'] ?? '', 40);
    if ($acquiredAt !== '' && !nfe_valid_date($acquiredAt)) throw new RuntimeException('Informe uma data de aquisição válida.');
    $valueText = text_value($input['value'] ?? '', 40);
    $value = $valueText === '' ? null : parse_decimal_input($valueText, 'Valor de aquisição');
    if ($value !== null && $value > 9999999999999.99) throw new RuntimeException('Valor de aquisição ultrapassa o limite permitido.');
    $usefulLifeText = text_value($input['usefulLife'] ?? '', 8);
    $usefulLife = null;
    if ($usefulLifeText !== '') {
        $parsedLife = filter_var($usefulLifeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1000]]);
        if ($parsedLife === false) throw new RuntimeException('Vida útil inválida.');
        $usefulLife = (int)$parsedLife;
    }

    return [
        'sourceSheet' => 'Cadastro direto sem NF-e',
        'sourceRow' => null,
        'legacyId' => null,
        'classification' => $classification,
        'name' => $name,
        'originalDescription' => $name,
        'category' => $category,
        'location' => $location,
        'patrimony' => $patrimony,
        'brand' => text_value($input['brand'] ?? '', 255),
        'color' => text_value($input['color'] ?? '', 255),
        'type' => text_value($input['type'] ?? '', 255),
        'condition' => text_value($input['condition'] ?? '', 64) ?: 'Não informado',
        'invoiceNumber' => '',
        'invoiceId' => null,
        'acquiredAt' => $acquiredAt !== '' ? $acquiredAt : null,
        'quantity' => 1.0,
        'value' => $value,
        'usefulLife' => $usefulLife,
        'purchaseYear' => $acquiredAt !== '' ? (int)substr($acquiredAt, 0, 4) : null,
        'status' => 'active',
        'disposalDate' => null,
        'disposalReason' => '',
        'notes' => text_value($input['notes'] ?? '', 10000),
        'original' => null,
    ];
}

function create_asset_without_invoice_action(): never
{
    require_permission('manage_users');
    require_permission('edit_assets');
    $fields = direct_asset_fields_from_post($_POST, get_catalogs(false));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach (['category' => 'category', 'location' => 'location'] as $field => $kind) {
            $catalogLock = $pdo->prepare('SELECT value FROM catalogs WHERE kind = ? FOR UPDATE');
            $catalogLock->execute([$kind]);
            $catalogValues = array_map('strval', $catalogLock->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($fields[$field], $catalogValues, true)) throw new RuntimeException('A Conta Patrimonial ou o Local foi alterado. Recarregue o formulário e tente novamente.');
        }

        foreach ($pdo->query('SELECT patrimony FROM assets WHERE patrimony <> \'\'')->fetchAll(PDO::FETCH_COLUMN) as $existingValue) {
            foreach (split_patrimony((string)$existingValue) as $existingToken) {
                if (strcasecmp($existingToken, $fields['patrimony']) === 0) throw new RuntimeException('Patrimônio já utilizado: ' . $fields['patrimony']);
            }
        }
        $registryCheck = $pdo->prepare('SELECT assetId FROM asset_patrimony_registry WHERE patrimony = ? FOR UPDATE');
        $registryCheck->execute([$fields['patrimony']]);
        if ($registryCheck->fetchColumn() !== false) throw new RuntimeException('Patrimônio já utilizado: ' . $fields['patrimony']);

        $insert = $pdo->prepare('INSERT INTO assets (sourceSheet,sourceRow,legacyId,classification,name,originalDescription,category,location,patrimony,brand,color,type,`condition`,invoiceNumber,invoiceId,acquiredAt,quantity,value,usefulLife,purchaseYear,status,disposalDate,disposalReason,notes,original) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute([
            $fields['sourceSheet'], $fields['sourceRow'], $fields['legacyId'], $fields['classification'], $fields['name'],
            $fields['originalDescription'], $fields['category'], $fields['location'], $fields['patrimony'], $fields['brand'],
            $fields['color'], $fields['type'], $fields['condition'], $fields['invoiceNumber'], $fields['invoiceId'],
            $fields['acquiredAt'], '1.000', $fields['value'] === null ? null : number_format((float)$fields['value'], 2, '.', ''),
            $fields['usefulLife'], $fields['purchaseYear'], $fields['status'], $fields['disposalDate'],
            $fields['disposalReason'], $fields['notes'], $fields['original'],
        ]);
        $assetId = (int)$pdo->lastInsertId();
        $pdo->prepare('INSERT INTO asset_patrimony_registry (patrimony, assetId) VALUES (?, ?)')->execute([$fields['patrimony'], $assetId]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        if ($driverCode === 1062) throw new RuntimeException('Patrimônio já utilizado: ' . $fields['patrimony']);
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    flash_set('success', 'Ativo cadastrado sem NF-e; patrimônio e valor preservados.');
    redirect_to('asset', ['id' => $assetId]);
}

function save_invoice_action(): never
{
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_int($_POST['id'], 'NF-e') : null;
    $pdo = db();
    $existing = $id ? get_invoice($id) : null;
    if ($id && !$existing) throw new RuntimeException('NF-e não encontrada.');
    if ($existing && $existing['status'] === 'registered') throw new RuntimeException('NF-e incorporada não pode ser editada.');
    $source = (string)($_POST['source'] ?? ($existing['source'] ?? 'manual'));
    if (!in_array($source, ['manual','xml'], true)) $source = 'manual';
    $xml = $existing['xml'] ?? null;
    $buffer = invoice_import_buffer_for($id);
    if ($buffer) $xml = $buffer['xml'];
    $header = [
        'accessKey' => nfe_digits(text_value($_POST['accessKey'] ?? '', 60)),
        'number' => text_value($_POST['number'] ?? '', 32),
        'series' => text_value($_POST['series'] ?? '', 16),
        'issuedAt' => text_value($_POST['issuedAt'] ?? '', 40),
        'supplier' => text_value($_POST['supplier'] ?? '', 320),
        'supplierCnpj' => nfe_digits(text_value($_POST['supplierCnpj'] ?? '', 32)),
        'recipient' => text_value($_POST['recipient'] ?? '', 320),
        'recipientCnpj' => nfe_digits(text_value($_POST['recipientCnpj'] ?? '', 32)),
        'total' => $source === 'xml' ? 0.0 : parse_decimal_input($_POST['total'] ?? '', 'Total da nota'),
        'source' => $source,
        'xmlStatus' => $source === 'xml' ? (string)($existing['xmlStatus'] ?? '') : 'Sem consulta à SEFAZ',
        'xml' => $xml,
        'notes' => text_value($_POST['notes'] ?? '', 10000),
    ];
    $parsedXml = null;
    if ($source === 'xml') {
        if (!is_string($xml) || $xml === '') throw new RuntimeException('O XML original desta nota não está disponível. Importe o arquivo novamente.');
        $parsed = nfe_parse_xml((string)$xml);
        $parsedXml = $parsed;
        foreach (['accessKey','number','series','issuedAt','supplier','supplierCnpj','recipient','recipientCnpj','total','xmlStatus'] as $field) $header[$field] = $parsed[$field];
    }
    $items = $source === 'xml'
        ? invoice_items_from_xml_form($parsedXml['items'], $_POST['items'] ?? [])
        : form_invoice_items();
    require_existing_invoice_catalog_values($items);
    $intent = (string)($_POST['submit_intent'] ?? ($_POST['intent'] ?? 'draft'));
    $register = $intent === 'register';
    if ($register) {
        $willCreateAssets = (bool)array_filter($items, static fn(array $item): bool => ($item['classification'] ?? '') !== 'skip');
        if ($willCreateAssets) {
            require_permission('edit_assets');
            if (!can('manage_catalogs')) require_existing_invoice_catalog_values($items);
        }
        validate_invoice_registration($header, $items, $id);
    }

    $pdo->beginTransaction();
    try {
        if ($id) {
            $statement = $pdo->prepare('UPDATE invoices SET accessKey=?,number=?,series=?,issuedAt=?,supplier=?,supplierCnpj=?,recipient=?,recipientCnpj=?,total=?,status=?,source=?,xmlStatus=?,xml=?,notes=? WHERE id=? AND status=\'draft\'');
            $statement->execute([$header['accessKey'] !== '' ? $header['accessKey'] : null,$header['number'],$header['series'],$header['issuedAt'],$header['supplier'],$header['supplierCnpj'],$header['recipient'],$header['recipientCnpj'],number_format($header['total'],2,'.',''),$register?'registered':'draft',$source,$header['xmlStatus'],$header['xml'],$header['notes'],$id]);
            $pdo->prepare('DELETE FROM invoice_items WHERE invoiceId = ?')->execute([$id]);
            $invoiceId = $id;
        } else {
            $statement = $pdo->prepare('INSERT INTO invoices (accessKey,number,series,issuedAt,supplier,supplierCnpj,recipient,recipientCnpj,total,status,source,xmlStatus,xml,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $statement->execute([$header['accessKey'] !== '' ? $header['accessKey'] : null,$header['number'],$header['series'],$header['issuedAt'],$header['supplier'],$header['supplierCnpj'],$header['recipient'],$header['recipientCnpj'],number_format($header['total'],2,'.',''),$register?'registered':'draft',$source,$header['xmlStatus'],$xml,$header['notes']]);
            $invoiceId = (int)$pdo->lastInsertId();
        }
        insert_invoice_items($pdo, $invoiceId, $items);
        if ($register) create_assets_from_invoice($pdo, $invoiceId, $header, $items);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    invoice_import_buffer_clear();
    unset($_SESSION['invoice_form_old']);
    flash_set('success', $register ? 'NF-e registrada e itens incorporados ao controle.' : 'Rascunho da NF-e salvo.');
    redirect_to('invoice', ['id' => $invoiceId]);
}

function import_xml_action(): never
{
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? positive_int($_POST['id'], 'NF-e') : null;
    $existing = $id ? get_invoice($id) : null;
    if ($id && !$existing) throw new RuntimeException('NF-e não encontrada.');
    if ($existing && $existing['status'] === 'registered') throw new RuntimeException('NF-e incorporada não pode ser substituída.');
    if (!isset($_FILES['xml_file']) || !is_array($_FILES['xml_file'])) throw new RuntimeException('Selecione um arquivo XML.');
    $file = $_FILES['xml_file'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Falha no envio do XML. Confirme se o arquivo não supera 2 MB.');
    if ((int)$file['size'] > 2 * 1024 * 1024) throw new RuntimeException('O XML ultrapassa 2 MB.');
    if (!is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('O upload do XML não pôde ser verificado.');
    $xml = file_get_contents((string)$file['tmp_name']);
    if ($xml === false) throw new RuntimeException('Não foi possível ler o XML enviado.');
    $invoice = nfe_parse_xml($xml);
    $duplicate = db()->prepare('SELECT id FROM invoices WHERE accessKey = ?' . ($id ? ' AND id <> ?' : '') . ' LIMIT 1');
    $duplicate->execute($id ? [$invoice['accessKey'], $id] : [$invoice['accessKey']]);
    if ($duplicate->fetch()) throw new RuntimeException('Já existe uma NF-e com essa chave de acesso.');
    invoice_import_buffer_store($id, $invoice, $xml);
    flash_set('success', 'XML analisado. Revise os itens; a nota só será salva quando você escolher uma ação.');
    redirect_to($id ? 'invoice-edit' : 'new-invoice', array_filter(['id' => $id, 'step' => 2, 'buffer' => 1], static fn($value): bool => $value !== null));
}

function asset_edit_fields_from_post(array $input, array $asset): array
{
    $classification = text_value($input['classification'] ?? '', 16);
    if (!in_array($classification, ['fixed','small'], true)) throw new RuntimeException('Selecione Ativo Fixo ou Bem de pequeno valor.');

    $acquiredAt = text_value($input['acquiredAt'] ?? '', 40);
    $currentAcquiredAt = (string)($asset['acquiredAt'] ?? '');
    if ($acquiredAt !== '' && $acquiredAt !== $currentAcquiredAt && !nfe_valid_date($acquiredAt)) throw new RuntimeException('Informe a data de aquisição no formato AAAA-MM-DD.');

    $quantityText = trim((string)($input['quantity'] ?? ''));
    $quantity = $quantityText === '' ? null : parse_decimal_input($quantityText, 'Quantidade');
    if ($quantity !== null && $quantity > 999999999.999) throw new RuntimeException('Quantidade ultrapassa o limite permitido.');

    $valueText = trim((string)($input['value'] ?? ''));
    $value = $valueText === '' ? null : parse_decimal_input($valueText, 'Valor de aquisição');
    if ($value !== null && $value > 9999999999999.99) throw new RuntimeException('Valor de aquisição ultrapassa o limite permitido.');

    $yearText = trim((string)($input['purchaseYear'] ?? ''));
    $purchaseYear = null;
    if ($yearText !== '') {
        $purchaseYear = filter_var($yearText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 9999]]);
        if ($purchaseYear === false) throw new RuntimeException('Ano da compra inválido.');
    }

    $lifeText = trim((string)($input['usefulLife'] ?? ''));
    $usefulLife = null;
    if ($lifeText !== '') {
        $usefulLife = filter_var($lifeText, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        if ($usefulLife === false) throw new RuntimeException('Vida útil inválida.');
    }

    return [
        'classification' => $classification,
        'name' => text_value($input['name'] ?? '', 5000),
        'category' => ($input['category'] ?? '') === '__new__' ? text_value($input['category_new'] ?? '', 255) : text_value($input['category'] ?? '', 255),
        'location' => text_value($input['location'] ?? '', 255),
        'patrimony' => text_value($input['patrimony'] ?? '', 5000),
        'brand' => text_value($input['brand'] ?? '', 255),
        'color' => text_value($input['color'] ?? '', 255),
        'type' => text_value($input['type'] ?? '', 255),
        'condition' => text_value($input['condition'] ?? '', 64),
        'acquiredAt' => $acquiredAt !== '' ? $acquiredAt : null,
        'quantity' => $quantity,
        'value' => $value,
        'usefulLife' => $usefulLife,
        'purchaseYear' => $purchaseYear === false ? null : $purchaseYear,
        'notes' => text_value($input['notes'] ?? '', 10000),
    ];
}

/** Filtros e página da lista de ativos que a pessoa estava vendo, para voltar ao mesmo ponto depois de editar ou excluir */
function assets_return_query(): array
{
    $query = $_SESSION['assets_list_query'] ?? [];
    return is_array($query) ? array_filter($query, static fn($v): bool => is_scalar($v) && (string)$v !== '') : [];
}

function save_asset_action(): never
{
    $id = positive_int($_POST['id'] ?? 0, 'Ativo');
    $asset = get_asset($id);
    if (!$asset) throw new RuntimeException('Ativo não encontrado.');
    $isDirectNoInvoice = (string)($asset['sourceSheet'] ?? '') === 'Cadastro direto sem NF-e'
        && trim((string)($asset['invoiceNumber'] ?? '')) === ''
        && empty($asset['invoiceId']);
    $fields = asset_edit_fields_from_post($_POST, $asset);
    if ($fields['name'] === '' || $fields['category'] === '') throw new RuntimeException('Informe o nome e a Conta Patrimonial.');
    foreach (['location' => 'local'] as $field => $kind) {
        if ($field === 'location' && $fields[$field] === '') continue;
        if ($fields[$field] !== (string)($asset[$field] ?? '') && !catalog_value_exists($kind, $fields[$field])) throw new RuntimeException('Selecione uma ' . $kind . ' já cadastrada. Para acrescentar outra, use o Painel de controle.');
    }
    $tokens = split_patrimony($fields['patrimony']);
    $uniqueTokens = array_values(array_unique($tokens));
    if (count($uniqueTokens) !== count($tokens)) throw new RuntimeException('O mesmo número de patrimônio foi repetido mais de uma vez neste ativo.');
    foreach ($uniqueTokens as $token) if (utf8_length($token) > 255) throw new RuntimeException('Número de patrimônio longo demais.');
    $newTokenSet = array_fill_keys($uniqueTokens, true);
    $currentTokens = array_fill_keys(split_patrimony((string)($asset['patrimony'] ?? '')), true);
    $other = db()->query('SELECT id, patrimony FROM assets')->fetchAll();
    $occupied = [];
    $otherAssetOwners = [];
    foreach ($other as $row) if ((int)$row['id'] !== $id) foreach (split_patrimony((string)$row['patrimony']) as $token) {
        $occupied[$token] = true;
        $otherAssetOwners[$token] ??= (int)$row['id'];
    }
    $registryOwners = [];
    foreach (db()->query('SELECT patrimony, assetId FROM asset_patrimony_registry')->fetchAll() as $row) {
        $token = (string)$row['patrimony'];
        $ownerId = (int)$row['assetId'];
        $registryOwners[$token] = $ownerId;
        if ($ownerId !== $id) $occupied[$token] = true;
    }
    foreach ($uniqueTokens as $token) if (isset($occupied[$token]) && !isset($currentTokens[$token])) throw new RuntimeException('Patrimônio já utilizado: ' . $token);
    $previouslyOwnedTokens = array_keys(array_filter($registryOwners, static fn(int $ownerId): bool => $ownerId === $id));
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $categoryQuery = $pdo->prepare('SELECT value FROM catalogs WHERE kind = ? FOR UPDATE');
        $categoryQuery->execute(['category']);
        $categorySelection = asset_category_edit_selection(
            $fields['category'],
            array_map('strval', $categoryQuery->fetchAll(PDO::FETCH_COLUMN)),
            (string)($asset['category'] ?? ''),
            can('manage_catalogs')
        );
        $fields['category'] = $categorySelection['value'];
        if ($categorySelection['create']) {
            $insertCategory = $pdo->prepare('INSERT INTO catalogs (kind, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            $insertCategory->execute(['category', $fields['category']]);
        }
        $updateColumns = 'classification=?,name=?,category=?,location=?,patrimony=?,brand=?,color=?,type=?,`condition`=?,acquiredAt=?,quantity=?,value=?,usefulLife=?,purchaseYear=?,notes=?';
        $updateValues = [$fields['classification'],$fields['name'],$fields['category'],$fields['location'],$fields['patrimony'],$fields['brand'],$fields['color'],$fields['type'],$fields['condition'],$fields['acquiredAt'],$fields['quantity'] === null ? null : number_format($fields['quantity'],3,'.',''),$fields['value'] === null ? null : number_format($fields['value'],2,'.',''),$fields['usefulLife'],$fields['purchaseYear'],$fields['notes']];
        if ($isDirectNoInvoice) { $updateColumns .= ',originalDescription=?'; $updateValues[] = $fields['name']; }
        $updateValues[] = $id;
        $stmt = $pdo->prepare('UPDATE assets SET ' . $updateColumns . ' WHERE id=?');
        $stmt->execute($updateValues);
        $pdo->prepare('DELETE FROM asset_patrimony_registry WHERE assetId = ?')->execute([$id]);
        foreach ($registryOwners as $token => $ownerId) if ($ownerId === $id) unset($registryOwners[$token]);
        $registry = $pdo->prepare('INSERT INTO asset_patrimony_registry (patrimony, assetId) VALUES (?, ?)');
        foreach ($uniqueTokens as $token) {
            if (isset($registryOwners[$token])) continue;
            $registry->execute([$token, $id]);
            $registryOwners[$token] = $id;
        }
        foreach ($previouslyOwnedTokens as $token) {
            if (isset($newTokenSet[$token]) || isset($registryOwners[$token]) || !isset($otherAssetOwners[$token])) continue;
            $registry->execute([$token, $otherAssetOwners[$token]]);
            $registryOwners[$token] = $otherAssetOwners[$token];
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    flash_set('success', 'Dados patrimoniais atualizados. Os dados originais da NF-e e a descrição de origem foram preservados.');
    redirect_to('assets', assets_return_query());
}

function dispose_asset_action(): never
{
    $id = positive_int($_POST['id'] ?? 0, 'Ativo');
    if (!get_asset($id)) throw new RuntimeException('Ativo não encontrado.');
    $date = text_value($_POST['disposalDate'] ?? '', 40);
    $reason = text_value($_POST['disposalReason'] ?? '', 2000);
    if (!nfe_valid_date($date)) throw new RuntimeException('Informe uma data de baixa válida.');
    if ($reason === '') throw new RuntimeException('Informe o motivo da baixa.');
    db()->prepare('UPDATE assets SET status=\'disposed\', disposalDate=?, disposalReason=? WHERE id=?')->execute([$date,$reason,$id]);
    flash_set('success', 'Baixa registrada. O ativo e seu histórico foram preservados.');
    redirect_to('asset', ['id' => $id]);
}

function asset_deletion_audit_row(array $asset, int $adminId, string $adminUsername, string $reason): array
{
    $assetId = (int)($asset['id'] ?? 0);
    $classification = (string)($asset['classification'] ?? '');
    $status = (string)($asset['status'] ?? '');
    $reason = text_value($reason, 2000);
    if ($assetId < 1 || !in_array($classification, ['fixed', 'small'], true) || !in_array($status, ['active', 'disposed'], true)) throw new RuntimeException('O ativo não contém dados válidos para registrar a exclusão.');
    if ($reason === '') throw new RuntimeException('Informe o motivo da exclusão.');
    if ($adminId < 1 || text_value($adminUsername, 80) === '') throw new RuntimeException('Não foi possível identificar a conta administradora.');

    return [
        'assetId' => $assetId,
        'invoiceId' => isset($asset['invoiceId']) && (int)$asset['invoiceId'] > 0 ? (int)$asset['invoiceId'] : null,
        'assetName' => text_value($asset['name'] ?? '', 5000),
        'classification' => $classification,
        'category' => text_value($asset['category'] ?? '', 255),
        'location' => text_value($asset['location'] ?? '', 255),
        'patrimony' => text_value($asset['patrimony'] ?? '', 5000),
        'invoiceNumber' => text_value($asset['invoiceNumber'] ?? '', 64),
        'assetStatus' => $status,
        'reason' => $reason,
        'deletedByAdminId' => $adminId,
        'deletedByUsername' => text_value($adminUsername, 80),
    ];
}

function delete_asset_action(): never
{
    $id = positive_int($_POST['id'] ?? 0, 'Ativo');
    $reason = text_value($_POST['reason'] ?? '', 2000);
    if ($reason === '') throw new RuntimeException('Informe o motivo da exclusão.');

    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $assetStatement = $pdo->prepare('SELECT id, name, classification, category, location, patrimony, invoiceNumber, invoiceId, status FROM assets WHERE id = ? FOR UPDATE');
        $assetStatement->execute([$id]);
        $asset = $assetStatement->fetch();
        if (!$asset) throw new RuntimeException('Ativo não encontrado.');

        $adminStatement = $pdo->prepare('SELECT username FROM admins WHERE id = ? LIMIT 1 FOR UPDATE');
        $adminStatement->execute([$adminId]);
        $adminUsername = $adminStatement->fetchColumn();
        if (!is_string($adminUsername) || $adminUsername === '') throw new RuntimeException('Não foi possível identificar a conta administradora.');

        $audit = asset_deletion_audit_row($asset, $adminId, $adminUsername, $reason);
        $auditStatement = $pdo->prepare('INSERT INTO asset_deletion_audit (assetId, invoiceId, assetName, classification, category, location, patrimony, invoiceNumber, assetStatus, reason, deletedByAdminId, deletedByUsername) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $auditStatement->execute(array_values($audit));
        $pdo->prepare('DELETE FROM asset_patrimony_registry WHERE assetId = ?')->execute([$id]);
        $deleteStatement = $pdo->prepare('DELETE FROM assets WHERE id = ?');
        $deleteStatement->execute([$id]);
        if ($deleteStatement->rowCount() !== 1) throw new RuntimeException('O ativo não foi excluído. Recarregue a página e tente novamente.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    flash_set('success', 'Ativo excluído. O motivo ficou registrado no histórico administrativo; a NF-e e seus itens foram preservados.');
    redirect_to('assets', assets_return_query());
}

function catalog_action(string $action): never
{
    if ($action === 'catalog-add') {
        $kind = (string)($_POST['kind'] ?? '');
        $value = text_value($_POST['value'] ?? '', 255);
        if (!in_array($kind, ['category','location'], true) || $value === '') throw new RuntimeException('Tipo ou nome do padrão inválido.');
        if ($kind === 'category') {
            $value = canonical_restore_category($value);
            $key = normalized_catalog_category_key($value);
            foreach (get_catalogs(false)['category'] as $existing) {
                if (normalized_catalog_category_key((string)$existing) !== $key) continue;
                if ((string)$existing === $value) {
                    flash_set('success', 'A conta patrimonial já está cadastrada; use o padrão existente.');
                    redirect_to('control-panel', ['section' => 'catalogs']);
                }
                throw new RuntimeException('Já existe uma conta patrimonial equivalente: “' . (string)$existing . '”. Use o padrão existente para evitar duplicações.');
            }
        }
        db()->prepare('INSERT INTO catalogs (kind, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$kind,$value]);
        flash_set('success', 'Padrão adicionado.');
        redirect_to('control-panel', ['section' => 'catalogs']);
    }
    if ($action === 'catalog-edit') {
        $kind = (string)($_POST['kind'] ?? '');
        $oldValue = text_value($_POST['old_value'] ?? '', 255);
        $newValue = text_value($_POST['new_value'] ?? '', 255);
        if (!in_array($kind, ['category','location'], true) || $oldValue === '' || $newValue === '') throw new RuntimeException('Tipo ou nome do padrão inválido.');
        if ($kind === 'category') {
            $newValue = canonical_restore_category($newValue);
            if (normalized_catalog_category_key($newValue) === '') throw new RuntimeException('Informe uma conta patrimonial válida.');
        }

        $pdo = db();
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT value FROM catalogs WHERE kind = ? FOR UPDATE');
            $lock->execute([$kind]);
            $catalogValues = array_map('strval', $lock->fetchAll(PDO::FETCH_COLUMN));
            if (!in_array($oldValue, $catalogValues, true)) throw new RuntimeException('O padrão que deseja editar não está mais cadastrado. Atualize a página e tente novamente.');

            if ($newValue === $oldValue) {
                $pdo->commit();
                flash_set('success', 'Nenhuma alteração foi necessária.');
                redirect_to('control-panel', ['section' => 'catalogs']);
            }

            foreach ($catalogValues as $existing) {
                if ($existing === $oldValue) continue;
                $conflict = $kind === 'category'
                    ? normalized_catalog_category_key($existing) === normalized_catalog_category_key($newValue)
                    : $existing === $newValue;
                if ($conflict) {
                    if ($kind === 'category') throw new RuntimeException('Já existe uma conta patrimonial equivalente: “' . $existing . '”. Não é possível mesclar padrões durante a edição.');
                    throw new RuntimeException('Já existe um local com esse nome: “' . $existing . '”. Não é possível mesclar padrões durante a edição.');
                }
            }

            $field = $kind === 'category' ? 'category' : 'location';
            $rename = $pdo->prepare('UPDATE catalogs SET value = ? WHERE kind = ? AND value = ?');
            $rename->execute([$newValue, $kind, $oldValue]);
            if ($rename->rowCount() !== 1) throw new RuntimeException('O padrão não foi atualizado. Recarregue a página e tente novamente.');
            $assetReferences = $pdo->prepare('UPDATE assets SET `' . $field . '` = ? WHERE BINARY `' . $field . '` = BINARY ?');
            $assetReferences->execute([$newValue, $oldValue]);
            $invoiceReferences = $pdo->prepare('UPDATE invoice_items SET `' . $field . '` = ? WHERE BINARY `' . $field . '` = BINARY ?');
            $invoiceReferences->execute([$newValue, $oldValue]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        flash_set('success', $kind === 'category' ? 'Conta patrimonial e referências relacionadas atualizadas.' : 'Local e referências relacionadas atualizados.');
        redirect_to('control-panel', ['section' => 'catalogs']);
    }
    if ($action !== 'catalog-delete') throw new RuntimeException('Ação de catálogo inválida.');
    $kind = (string)($_POST['kind'] ?? '');
    $value = text_value($_POST['value'] ?? '', 255);
    if (!in_array($kind, ['category','location'], true) || $value === '') throw new RuntimeException('Padrão inválido.');
    $field = $kind === 'category' ? 'category' : 'location';
    $stmt = db()->prepare('SELECT (SELECT COUNT(*) FROM assets WHERE `' . $field . '` = ?) + (SELECT COUNT(*) FROM invoice_items WHERE `' . $field . '` = ?)');
    $stmt->execute([$value,$value]);
    if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Este padrão já está em uso. Mantenha-o para não perder a referência dos registros existentes.');
    db()->prepare('DELETE FROM catalogs WHERE kind = ? AND value = ?')->execute([$kind,$value]);
    flash_set('success', 'Padrão removido.');
    redirect_to('control-panel', ['section' => 'catalogs']);
}

function normalized_catalog_category_key(string $value): string
{
    $value = canonical_restore_category(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    if (function_exists('iconv')) {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii)) $value = $ascii;
    }
    $value = strtolower($value);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value);
}

function asset_category_edit_selection(string $submitted, array $catalogValues, string $currentValue, bool $canManageCatalogs): array
{
    $rawValue = text_value($submitted, 255);
    if ($rawValue === '') throw new RuntimeException('Informe uma Conta Patrimonial válida.');
    if ($rawValue === $currentValue) return ['value' => $rawValue, 'create' => false];

    $value = canonical_restore_category($rawValue);
    $key = normalized_catalog_category_key($value);
    if ($key === '') throw new RuntimeException('Informe uma Conta Patrimonial válida.');
    foreach ($catalogValues as $catalogValue) {
        $catalogValue = (string)$catalogValue;
        if ($catalogValue === $value || normalized_catalog_category_key($catalogValue) === $key) {
            return ['value' => $catalogValue, 'create' => false];
        }
    }
    if (!$canManageCatalogs) throw new RuntimeException('Selecione uma conta patrimonial já cadastrada. Para acrescentar outra, use o Painel de controle.');
    return ['value' => $value, 'create' => true];
}

function handle_post_action(): void
{
    $action = (string)($_GET['api'] ?? ($_POST['submit_action'] ?? ($_POST['action'] ?? '')));
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (isset($_GET['api'])) {
        if ($method !== 'POST') {
            if (str_starts_with($action, 'restore-')) require_restore_permissions();
            json_response(['error' => 'Método não permitido. Use POST.'], 405);
        }
        if (!str_starts_with($action, 'restore-')) json_response(['error' => 'API desconhecida.'], 404);
        try { restore_api($action); }
        catch (CsrfValidationException $e) { json_response(['error' => $e->getMessage()], 403); }
        catch (Throwable $e) { json_response(['error' => $e->getMessage()], 400); }
    }
    if ($method !== 'POST') return;
    try {
        verify_csrf();
        if ($action === 'login') {
            if (!is_logged_in()) {
                $username = text_value($_POST['username'] ?? '', 80);
                $password = (string)($_POST['password'] ?? '');
                if (!authenticate_admin($username, $password)) throw new RuntimeException('Usuário ou senha incorretos.');
            }
            redirect_to('dashboard');
        }
        require_login();
        if ($action === 'logout') { $fromSumma = summa_enabled(); $logoutUrl = summa_logout_url(); logout_admin(); header('Location: ' . ($fromSumma ? $logoutUrl : 'index.php?page=login'), true, 303); exit; }
        if (in_array($action, ['create-admin', 'update-admin-permissions'], true) && summa_manages_users()) throw new RuntimeException('Usuários e permissões do Patrimônio são definidos no Summa: Painel de controle › Usuários › Módulos e áreas.');
        if ($action === 'create-admin') { require_permission('manage_users'); create_admin_action(); }
        if ($action === 'update-admin-permissions') { require_permission('manage_users'); update_admin_permissions_action(); }
        if ($action === 'import-xml') { require_permission('edit_invoices'); import_xml_action(); }
        if ($action === 'save-invoice') { require_permission('edit_invoices'); save_invoice_action(); }
        if ($action === 'create-asset-no-invoice') { require_permission('manage_users'); require_permission('edit_assets'); create_asset_without_invoice_action(); }
        if ($action === 'save-asset') { require_permission('edit_assets'); save_asset_action(); }
        if ($action === 'dispose-asset') { require_permission('edit_assets'); dispose_asset_action(); }
        if ($action === 'delete-asset') { require_permission('manage_users'); require_permission('edit_assets'); delete_asset_action(); }
        if (in_array($action, ['catalog-add','catalog-edit','catalog-delete'], true)) { require_permission('manage_catalogs'); catalog_action($action); }
        throw new RuntimeException('Ação desconhecida.');
    } catch (CsrfValidationException $e) {
        denied_response($e->getMessage());
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
        if (in_array($action, ['save-invoice', 'import-xml'], true)) {
            try { if (can('edit_invoices')) invoice_form_old_store($_POST); } catch (Throwable) { }
        }
        $page = match ($action) {
            'save-invoice' => !empty($_POST['id']) ? 'invoice-edit' : 'new-invoice',
            'import-xml' => !empty($_POST['id']) ? 'invoice-edit' : 'new-invoice',
            'create-asset-no-invoice' => 'new-asset',
            'save-asset','dispose-asset' => 'asset-edit',
            'delete-asset' => 'asset',
            'catalog-add','catalog-edit','catalog-delete','create-admin','update-admin-permissions' => 'control-panel',
            'login' => 'login',
            default => 'dashboard',
        };
        $extra = [];
        if (in_array($action, ['catalog-add', 'catalog-edit', 'catalog-delete'], true)) $extra['section'] = 'catalogs';
        if (in_array($action, ['create-admin', 'update-admin-permissions'], true)) $extra['section'] = 'users';
        if (isset($_POST['id']) && ctype_digit((string)$_POST['id'])) $extra['id'] = (int)$_POST['id'];
        if (in_array($action, ['save-invoice', 'import-xml'], true)) {
            $extra['step'] = max(1, min(3, (int)($_POST['current_step'] ?? ($action === 'import-xml' ? 1 : 3))));
            $invoiceId = isset($_POST['id']) && ctype_digit((string)$_POST['id']) && (int)$_POST['id'] > 0 ? (int)$_POST['id'] : null;
            if (invoice_import_buffer_for($invoiceId)) $extra['buffer'] = 1;
        }
        redirect_to($page, $extra);
    }
}
