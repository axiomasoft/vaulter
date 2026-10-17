<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Requests\Nodes\RenameNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Nodes\StaleNodeException;
use Vaulter\Http\Middleware\ReadIdempotencyKey;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;

/** Rename a node (Edit); If-Match is required; idempotent by Idempotency-Key. */
#[Internal]
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
            // committed — and its node may be in the trash since. The operation answers it from the receipt, or —
            // without one — refuses it as stale or missing and stores that refusal (D25). It gets a sequence no live
            // node holds: PHP_INT_MAX, or the one below it for a node that holds PHP_INT_MAX itself. (A purged node
            // has no drive to resolve from the route: that retry is a 404 before it gets here.)
            if (ReadIdempotencyKey::key($request) === null) {
                throw $refusal;
            }
            $held = $refusal instanceof StaleNodeException ? $drive->nodes()->find($node)->sequence : null;
            $expected = $held === PHP_INT_MAX ? PHP_INT_MAX - 1 : PHP_INT_MAX;
        }

        return $this->nodes->respond($drive, $drive->nodes()->rename($node, $request->name(), $expected));
    }
}
