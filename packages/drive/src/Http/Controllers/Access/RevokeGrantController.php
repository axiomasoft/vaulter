<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Access;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Revoke one grant; a grant of another node is as unknown as a missing one. If-Match carries the node's access tag
 * from grants.index (428 without it, 412 stale_write when the access changed since).
 */
#[Internal]
final readonly class RevokeGrantController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(Request $request, string $node, string $grant): Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $drive->access()->revoke($node, $grant, $this->preconditions->expectedAccessVersion($request, $drive, $node, $grant));

        return new Response(status: 204);
    }
}
