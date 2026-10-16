<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Requests\Sharing\UpdateShareLinkRequest;
use Vaulter\Http\Resources\Sharing\ShareResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

#[Internal]
final readonly class UpdateShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(UpdateShareLinkRequest $request, string $node, string $share): JsonResponse
    {
        $updated = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares()->update($node, $share, $request->toData());

        return (new ShareResource($updated))->response();
    }
}
