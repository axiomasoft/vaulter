<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Configuration\SharingConfig;
use Vaulter\Drive\Http\Resources\Sharing\MintedShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Replace a link's token: the old token and its visits stop working at once. */
#[Internal]
final readonly class RotateShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private SharingConfig $sharing) {}

    public function __invoke(Request $request, string $node, string $share): JsonResponse
    {
        $minted = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares()->rotate($node, $share);

        return (new MintedShareResource($minted, $this->sharing->urlBase()))->response();
    }
}
