<?php

declare(strict_types=1);

/** Build a ZIP archive using STORE (no compression), avoiding ZipArchive/zlib dependencies. */
function zip_build_stored(array $files): string
{
    $body = '';
    $central = '';
    $offset = 0;
    $fileCount = 0;
    foreach ($files as $name => $contents) {
        if (!is_string($name) || !is_string($contents)) continue;
        $name = str_replace('\\', '/', $name);
        if ($name === '' || str_starts_with($name, '/') || preg_match('~(^|/)\.\.(?:/|$)~', $name)) {
            throw new RuntimeException('Nome de arquivo inválido para o backup.');
        }
        $nameBytes = $name;
        $crc = (int)sprintf('%u', crc32($contents));
        $size = strlen($contents);
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, 0, 0, $crc, $size, $size, strlen($nameBytes), 0) . $nameBytes . $contents;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, 0, 0, $crc, $size, $size, strlen($nameBytes), 0, 0, 0, 0, 0, $offset) . $nameBytes;
        $body .= $local;
        $offset += strlen($local);
        $fileCount++;
    }
    if ($fileCount === 0) throw new RuntimeException('O backup não possui arquivos.');
    $centralOffset = strlen($body);
    $centralSize = strlen($central);
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $fileCount, $fileCount, $centralSize, $centralOffset, 0);
    return $body . $central . $end;
}
