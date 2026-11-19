<?php

declare(strict_types=1);

function nfe_digits(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function nfe_valid_cnpj(string $value): bool
{
    $digits = nfe_digits($value);
    if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) return false;
    $calc = static function (string $base, array $weights): int {
        $sum = 0;
        foreach ($weights as $i => $weight) $sum += (int)$base[$i] * $weight;
        $rest = $sum % 11;
        return $rest < 2 ? 0 : 11 - $rest;
    };
    $first = $calc(substr($digits, 0, 12), [5,4,3,2,9,8,7,6,5,4,3,2]);
    $second = $calc(substr($digits, 0, 12) . $first, [6,5,4,3,2,9,8,7,6,5,4,3,2]);
    return $first === (int)$digits[12] && $second === (int)$digits[13];
}

function nfe_valid_key(string $value): bool
{
    $key = nfe_digits($value);
    if (strlen($key) !== 44) return false;
    $sum = 0;
    $weight = 2;
    for ($i = 42; $i >= 0; $i--) {
        $sum += (int)$key[$i] * $weight;
        $weight = $weight === 9 ? 2 : $weight + 1;
    }
    $remainder = $sum % 11;
    $digit = $remainder < 2 ? 0 : 11 - $remainder;
    return $digit === (int)$key[43];
}

function nfe_valid_date(string $value): bool
{
    $date = substr(trim($value), 0, 10);
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $dt !== false && $dt->format('Y-m-d') === $date;
}

function nfe_xpath_text(DOMXPath $xpath, string $query, ?DOMNode $context = null): string
{
    $nodes = $xpath->query($query, $context);
    if (!$nodes || $nodes->length === 0) return '';
    return trim((string)$nodes->item(0)?->textContent);
}

