<?php

declare(strict_types=1);

function csv_cell(mixed $value): string
{
    $text = (string)($value ?? '');
    if (preg_match('/^[\s]*[=+@\-]/u', $text)) $text = "'" . $text;
    if (preg_match('/^\d{16,}$/', $text) || preg_match('/^0\d+$/', $text)) $text = "'" . $text;
    return '"' . str_replace('"', '""', $text) . '"';
}

function csv_document(array $headers, iterable $rows): string
{
    $lines = [implode(';', array_map('csv_cell', $headers))];
    foreach ($rows as $row) $lines[] = implode(';', array_map('csv_cell', $row));
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

function asset_workbook_rows(array $assets): array
{
    $headers = ['Conta Patrimonial','Local','N.Patrimônio','Item','Cor','Tipo','Marca','N.Nota','Data','Unidade','Valor de Aquisição','v. Útil','CADASTRADO POR','BAIXA EM :','ANO COMPRA','Estado','Descrição original','Observações'];
    $sheets = ['Ativo Fixo'=>[$headers], 'Bens de pequeno valor'=>[$headers], 'descartes bens'=>[$headers]];
    foreach ($assets as $asset) {
        $sheet = ($asset['status'] ?? '') === 'disposed' ? 'descartes bens' : (($asset['classification'] ?? '') === 'small' ? 'Bens de pequeno valor' : 'Ativo Fixo');
        $year = $asset['purchaseYear'] ?? (($asset['acquiredAt'] ?? '') !== '' ? substr((string)$asset['acquiredAt'],0,4) : '');
        $sheets[$sheet][] = [
            (string)($asset['category'] ?? ''),(string)($asset['location'] ?? ''),(string)($asset['patrimony'] ?? ''),(string)($asset['name'] ?? ''),(string)($asset['color'] ?? ''),(string)($asset['type'] ?? ''),(string)($asset['brand'] ?? ''),asset_invoice_display_label($asset),(string)($asset['acquiredAt'] ?? ''),
            $asset['quantity'] === null || $asset['quantity'] === '' ? '' : (float)$asset['quantity'],
            $asset['value'] === null || $asset['value'] === '' ? '' : (float)$asset['value'],
            $asset['usefulLife'] === null || $asset['usefulLife'] === '' ? '' : (int)$asset['usefulLife'],
            '',(string)($asset['disposalDate'] ?? ''),$year === '' ? '' : (int)$year,(string)($asset['condition'] ?? ''),(string)($asset['originalDescription'] ?? ''),(string)(($asset['disposalReason'] ?? '') ?: ($asset['notes'] ?? '')),
        ];
    }
    return $sheets;
}

function invoice_workbook_rows(array $invoices): array
{
    $invoiceRows = [['Número','Série','Emissão','Fornecedor','CNPJ fornecedor','Chave de acesso','Total','Situação','Origem','Destinatário','CNPJ destinatário','Situação no XML','Observações']];
    $itemRows = [['Nota','Série','Fornecedor','Item','Descrição original','Quantidade','Unidade','Valor unitário','Total item','NCM','CFOP','Classe','Conta Patrimonial','Local','Patrimônio','Marca','Cor','Tipo','Estado','Vida útil','Observações']];
    foreach ($invoices as $invoice) {
        $invoiceRows[] = [(string)($invoice['number'] ?? ''),(string)($invoice['series'] ?? ''),(string)($invoice['issuedAt'] ?? ''),(string)($invoice['supplier'] ?? ''),(string)($invoice['supplierCnpj'] ?? ''),(string)($invoice['accessKey'] ?? ''),(float)($invoice['total'] ?? 0),(string)($invoice['status'] ?? ''),(string)($invoice['source'] ?? ''),(string)($invoice['recipient'] ?? ''),(string)($invoice['recipientCnpj'] ?? ''),(string)($invoice['xmlStatus'] ?? ''),(string)($invoice['notes'] ?? '')];
        foreach (($invoice['items'] ?? []) as $item) {
            $itemRows[] = [(string)($invoice['number'] ?? ''),(string)($invoice['series'] ?? ''),(string)($invoice['supplier'] ?? ''),(string)($item['name'] ?? ''),(string)($item['originalDescription'] ?? ''),(float)($item['quantity'] ?? 0),(string)($item['unit'] ?? ''),(float)($item['unitValue'] ?? 0),(float)($item['total'] ?? 0),(string)($item['ncm'] ?? ''),(string)($item['cfop'] ?? ''),(string)($item['classification'] ?? ''),(string)($item['category'] ?? ''),(string)($item['location'] ?? ''),(string)($item['patrimony'] ?? ''),(string)($item['brand'] ?? ''),(string)($item['color'] ?? ''),(string)($item['type'] ?? ''),(string)($item['condition'] ?? ''),(int)($item['usefulLife'] ?? 5),(string)($item['notes'] ?? '')];
        }
    }
    return ['Notas fiscais'=>$invoiceRows,'Itens das NF-es'=>$itemRows];
}
