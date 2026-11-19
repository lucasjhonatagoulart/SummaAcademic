<?php

declare(strict_types=1);

function normalize_mysql_datetime(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    $text = str_replace('T', ' ', (string)$value);
    $text = preg_replace('/\.\d+(?=Z?$)/', '', $text) ?? $text;
    $text = rtrim($text, 'Z');
    return substr($text, 0, 19) ?: null;
}

function json_preserve(mixed $value): ?string
{
    if ($value === null || $value === '') return null;
    if (is_string($value)) return $value;
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}
