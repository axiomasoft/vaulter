<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Http\Resources\Sharing\ShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;

/** The live share links of a node (Share); tokens are never listed. */
#[Internal]
final readonly class ListSharesController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $shares = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares()->list($node);

        return new JsonResponse(['data' => array_map(static fn (ShareLinkSnapshot $share): ShareResource => new ShareResource($share), $shares)]);
    }
}
