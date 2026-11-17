<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Trash;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\RestoreOptions;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Requests\Trash\RestoreNodeRequest;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Bring a trash root back (Restore; Create when it lands somewhere else). If-Match is
 * required (idempotent by Idempotency-Key) and is compared with the trashed node as the trash listing showed it.
 */
#[Internal]
final readonly class RestoreNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(RestoreNodeRequest $request, string $node): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequenceForReplay($request, $drive, $node, Ability::Restore, includeTrashed: true);
        $restored = $drive->trash()->restore($node, new RestoreOptions(destination: $request->destinationId(), onConflict: $request->onConflict(), expectedSequence: $expected));

        return $this->nodes->respond($drive, $restored);
    }
}
