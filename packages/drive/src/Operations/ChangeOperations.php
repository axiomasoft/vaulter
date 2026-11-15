<?php

declare(strict_types=1);

namespace Vaulter\Drive\Operations;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Access\OperationGuard;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Access\ValueObjects\AccessScope;
use Vaulter\Drive\Changes\ChangeCursor;
use Vaulter\Drive\Changes\ChangeJournal;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Configuration\ChangesConfig;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Changes\ChangePage;
use Vaulter\Drive\Data\Changes\NodeChange;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Enums\Changes\ChangeOperation;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Repositories\Access\GrantReadRepository;
use Vaulter\Drive\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Tenancy\TenancyBoundary;
use Vaulter\Support\Identity;

/**
 * The change journal of a drive as one reader sees it. A syncing
 * client first takes startCursor(), only then lists the drive, and from then on
 * applies list() pages from C0 instead of listing again: changes made while
 * it listed are caught up by the feed.
 *
 * Entries are filtered by the reader's current access with the same exact resolver the
 * listing uses: an upsert of a node the reader may see carries its current snapshot; an
 * upsert of a node the reader cannot see is a remove when a move or an access change
 * explains why it disappeared, and is skipped otherwise. A remove carries no snapshot, no
 * node id and no reason, only the reader-scoped sync key of the node.
 */
