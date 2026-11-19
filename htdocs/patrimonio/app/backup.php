<?php

declare(strict_types=1);

function emit_download(string $name, string $mime, string $contents): never
{
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . (preg_replace('/[^A-Za-z0-9._-]/', '-', $name) ?: 'download') . '"');
    header('Content-Length: ' . strlen($contents));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    echo $contents;
    exit;
}

function asset_csv_rows(array $assets): array
{
    $headers = ['Conta Patrimonial','Local','N.Patrimônio','Item','Cor','Tipo','Marca','N.Nota','Data','Unidade','Valor de Aquisição','v. Útil','CADASTRADO POR','BAIXA EM :','ANO COMPRA','Estado','Descrição original','Observações'];
    $rows = [$headers];
    foreach ($assets as $asset) {
        $year = $asset['purchaseYear'] ?? (($asset['acquiredAt'] ?? '') !== '' ? substr((string)$asset['acquiredAt'], 0, 4) : '');
        $rows[] = [(string)($asset['category'] ?? ''),(string)($asset['location'] ?? ''),(string)($asset['patrimony'] ?? ''),(string)($asset['name'] ?? ''),(string)($asset['color'] ?? ''),(string)($asset['type'] ?? ''),(string)($asset['brand'] ?? ''),asset_invoice_display_label($asset),(string)($asset['acquiredAt'] ?? ''),(string)($asset['quantity'] ?? ''),(string)($asset['value'] ?? ''),(string)($asset['usefulLife'] ?? ''),'',(string)($asset['disposalDate'] ?? ''),(string)$year,(string)($asset['condition'] ?? ''),(string)($asset['originalDescription'] ?? ''),(string)(($asset['disposalReason'] ?? '') ?: ($asset['notes'] ?? ''))];
    }
    return $rows;
}

function invoice_csv_rows(array $invoices): array
{
    $rows = [['Número','Série','Emissão','Fornecedor','CNPJ fornecedor','Chave de acesso','Total','Situação','Origem','Destinatário','CNPJ destinatário','Situação no XML','Observações']];
    foreach ($invoices as $invoice) $rows[] = [(string)($invoice['number'] ?? ''),(string)($invoice['series'] ?? ''),(string)($invoice['issuedAt'] ?? ''),(string)($invoice['supplier'] ?? ''),(string)($invoice['supplierCnpj'] ?? ''),(string)($invoice['accessKey'] ?? ''),(string)($invoice['total'] ?? ''),(string)($invoice['status'] ?? ''),(string)($invoice['source'] ?? ''),(string)($invoice['recipient'] ?? ''),(string)($invoice['recipientCnpj'] ?? ''),(string)($invoice['xmlStatus'] ?? ''),(string)($invoice['notes'] ?? '')];
    return $rows;
}

function invoice_item_csv_rows(array $invoices): array
{
    $rows = [['Nota','Série','Fornecedor','Item','Descrição original','Quantidade','Unidade','Valor unitário','Total item','NCM','CFOP','Classe','Conta Patrimonial','Local','Patrimônio','Marca','Cor','Tipo','Estado','Vida útil','Observações']];
    foreach ($invoices as $invoice) foreach (($invoice['items'] ?? []) as $item) $rows[] = [(string)($invoice['number'] ?? ''),(string)($invoice['series'] ?? ''),(string)($invoice['supplier'] ?? ''),(string)($item['name'] ?? ''),(string)($item['originalDescription'] ?? ''),(string)($item['quantity'] ?? ''),(string)($item['unit'] ?? ''),(string)($item['unitValue'] ?? ''),(string)($item['total'] ?? ''),(string)($item['ncm'] ?? ''),(string)($item['cfop'] ?? ''),(string)($item['classification'] ?? ''),(string)($item['category'] ?? ''),(string)($item['location'] ?? ''),(string)($item['patrimony'] ?? ''),(string)($item['brand'] ?? ''),(string)($item['color'] ?? ''),(string)($item['type'] ?? ''),(string)($item['condition'] ?? ''),(string)($item['usefulLife'] ?? ''),(string)($item['notes'] ?? '')];
    return $rows;
}

