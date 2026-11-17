<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Http\Requests\Access\RestrictInheritanceRequest;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** If-Match carries the node's access tag from grants.index (428 without it, 412 stale_write when the access changed since). */
#[Internal]
final readonly class RestrictInheritanceController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(RestrictInheritanceRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $snapshot = $drive->access()->restrictInheritance($node, $request->mode(), $this->preconditions->expectedAccessVersion($request, $drive, $node));
        // Restricting can remove the caller's own inherited access; the change stands,
        // and the answer then says so with all abilities off.
        $resource = new NodeResource($snapshot, $drive->nodes()->abilitiesMany([$snapshot])[$snapshot->id] ?? NodeAbilities::denied());

        return $resource->response()->setEtag($resource->etag());
    }
}
