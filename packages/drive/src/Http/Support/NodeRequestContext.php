<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Support;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Operations\DriveContext;

/** Resolve the request's actor and tenant-scoped drive for a node addressed by an extension route. */
#[Spi(SpiKind::Call)]
final readonly class NodeRequestContext
{
    #[Internal]
    public function __construct(private HttpResourceResolver $resources, private HttpOperationContextFactory $contexts) {}

    public function resolve(Request $request, string $node): DriveContext
    {
        return $this->contexts->driveContext($request, $this->resources->driveOfNode($node));
    }
}
