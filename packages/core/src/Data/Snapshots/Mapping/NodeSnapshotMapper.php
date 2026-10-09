<?php

declare(strict_types=1);

namespace Vaulter\Data\Snapshots\Mapping;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Contracts\Drive\NodeDocumentHeads;
use Vaulter\Data\Drive\NodePage;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\ViewerStateSnapshot;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Enums\Drive\ScanStatus;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\NodeUserState;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * @internal Models to NodeSnapshot, in batches: one query for the file heads, one for
 *           the viewer's stars and one for document heads, whatever the page size.
 */
final readonly class NodeSnapshotMapper
{
    public function __construct(private Container $container) {}

    public function map(Node $node, ?ActorRef $viewer = null): NodeSnapshot
    {
        return $this->mapMany([$node], $viewer)[0];
    }

    /** @return Page<NodeSnapshot> */
    public function page(NodePage $page, ?ActorRef $viewer = null): Page
    {
        return new Page(items: $this->mapMany($page->items, $viewer), nextCursor: $page->nextCursor, scanned: $page->scanned);
    }

    /**
     * @param  iterable<Node>     $nodes
     * @return list<NodeSnapshot>
     */
    public function mapMany(iterable $nodes, ?ActorRef $viewer = null): array
    {
        $nodes = array_values(is_array($nodes) ? $nodes : iterator_to_array($nodes, false));
        if ($nodes === []) {
            return [];
        }
        $this->loadHeads($nodes);
        $stars = $this->stars($nodes, $viewer);
        $documents = $this->documents($nodes);

        $snapshots = [];
        foreach ($nodes as $node) {
            $id = SnapshotValue::id($node->getKey());
            $snapshots[] = new NodeSnapshot(
                id: $id,
                driveId: SnapshotValue::id($node->drive_id),
                parentId: SnapshotValue::nullableId($node->parent_id),
                kind: $node->kind,
                name: $node->name,
                mimeType: $node->mime_type,
                extension: $node->extension,
                sizeBytes: $node->size_bytes,
                sequence: $node->sequence,
                inheritsAccess: (bool) $node->inherit_grants,
                properties: self::properties($node->properties),
                createdAt: SnapshotValue::requiredTime($node->created_at),
                createdBy: SnapshotValue::nullableId($node->getAttribute('created_by')),
                updatedAt: SnapshotValue::requiredTime($node->updated_at),
                updatedBy: SnapshotValue::nullableId($node->getAttribute('updated_by')),
                contentModifiedAt: SnapshotValue::time($node->content_modified_at),
                contentModifiedBy: SnapshotValue::nullableId($node->content_modified_by),
                trashedAt: SnapshotValue::time($node->trashed_at),
                trashedBy: SnapshotValue::nullableId($node->trashed_by),
                trashRoot: $node->trashed_at !== null && $node->trash_root_id !== null
                    && SnapshotValue::id($node->trash_root_id) === $id,
                file: $node->kind === NodeKind::File ? $this->fileHead($node) : null,
                document: $documents[$id] ?? null,
                viewer: $stars === null ? null : new ViewerStateSnapshot(starred: isset($stars[$id])),
            );
        }

        return $snapshots;
    }

    /** @param list<Node> $nodes */
    private function loadHeads(array $nodes): void
    {
        $files = new Collection(array_values(array_filter($nodes, static fn (Node $node): bool => $node->kind === NodeKind::File)));
        $files->loadMissing('file');
        $aggregates = new Collection;
        foreach ($files as $node) {
            if ($node->file !== null) {
                $aggregates->push($node->file);
            }
        }
        $aggregates->loadMissing('currentRevision');
    }

    private function fileHead(Node $node): ?FileHeadSnapshot
    {
        $file = $node->file;
        $revision = $file?->currentRevision;
        if ($file === null || $revision === null) {
            return null;
        }

        return new FileHeadSnapshot(
            fileId: SnapshotValue::id($file->getKey()),
            revisionId: SnapshotValue::id($revision->getKey()),
            revisionNumber: $revision->number,
            sha256: $revision->sha256,
            sizeBytes: $revision->size_bytes,
            mimeType: $revision->mime_type,
            scanStatus: ScanStatus::tryFrom($revision->scan_status) ?? ScanStatus::Failed,
        );
    }

    /**
     * Ids of the nodes the viewer starred; null when there is no user viewer.
     *
     * @param  list<Node>               $nodes
     * @return array<string, true>|null
     */
    private function stars(array $nodes, ?ActorRef $viewer): ?array
    {
        if ($viewer === null || $viewer->type === null || $viewer->id === null || $viewer->type === ActorRef::SYSTEM_TYPE) {
            return null;
        }
        $stars = [];
        $missing = [];
        foreach ($nodes as $node) {
            if ($node->relationLoaded('userStates')) {
                foreach ($node->userStates as $state) {
                    if ($state->starred_at !== null && $state->actor_type === $viewer->type && Identity::stringify($state->actor_id) === Identity::stringify($viewer->id)) {
                        $stars[SnapshotValue::id($node->getKey())] = true;
                    }
                }

                continue;
            }
            $missing[] = $node->getKey();
        }
        if ($missing !== []) {
            $states = NodeUserState::query()->where('actor_type', $viewer->type)->where('actor_id', $viewer->id)
                ->whereIn('node_id', $missing)->whereNotNull('starred_at')->pluck('node_id');
            foreach ($states as $nodeId) {
                $stars[SnapshotValue::id($nodeId)] = true;
            }
        }

        return $stars;
    }

    /**
     * @param  list<Node>                          $nodes
     * @return array<string, DocumentHeadSnapshot>
     */
    private function documents(array $nodes): array
    {
        $documents = array_values(array_filter($nodes, static fn (Node $node): bool => $node->kind === NodeKind::Document));
        if ($documents === [] || !$this->container->bound(NodeDocumentHeads::class)) {
            return [];
        }

        return $this->container->make(NodeDocumentHeads::class)->heads($documents);
    }

    /**
     * @param  array<string, array<string, string>>|null $properties
     * @return array<string, array<string, string>>
     */
    private static function properties(?array $properties): array
    {
        return $properties ?? [];
    }
}
