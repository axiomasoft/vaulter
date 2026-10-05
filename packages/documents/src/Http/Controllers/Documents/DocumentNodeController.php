<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers\Documents;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Documents\Actions\Documents\CreateDocumentAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Revisions\CreateNamedRevisionAction;
use Vaulter\Documents\Actions\Revisions\RestoreDocumentRevisionAction;
use Vaulter\Documents\Actions\Revisions\UpdateRevisionRetentionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentInput;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Data\Revisions\CreateNamedRevisionInput;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Http\Requests\CreateDocumentNodeRequest;
use Vaulter\Documents\Http\Requests\CreateNamedVersionRequest;
use Vaulter\Documents\Http\Requests\UpdateDocumentContentRequest;
use Vaulter\Documents\Http\Resources\DocumentRevisionResource;
use Vaulter\Documents\Repositories\Revisions\DocumentRevisionReadRepository;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Support\TrashGuard;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The Documents REST surface over the M4a document-node domain (§5 / §7): create a
 * document node, read its content + version history, autosave its content, snapshot
 * a named version, restore a prior version, resolve its handler descriptor and export
 * it. Thin — every write delegates to a final readonly Action, every read to a
 * repository / read-only service; no model queries live here.
 *
 * Authorization (H.2): every method guards the relevant Ability through the
 * AccessResolver before acting (Create on the destination, View to read, Edit to
 * autosave/version/restore); a denial 403s via PermissionDeniedException.
 */
final class DocumentNodeController extends Controller
{
    public function __construct(
        private readonly NodeReadRepository $nodes,
        private readonly DocumentRevisionReadRepository $versions,
        private readonly AccessResolver $access,
        private readonly CreateDocumentAction $createDocument,
        private readonly UpdateDocumentContentAction $updateContent,
        private readonly CreateNamedRevisionAction $createNamedVersion,
        private readonly RestoreDocumentRevisionAction $restoreVersion,
        private readonly UpdateRevisionRetentionAction $releaseKeptVersion,
        private readonly NodePresenter $handlers,
        private readonly NodeExportService $exports,
    ) {}

    /**
     * Create a kind=document node under a folder (or storage root) with its v1
     * version.
     */
    public function store(CreateDocumentNodeRequest $request, Drive $storage): JsonResponse
    {
        $parent = $this->resolveParentById($request->parentId(), $storage);

        $this->guardCreate($request, $storage, $parent);

        $node = $this->createDocument->execute(new CreateDocumentInput(
            storage: $storage,
            name: $request->name(),
            content: $request->content(),
            parent: $parent,
            format: $request->versionFormat(),
            mimeType: $request->nodeMimeType(),
            actorId: $this->actorId($request),
        ));

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    /**
     * The document node plus its current head content.
     */
    public function content(Request $request, Node $node): DocumentRevisionResource
    {
        $this->guardDocument($request, Ability::View, $node);

        $head = $this->versions->head($node) ?? $this->versions->latest($node);

        if ($head === null) {
            abort(404);
        }

        return DocumentRevisionResource::make($head)->withContent();
    }

    /**
     * The version history of a document node, newest first.
     */
    public function versions(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardDocument($request, Ability::View, $node);

        $history = $this->versions
            ->history($node)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return DocumentRevisionResource::collection($history);
    }

    /**
     * Autosave the document node's content (the debounce path).
     */
    public function update(UpdateDocumentContentRequest $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $version = $this->updateContent->execute(new UpdateDocumentContentInput(
            node: $node,
            content: $request->content(),
            yjsState: $request->yjsState(),
            actorId: $this->actorId($request),
        ));

        // Autosave is semantically an UPDATE (200) even when it appended a fresh
        // version row — the resource's wasRecentlyCreated 201 default is overridden.
        return DocumentRevisionResource::make($version)->response()->setStatusCode(200);
    }

    /**
     * Snapshot the current head as a NAMED version.
     */
    public function storeVersion(CreateNamedVersionRequest $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $version = $this->createNamedVersion->execute(new CreateNamedRevisionInput(
            node: $node,
            label: $request->label(),
            keepForever: $request->keepForever(),
            actorId: $this->actorId($request),
        ));

        return DocumentRevisionResource::make($version)->response()->setStatusCode(201);
    }

    /**
     * Restore a prior version: repoint the head to a copy of it.
     */
    public function restore(Request $request, Node $node, int|string $version): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $restored = $this->restoreVersion->execute(
            node: $node,
            versionId: $version,
            actorId: $this->actorId($request),
        );

        return DocumentRevisionResource::make($restored)->response()->setStatusCode(201);
    }

