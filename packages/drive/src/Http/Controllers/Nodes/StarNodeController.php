<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Star a node for the caller (View). Personal and idempotent: it changes only this
 * caller's representation (viewer.starred and so its tag), never the node's sequence.
 */
#[Internal]
final readonly class StarNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));

        return $this->nodes->respond($drive, $drive->nodes()->star($node));
    }
}
