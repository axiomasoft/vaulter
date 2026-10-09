<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Models\Drive\Node;

/**
 * The default {@see ExportFilenameResolver}: the node's basename plus the exporter's
 * extension (e.g. `Report.html`). A host rebinds the contract for another scheme.
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
