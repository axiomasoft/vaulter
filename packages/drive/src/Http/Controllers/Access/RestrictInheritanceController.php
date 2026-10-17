<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\NodeAbilities;
use Vaulter\Drive\Http\Requests\Access\RestrictInheritanceRequest;
use Vaulter\Drive\Http\Resources\Drive\NodeResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

#[Internal]
final readonly class RestrictInheritanceController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(RestrictInheritanceRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $snapshot = $drive->access()->restrictInheritance($node, $request->mode());
        // Restricting can remove the caller's own inherited access; the change stands,
        // and the answer then says so with all abilities off.
        $resource = new NodeResource($snapshot, $drive->nodes()->abilitiesMany([$snapshot])[$snapshot->id] ?? NodeAbilities::denied());

        return $resource->response()->setEtag($resource->etag());
    }
}