#[Api]
final readonly class ChangeOperations
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private ChangeHeadRepository $heads,
        private ChangeEntryRepository $entries,
        private OperationGuard $guard,
        private NodeSnapshotMapper $mapper,
        private VaulterDatabase $database,
        private DriveConfig $config,
        private ChangesConfig $changes,
        private TenancyConfig $tenancy,
        private TenancyBoundary $boundary,
        private Repository $repository,
        private PermissionDriverManager $drivers,
        private GrantReadRepository $grants,
    ) {}

    /**
     * A cursor at the end of the journal: list() from it returns what changes next.
     * The head is created and committed first, then the drive is authorized afresh and
     * the head read FOR SHARE in a short transaction of its own, so a cursor never points
     * behind a head that a rollback could still take away.
     *
     * @throws LogicException inside an open transaction: its snapshot could be older than the head
     */
    public function startCursor(): string
    {
        if ($this->database->connection()->transactionLevel() > 0) {
            throw new LogicException('[Vaulter] startCursor() cannot run inside an open transaction. '
                .'Why: the cursor must follow a committed journal head, not an uncommitted one or an older snapshot. '
                .'Fix: take the start cursor before the transaction begins or after it commits.');
        }
        $this->heads->create($this->context->driveId(), self::tenant($this->drive()));
        $drive = $this->drive();
        $head = $this->database->transaction(fn (): array => $this->heads->find($this->context->driveId(), 'share') ?? self::emptyHead());
        $now = Carbon::now();
        $issuedAt = $now->getTimestamp();

        return $this->cursor($drive, epoch: $head['epoch'], after: $head['last_seq'], issuedAt: $issuedAt, notAfter: $this->deadline($drive, $issuedAt, null, $now));
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
        $identity = $this->identity();
        $position = ChangeCursor::decode($cursor, $identity->cursorSubkey());
        $principal = $this->principal();
        if ($position->driveId !== Identity::stringify($drive->getKey()) || $position->tenantId !== self::tenant($drive)
            || $position->actorType !== $principal['type'] || $position->actorId !== $principal['id'] || $position->actorReason !== $principal['reason']) {
            throw InvalidCursorException::otherDrive();
        }
        if ($position->keyGeneration !== $identity->keyGeneration) {
            throw CursorExpiredException::reset();
        }
        // One clock for the page, the resolver's: the deadline only ever shrinks.
        $now = Carbon::now();
        $notAfter = $this->deadline($drive, $position->issuedAt, $position->notAfter, $now);
        $this->assertLive($notAfter, $now);

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
        $changes = $this->project($drive, $rows);

        // Nothing is handed out once the cursor died while the page was projected: the
        // deadline, the key generation and, outside a transaction, the head again.
        $this->assertLive($notAfter, Carbon::now());
        if ($position->keyGeneration !== $this->changes->keyGeneration()) {
            throw CursorExpiredException::reset();
        }
        if (!$inTransaction) {
            $this->assertCurrent($position, $this->heads->find($this->context->driveId()) ?? self::emptyHead());
        }

        return new ChangePage(
            changes: $changes,
            cursor: $this->cursor($drive, epoch: $position->epoch, after: $after, issuedAt: $position->issuedAt, notAfter: $notAfter),
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

        // A remove names no node: only the reader-scoped key of the node it hides.
        $identity = $this->identity();
        $actor = $context->actor->ref();
        $tenant = self::tenant($drive);
        $driveId = Identity::stringify($drive->getKey());
        $key = static fn (int|string $nodeId): ?string => $actor->type === null ? null : $identity->syncKey($tenant, $driveId, $actor, $nodeId);

        $changes = [];
        foreach ($rows as $row) {
            $nodeId = Identity::stringify($row->node_id);
            if ($row->operation === ChangeOperation::Remove->value) {
                $changes[] = self::remove($key($nodeId));

                continue;
            }
            if (isset($visible[$nodeId])) {
                [$node, $snapshot] = $visible[$nodeId];
                $changes[] = new NodeChange(
                    operation: ChangeOperation::Upsert,
                    syncKey: $key($nodeId),
                    nodeId: $nodeId,
                    subtree: (bool) $row->subtree,
                    node: $snapshot,
                    nodeSequence: $node->sequence,
                );

                continue;
            }
            if (ChangeJournal::invisibilityReason($row->event_type) !== null) {
                $changes[] = self::remove($key($nodeId));
            }
        }

        // An anonymous reader has no key to name a remove by: nothing to tell it.
        return array_values(array_filter($changes, static fn (NodeChange $change): bool => $change->operation === ChangeOperation::Upsert || $change->syncKey !== null));
    }

    private static function remove(?string $syncKey): NodeChange
    {
        return new NodeChange(operation: ChangeOperation::Remove, syncKey: $syncKey, nodeId: null, subtree: false, node: null, nodeSequence: null);
    }

    /**
     * The cursor deadline: the smallest of the latched one, issued_at plus
     * the current cap (never now plus it) and the earliest expiry of a live grant of
     * this reader's subject-set in the drive. A permanent grant or none adds no bound.
     */
    private function deadline(Drive $drive, int $issuedAt, ?int $latched, Carbon $now): int
    {
        $deadline = $issuedAt + $this->changes->cursorMaxAgeSeconds();
        if ($latched !== null) {
            $deadline = min($deadline, $latched);
        }
        $expiry = $this->grantExpiry($drive, $now);

        return $expiry === null ? $deadline : min($deadline, $expiry);
    }

    /** The subject-set and clock of the AccessResolver, read through the same driver. */
    private function grantExpiry(Drive $drive, Carbon $now): ?int
    {
        $actor = $this->context->actor->authorizable();
        if ($actor === null || !$this->config->featureEnabled('grants', $drive->profile)) {
            return null;
        }
        $driver = $this->drivers->forProfile($drive->profile);

        return $this->grants->earliestExpiryIn(
            subjects: $this->drivers->subjectsFor($driver, $actor, AccessScope::forDrive($drive)),
            driveId: $this->context->driveId(),
            now: $now,
        );
    }

    private function assertLive(int $notAfter, Carbon $now): void
    {
        if ($now->getTimestamp() > $notAfter) {
            throw CursorExpiredException::reset();
        }
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

    private function cursor(Drive $drive, int $epoch, int $after, int $issuedAt, int $notAfter): string
    {
        $identity = $this->identity();
        $principal = $this->principal();

        return (new ChangeCursor(
            tenantId: self::tenant($drive), driveId: Identity::stringify($drive->getKey()),
            actorType: $principal['type'], actorId: $principal['id'], actorReason: $principal['reason'],
            keyGeneration: $identity->keyGeneration, epoch: $epoch, after: $after, issuedAt: $issuedAt, notAfter: $notAfter,
        ))->encode($identity->cursorSubkey());
    }

    private function identity(): ReaderSyncIdentity
    {
        return new ReaderSyncIdentity($this->appKey(), $this->changes->keyGeneration());
    }

    /**
     * The principal the cursor is bound to; an anonymous reader is bound as one.
     *
     * @return array{type: ?string, id: string, reason: string}
     */
    private function principal(): array
    {
        $actor = $this->context->actor->ref();

        return $actor->type === null ? ['type' => null, 'id' => '', 'reason' => ''] : ReaderSyncIdentity::principal($actor);
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
        return ReaderSyncIdentity::tenant($drive->getRawOriginal('tenant_id'));
    }

    /** @return array{last_seq: int, pruned_through: int, epoch: int} a drive whose journal has never been written */
    private static function emptyHead(): array
    {
        return ['last_seq' => 0, 'pruned_through' => 0, 'epoch' => 1];
    }
}
