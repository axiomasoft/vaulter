<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Rename a node (Edit); If-Match is required; idempotent by Idempotency-Key. */
#[Internal]
final readonly class UpdateNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(RenameNodeRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        // A retry under its Idempotency-Key repeats the If-Match of the first request: the operation answers it from the receipt.
        $expected = $this->nodes->expectedSequenceForReplay($request, $drive, $node, Ability::Edit);

        return $this->nodes->respond($drive, $drive->nodes()->rename($node, $request->name(), $expected));
    }
}
