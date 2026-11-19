<?php

declare(strict_types=1);
require_once __DIR__ . '/../app/nfe.php';
require_once __DIR__ . '/../app/zip.php';
require_once __DIR__ . '/../app/xlsx.php';
require_once __DIR__ . '/../app/security.php';
require_once __DIR__ . '/../app/restore.php';
require_once __DIR__ . '/../app/actions.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException('Falhou: ' . $message);
    echo "OK - {$message}\n";
};
$assertThrows = static function (callable $callback, string $message): void {
    try {
        $callback();
    } catch (RuntimeException) {
        echo "OK - {$message}\n";
        return;
    }
    throw new RuntimeException('Falhou: ' . $message);
};
$credentials = validate_admin_credentials('  admin.test  ', str_repeat('s', 8), str_repeat('s', 8));
$assert($credentials['username'] === 'admin.test', 'normaliza e aceita usuário administrativo válido');
$assert(strlen(validate_admin_username(str_repeat('a', 80))) === 80, 'aceita usuário com 80 caracteres');
$assertThrows(static fn(): array => validate_admin_credentials('ab', str_repeat('s', 8), str_repeat('s', 8)), 'rejeita usuário curto');
$assertThrows(static fn(): string => validate_admin_username(str_repeat('a', 81)), 'rejeita usuário com mais de 80 caracteres');
$assertThrows(static fn(): array => validate_admin_credentials('admin test', str_repeat('s', 8), str_repeat('s', 8)), 'rejeita caractere inválido no usuário');
$assertThrows(static fn(): array => validate_admin_credentials('admin.test', str_repeat('s', 7), str_repeat('s', 7)), 'rejeita senha com menos de 8 caracteres');
$assert(validate_admin_credentials('admin.test', str_repeat('s', 72), str_repeat('s', 72))['username'] === 'admin.test', 'aceita senha no limite de 72 bytes');
$multibytePassword = str_repeat('á', 8);
$assert(strlen($multibytePassword) === 16 && validate_admin_credentials('admin.test', $multibytePassword, $multibytePassword)['username'] === 'admin.test', 'aceita 8 caracteres UTF-8 contando caracteres, não bytes');
$assertThrows(static fn(): array => validate_admin_credentials('admin.test', str_repeat('á', 7), str_repeat('á', 7)), 'rejeita 7 caracteres multibyte embora excedam 8 bytes');
$assertThrows(static fn(): array => validate_admin_credentials('admin.test', str_repeat('s', 73), str_repeat('s', 73)), 'rejeita senha acima do limite de 72 bytes');
$assertThrows(static fn(): array => validate_admin_credentials('admin.test', str_repeat('s', 8), str_repeat('t', 8)), 'rejeita confirmação de senha diferente');
$key = '35261198765432000198550010000000011000000013';
$assert(nfe_valid_key($key), 'chave de acesso sintética com dígito válido');
$assert(!nfe_valid_key(substr($key, 0, 43) . ((int)$key[43] + 1) % 10), 'rejeita dígito de chave alterado');
$assert(nfe_valid_cnpj('98765432000198'), 'CNPJ sintético válido');
$assert(!nfe_valid_cnpj('11111111111111'), 'rejeita CNPJ repetido');
$assert(nfe_valid_date('2026-11-03'), 'data ISO válida');
$assert(!nfe_valid_date('2026-02-31'), 'rejeita data impossível');

$xml = '<?xml version="1.0" encoding="UTF-8"?><nfeProc><NFe><infNFe Id="NFe' . $key . '"><ide><mod>55</mod><serie>1</serie><nNF>1</nNF><dhEmi>2026-11-03T12:00:00-03:00</dhEmi></ide><emit><CNPJ>98765432000198</CNPJ><xNome>EMITENTE DE TESTE</xNome></emit><dest><CNPJ>98765432000198</CNPJ><xNome>DESTINO DE TESTE</xNome></dest><det nItem="1"><prod><xProd>ITEM DE TESTE</xProd><NCM>84145990</NCM><CFOP>6106</CFOP><uCom>UN</uCom><qCom>1.0000</qCom><vUnCom>12.500000</vUnCom><vProd>12.50</vProd></prod></det><total><ICMSTot><vNF>12.50</vNF></ICMSTot></total></infNFe></NFe></nfeProc>';
$invoice = nfe_parse_xml($xml);
$assert($invoice['number'] === '1' && $invoice['items'][0]['name'] === 'ITEM DE TESTE', 'parser le XML sintético sem namespace');
try { nfe_parse_xml('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' . $xml); throw new RuntimeException('Falhou: bloqueio de DTD'); } catch (RuntimeException $e) { $assert(str_contains($e->getMessage(), 'DTD'), 'bloqueia DTD/entidade externa'); }

