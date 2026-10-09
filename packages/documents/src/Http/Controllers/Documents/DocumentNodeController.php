<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Documents\DocumentsManager;
use Vaulter\Documents\Http\Requests\CreateDocumentNodeRequest;
use Vaulter\Documents\Http\Requests\CreateNamedVersionRequest;
use Vaulter\Documents\Http\Requests\UpdateDocumentContentRequest;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Documents\Operations\DocumentOperations;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * The Documents REST surface (§5 / §7): create a document node, read its content and
 * revision history, autosave its content, snapshot a named revision, restore one,
 * release a pin, resolve its handler descriptor and export it.
 *
 * Thin: every endpoint calls {@see DocumentOperations} as the request's user, which
 * locates the node in its drive and authorizes it (View to read, Edit to write,
 * Download to export, Create on the destination). A node the user cannot see, a
 * trashed node and a node of another tenant all answer 404 node_not_found.
 */
final class DocumentNodeController extends Controller
{
    public function __construct(
        private readonly VaulterManager $vaulter,
        private readonly DocumentsManager $documents,
        private readonly NodePresenter $handlers,
    ) {}

    /**
     * Create a kind=document node under a folder (or storage root) with its first
     * checkpoint.
     */
    public function store(CreateDocumentNodeRequest $request, Drive $storage): JsonResponse
    {
        $node = $this->operations($request, $storage)->create(
            name: $request->name(),
            type: $request->typeKey(),
            content: $request->content(),
            parent: $request->parentId(),
        );

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    /**
     * The document node plus its current working state.
     */
    public function content(Request $request, Node $node): DocumentRevisionResource
    {
        return DocumentRevisionResource::make($this->operationsFor($request, $node)->content($this->key($node)))->withContent();
    }

    /**
     * The revision history of a document node, newest first.
     */
    public function versions(Request $request, Node $node): AnonymousResourceCollection
    {
        $cursor = $request->query('cursor');
        $history = $this->operationsFor($request, $node)->revisionPage(
            $this->key($node),
            new ListOptions(perPage: $this->perPage($request), cursor: is_string($cursor) && $cursor !== '' ? $cursor : null),
        );

        return DocumentRevisionResource::collection($history->withQueryString());
    }

    /**
     * Autosave the document node's content (the debounce path).
     */
    public function update(UpdateDocumentContentRequest $request, Node $node): JsonResponse
    {
        $document = $this->operationsFor($request, $node)->updateContent(
            $this->key($node),
            $request->content(),
            $request->yjsState(),
            $request->expectedStateVersion(),
        );

        // Autosave is an UPDATE of the working state (200) even when it also took a
        // checkpoint.
        return DocumentRevisionResource::make($document)->response()->setStatusCode(200);
    }

    /**
     * Snapshot the current working state as a NAMED revision.
     */
    public function storeVersion(CreateNamedVersionRequest $request, Node $node): JsonResponse
    {
        $revision = $this->operationsFor($request, $node)->snapshot(
            $this->key($node),
            label: $request->label(),
            keepForever: $request->keepForever(),
        );

        return DocumentRevisionResource::make($revision)->response()->setStatusCode(201);
    }

    /**
     * Restore a prior revision: copy it forward as the new head.
     */
    public function restore(Request $request, Node $node, int|string $version): JsonResponse
    {
        $document = $this->operationsFor($request, $node)->restore($this->key($node), Identity::stringify($version));

        return DocumentRevisionResource::make($document->revision)->response()->setStatusCode(201);
    }

    /**
     * Release a revision's keep_forever pin (§2.5 / M4a, F-P1.12-03) so it re-enters
     * the prune window on the node's next checkpoint. Same ability as setting the pin:
     * Edit on the node.
     */
    public function releaseVersion(Request $request, Node $node, int|string $version): JsonResponse
    {
        $released = $this->operationsFor($request, $node)->setRetention($this->key($node), Identity::stringify($version), keepForever: false);

        return DocumentRevisionResource::make($released)->response();
    }

    /**
     * The node's presentation (handler or document type, capabilities, viewer) so the
     * client knows which editor/preview/download surface to mount.
     */
    public function handler(Request $request, Node $node): JsonResponse
    {
        $snapshot = $this->handle($request, $this->driveOf($node))->nodes()->find($this->key($node));

        return new JsonResponse(['data' => $this->handlers->present($snapshot)->toArray()]);
    }

    /**
     * Export a document node's working state in the requested format (?format=html|
     * markdown|…) as a file. The bytes are the exporter's own and are never cached.
     */
    public function export(Request $request, Node $node): Response
    {
        $format = $request->string('format')->toString();
        $format = $format !== '' ? $format : 'html';
        $export = $this->operationsFor($request, $node)->export($this->key($node), $format);

        // An ASCII fallback beside the RFC 6266 filename* keeps old clients on a safe name.
        $fallback = str_replace(['%', '/', '\\', '"'], '_', Str::ascii($export->filename));

        return new Response($export->bytes, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $export->filename, $fallback),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function operations(Request $request, Drive $drive): DocumentOperations
    {
        return $this->documents->in($this->handle($request, $drive));
    }

    private function operationsFor(Request $request, Node $node): DocumentOperations
    {
        return $this->operations($request, $this->driveOf($node));
    }

    private function handle(Request $request, Drive $drive): DriveHandle
    {
        $user = $request->user();
        if (!$user instanceof Authorizable) {
            throw MissingActorException::required();
        }

        return $this->vaulter->drive(ownerOrDrive: $drive)->actingAs($user);
    }

    private function driveOf(Node $node): Drive
    {
        return $node->storage()->first() ?? throw NodeNotFoundException::forId(Identity::stringify($node->getKey()));
    }

    private function key(Node $node): string
    {
        return Identity::stringify($node->getKey());
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }
}
