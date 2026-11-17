<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Packman\Attributes\Internal;
use Vaulter\Drive\Configuration\SharingOptions;
use Vaulter\Drive\Http\Requests\Sharing\StoreShareLinkRequest;
use Vaulter\Drive\Http\Resources\Sharing\MintedShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** Create a share link; the response is the only place its token appears. */
#[Internal]
final readonly class CreateShareController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private SharingOptions $sharing) {}

    public function __invoke(StoreShareLinkRequest $request, string $node): JsonResponse
    {
        $minted = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares()->create($node, $request->toData());

        return new MintedShareResource($minted, $this->sharing->urlBase)->response()->setStatusCode(201);
    }
}
