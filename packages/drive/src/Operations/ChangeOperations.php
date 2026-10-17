<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ChangesConfig;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Changes\ChangeCursor;
use Vaulter\Drive\Changes\ChangeJournal;
use Vaulter\Drive\Data\Changes\ChangePage;
use Vaulter\Drive\Data\Changes\NodeChange;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\Changes\ChangeReason;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * The change journal of a drive as one reader sees it. A syncing
 * client lists the drive, takes startCursor(), and from then on applies list() pages
 * instead of listing again.
 *
 * Entries are filtered by the reader's current access with the same exact resolver the
 * listing uses: an upsert of a node the reader may see carries its current snapshot; an
 * upsert of a node the reader cannot see is a remove when a move or an access change
 * explains why it disappeared, and is skipped otherwise. A remove carries no snapshot.
 */
#[Internal]
final readonly class ChangeOperations
{
    public function __construct(
        private DriveContext $context,
        private ChangeHeadRepository $heads,
        private ChangeEntryRepository $entries,
        private OperationGuard $guard,
        private NodeSnapshotMapper $mapper,
        private VaulterDatabase $database,
        private VaulterConfig $config,
        private ChangesConfig $changes,
        private TenancyConfig $tenancy,
        private TenancyBoundary $boundary,
        private Repository $repository,
    ) {}

    /** A cursor at the end of the journal: list() from it returns what changes next. */
    public function startCursor(): string
    {
        $drive = $this->drive();
        $head = $this->heads->find($this->context->driveId()) ?? self::emptyHead();

        return $this->cursor($drive, epoch: $head['epoch'], after: $head['last_seq']);
    }

    /**
     * The changes after $cursor, at most $limit of them, and the cursor to continue from.
     *
     * @throws InvalidCursorException   when the cursor was not issued for this drive
     * @throws CursorExpiredException   when entries after it were pruned or the journal was reset
     * @throws InvalidArgumentException when $limit is outside 1..vaulter.changes.max_page_size
     */
    public function list(string $cursor, int $limit = 100): ChangePage
    {
        $max = $this->changes->maxPageSize();
        if ($limit < 1 || $limit > $max) {
            throw new InvalidArgumentException("[Vaulter] The change page limit must be between 1 and {$max}.");
        }
        $drive = $this->drive();
        $position = ChangeCursor::decode($cursor, $this->appKey());
        if ($position->driveId !== Identity::stringify($drive->getKey()) || $position->tenantId !== self::tenant($drive)) {
            throw InvalidCursorException::otherDrive();
        }

        // Inside a transaction the head is first read FOR SHARE: a prune waits until this
        // transaction ends, and one that committed earlier is seen even under a snapshot.
        // The page bound then comes from a plain read, so the head, the entries and the
        // nodes they are projected with come from one snapshot (MySQL REPEATABLE READ);
        // an entry committed after the snapshot waits for the next read rather than being
        // projected with nodes older than it. Outside a transaction the head is read again
        // afterwards, so a prune that ran in between is noticed instead of silently
        // skipping entries.
        $inTransaction = $this->database->connection()->transactionLevel() > 0;
        if ($inTransaction) {
            $this->assertCurrent($position, $this->heads->find($this->context->driveId(), 'share') ?? self::emptyHead());
        }
        $head = $this->heads->find($this->context->driveId()) ?? self::emptyHead();
        $this->assertCurrent($position, $head);
        $rows = $this->entries->page($this->context->driveId(), after: $position->after, through: $head['last_seq'], limit: $limit + 1);
        if (!$inTransaction) {
            $this->assertCurrent($position, $this->heads->find($this->context->driveId()) ?? self::emptyHead());
        }

        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[count($rows) - 1];
        $after = $hasMore && $last !== null ? ChangeHeadRepository::integer($last->seq) : $head['last_seq'];

        return new ChangePage(
            changes: $this->project($drive, $rows),
            cursor: $this->cursor($drive, epoch: $position->epoch, after: $after),
            hasMore: $hasMore,
        );
    }

    /**
     * @param  list<object{seq: int|string, change_id: string, event_type: string, node_id: int|string, operation: string, reason: ?string, subtree: bool|int|string, node_sequence: int|string|null}> $rows
     * @return list<NodeChange>
     */
    private function project(Drive $drive, array $rows): array
    {
        $context = DriveContext::for(drive: $drive, actor: $this->context->actor, correlationId: $this->context->correlationId);
        $ids = [];
        foreach ($rows as $row) {
            if ($row->operation === ChangeOperation::Upsert->value) {
                $ids[(string) $row->node_id] = $row->node_id;
            }
        }
        // A node in the trash, purged or never seen is not visible; the guard rebinds the
        // candidates in a tenant-scoped query of its own.
        $class = $this->config->model('node');
        $candidates = $ids === [] ? [] : $class::query()->where('drive_id', $drive->getKey())->whereNull('trashed_at')->whereKey(array_values($ids))->get();
        $visible = [];
        $nodes = $this->guard->visible(context: $context, nodes: $candidates);
        // One mapping pass for the page: heads, stars and documents load in batches.
        foreach ($this->mapper->mapMany($nodes, $context->actor->ref()) as $index => $snapshot) {
            $visible[$snapshot->id] = [$nodes[$index], $snapshot];
        }

        $changes = [];
        foreach ($rows as $row) {
            $nodeId = Identity::stringify($row->node_id);
            if ($row->operation === ChangeOperation::Remove->value) {
                $changes[] = new NodeChange(
                    nodeId: $nodeId,
                    operation: ChangeOperation::Remove,
                    reason: ChangeReason::tryFrom((string) $row->reason) ?? ChangeReason::Deleted,
                    subtree: false,
                    node: null,
                    nodeSequence: $row->node_sequence === null ? null : (int) $row->node_sequence,
                );

                continue;
            }
            if (isset($visible[$nodeId])) {
                [$node, $snapshot] = $visible[$nodeId];
                $changes[] = new NodeChange(
                    nodeId: $nodeId,
                    operation: ChangeOperation::Upsert,
                    reason: null,
                    subtree: (bool) $row->subtree,
                    node: $snapshot,
                    nodeSequence: $node->sequence,
                );

                continue;
            }
            $reason = ChangeJournal::invisibilityReason($row->event_type);
            if ($reason !== null) {
                $changes[] = new NodeChange(nodeId: $nodeId, operation: ChangeOperation::Remove, reason: $reason, subtree: false, node: null, nodeSequence: null);
            }
        }

        return $changes;
    }

    /** @param array{last_seq: int, pruned_through: int, epoch: int} $head */
    private function assertCurrent(ChangeCursor $position, array $head): void
    {
        if ($position->epoch !== $head['epoch']) {
            throw CursorExpiredException::reset();
        }
        if ($position->after < $head['pruned_through']) {
            throw CursorExpiredException::pruned();
        }
    }

    /** The drive as it is now: a drive being deleted is gone, an archived one is still read. */
    private function drive(): Drive
    {
        if (!$this->config->featureEnabled('changes')) {
            throw ProfileRestrictionException::forOperation('changes');
        }
        $drive = $this->context->drive->newQuery()->whereKey($this->context->driveId())->first();
        if (!$drive instanceof Drive || $drive->status === DriveStatus::Deleting) {
            throw NodeNotFoundException::forId($this->context->driveId());
        }
        if ($this->tenancy->enabled()) {
            $this->boundary->assertDrive($drive);
        }

        return $drive;
    }

    private function cursor(Drive $drive, int $epoch, int $after): string
    {
        return (new ChangeCursor(tenantId: self::tenant($drive), driveId: Identity::stringify($drive->getKey()), epoch: $epoch, after: $after))->encode($this->appKey());
    }

    private function appKey(): string
    {
        $key = $this->repository->get('app.key');
        if (!is_string($key) || $key === '') {
            throw new LogicException('[Vaulter] Change cursors require an application key (app.key).');
        }

        return $key;
    }

    private static function tenant(Drive $drive): ?string
    {
        $tenant = Identity::stringify($drive->getRawOriginal('tenant_id'));

        return $tenant === '' ? null : $tenant;
    }

    /** @return array{last_seq: int, pruned_through: int, epoch: int} a drive whose journal has never been written */
    private static function emptyHead(): array
    {
        return ['last_seq' => 0, 'pruned_through' => 0, 'epoch' => 1];
    }
}
