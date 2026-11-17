<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Http\Resources\AggregatePreconditions;
use Vaulter\Drive\Http\Resources\Sharing\MintedShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/**
 * Replace a link's token: the old token and its visits stop working at once. If-Match carries the link's tag (428
 * without it, 412 stale_write when it changed since). A response with a token carries its tag in the body only.
 */
#[Internal]
final readonly class RotateShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private SharingOptions $sharing, private AggregatePreconditions $preconditions) {}

    public function __invoke(Request $request, string $node, string $share): JsonResponse
    {
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $minted = $drive->shares()->rotate($node, $share, $this->preconditions->expectedShareVersion($request, $drive, $node, $share));

        return new MintedShareResource($minted, $this->sharing->urlBase)->response();
    }
}