$zip = zip_build_stored(['manifesto.json' => '{"ok":true}', 'pastas/arquivo.txt' => 'conteúdo de teste']);
$path = sys_get_temp_dir() . '/ativa-php-test-' . bin2hex(random_bytes(5)) . '.zip';
file_put_contents($path, $zip);
$assert(substr($zip, 0, 4) === "PK\x03\x04", 'gerador emite cabeçalho ZIP');
if (class_exists(ZipArchive::class)) {
    $archive = new ZipArchive();
    if ($archive->open($path) === true) {
        $assert($archive->getFromName('manifesto.json') === '{"ok":true}', 'ZIP pode ser lido pela extensão padrão');
        $archive->close();
    } else {
        throw new RuntimeException('Falhou: o ZIP gerado não é legível.');
    }
}
$xlsx = xlsx_build(['Aba' => [['Cabeçalho'], ['Valor']]]);
$xlsxPath = sys_get_temp_dir() . '/ativa-xlsx-test-' . bin2hex(random_bytes(5)) . '.xlsx';
file_put_contents($xlsxPath, $xlsx);
$assert(substr($xlsx, 0, 4) === "PK\x03\x04", 'gerador produz contêiner XLSX');
unlink($path);
unlink($xlsxPath);

$assert(canonical_restore_category('Energia Fotovoltaico') === 'Sistema Fotovoltaico', 'mapeia a categoria aprovada Energia Fotovoltaico');
$assert(canonical_restore_category('Sistema Fotovotaico') === 'Sistema Fotovoltaico', 'corrige a grafia da categoria Sistema Fotovotaico');
$assert(canonical_restore_category('  SISTEMA   FOTOVOTAICO  ') === 'Sistema Fotovoltaico', 'normaliza espaços e caixa nas duas grafias aprovadas');
$assert(canonical_restore_category('Móveis e Utensílios') === 'Móveis e Utensílios', 'preserva categorias fora do mapeamento autorizado');
$assert(normalized_catalog_category_key('SISTEMA FOTOVOTAICO') === normalized_catalog_category_key('Sistema Fotovoltaico'), 'trata a grafia solar aprovada como categoria canônica');
$assert(normalized_catalog_category_key('Moveis e Utensilios') === normalized_catalog_category_key('Móveis e Utensílios'), 'detecta categoria duplicada por acentos');
$assert(normalized_catalog_category_key('Equip. Info e Perifericos') === normalized_catalog_category_key('Equip.Info e Periféricos'), 'detecta categoria duplicada por pontuação e caixa');
$catalogRows = normalize_restore_records('catalogs', [
    ['kind' => 'category', 'value' => 'Energia Fotovoltaico'],
    ['kind' => 'category', 'value' => 'Sistema Fotovotaico'],
    ['kind' => 'category', 'value' => 'Software'],
]);
$catalogRows = deduplicate_restore_catalogs($catalogRows);
$assert(count($catalogRows) === 2 && $catalogRows[0]['value'] === 'Sistema Fotovoltaico', 'unifica as duas entradas do catálogo sem alterar as outras');
$assetRows = normalize_restore_records('assets', [['category' => 'Sistema Fotovotaico', 'original' => '{"category":"Sistema Fotovotaico"}']]);
$assert($assetRows[0]['category'] === 'Sistema Fotovoltaico' && str_contains($assetRows[0]['original'], 'Sistema Fotovotaico'), 'normaliza o campo usado e preserva a origem bruta');
$registryRows = build_restore_patrimony_registry_rows([
    ['id' => 11, 'patrimony' => 'COD-A/COD-B'],
    ['id' => 12, 'patrimony' => 'COD-A, COD-C'],
    ['id' => 13, 'patrimony' => 'COD-C'],
]);
$assert(count($registryRows) === 3 && $registryRows[0] === ['patrimony' => 'COD-A', 'assetId' => 11] && $registryRows[2] === ['patrimony' => 'COD-C', 'assetId' => 12], 'preserva ativos com código histórico repetido e cria uma reserva por código distinto');
$assertThrows(static fn(): array => build_restore_patrimony_registry_rows([['patrimony' => 'SEM-ID']]), 'rejeita reconstruir reserva sem um identificador de ativo válido');
