<?php

declare(strict_types=1);

function report_asset_field_definitions(): array
{
    return [
        'name' => ['label' => 'Item', 'weight' => 3.2, 'excel_type' => 'text'],
        'originalDescription' => ['label' => 'Descrição original', 'weight' => 2.2, 'excel_type' => 'text'],
        'classification' => ['label' => 'Classificação', 'weight' => 1.3, 'excel_type' => 'text'],
        'category' => ['label' => 'Conta Patrimonial', 'weight' => 1.25, 'excel_type' => 'text'],
        'location' => ['label' => 'Local', 'weight' => 1.15, 'excel_type' => 'text'],
        'patrimony' => ['label' => 'Patrimônio', 'weight' => 0.95, 'excel_type' => 'text'],
        'brand' => ['label' => 'Marca', 'weight' => 1.0, 'excel_type' => 'text'],
        'color' => ['label' => 'Cor', 'weight' => 0.8, 'excel_type' => 'text'],
        'type' => ['label' => 'Tipo', 'weight' => 1.0, 'excel_type' => 'text'],
        'condition' => ['label' => 'Estado', 'weight' => 0.95, 'excel_type' => 'text'],
        'invoiceNumber' => ['label' => 'Nota fiscal', 'weight' => 0.82, 'excel_type' => 'text'],
        'acquiredAt' => ['label' => 'Data de aquisição', 'weight' => 0.9, 'excel_type' => 'date'],
        'quantity' => ['label' => 'Quantidade', 'weight' => 0.72, 'excel_type' => 'number'],
        'value' => ['label' => 'Valor de aquisição', 'weight' => 0.86, 'excel_type' => 'number'],
        'usefulLife' => ['label' => 'Vida útil', 'weight' => 0.72, 'excel_type' => 'integer'],
        'status' => ['label' => 'Status', 'weight' => 0.65, 'excel_type' => 'text'],
        'disposalDate' => ['label' => 'Data da baixa', 'weight' => 0.9, 'excel_type' => 'date'],
        'disposalReason' => ['label' => 'Motivo da baixa', 'weight' => 1.8, 'excel_type' => 'text'],
        'notes' => ['label' => 'Observações', 'weight' => 2.0, 'excel_type' => 'text'],
        'purchaseYear' => ['label' => 'Ano da compra', 'weight' => 0.72, 'excel_type' => 'integer'],
        'legacyId' => ['label' => 'Identificador histórico', 'weight' => 1.0, 'excel_type' => 'text'],
        'sourceSheet' => ['label' => 'Aba de origem', 'weight' => 1.0, 'excel_type' => 'text'],
        'sourceRow' => ['label' => 'Linha de origem', 'weight' => 0.72, 'excel_type' => 'integer'],
    ];
}

function report_default_field_keys(): array
{
    return ['name', 'patrimony', 'category', 'location', 'invoiceNumber', 'acquiredAt', 'value', 'status'];
}

function report_selected_field_keys(mixed $input, bool $useDefaults = false): array
{
    $definitions = report_asset_field_definitions();
    if (!is_array($input)) $input = [];
    if ($useDefaults && !$input) return report_default_field_keys();

    $selected = [];
    foreach (array_slice($input, 0, count($definitions)) as $key) {
        if (is_string($key) && isset($definitions[$key]) && !in_array($key, $selected, true)) $selected[] = $key;
    }
    return $selected;
}

function report_valid_iso_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date instanceof DateTimeImmutable
        && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function report_filters_from_input(array $input): array
{
    $rawFrom = is_string($input['from'] ?? null) ? trim($input['from']) : '';
    $rawTo = is_string($input['to'] ?? null) ? trim($input['to']) : '';
    $invalidDate = ($rawFrom !== '' && !report_valid_iso_date($rawFrom)) || ($rawTo !== '' && !report_valid_iso_date($rawTo));
    $from = $rawFrom !== '' && report_valid_iso_date($rawFrom) ? $rawFrom : '';
    $to = $rawTo !== '' && report_valid_iso_date($rawTo) ? $rawTo : '';
    $status = is_string($input['status'] ?? null) ? $input['status'] : (is_string($input['scope'] ?? null) ? $input['scope'] : 'active');
    if (!in_array($status, ['active', 'disposed', 'all'], true)) $status = 'active';
    $assetClass = is_string($input['asset_class'] ?? null) ? $input['asset_class'] : 'all';
    if (!in_array($assetClass, ['fixed', 'small', 'all'], true)) $assetClass = 'all';
    $orientation = is_string($input['orientation'] ?? null) ? $input['orientation'] : 'auto';
    if (!in_array($orientation, ['auto', 'portrait', 'landscape'], true)) $orientation = 'auto';

    return [
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'asset_class' => $assetClass,
        'orientation' => $orientation,
        'invalid_date' => $invalidDate,
        'invalid_range' => !$invalidDate && $from !== '' && $to !== '' && $from > $to,
    ];
}

