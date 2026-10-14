<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Vaulter\Kernel;
use Vaulter\Modules\ModuleGraphException;

/**
 * The one route the core registers under the Vaulter prefix while the module graph is invalid: every
 * request answers 503 `module_graph_invalid` instead of a 404 for a route no module mounted.
 */
final class ModuleGraphInvalidController
{
    public function __invoke(Kernel $kernel): never
    {
        throw ModuleGraphException::invalid($kernel->reasons());
    }
}
