<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Revoke a link; If-Match carries the link's tag (428 without it, 412 stale_write when it changed since). */
#[Internal]
final readonly class RevokeShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private AggregatePreconditions $preconditions) {}

    public function __invoke(Request $request, string $node, string $share): Response
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $drive->shares()->revoke($node, $share, $this->preconditions->expectedShareVersion($request, $drive, $node, $share));

        return new Response(status: 204);
    }
}
