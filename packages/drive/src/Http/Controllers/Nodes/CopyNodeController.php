<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Data\Drive\CopyOptions;
use Vaulter\Drive\Http\Requests\Nodes\CopyNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/**
 * Copy a node (Copy on the source, Create in the destination). The source is not
 * changed, so no If-Match is asked for; the copy is a new node.
 */
final readonly class CopyNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(CopyNodeRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $copy = $drive->nodes()->copy($node, $request->destinationId(), new CopyOptions(name: $request->name()));

        return $this->nodes->respond($drive, $copy, 201);
    }
}