function report_filter_error(array $filters): string
{
    if (!empty($filters['invalid_date'])) return 'Informe as datas no formato válido.';
    if (!empty($filters['invalid_range'])) return 'A data inicial deve ser anterior ou igual à data final.';
    return '';
}

function report_filter_assets(array $assets, array $filters): array
{
    if (report_filter_error($filters) !== '') return [];
    $from = (string)($filters['from'] ?? '');
    $to = (string)($filters['to'] ?? '');
    $status = (string)($filters['status'] ?? 'active');
    $assetClass = (string)($filters['asset_class'] ?? 'all');

    return array_values(array_filter($assets, static function (array $asset) use ($from, $to, $status, $assetClass): bool {
        if ($status !== 'all' && (string)($asset['status'] ?? '') !== $status) return false;
        if ($assetClass !== 'all' && (string)($asset['classification'] ?? '') !== $assetClass) return false;
        $date = substr((string)($asset['acquiredAt'] ?? ''), 0, 10);
        if ($from !== '' && ($date === '' || $date < $from)) return false;
        if ($to !== '' && ($date === '' || $date > $to)) return false;
        return true;
    }));
}

function report_value_to_cents(mixed $value): int
{
    if (!is_scalar($value) || $value === '') return 0;
    $text = trim((string)$value);
    $text = str_replace(['R$', ' '], '', $text);
    if (str_contains($text, ',') && str_contains($text, '.')) {
        if ((int)strrpos($text, ',') > (int)strrpos($text, '.')) $text = str_replace(',', '.', str_replace('.', '', $text));
        else $text = str_replace(',', '', $text);
    } elseif (str_contains($text, ',')) {
        $text = str_replace(',', '.', $text);
    }
    if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $matches)) return 0;
    $whole = (int)$matches[2];
    $fraction = (string)($matches[3] ?? '');
    $cents = (int)str_pad(substr($fraction, 0, 2), 2, '0');
    if (strlen($fraction) > 2 && (int)$fraction[2] >= 5) $cents++;
    if ($cents >= 100) { $whole++; $cents = 0; }
    $total = $whole * 100 + $cents;
    return ($matches[1] ?? '') === '-' ? -$total : $total;
}

function report_total_cents(array $assets): int
{
    $total = 0;
    foreach ($assets as $asset) $total += report_value_to_cents($asset['value'] ?? null);
    return $total;
}

function report_cents_to_float(int $cents): float
{
    return $cents / 100;
}

function report_format_cents(int $cents): string
{
    $absolute = abs($cents);
    $sign = $cents < 0 ? '-' : '';
    return 'R$ ' . $sign . number_format(intdiv($absolute, 100), 0, ',', '.') . ',' . str_pad((string)($absolute % 100), 2, '0', STR_PAD_LEFT);
}

function report_format_date(mixed $value): string
{
    $raw = (string)$value;
    if ($raw === '') return '';
    $date = substr($raw, 0, 10);
    if (!report_valid_iso_date($date)) return $raw;
    return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
}

function asset_invoice_display_label(array $asset): string
{
    $number = trim((string)($asset['invoiceNumber'] ?? ''));
    return $number === '' ? 'Sem NF-e' : $number;
}

