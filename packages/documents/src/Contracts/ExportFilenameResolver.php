<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts;

use Vaulter\Attributes\Spi;
use Vaulter\Attributes\SpiKind;
use Vaulter\Documents\Actions\Export\ExportDocumentAction;
use Vaulter\Documents\Export\DefaultExportFilenameResolver;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;

/**
 * The export filename scheme for {@see ExportDocumentAction}.
 * A host with a different naming convention (e.g. `{name}-v{version}.{ext}`) binds its
 * own implementation over this contract instead of forking the action. The default,
 * {@see DefaultExportFilenameResolver}, is the node basename
 * plus the exporter's extension.
 */
#[Spi(SpiKind::Implement)]
interface ExportFilenameResolver
{
    public function resolve(NodeSnapshot $node, string $extension): string;
}
