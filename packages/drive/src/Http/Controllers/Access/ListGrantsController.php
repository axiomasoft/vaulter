<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Http\Resources\Access\GrantResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** The grants set directly on a node (Manage). */
#[Internal]
final readonly class ListGrantsController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $grants = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->access()->grants($node);

        return new JsonResponse(['data' => array_map(static fn (GrantSnapshot $grant): GrantResource => new GrantResource($grant), $grants)]);
    }
}
