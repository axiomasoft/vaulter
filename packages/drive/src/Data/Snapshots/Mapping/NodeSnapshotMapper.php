<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots\Mapping;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ViewerStateSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\NodeUserState;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Kernel;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Models to NodeSnapshot, in batches: one query for the file heads, one for
 *           the viewer's stars and one for document heads, whatever the page size.
 */
#[Internal]
final readonly class NodeSnapshotMapper
{
    /** The fragment the snapshot has a place for; every other key is a core key of the snapshot or unknown to it. */
    private const string DOCUMENT = 'document';

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
        $fragments = $this->fragments($nodes);

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
                document: $fragments[self::DOCUMENT][$id] ?? null,
                viewer: $stars === null ? null : new ViewerStateSnapshot(starred: isset($stars[$id])),
                accessVersion: self::version($node->getAttribute('access_version')),
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
     * The fragments of the active contributors, one batch call each (no N+1): key => node id => fragment.
     * A reserved or duplicate key, or a fragment of the wrong shape, is an error, never a silent skip.
     *
     * @param  list<Node>                                         $nodes
     * @return array<string, array<string, DocumentHeadSnapshot>>
     */
    private function fragments(array $nodes): array
    {
        $fragments = [];
        $ids = array_map(static fn (Node $node): string => SnapshotValue::id($node->getKey()), $nodes);
        foreach ($this->container->make(Kernel::class)->extensions()->resolve(NodeSnapshotContributor::class) as $contributor) {
            $key = $contributor->key();
            if ($key !== self::DOCUMENT) {
                throw InvalidExtensionResult::reservedKey(NodeSnapshotContributor::class, $key);
            }
            if (isset($fragments[$key])) {
                throw InvalidExtensionResult::duplicateKey(NodeSnapshotContributor::class, $key);
            }
            $fragments[$key] = [];
            foreach ($contributor->contribute($nodes) as $id => $fragment) {
                if (!$fragment instanceof DocumentHeadSnapshot || !in_array((string) $id, $ids, true)) {
                    throw InvalidExtensionResult::invalidFragment(NodeSnapshotContributor::class, $key, DocumentHeadSnapshot::class);
                }
                $fragments[$key][(string) $id] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * @param  array<string, array<string, string>>|null $properties
     * @return array<string, array<string, string>>
     */
    private static function properties(?array $properties): array
    {
        return $properties ?? [];
    }

    /** A version column, 0 for a row read without it. */
    private static function version(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
