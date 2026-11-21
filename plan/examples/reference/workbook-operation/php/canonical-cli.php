<?php

declare(strict_types=1);

/**
 * Digest parity tool: reads JSON texts (one per line) from stdin, prints for each line
 * {"ok":true,"canonical":...,"sha256":...} or {"ok":false,"code":...}. Used by the check to
 * compare the PHP owner with the Node runtime byte for byte.
 */

namespace Vaulter\Reference\Workbook;

require_once __DIR__ . '/Wire.php';

while (($line = fgets(STDIN)) !== false) {
    $line = rtrim($line, "\n");
    try {
        $canonical = CanonicalJson::encode(StrictJson::decode($line));
        $out = ['ok' => true, 'canonical' => $canonical, 'sha256' => hash('sha256', $canonical)];
    } catch (WireException $e) {
        $out = ['ok' => false, 'code' => $e->wireCode, 'message' => $e->getMessage()];
    }
    fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
}
