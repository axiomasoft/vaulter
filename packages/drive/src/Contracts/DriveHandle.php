<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\Access\AccessOperations;
use Vaulter\Drive\Contracts\Activity\ActivityOperations;
use Vaulter\Drive\Contracts\Sharing\ShareOperations;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\QuotaSnapshot;
use Vaulter\Drive\Exceptions\InvalidDriveStateException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Operations\ChangeOperations;
use Vaulter\Drive\Profiles\Profile;
use Vaulter\Exceptions\Concurrency\StaleWriteException;
use Vaulter\ValueObjects\Access\Actor;

#[Api]
interface DriveHandle
{
    /** The Eloquent row, for Vaulter packages and tests; hosts use snapshot(). */
    #[Internal]
    public function model(): Drive;

    public function snapshot(): DriveSnapshot;

    public function quota(): QuotaSnapshot;

    /**
     * active → archived: the drive becomes read-only. Needs Manage on the
     * root, or the system actor. Runs as the operation drive.drives.archive;
     * `$expectedVersion` — the drive's version the caller read
     * ({@see DriveSnapshot::$version}) — is compared under the drive's row lock, a
     * mismatch throws {@see StaleWriteException}; null skips the check.
     *
     * @throws InvalidDriveStateException
     */
    public function archive(?int $expectedVersion = null): DriveSnapshot;

    /**
     * archived → active. Needs Manage on the root, or the system actor. Runs as
     * drive.drives.unarchive; `$expectedVersion` as for {@see archive()}.
     */
    public function unarchive(?int $expectedVersion = null): DriveSnapshot;

    /**
     * Start the asynchronous, idempotent purge of the whole drive: the drive
     * is gone for everyone at once, its nodes, revisions and blob references follow, the
     * drive row last. Needs Manage on the root, or the system actor. Runs as
     * drive.drives.delete; `$expectedVersion` as for {@see archive()}.
     */
    public function delete(?int $expectedVersion = null): DriveSnapshot;

    public function profile(): Profile;

    public function actor(): Actor;

    public function actingAs(Authorizable $user): static;

    public function asSystem(string $reason): static;

    public function withCorrelationId(string $correlationId): static;

    /**
     * The idempotency key of the writes made through this handle: opaque bytes, 1–255, compared exactly.
     * Only an operation that declares idempotency accepts it (the receipt operations of the catalogue,
     * `vaulter:operations`); any other write refuses a key before it runs. A retry with the same key and the same
     * request returns the stored outcome after a fresh authorization check; another request under the key fails
     * with `idempotency_key_reused`. `$requestFingerprint` is for a transport that fingerprints its own request
     * (the HTTP layer); a PHP caller omits it.
     */
    public function withIdempotencyKey(string $key, ?string $requestFingerprint = null): static;

    public function root(): NodeSnapshot;

    public function nodes(): NodeOperations;

    public function folders(): FolderOperations;

    public function files(): FileOperations;

    public function trash(): TrashOperations;

    public function access(): AccessOperations;

    public function shares(): ShareOperations;

    public function activity(): ActivityOperations;

    /**
     * The drive's change journal for the acting reader: a cursor to start
     * from after a full listing, then pages of node changes filtered by the reader's
     * current access.
     */
    public function changes(): ChangeOperations;

    /**
     * @template T of object
     *
     * @param  class-string<T> $operations
     * @return T
     */
    public function extension(string $operations): object;
}