function download_xlsx(string $kind): never
{
    if ($kind === 'invoices') {
        emit_download('Controle-Patrimonial-notas-fiscais.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', xlsx_build(invoice_workbook_rows(all_invoices())));
    }
    if ($kind !== 'assets') throw new RuntimeException('Tipo de exportação inválido.');
    $sheets = asset_workbook_rows(all_assets());
    $sheets['Leia-me'] = [['Notas sobre os dados'],['Valores representam valores históricos de aquisição; não são cálculo de depreciação.'],['Campos vazios não foram preenchidos por inferência.'],['A aba descartes bens contém os ativos baixados; demais abas contêm ativos por classe.']];
    emit_download('Controle-Patrimonial-ativos.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', xlsx_build($sheets));
}

function download_csv(string $kind): never
{
    if (in_array($kind, ['assets-fixed','assets-small','assets-disposed'], true)) {
        $assets = all_assets();
        $assets = array_values(array_filter($assets, static function (array $asset) use ($kind): bool {
            return match ($kind) {
                'assets-fixed' => ($asset['classification'] ?? '') === 'fixed' && ($asset['status'] ?? '') === 'active',
                'assets-small' => ($asset['classification'] ?? '') === 'small' && ($asset['status'] ?? '') === 'active',
                default => ($asset['status'] ?? '') === 'disposed',
            };
        }));
        $rows = asset_csv_rows($assets); $headers = array_shift($rows);
        $name = match ($kind) {'assets-fixed'=>'Ativo-Fixo.csv','assets-small'=>'Bens-pequeno-valor.csv',default=>'descartes-bens.csv'};
        emit_download($name, 'text/csv; charset=utf-8', csv_document($headers, $rows));
    }
    if ($kind === 'invoices') { $rows=invoice_csv_rows(all_invoices()); emit_download('notas-fiscais.csv','text/csv; charset=utf-8',csv_document(array_shift($rows),$rows)); }
    if ($kind === 'items') { $rows=invoice_item_csv_rows(all_invoices()); emit_download('itens-das-nfes.csv','text/csv; charset=utf-8',csv_document(array_shift($rows),$rows)); }
    throw new RuntimeException('Tipo de exportação inválido.');
}

function backup_asset_json(array $asset): array
{
    if (!empty($asset['original']) && is_string($asset['original'])) {
        $decoded = json_decode($asset['original'], true);
        if (json_last_error() === JSON_ERROR_NONE) $asset['original'] = $decoded;
    }
    foreach (['id','sourceRow','invoiceId','usefulLife','purchaseYear'] as $field) $asset[$field] = ($asset[$field] === null || $asset[$field] === '') ? null : (int)$asset[$field];
    foreach (['quantity','value'] as $field) $asset[$field] = ($asset[$field] === null || $asset[$field] === '') ? null : (float)$asset[$field];
    return $asset;
}

function backup_asset_deletion_json(array $record): array
{
    foreach (['id','assetId','invoiceId','deletedByAdminId'] as $field) {
        $record[$field] = ($record[$field] === null || $record[$field] === '') ? null : (int)$record[$field];
    }
    return $record;
}

