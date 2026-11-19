<?php

declare(strict_types=1);

function utf8_length(string $value): int
{
    $matches = preg_match_all('/./us', $value);
    return $matches === false ? strlen($value) : $matches;
}

require_once __DIR__ . '/../app/actions.php';

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    $checks++;
    echo "OK - {$message}\n";
};
$assertThrows = static function (callable $callback, string $message) use (&$checks): void {
    try { $callback(); } catch (RuntimeException) { $checks++; echo "OK - {$message}\n"; return; }
    throw new RuntimeException('Falhou: ' . $message);
};

$item = [
    'name' => 'Notebook de teste',
    'category' => 'Equipamentos de teste',
    'location' => '',
    'patrimony' => '',
    'quantity' => 3.0,
    'unitValue' => 100.0,
    'total' => 300.0,
];

$xmlTokens = invoice_registration_item_tokens($item, 1, true);
$assert($xmlTokens === [], 'item XML aceita Local e Patrimônio vazios quando a Conta Patrimonial está definida');
$allocation = invoice_asset_allocations($item, true);
$assert(count($allocation) === 1 && $allocation[0]['token'] === '' && $allocation[0]['quantity'] === 3.0 && $allocation[0]['valueCents'] === 30000, 'item XML sem códigos gera um registro agregado seguro com quantidade e valor total preservados');

$numbered = $item;
$numbered['quantity'] = 2.0;
$numbered['total'] = 200.01;
$numbered['patrimony'] = "ATV-001\nATV-002";
$tokens = invoice_registration_item_tokens($numbered, 2, true);
$unitAllocations = invoice_asset_allocations($numbered, true);
$assert($tokens === ['ATV-001', 'ATV-002'] && count($unitAllocations) === 2, 'itens XML com Patrimônio mantêm um ativo por unidade');
$assert(array_column($unitAllocations, 'valueCents') === [10000, 10001] && array_sum(array_column($unitAllocations, 'valueCents')) === 20001, 'a alocação unitária mantém o total em centavos inclusive quando há resto');

$manual = $item;
$assertThrows(static fn(): array => invoice_registration_item_tokens($manual, 1, false), 'cadastro manual continua exigindo Local');
$manual['location'] = 'Depósito de teste';
$assertThrows(static fn(): array => invoice_registration_item_tokens($manual, 1, false), 'cadastro manual continua exigindo um patrimônio por unidade');
$assertThrows(static fn(): array => invoice_registration_item_tokens([...$item, 'category' => ''], 1, true), 'Conta Patrimonial continua obrigatória para registrar item XML');
$assertThrows(static fn(): array => invoice_registration_item_tokens([...$numbered, 'patrimony' => 'ATV-001'], 2, true), 'se o XML informar códigos, exige exatamente um por unidade');
$assertThrows(static fn(): array => invoice_asset_allocations($manual, false), 'alocação sem patrimônio é recusada fora do fluxo XML');

$oversized = $item;
$oversized['patrimony'] = str_repeat('X', 256) . '/' . str_repeat('Y', 256) . '/' . str_repeat('Z', 256);
$oversized['quantity'] = 3.0;
$assertThrows(static fn(): array => invoice_registration_item_tokens($oversized, 3, true), 'códigos preenchidos preservam limite de 255 caracteres');

echo "Total: {$checks} verificações de registro NF-e aprovadas.\n";
