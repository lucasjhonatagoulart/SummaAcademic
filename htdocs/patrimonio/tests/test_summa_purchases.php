<?php

declare(strict_types=1);

// Entradas de Compras do Summa (SQLite em memória): vira NF-e em rascunho, não importa duas vezes e separa arquivos inválidos.
require_once __DIR__ . '/../app/summa_purchases.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE invoices (id INTEGER PRIMARY KEY AUTOINCREMENT, accessKey TEXT UNIQUE, number TEXT NOT NULL DEFAULT '', series TEXT NOT NULL DEFAULT '', issuedAt TEXT NOT NULL DEFAULT '', supplier TEXT NOT NULL DEFAULT '', supplierCnpj TEXT NOT NULL DEFAULT '', recipient TEXT NOT NULL DEFAULT '', recipientCnpj TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'draft', source TEXT NOT NULL DEFAULT 'manual', xmlStatus TEXT NOT NULL DEFAULT '', xml TEXT, notes TEXT NOT NULL)");
$pdo->exec("CREATE TABLE invoice_items (invoiceId INTEGER NOT NULL, id TEXT NOT NULL, originalDescription TEXT NOT NULL, name TEXT NOT NULL, quantity REAL NOT NULL, unit TEXT NOT NULL, unitValue REAL NOT NULL, total REAL NOT NULL, ncm TEXT NOT NULL, cfop TEXT NOT NULL, classification TEXT NOT NULL, category TEXT NOT NULL, location TEXT NOT NULL, patrimony TEXT NOT NULL, brand TEXT NOT NULL, color TEXT NOT NULL, type TEXT NOT NULL, `condition` TEXT NOT NULL, usefulLife INTEGER NOT NULL, notes TEXT NOT NULL, PRIMARY KEY (invoiceId, id))");

$failures = 0; $total = 0;
function check(bool $ok, string $label): void { global $failures, $total; $total++; if (!$ok) { $failures++; fwrite(STDERR, "FALHOU: $label\n"); } else echo "OK - $label\n"; }

$dir = sys_get_temp_dir() . '/entradas-' . bin2hex(random_bytes(4));
mkdir($dir);
$entry = ['versao' => 1, 'id' => 'summa-1-7-20261119-abc123', 'origem' => 'Summa Compras', 'solicitacao' => 'SC-0007', 'titulo' => 'Notebooks do laboratório', 'setor' => 'TI',
    'solicitante' => 'Ana Souza', 'aprovado_por' => 'Carlos Lima', 'fornecedor' => 'Alfa Informática', 'nf_numero' => '000184552', 'recebido_em' => '2026-11-19', 'enviado_por' => 'Rita',
    'itens' => [['descricao' => 'Notebook 15"', 'quantidade' => 8, 'unidade' => 'un.', 'valor_unitario' => '2199.00'], ['descricao' => 'Mouse sem fio', 'quantidade' => 2, 'unidade' => 'un.', 'valor_unitario' => '59.90']]];
file_put_contents("$dir/{$entry['id']}.json", json_encode($entry));
file_put_contents("$dir/ruim.json", '{"versao":1}');

check(import_summa_purchases($pdo, $dir) === 1, 'um arquivo válido vira uma nota');
$inv = $pdo->query('SELECT * FROM invoices')->fetch();
check($inv['status'] === 'draft' && $inv['source'] === 'manual' && $inv['number'] === '000184552' && $inv['supplier'] === 'Alfa Informática', 'nota em rascunho com número e fornecedor');
check(abs((float)$inv['total'] - (8 * 2199 + 2 * 59.9)) < 0.001, 'total da nota soma os itens');
check(str_contains($inv['notes'], 'SC-0007') && str_contains($inv['notes'], 'Ana Souza'), 'observação identifica a solicitação e o solicitante');
$items = $pdo->query('SELECT * FROM invoice_items ORDER BY id')->fetchAll();
check(count($items) === 2 && $items[0]['id'] === 'compras-1' && $items[0]['classification'] === 'fixed' && $items[0]['category'] === '' && (float)$items[0]['quantity'] === 8.0, 'itens como ativo imobilizado, sem conta definida');
check(!is_file("$dir/{$entry['id']}.json"), 'arquivo importado é apagado');
check(is_file("$dir/com-erro/ruim.json"), 'arquivo inválido vai para com-erro/');
check(summa_purchase_drafts($pdo) === 1, 'aviso conta a nota de Compras em rascunho');

file_put_contents("$dir/{$entry['id']}.json", json_encode($entry));
check(import_summa_purchases($pdo, $dir) === 0 && (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 1, 'o mesmo envio não é importado duas vezes');
check(!is_file("$dir/{$entry['id']}.json"), 'arquivo repetido é descartado');

$pdo->exec("UPDATE invoices SET status = 'registered'");
check(summa_purchase_drafts($pdo) === 0, 'nota incorporada sai do aviso');
check(summa_purchase_payload(json_encode(['itens' => [['descricao' => 'x', 'quantidade' => -1]]] + $entry)) === null, 'quantidade negativa recusada');
check(import_summa_purchases($pdo, $dir . '/nao-existe') === 0, 'sem pasta, nada acontece');

@unlink("$dir/com-erro/ruim.json"); @rmdir("$dir/com-erro"); @rmdir($dir);
echo "$total verificações, $failures falhas\n";
exit($failures ? 1 : 0);
