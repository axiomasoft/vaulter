<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Rename a node (Edit); If-Match is required; idempotent by Idempotency-Key. */
final readonly class UpdateNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(RenameNodeRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        try {
            $expected = $this->nodes->expectedSequence($request, $drive, $node, Ability::Edit);
        } catch (StaleNodeException|NodeNotFoundException $refusal) {
            // A retry under its Idempotency-Key repeats the If-Match of the first request, stale once that one
            // committed — and its node may be gone since (purged, in the trash). The operation answers it from the
            // receipt, or — without one — refuses it as stale or missing and stores that refusal (D25). The sequence
            // PHP_INT_MAX stands for "no live node holds it"; a node that does hold it keeps the 412 right here.
            if (ReadIdempotencyKey::key($request) === null || ($refusal instanceof StaleNodeException && $drive->nodes()->find($node)->sequence === PHP_INT_MAX)) {
                throw $refusal;
            }
            $expected = PHP_INT_MAX;
        }

        return $this->nodes->respond($drive, $drive->nodes()->rename($node, $request->name(), $expected));
    }
}