function download_backup(): never
{
    $assets = array_map('backup_asset_json', all_assets());
    $deletionAudits = array_map('backup_asset_deletion_json', all_asset_deletion_audits());
    $invoices = all_invoices();
    $catalogs = get_catalogs(false);
    $catalogExport = ['categories' => $catalogs['category'] ?? [], 'locations' => $catalogs['location'] ?? []];
    $itemCount = 0; $xmlEntries = []; $files = [];
    foreach ($invoices as $index => &$invoice) {
        $invoice['id'] = (int)$invoice['id']; $invoice['total'] = (float)$invoice['total'];
        foreach ($invoice['items'] as &$item) {
            $item['invoiceId']=(int)$invoice['id'];
            foreach(['quantity','unitValue','total'] as $field) $item[$field]=(float)$item[$field];
            $item['usefulLife']=(int)$item['usefulLife']; $itemCount++;
        }
        unset($item);
        if (!empty($invoice['xml'])) {
            $path=sprintf('xml/nfe-%06d.xml',$index+1);
            $files[$path]=(string)$invoice['xml'];
            $xmlEntries[]=['invoiceId'=>(int)$invoice['id'],'number'=>(string)$invoice['number'],'series'=>(string)$invoice['series'],'path'=>$path,'reason'=>null];
        } else $xmlEntries[]=['invoiceId'=>(int)$invoice['id'],'number'=>(string)$invoice['number'],'series'=>(string)$invoice['series'],'path'=>null,'reason'=>'XML original não disponível para esta nota.'];
        unset($invoice['xml']);
    }
    unset($invoice);
    $fixed=array_values(array_filter($assets,static fn(array $a):bool=>$a['classification']==='fixed'&&$a['status']==='active'));
    $small=array_values(array_filter($assets,static fn(array $a):bool=>$a['classification']==='small'&&$a['status']==='active'));
    $disposed=array_values(array_filter($assets,static fn(array $a):bool=>$a['status']==='disposed'));
    $xmlCount=count(array_filter($xmlEntries,static fn(array $e):bool=>$e['path']!==null));
    $files['manifesto.json']=json_encode(['format'=>'ativa-complete-backup','version'=>1,'generatedAt'=>date(DATE_ATOM),'encrypted'=>false,'scope'=>'Todos os dados da instalação, sem depender dos filtros do relatório.','automaticRestoreSupported'=>true,'counts'=>['assets'=>count($assets),'active'=>count(array_filter($assets,static fn(array $a):bool=>$a['status']==='active')),'disposed'=>count($disposed),'invoices'=>count($invoices),'invoiceItems'=>$itemCount,'assetDeletionAudits'=>count($deletionAudits),'xmlFiles'=>$xmlCount,'invoicesWithoutXml'=>count($invoices)-$xmlCount,'categories'=>count($catalogs['category']),'locations'=>count($catalogs['location'])],'xmlEntries'=>$xmlEntries],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    $files['dados/ativos.json']=json_encode($assets,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $files['dados/historico-exclusoes-ativos.json']=json_encode($deletionAudits,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $files['dados/notas-fiscais.json']=json_encode($invoices,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    $files['dados/padroes-de-cadastro.json']=json_encode($catalogExport,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
    foreach ([['dados/ativos-fixos.csv',$fixed],['dados/bens-pequeno-valor.csv',$small],['dados/baixas.csv',$disposed]] as [$path,$rows]) { $data=asset_csv_rows($rows); $files[$path]=csv_document(array_shift($data),$data); }
    $rows=invoice_csv_rows($invoices); $files['dados/notas-fiscais.csv']=csv_document(array_shift($rows),$rows);
    $rows=invoice_item_csv_rows($invoices); $files['dados/itens-das-nfes.csv']=csv_document(array_shift($rows),$rows);
    $files['planilhas/controle-patrimonial.xlsx']=xlsx_build(asset_workbook_rows($assets));
    $files['planilhas/notas-fiscais.xlsx']=xlsx_build(invoice_workbook_rows($invoices));
    $files['LEIA-ME.txt']="Backup completo do Controle de Ativos — NF-e.\n\nGerado em: ".date('d/m/Y H:i:s')."\nAtivos: ".count($assets)."\nNotas fiscais: ".count($invoices)."\nHistóricos de exclusão: ".count($deletionAudits)."\nXMLs incluídos: ".$xmlCount."\n\nEste arquivo contém dados patrimoniais, fiscais e motivos de exclusão sensíveis e não é criptografado. Guarde-o em local restrito.\n\nO conteúdo JSON preserva os dados completos, inclusive o histórico administrativo. CSVs abrem no Excel e arquivos XLSX reproduzem apenas as abas patrimoniais/fiscais; motivos de exclusão não aparecem nesses relatórios. Notas sem XML são indicadas no manifesto e não são reconstruídas.\n";
    if (count($files) > 120) throw new RuntimeException('O backup excedeu o limite seguro de arquivos para restauração. Use também a cópia do banco no painel da hospedagem.');
    $uncompressedBytes = array_sum(array_map('strlen', $files));
    if ($uncompressedBytes > 19 * 1024 * 1024) throw new RuntimeException('O backup passou do limite seguro de restauração via navegador. Faça também uma cópia completa do banco MySQL no painel de hospedagem.');
    $archive = zip_build_stored($files);
    if (strlen($archive) > 20 * 1024 * 1024) throw new RuntimeException('O backup passou de 20 MB, limite da restauração via navegador. Faça também uma cópia completa do banco MySQL no painel de hospedagem.');
    emit_download('Controle-Patrimonial-backup-'.date('Ymd-His').'.zip','application/zip',$archive);
}

function download_invoice_xml(int $id): never
{
    $statement=db()->prepare('SELECT number, xml FROM invoices WHERE id = ? LIMIT 1');
    $statement->execute([$id]); $invoice=$statement->fetch();
    if(!$invoice||empty($invoice['xml'])) throw new RuntimeException('O XML original não está disponível.');
    $number=preg_replace('/[^A-Za-z0-9_-]/','',(string)$invoice['number'])?:'nota';
    emit_download('NFe-'.$number.'.xml','application/xml; charset=utf-8',(string)$invoice['xml']);
}