function nfe_parse_xml(string $xml): array
{
    $length = strlen($xml);
    if ($length === 0) throw new RuntimeException('O XML está vazio.');
    if ($length > 2 * 1024 * 1024) throw new RuntimeException('O XML ultrapassa o limite de 2 MB.');
    if (preg_match('/<!DOCTYPE\b|<!ENTITY\b|SYSTEM\s+["\']|PUBLIC\s+["\']/i', $xml)) {
        throw new RuntimeException('XML com DTD ou entidade externa não é permitido.');
    }
    if (!class_exists(DOMDocument::class)) throw new RuntimeException('A extensão XML do PHP não está disponível nesta hospedagem.');

    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument();
    $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
    $errors = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded || $errors) throw new RuntimeException('O arquivo não contém XML válido.');

    $xpath = new DOMXPath($document);
    $infNodes = $xpath->query('//*[local-name()="infNFe"]');
    if (!$infNodes || $infNodes->length === 0) throw new RuntimeException('O XML não contém infNFe.');
    $inf = $infNodes->item(0);
    $idKey = $inf instanceof DOMElement ? preg_replace('/^NFe/i', '', $inf->getAttribute('Id')) : '';
    $protocolKey = nfe_xpath_text($xpath, '(//*[local-name()="infProt"]/*[local-name()="chNFe"])[1]');
    $accessKey = $protocolKey !== '' ? $protocolKey : (string)$idKey;
    $accessKey = nfe_digits($accessKey);
    if (!nfe_valid_key($accessKey)) throw new RuntimeException('A chave de acesso no XML é inválida.');

    $model = nfe_xpath_text($xpath, '(//*[local-name()="ide"]/*[local-name()="mod"])[1]');
    if ($model !== '55') throw new RuntimeException('Somente NF-e modelo 55 é aceita.');
    $issuedRaw = nfe_xpath_text($xpath, '(//*[local-name()="ide"]/*[local-name()="dhEmi"])[1]');
    if ($issuedRaw === '') $issuedRaw = nfe_xpath_text($xpath, '(//*[local-name()="ide"]/*[local-name()="dEmi"])[1]');
    $issuedAt = substr($issuedRaw, 0, 10);
    if (!nfe_valid_date($issuedAt)) throw new RuntimeException('A data de emissão do XML é inválida.');

    $number = nfe_xpath_text($xpath, '(//*[local-name()="ide"]/*[local-name()="nNF"])[1]');
    $series = nfe_xpath_text($xpath, '(//*[local-name()="ide"]/*[local-name()="serie"])[1]');
    $supplierCnpj = nfe_digits(nfe_xpath_text($xpath, '(//*[local-name()="emit"]/*[local-name()="CNPJ"])[1]'));
    if (!nfe_valid_cnpj($supplierCnpj)) throw new RuntimeException('O CNPJ do emitente é inválido.');
    if ($protocolKey !== '' && $idKey !== '' && nfe_digits((string)$idKey) !== nfe_digits($protocolKey)) {
        throw new RuntimeException('A chave chNFe diverge do identificador infNFe.');
    }
    if (substr($accessKey, 20, 2) !== '55') throw new RuntimeException('A chave não identifica NF-e modelo 55.');
    if (ltrim(substr($accessKey, 6, 14), '0') !== ltrim($supplierCnpj, '0')) throw new RuntimeException('O CNPJ do emitente diverge da chave.');
    if ((int)$number !== (int)substr($accessKey, 25, 9)) throw new RuntimeException('O número da NF-e diverge da chave.');
    if ((int)$series !== (int)substr($accessKey, 22, 3)) throw new RuntimeException('A série da NF-e diverge da chave.');
    $monthFromKey = substr($accessKey, 2, 4);
    if (substr($issuedAt, 2, 2) . substr($issuedAt, 5, 2) !== $monthFromKey) throw new RuntimeException('A data de emissão diverge da chave.');

    $items = [];
    $details = $xpath->query('//*[local-name()="det"]');
    if ($details) {
        foreach ($details as $index => $detail) {
            if (!$detail instanceof DOMElement) continue;
            $prodNodes = $xpath->query('./*[local-name()="prod"]', $detail);
            $prod = $prodNodes && $prodNodes->length ? $prodNodes->item(0) : null;
            if (!$prod) continue;
            $get = static fn(string $name): string => nfe_xpath_text($xpath, './*[local-name()="' . $name . '"]', $prod);
            $quantity = (float)str_replace(',', '.', $get('qCom'));
            $unitValue = (float)str_replace(',', '.', $get('vUnCom'));
            $total = (float)str_replace(',', '.', $get('vProd'));
            if (!is_finite($quantity) || $quantity <= 0) throw new RuntimeException('Um item tem quantidade inválida.');
            if (!is_finite($unitValue) || $unitValue < 0 || !is_finite($total) || $total < 0) throw new RuntimeException('Um item tem valor inválido.');
            $expected = $quantity * $unitValue;
            if (abs($expected - $total) > max(0.01, $quantity * 0.01)) throw new RuntimeException('O valor total de um item não confere com quantidade e valor unitário.');
            $original = $get('xProd');
            if ($original === '') $original = 'Item ' . ($index + 1);
            $itemId = $detail->getAttribute('nItem') ?: (string)($index + 1);
            $items[] = [
                'id' => $itemId,
                'originalDescription' => $original,
                'name' => $original,
                'quantity' => $quantity,
                'unit' => $get('uCom'),
                'unitValue' => $unitValue,
                'total' => $total,
                'ncm' => $get('NCM'),
                'cfop' => $get('CFOP'),
                'classification' => 'fixed',
                'category' => '',
                'location' => '',
                'patrimony' => '',
                'brand' => '',
                'color' => '',
                'type' => '',
                'condition' => 'Novo',
                'usefulLife' => 5,
                'notes' => '',
            ];
        }
    }
    if (!$items) throw new RuntimeException('O XML não contém itens de produto.');

    $totalText = nfe_xpath_text($xpath, '(//*[local-name()="ICMSTot"]/*[local-name()="vNF"])[1]');
    if ($totalText === '') $totalText = nfe_xpath_text($xpath, '(//*[local-name()="ICMSTot"]/*[local-name()="vProd"])[1]');
    $invoiceTotal = (float)str_replace(',', '.', $totalText);
    if (!is_finite($invoiceTotal) || $invoiceTotal < 0) throw new RuntimeException('O total da NF-e é inválido.');
    $cStat = nfe_xpath_text($xpath, '(//*[local-name()="infProt"]/*[local-name()="cStat"])[1]');
    $reason = nfe_xpath_text($xpath, '(//*[local-name()="infProt"]/*[local-name()="xMotivo"])[1]');
    $xmlStatus = $cStat === '' ? 'Sem protocolo de autorização no XML' : 'cStat ' . $cStat . ($reason !== '' ? ' — ' . $reason : '');

    return [
        'accessKey' => $accessKey,
        'number' => $number,
        'series' => $series,
        'issuedAt' => $issuedAt,
        'supplier' => nfe_xpath_text($xpath, '(//*[local-name()="emit"]/*[local-name()="xNome"])[1]'),
        'supplierCnpj' => $supplierCnpj,
        'recipient' => nfe_xpath_text($xpath, '(//*[local-name()="dest"]/*[local-name()="xNome"])[1]'),
        'recipientCnpj' => nfe_digits(nfe_xpath_text($xpath, '(//*[local-name()="dest"]/*[local-name()="CNPJ"])[1]')),
        'total' => $invoiceTotal,
        'status' => 'draft',
        'source' => 'xml',
        'xmlStatus' => $xmlStatus,
        'protocolCode' => $cStat,
        'xml' => $xml,
        'notes' => '',
        'items' => $items,
    ];
}
