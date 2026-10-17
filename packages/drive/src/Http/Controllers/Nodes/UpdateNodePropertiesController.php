<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Requests\Nodes\UpdateNodePropertiesRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Patch one property namespace of a node (Edit); If-Match is required. */
#[Internal]
final readonly class UpdateNodePropertiesController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(UpdateNodePropertiesRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequence($request, $drive, $node, Ability::Edit);

        return $this->nodes->respond($drive, $drive->nodes()->setProperties($node, $request->propertyNamespace(), $request->set(), $request->unset(), $expected));
    }
}
