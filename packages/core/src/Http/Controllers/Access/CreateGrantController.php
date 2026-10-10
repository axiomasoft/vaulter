<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Vaulter\Http\Requests\Access\CreateGrantRequest;
use Vaulter\Http\Resources\Access\GrantResource;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

final readonly class CreateGrantController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(CreateGrantRequest $request, string $node): JsonResponse
    {
        $grant = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->access()->grant($node, $request->toData());

        return (new GrantResource($grant))->response()->setStatusCode(201);
    }
}
