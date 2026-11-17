<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Http\Requests\Access\CreateGrantRequest;
use Vaulter\Drive\Http\Resources\Access\GrantResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

#[Internal]
final readonly class CreateGrantController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(CreateGrantRequest $request, string $node): JsonResponse
    {
        $grant = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->access()->grant($node, $request->toData());

        return new GrantResource($grant)->response()->setStatusCode(201);
    }
}
