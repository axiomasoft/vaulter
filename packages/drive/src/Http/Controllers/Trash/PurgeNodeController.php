<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Trash;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Delete a trashed node for good (Delete). Only what is in the trash can be purged
 * (409 node_not_trashed otherwise); If-Match is required, and a stale one leaves the
 * node and its stored bytes untouched.
 */
#[Internal]
final readonly class PurgeNodeController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $node): Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $expected = $this->nodes->expectedSequenceForReplay($request, $drive, $node, Ability::Delete, includeTrashed: true);
        $drive->trash()->purge($node, $expected);

        return new Response(status: 204);
    }
}
