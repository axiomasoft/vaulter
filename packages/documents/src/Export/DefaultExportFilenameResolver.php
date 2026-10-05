<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Models\Drive\Node;

/**
 * The default {@see ExportFilenameResolver}: the node's basename plus the
 * exporter's extension (e.g. `Report.html`) — the pre-P4.5 hardcoded scheme,
 * unchanged. Bound by {@see VaulterDocumentsServiceProvider};
 * a host rebinds {@see ExportFilenameResolver} to its own class for a different
 * naming scheme (P4.5 / F-P3.1-04).
 */
final class DefaultExportFilenameResolver implements ExportFilenameResolver
{
    public function resolve(Node $node, string $extension): string
    {
        $base = pathinfo($node->name, PATHINFO_FILENAME);
        $base = $base === '' ? 'document' : $base;

        return $base.'.'.$extension;
    }
}
