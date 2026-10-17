<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

#[Internal]
final readonly class RestoreInheritanceController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $snapshot = $drive->access()->restoreInheritance($node);
        // Restricting can remove the caller's own inherited access; the change stands,
        // and the answer then says so with all abilities off.
        $resource = new NodeResource($snapshot, $drive->nodes()->abilitiesMany([$snapshot])[$snapshot->id] ?? NodeAbilities::denied());

        return $resource->response()->setEtag($resource->etag());
    }
}
