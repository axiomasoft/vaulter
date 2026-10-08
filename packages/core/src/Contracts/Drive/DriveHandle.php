<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Vaulter\Contracts\Access\AccessOperations;
use Vaulter\Contracts\Activity\ActivityOperations;
use Vaulter\Contracts\Sharing\ShareOperations;
use Vaulter\Data\Snapshots\DriveSnapshot;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Data\Snapshots\QuotaSnapshot;
use Vaulter\Models\Drive\Drive;
use Vaulter\Profiles\Profile;
use Vaulter\ValueObjects\Access\Actor;

interface DriveHandle
{
    /** @internal The Eloquent row, for Vaulter packages and tests; hosts use snapshot(). */
    public function model(): Drive;

    public function snapshot(): DriveSnapshot;

    public function quota(): QuotaSnapshot;

    public function profile(): Profile;

    public function actor(): Actor;

    public function actingAs(Authorizable $user): static;

    public function asSystem(string $reason): static;

    public function withCorrelationId(string $correlationId): static;

    public function root(): NodeSnapshot;

    public function nodes(): NodeOperations;

    public function folders(): FolderOperations;

    public function files(): FileOperations;

    public function trash(): TrashOperations;

    public function access(): AccessOperations;

    public function shares(): ShareOperations;

    public function activity(): ActivityOperations;

    /**
     * @template T of object
     *
     * @param  class-string<T> $operations
     * @return T
     */
    public function extension(string $operations): object;
}
