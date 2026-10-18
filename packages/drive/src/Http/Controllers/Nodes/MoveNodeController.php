<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\MoveOptions;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Requests\Nodes\MoveNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Move a node into another folder of its drive (Move, Create there); If-Match is required; `X-Vaulter-Destination-If-Match` optionally pins the destination folder; idempotent by Idempotency-Key. */
#[Internal]
final readonly class MoveNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(MoveNodeRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequenceForReplay($request, $drive, $node, Ability::Move);
        $destination = $request->destinationId();
        $moved = $drive->nodes()->move($node, $destination, new MoveOptions(expectedSequence: $expected, onConflict: $request->onConflict(), expectedDestinationSequence: $this->nodes->expectedDestinationSequence($request, $drive, $destination)));

        return $this->nodes->respond($drive, $moved);
    }
}
