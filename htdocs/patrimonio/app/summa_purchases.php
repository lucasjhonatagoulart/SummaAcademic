<?php

declare(strict_types=1);

// Entradas do módulo Compras do Summa: cada recebimento com itens patrimoniáveis chega como um arquivo JSON em
// entradas-compras/ e vira uma NF-e em rascunho em Notas fiscais. Quem cuida do patrimônio define conta, local e
// número de cada ativo e incorpora como faz com qualquer nota. Um arquivo nunca é importado duas vezes.

const SUMMA_PURCHASES_DIR = __DIR__ . '/../entradas-compras';

function summa_purchases_dir(): string { return SUMMA_PURCHASES_DIR; }

/** Lê e confere um arquivo de entrada; devolve null se estiver malformado */
function summa_purchase_payload(string $json): ?array
{
    $d = json_decode($json, true);
    if (!is_array($d) || (int)($d['versao'] ?? 0) !== 1 || !preg_match('/^[A-Za-z0-9_-]{6,80}$/', (string)($d['id'] ?? ''))) return null;
    if (!is_array($d['itens'] ?? null) || !$d['itens'] || count($d['itens']) > 200) return null;
    $items = [];
    foreach ($d['itens'] as $k => $it) {
        if (!is_array($it)) return null;
        $name = trim(preg_replace('/\s+/u', ' ', (string)($it['descricao'] ?? '')) ?? '');
        $qty = (float)($it['quantidade'] ?? 0); $unit = (float)($it['valor_unitario'] ?? 0);
        if ($name === '' || $qty <= 0 || $qty > 100000 || $unit < 0) return null;
        $items[] = ['id' => 'compras-' . ($k + 1), 'name' => mb_substr($name, 0, 255), 'unit' => mb_substr((string)($it['unidade'] ?? 'un.'), 0, 32), 'quantity' => $qty, 'unitValue' => $unit, 'total' => round($qty * $unit, 2)];
    }
    $s = static fn(string $k, int $max): string => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
    return ['id' => (string)$d['id'], 'code' => $s('solicitacao', 20), 'title' => $s('titulo', 160), 'sector' => $s('setor', 80), 'requester' => $s('solicitante', 160),
        'approver' => $s('aprovado_por', 160), 'supplier' => $s('fornecedor', 320), 'number' => $s('nf_numero', 32), 'receivedOn' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['recebido_em'] ?? '')) ? (string)$d['recebido_em'] : '',
        'sentBy' => $s('enviado_por', 160), 'items' => $items];
}

/**
 * Importa os arquivos pendentes. Cada arquivo vira uma nota em rascunho na mesma transação que o marca como importado;
 * o arquivo só é apagado depois. Arquivos inválidos vão para a subpasta com-erro/. Devolve quantas notas foram criadas.
 */
function import_summa_purchases(PDO $pdo, ?string $dir = null): int
{
    $dir ??= summa_purchases_dir();
    if (!is_dir($dir)) return 0;
    $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
    if (!$files) return 0;
    $pdo->exec('CREATE TABLE IF NOT EXISTS summa_purchase_imports (id VARCHAR(80) NOT NULL PRIMARY KEY, invoiceId INT NOT NULL, importedAt VARCHAR(20) NOT NULL)');
    $seen = $pdo->prepare('SELECT invoiceId FROM summa_purchase_imports WHERE id = ?');
    $created = 0;
    sort($files);
    foreach (array_slice($files, 0, 50) as $file) {
        $p = summa_purchase_payload((string)@file_get_contents($file));
        if (!$p || basename($file) !== $p['id'] . '.json') {
            @mkdir($dir . '/com-erro', 0755);
            @rename($file, $dir . '/com-erro/' . basename($file));
            continue;
        }
        $seen->execute([$p['id']]);
        if ($seen->fetchColumn() !== false) { @unlink($file); continue; }
        $notes = 'Entrada de Compras do Summa: ' . $p['code'] . ($p['title'] !== '' ? ' — ' . $p['title'] : '')
            . ($p['sector'] !== '' ? '. Setor: ' . $p['sector'] : '') . ($p['requester'] !== '' ? '. Solicitante: ' . $p['requester'] : '')
            . ($p['approver'] !== '' ? '. Aprovado por: ' . $p['approver'] : '') . ($p['sentBy'] !== '' ? '. Enviado por: ' . $p['sentBy'] : '') . '.';
        $total = array_sum(array_column($p['items'], 'total'));
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO invoices (accessKey,number,series,issuedAt,supplier,supplierCnpj,recipient,recipientCnpj,total,status,source,xmlStatus,xml,notes) VALUES (NULL,?,\'\',?,?,\'\',\'\',\'\',?,\'draft\',\'manual\',\'\',NULL,?)')
                ->execute([$p['number'], $p['receivedOn'], $p['supplier'], number_format($total, 2, '.', ''), $notes]);
            $invoiceId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO invoice_items (invoiceId,id,originalDescription,name,quantity,unit,unitValue,total,ncm,cfop,classification,category,location,patrimony,brand,color,type,`condition`,usefulLife,notes) VALUES (?,?,?,?,?,?,?,?,\'\',\'\',\'fixed\',\'\',\'\',\'\',\'\',\'\',\'\',\'Novo\',5,\'\')');
            foreach ($p['items'] as $it) $ins->execute([$invoiceId, $it['id'], $it['name'], $it['name'], number_format($it['quantity'], 3, '.', ''), $it['unit'], number_format($it['unitValue'], 6, '.', ''), number_format($it['total'], 2, '.', '')]);
            $pdo->prepare('INSERT INTO summa_purchase_imports (id, invoiceId, importedAt) VALUES (?, ?, ?)')->execute([$p['id'], $invoiceId, date('Y-m-d H:i:s')]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Entrada de Compras não importada (' . basename($file) . '): ' . $e->getMessage());
            continue;
        }
        @unlink($file);
        $created++;
    }
    return $created;
}

/** Notas em rascunho que vieram de Compras (para o aviso da Visão geral e de Notas fiscais) */
function summa_purchase_drafts(PDO $pdo): int
{
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM invoices i JOIN summa_purchase_imports s ON s.invoiceId = i.id WHERE i.status = 'draft'")->fetchColumn();
    } catch (PDOException) {
        return 0; // tabela ainda não criada: nenhuma entrada recebida
    }
}
