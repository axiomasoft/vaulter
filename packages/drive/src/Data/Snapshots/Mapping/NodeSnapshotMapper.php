<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Snapshots\Mapping;

use DateTimeImmutable;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Page;
use Vaulter\Data\Snapshots\Mapping\SnapshotValue;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Configuration\ChangesConfig;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\NodeSnapshotContributor;
use Vaulter\Drive\Data\NodePage;
use Vaulter\Drive\Data\Snapshots\DocumentHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\FileHeadSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ViewerStateSnapshot;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Enums\ScanStatus;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\NodeUserState;
use Vaulter\Exceptions\Support\InvalidExtensionResult;
use Vaulter\Kernel;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * Models to NodeSnapshot, in batches: one query for the file heads, one for
 *           the viewer's stars and one for document heads, whatever the page size.
 *
 * @phpstan-type NodeFacts array{id: string, driveId: string, parentId: ?string, kind: NodeKind, name: string, mimeType: ?string, extension: ?string, sizeBytes: int, sequence: int, inheritsAccess: bool, properties: array<string, array<string, string>>, createdAt: DateTimeImmutable, createdBy: ?string, updatedAt: DateTimeImmutable, updatedBy: ?string, contentModifiedAt: ?DateTimeImmutable, contentModifiedBy: ?string, trashedAt: ?DateTimeImmutable, trashedBy: ?string, trashRoot: bool, file: ?FileHeadSnapshot, accessVersion: int}
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
        $keys = $this->syncKeys($nodes, $viewer);
        $facts = $this->factsOf($nodes);
        $fragments = $this->fragments(array_map(static fn (array $fact): NodeSnapshot => new NodeSnapshot(...$fact, document: null, viewer: null), $facts));

        $snapshots = [];
        foreach ($facts as $fact) {
            $id = $fact['id'];
            $snapshots[] = new NodeSnapshot(
                ...$fact,
                document: $fragments[self::DOCUMENT][$id] ?? null,
                viewer: $stars === null ? null : new ViewerStateSnapshot(starred: isset($stars[$id])),
                syncKey: $keys[$id] ?? null,
            );
        }

        return $snapshots;
    }

    /**
     * The point-in-time facts of one row, without fragments, viewer state or a reader key: what an event keeps, what a
     * guard, a lifecycle or a contributor reads. No contribution runs, so capturing them inside a write is side-effect free.
     */
    public function facts(Node $node): NodeSnapshot
    {
        $this->loadHeads([$node]);

        return new NodeSnapshot(...$this->factsOf([$node])[0], document: null, viewer: null);
    }

    /**
     * The facts of each row once, heads already loaded.
     *
     * @param  list<Node>      $nodes
     * @return list<NodeFacts>
     */
    private function factsOf(array $nodes): array
    {
        $facts = [];
        foreach ($nodes as $node) {
            $id = SnapshotValue::id($node->getKey());
            $facts[] = [
                'id' => $id,
                'driveId' => SnapshotValue::id($node->drive_id),
                'parentId' => SnapshotValue::nullableId($node->parent_id),
                'kind' => $node->kind,
                'name' => $node->name,
                'mimeType' => $node->mime_type,
                'extension' => $node->extension,
                'sizeBytes' => $node->size_bytes,
                'sequence' => $node->sequence,
                'inheritsAccess' => (bool) $node->inherit_grants,
                'properties' => self::properties($node->properties),
                'createdAt' => SnapshotValue::requiredTime($node->created_at),
                'createdBy' => SnapshotValue::nullableId($node->getAttribute('created_by')),
                'updatedAt' => SnapshotValue::requiredTime($node->updated_at),
                'updatedBy' => SnapshotValue::nullableId($node->getAttribute('updated_by')),
                'contentModifiedAt' => SnapshotValue::time($node->content_modified_at),
                'contentModifiedBy' => SnapshotValue::nullableId($node->content_modified_by),
                'trashedAt' => SnapshotValue::time($node->trashed_at),
                'trashedBy' => SnapshotValue::nullableId($node->trashed_by),
                'trashRoot' => $node->trashed_at !== null && $node->trash_root_id !== null
                    && SnapshotValue::id($node->trash_root_id) === $id,
                'file' => $node->kind === NodeKind::File ? $this->fileHead($node) : null,
                'accessVersion' => self::version($node->getAttribute('access_version')),
            ];
        }

        return $facts;
    }

    /**
     * The reader-scoped sync key of each node (the primitive, the one the change feed names
     * a remove by); none for a viewer without a sync identity. At most one query for missing drive scopes.
     *
     * @param  list<Node>            $nodes
     * @return array<string, string> node id => key
     */
    private function syncKeys(array $nodes, ?ActorRef $viewer): array
    {
        $identity = $this->identity();
        if ($identity === null || $viewer === null || $viewer->type === null) {
            return [];
        }
        $drives = [];
        $tenants = [];
        foreach ($nodes as $node) {
            $driveId = SnapshotValue::id($node->drive_id);
            $drives[$driveId] = $driveId;
            $drive = $node->relationLoaded('storage') ? $node->getRelation('storage') : null;
            // Exact listings already fetched the drive for their access check.
            // Reuse that persisted scope only when both identities agree; a
            // missing, partial or changed relation still takes the lookup below.
            if ($drive instanceof Drive && SnapshotValue::id($drive->getKey()) === $driveId
                && array_key_exists('tenant_id', $drive->getAttributes())
                && array_key_exists('tenant_id', $node->getAttributes())
                && !$drive->isDirty(['tenant_id', $drive->getKeyName()])
                && !$node->isDirty(['tenant_id', 'drive_id'])
                && ReaderSyncIdentity::tenant($node->getRawOriginal('tenant_id')) === ReaderSyncIdentity::tenant($drive->getRawOriginal('tenant_id'))) {
                $tenants[$driveId] = ReaderSyncIdentity::tenant($drive->getRawOriginal('tenant_id'));
            }
        }
        $missing = array_diff_key($drives, $tenants);
        if ($missing !== []) {
            $tenants += $this->tenants(array_values($missing));
        }
        $keys = [];
        foreach ($nodes as $node) {
            $driveId = SnapshotValue::id($node->drive_id);
            $id = SnapshotValue::id($node->getKey());
            $keys[$id] = $identity->syncKey($tenants[$driveId] ?? null, $driveId, $viewer, $id);
        }

        return $keys;
    }

    /**
     * A snapshot decoded from a receipt carries no key: after the replay was authorized afresh it gets the
     * key of the reader it is returned to, under the current key generation.
     */
    public function withSyncKey(NodeSnapshot $snapshot, ?ActorRef $viewer): NodeSnapshot
    {
        $identity = $this->identity();
        if ($identity === null || $viewer === null || $viewer->type === null) {
            return $snapshot->withSyncKey(null);
        }

        return $snapshot->withSyncKey($identity->syncKey($this->tenants([$snapshot->driveId])[$snapshot->driveId] ?? null, $snapshot->driveId, $viewer, $snapshot->id));
    }

    private function identity(): ?ReaderSyncIdentity
    {
        $appKey = $this->container->make(Repository::class)->get('app.key');
        if (!is_string($appKey) || $appKey === '') {
            return null;
        }

        return new ReaderSyncIdentity($appKey, $this->container->make(ChangesConfig::class)->keyGeneration());
    }

    /**
     * @param  list<string>           $driveIds
     * @return array<string, ?string> drive id => tenant
     */
    private function tenants(array $driveIds): array
    {
        $class = $this->container->make(DriveConfig::class)->model('drive');
        $tenants = [];
        foreach ($class::query()->whereKey($driveIds)->get() as $drive) {
            $tenants[SnapshotValue::id($drive->getKey())] = ReaderSyncIdentity::tenant($drive->getRawOriginal('tenant_id'));
        }

        return $tenants;
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
     * Each contributor gets the reader-free facts of the page (no viewer state, no sync key, no fragment), never a row.
     *
     * @param  list<NodeSnapshot>                                 $nodes
     * @return array<string, array<string, DocumentHeadSnapshot>>
     */
    private function fragments(array $nodes): array
    {
        $fragments = [];
        $ids = array_map(static fn (NodeSnapshot $node): string => $node->id, $nodes);
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
