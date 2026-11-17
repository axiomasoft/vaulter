<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Http\Requests\Sharing\UpdateShareLinkRequest;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Resources\Sharing\ShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Change a live link; If-Match carries the link's tag (428 without it, 412 stale_write when it changed since). */
#[Internal]
final readonly class UpdateShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(UpdateShareLinkRequest $request, string $node, string $share): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $updated = $drive->shares()->update($node, $share, $request->toData(), $this->preconditions->expectedShareVersion($request, $drive, $node, $share));
        $resource = new ShareResource($updated);

        return $resource->response()->setEtag($resource->etag());
    }
}
