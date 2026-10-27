<?php

declare(strict_types=1);

namespace Vaulter\Documents\Export;

use Vaulter\Attributes\Api;
use Vaulter\Documents\Contracts\ExportFilenameResolver;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

/**
 * The default {@see ExportFilenameResolver}: the node's basename plus the exporter's
 * extension (e.g. `Report.html`). A host rebinds the contract for another scheme.
 */
#[Api]
final class DefaultExportFilenameResolver implements ExportFilenameResolver
{
    public function resolve(NodeSnapshot $node, string $extension): string
    {
        $base = pathinfo($node->name, PATHINFO_FILENAME);
        $base = $base === '' ? 'document' : $base;

        return $base.'.'.$extension;
    }
}
