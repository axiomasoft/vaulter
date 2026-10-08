<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\GrantSet;
use Vaulter\Access\ValueObjects\NodeAbilities;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Drive\NodeKind;

/**
 * The read shape of a Drive node (§2.2 / §5): the unified folder|file|document
 * leaf the Vue client renders. Read-only — it transforms a {@see NodeSnapshot} and
 * the optional children/breadcrumb snapshots handed to it.
 *
 * The `abilities` object is filled by the AccessResolver (§2.6 / H.2) for the
 * request's actor, so the client renders only the actions that actor may perform
 * on this node. With no acting user every flag is false (a read-only shell).
 *
 * @mixin NodeSnapshot
 */
final class NodeResource extends JsonResource
{
    private ?GrantSet $grantSet = null;

    /** @var array<string, GrantSet> */
    private array $childrenGrants = [];

    /** @var list<NodeSnapshot>|null */
    private ?array $children = null;

    /** @var list<NodeSnapshot>|null */
    private ?array $breadcrumb = null;

    public function withGrantSet(GrantSet $set): static
    {
        $this->grantSet = $set;

        return $this;
    }

    /** @param array<string, GrantSet> $sets */
    public function withChildrenGrantSets(array $sets): self
    {
        $this->childrenGrants = $sets;

        return $this;
    }

    /**
     * Embed the direct children of this node in the response (the folder listing
     * a `show` may return alongside the node).
     *
     * @param list<NodeSnapshot> $children
     */
    public function withChildren(array $children): self
    {
        $this->children = $children;

        return $this;
    }

    /**
     * Embed the breadcrumb chain (root first, this node last) in the response.
     *
     * @param list<NodeSnapshot> $breadcrumb
     */
    public function withBreadcrumb(array $breadcrumb): self
    {
        $this->breadcrumb = $breadcrumb;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeSnapshot $node */
        $node = $this->resource;

        return [
            'id' => $node->id,
            'kind' => $node->kind->value,
            'name' => $node->name,
            'mime' => $node->mimeType,
            'extension' => $node->extension,
            'size_bytes' => $node->sizeBytes,
            'storage_id' => $node->driveId,
            'parent_id' => $node->parentId,
            ...$this->headKeys($node),
            'trashed_at' => SnapshotValue::iso($node->trashedAt),
            'created_at' => SnapshotValue::iso($node->createdAt),
            'updated_at' => SnapshotValue::iso($node->updatedAt),
            'content_modified_at' => SnapshotValue::iso($node->contentModifiedAt),
            'viewer' => $node->viewer === null ? null : ['starred' => $node->viewer->starred],
            'children' => $this->children === null
                ? null
                : self::collection(array_map(fn (NodeSnapshot $child): self => $this->child($child), $this->children)),
            'breadcrumb' => $this->breadcrumb === null
                ? null
                : self::collection($this->breadcrumb),
            'abilities' => $this->abilities($request, $node),
            // The resolved format handler (§4.5 / H.5): id + capabilities + ui so the
            // Vue client routes the node to the right editor/preview/download surface.
            // A folder has no payload, so it carries no handler descriptor.
            'handler' => $this->handler($node),
        ];
    }

    private function child(NodeSnapshot $child): self
    {
        $resource = self::make($child);
        if (isset($this->childrenGrants[$child->id])) {
            $resource->withGrantSet($this->childrenGrants[$child->id]);
        }

        return $resource;
    }

    /**
     * The handler descriptor for a leaf node (file/document/shortcut) — resolved
     * through the registry by the node's server-trusted mime/extension (H.5). Null
     * for a folder.
     *
     * The {@see NodePresenter} contract and its engine are core-owned since
     * P0.4/D10: a host running Drive WITHOUT vaulter-documents still gets a real
     * preview/download descriptor (never a degrade-to-null) — the built-in
     * PreviewHandler/DownloadOnlyHandler are the guaranteed baseline; vaulter-documents
     * only adds richer formats (TipTap, Univer, …) on top.
     *
     * @return array<string, mixed>|null
     */
    private function handler(NodeSnapshot $node): ?array
    {
        if ($node->kind === NodeKind::Folder) {
            return null;
        }

        return app(NodePresenter::class)->present($node)->toArray();
    }

    /**
     * The ability map the Vue client binds against, resolved through the
     * AccessResolver for the request's actor on this node (H.2). No acting user ⇒
     * an all-false map (a read-only shell), never a mis-advertised action.
     *
     * @return array<string, bool>
     */
    private function abilities(Request $request, NodeSnapshot $snapshot): array
    {
        if ($this->grantSet !== null) {
            return NodeAbilities::fromGrantSet($this->grantSet)->toArray();
        }

        $user = $request->user();
        if (!$user instanceof Authorizable) {
            return NodeAbilities::denied()->toArray();
        }

        return app(AccessResolver::class)->abilitiesForSnapshot($user, $snapshot);
    }

    /**
     * The legacy file_id/current_version_id keys, read from the kind's own head. Core
     * does not depend on the documents package: it supplies the document head to the
     * snapshot, and without it a document has no head here.
     *
     * @return array{file_id: int|string|null, current_version_id: int|string|null}
     */
    private function headKeys(NodeSnapshot $node): array
    {
        if ($node->kind === NodeKind::File) {
            return ['file_id' => $node->file?->fileId, 'current_version_id' => $node->file?->revisionId];
        }

        if ($node->kind === NodeKind::Document) {
            return ['file_id' => null, 'current_version_id' => $node->document?->headRevisionId];
        }

        return ['file_id' => null, 'current_version_id' => null];
    }
}
