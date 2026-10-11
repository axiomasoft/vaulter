<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Controllers\Nodes;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Http\Resources\NodeResponses;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Http\Resources\PageResponse;
use Vaulter\Http\Support\HttpOperationContextFactory;
use Vaulter\Http\Support\HttpResourceResolver;
use Vaulter\Http\Support\Preconditions;

/**
 * One node as the caller sees it, with its tag in the ETag header. A matching
 * If-None-Match is answered 304 only after the node was found as this caller, so a
 * caller who lost access gets 404, never a cached "not modified".
 *
 * `?include=breadcrumb,children` adds the visible ancestors and the first page of a
 * folder's children; that response is a different variant and carries no ETag.
 */
final readonly class ShowNodeController
{
    private const array INCLUDES = ['breadcrumb', 'children'];

    public function __construct(private HttpResourceResolver $resolver, private HttpOperationContextFactory $contexts, private NodeResponses $nodes) {}

    public function __invoke(Request $request, string $node): JsonResponse|Response
    {
        $include = $this->includes($request);
        $perPage = in_array('children', $include, true) ? PageResponse::perPage($request) : PageResponse::DEFAULT_PER_PAGE;
        $drive = $this->contexts->forDrive($request, $this->resolver->driveOfNode($node));
        $resource = $this->nodes->current($request, $drive, $node);

        if ($include === []) {
            if (Preconditions::notModified($request, $resource->etag())) {
                return (new Response(status: 304))->setEtag($resource->etag());
            }

            return $resource->response()->setEtag($resource->etag());
        }

        /** @var NodeSnapshot $snapshot */
        $snapshot = $resource->resource;
        $data = $resource->toArray($request);
        if (in_array('breadcrumb', $include, true)) {
            $data['breadcrumb'] = $this->nodes->breadcrumb($request, $drive, $snapshot);
        }
        if (in_array('children', $include, true)) {
            $data['children'] = $snapshot->kind === NodeKind::Folder
                ? $this->nodes->page($drive, $drive->nodes()->children($snapshot->id, new ListOptions(perPage: $perPage)), $perPage)->getData()
                : null;
        }

        return new JsonResponse(['data' => $data]);
    }

    /** @return list<string> */
    private function includes(Request $request): array
    {
        $raw = $request->query('include');
        $include = is_string($raw) && $raw !== '' ? array_values(array_unique(array_map(trim(...), explode(',', $raw)))) : [];
        Validator::make(['include' => $include], ['include.*' => ['in:'.implode(',', self::INCLUDES)]])->validate();

        return $include;
    }
}