function report_field_display_value(array $asset, string $key): string
{
    $definitions = report_asset_field_definitions();
    if (!isset($definitions[$key])) return '';
    if ($key === 'invoiceNumber') return asset_invoice_display_label($asset);
    $value = $asset[$key] ?? null;
    if ($value === null || $value === '') {
        if ($key === 'purchaseYear' && !empty($asset['acquiredAt'])) return substr((string)$asset['acquiredAt'], 0, 4);
        return '';
    }
    if ($key === 'classification') return ($value === 'small') ? 'Bem de pequeno valor' : 'Ativo imobilizado';
    if ($key === 'status') return ($value === 'disposed') ? 'Baixado' : 'Ativo';
    if ($key === 'value') return report_format_cents(report_value_to_cents($value));
    if ($key === 'quantity') {
        $formatted = number_format((float)$value, 3, ',', '.');
        return rtrim(rtrim($formatted, '0'), ',');
    }
    if ($key === 'usefulLife') return ((int)$value) . ((int)$value === 1 ? ' ano' : ' anos');
    if ($key === 'acquiredAt' || $key === 'disposalDate') return report_format_date($value);
    if ($key === 'purchaseYear' || $key === 'sourceRow') return (string)(int)$value;
    return (string)$value;
}

function report_field_excel_value(array $asset, string $key): string|int|float|null
{
    $definition = report_asset_field_definitions()[$key] ?? null;
    if (!$definition) return null;
    if ($key === 'invoiceNumber') return asset_invoice_display_label($asset);
    $value = $asset[$key] ?? null;
    if ($value === null || $value === '') {
        if ($key === 'purchaseYear' && !empty($asset['acquiredAt'])) return (int)substr((string)$asset['acquiredAt'], 0, 4);
        return null;
    }
    return match ($definition['excel_type']) {
        'number' => is_numeric($value) ? (float)$value : null,
        'integer' => is_numeric($value) ? (int)$value : null,
        default => report_field_display_value($asset, $key),
    };
}

function report_xlsx_rows(array $assets, array $fieldKeys, array $filters): array
{
    $definitions = report_asset_field_definitions();
    $fields = report_selected_field_keys($fieldKeys);
    if (!$fields) throw new RuntimeException('Selecione pelo menos um campo para exportar o relatório.');
    $filtersError = report_filter_error($filters);
    if ($filtersError !== '') throw new RuntimeException($filtersError);

    $fromLabel = ($filters['from'] ?? '') !== '' ? report_format_date($filters['from']) : 'Sem limite inicial';
    $toLabel = ($filters['to'] ?? '') !== '' ? report_format_date($filters['to']) : 'Sem limite final';
    $statusLabels = ['active' => 'Ativos', 'disposed' => 'Baixas', 'all' => 'Todos'];
    $classLabels = ['fixed' => 'Ativo imobilizado', 'small' => 'Bem de pequeno valor', 'all' => 'Todos'];
    $rows = [
        ['Relatório patrimonial'],
        ['Período', $fromLabel . ' a ' . $toLabel, 'Status', $statusLabels[$filters['status']] ?? 'Todos', 'Classificação', $classLabels[$filters['asset_class']] ?? 'Todos'],
        [],
        array_map(static fn(string $key): string => $definitions[$key]['label'], $fields),
    ];
    foreach ($assets as $asset) $rows[] = array_map(static fn(string $key): string|int|float|null => report_field_excel_value($asset, $key), $fields);
    $rows[] = [];
    $rows[] = ['Valor total de aquisição após filtros', report_cents_to_float(report_total_cents($assets))];
    return $rows;
}

function download_report_xlsx(): never
{
    require_permission('export_data');
    if ((string)($_GET['report'] ?? '') !== '1') throw new RuntimeException('Gere um relatório antes de exportar para Excel.');
    $fields = report_selected_field_keys($_GET['fields'] ?? []);
    $filters = report_filters_from_input($_GET);
    $error = report_filter_error($filters);
    if ($error !== '') throw new RuntimeException($error);
    $assets = report_filter_assets(all_assets(), $filters);
    $rows = report_xlsx_rows($assets, $fields, $filters);
    $sheet = xlsx_build(['Relatório patrimonial' => $rows]);
    $filename = 'Controle-Patrimonial-relatorio-' . date('Ymd-His') . '.xlsx';
    emit_download($filename, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $sheet);
}
