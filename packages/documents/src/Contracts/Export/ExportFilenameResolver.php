<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts\Export;

use Vaulter\Models\Drive\Node;

/**
 * The export filename scheme for {@see \Vaulter\Documents\Export\
 * NodeExportService::export()} (P4.5 / F-P3.1-04). A genuine variance seam: a host
 * with a different naming convention (e.g. `{name}-v{version}.{ext}`) binds its own
 * implementation over this contract instead of forking `NodeExportService`, which
 * stays `final` (H.5, `ArchTest::the_document_handler_registry_and_its_handlers_
 * are_final` covers it alongside `Vaulter\Documents\Engines\Handlers`).
 *
 * The default {@see DefaultExportFilenameResolver} reproduces the pre-P4.5
 * hardcoded scheme (node basename + exporter extension) unchanged.
 */
interface ExportFilenameResolver
{
    public function resolve(Node $node, string $extension): string;
}
