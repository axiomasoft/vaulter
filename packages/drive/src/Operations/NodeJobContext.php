<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Closure;
use Illuminate\Support\Str;
use Packman\Attributes\Internal;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/** Resolve a node job using the host-restored tenant and the original actor's current identity. */
#[Spi(SpiKind::Call)]
final readonly class NodeJobContext
{
    #[Internal]
    public function __construct(private HttpResourceResolver $nodes) {}

    /** @param Closure(DriveContext): void $operation */
    public function run(string $driveId, ?string $tenantId, ActorRef $actor, string $node, Closure $operation): void
    {
        $restore = new RestoreTenantContext($driveId, $tenantId, $actor);
        $restore->handle($this, function () use ($restore, $driveId, $node, $operation): void {
            $drive = $this->nodes->driveOfNode($node);
            if (Identity::stringify($drive->getKey()) === $driveId && $restore->actor() !== null) {
                $operation(DriveContext::for($drive, $restore->actor(), (string) Str::ulid()));
            }
        });
    }
}
