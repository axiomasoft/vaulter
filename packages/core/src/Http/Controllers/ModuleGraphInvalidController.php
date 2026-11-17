<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers;

use Packman\Attributes\Internal;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Kernel;
use Vaulter\Modules\ModuleGraphException;

/**
 * The one route the core registers under the Vaulter prefix while the module graph is invalid: every
 * request answers 503 `module_graph_invalid` instead of a 404 for a route no module mounted. A route cache
 * built while the graph was invalid keeps this catch-all after the graph is fixed: then it is a plain 404
 * and the doctor reports the stale cache.
 */
#[Internal]
final class ModuleGraphInvalidController
{
    public function __invoke(Kernel $kernel): never
    {
        if ($kernel->isValid()) {
            throw new NotFoundHttpException;
        }

        throw ModuleGraphException::invalid($kernel->reasons());
    }
}