    /**
     * Release a version's keep_forever pin (§2.5 / M4a, F-P1.12-03) so it re-enters
     * the prune window on the node's next snapshot/restore. Same guard as setting
     * the pin (storeVersion) — Edit on the node.
     */
    public function releaseVersion(Request $request, Node $node, int|string $version): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $released = $this->releaseKeptVersion->execute($node, $version);

        return DocumentRevisionResource::make($released)->response();
    }

    /**
     * The resolved handler descriptor (id + capabilities + ui) for a node so the
     * client knows which editor/preview/download surface to mount (§4.5).
     */
    public function handler(Request $request, Node $node): JsonResponse
    {
        $this->guard($request, Ability::View, $node);

        return new JsonResponse(['data' => $this->handlers->present($node)->toArray()]);
    }

    /**
     * Export a document node's head content in the requested format (?format=html|
     * markdown|…) through the handler → exporter seam.
     */
    public function export(Request $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::View, $node);

        $format = $request->string('format')->toString();
        $format = $format !== '' ? $format : 'html';

        return new JsonResponse(['data' => $this->exports->export($node, $format)->toArray()]);
    }

    /**
     * Resolve the destination parent from an already-extracted id (null = root).
     */
    private function resolveParentById(int|string|null $parentId, Drive $storage): ?Node
    {
        if ($parentId === null) {
            return null;
        }

        $node = $this->nodes->find($parentId);

        if (!$node instanceof Node || $node->drive_id !== $storage->getKey()) {
            abort(404);
        }

        return $node;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }

    /**
     * Guard an ability on a node that MUST be a document node — a non-document node
     * 404s before the ability check (a folder/file has no document payload here).
     * Item-safety (P2.2 / F-P1.12-01): a trashed node ALSO 404s before the ability
     * check — trash is orthogonal to the ACL, not encoded into it (mirrors
     * DownloadController::guardDownload on the file-content surface).
     */
    private function guardDocument(Request $request, Ability $ability, Node $node): void
    {
        if ($node->kind !== NodeKind::Document) {
            throw NotADocumentException::forNode($node->getKey());
        }

        TrashGuard::assertNotTrashed($node);

        $this->guard($request, $ability, $node);
    }

    /**
     * Authorize an ability on a node through the AccessResolver (H.2); a denial (or
     * no actor) 403s.
     */
    private function guard(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    /**
     * Authorize a document CREATE under a destination (§6): the workspace-create gate
     * AND the CREATE bit on the destination node.
     */
    private function guardCreate(Request $request, Drive $storage, ?Node $destination): void
    {
        $actor = $this->authorizable($request);
        $target = $destination ?? $this->driveRoot($storage);

        if ($actor === null
            || !$this->access->canCreateInWorkspace($actor, $storage)
            || !$this->access->can($actor, Ability::Create, $target)
        ) {
            throw PermissionDeniedException::forAbility(Ability::Create->value);
        }
    }

    /**
     * The storage's root folder node (the create destination when no parent given),
     * resolved through the read repository — never a controller model query.
     */
    private function driveRoot(Drive $storage): Node
    {
        $rootId = $storage->root_node_id;

        $root = $rootId === null ? null : $this->nodes->find($rootId);

        if (!$root instanceof Node) {
            abort(404);
        }

        return $root;
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
