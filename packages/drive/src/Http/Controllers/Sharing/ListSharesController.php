<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Sharing;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Http\Resources\Sharing\ShareResource;
use Vaulter\Drive\Http\Support\HttpOperationContextFactory;
use Vaulter\Drive\Http\Support\HttpResourceResolver;
use Vaulter\Drive\Operations\ShareOperations;
use Vaulter\Http\Resources\PageResponse;

/** One bounded page (default 50, max 200) of the share links of a node (Share); tokens are never listed. */
#[Internal]
final readonly class ListSharesController
{
    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts) {}

    public function __invoke(Request $request, string $node): JsonResponse
    {
        $perPage = PageResponse::perPage($request);
        $cursor = PageResponse::cursor($request);
        $shares = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node))->shares();
        if (!$shares instanceof ShareOperations) {
            throw new LogicException('The drive share operations must be the drive package implementation.');
        }

        return PageResponse::make($shares->paginate($node, $perPage, $cursor), static fn (ShareLinkSnapshot $share): ShareResource => new ShareResource($share), $perPage);
    }
}
