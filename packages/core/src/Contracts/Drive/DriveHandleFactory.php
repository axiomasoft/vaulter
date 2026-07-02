<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Models\Drive\Storage;

/**
 * Opens {@see DriveHandle}s — the seam VaulterManager (core) delegates to so the
 * public `Vaulter::for()` / `Vaulter::forStorage()` entry points stay in core
 * without core referencing any vaulter-drive class. The concrete factory ships in
 * vaulter-drive (it wires the StorageManager + the Drive Actions) and is bound to
 * this contract by that package's provider (P2 #5 / ADR-0015). Absent the package
 * the binding is unresolved and `Vaulter::for()` fails loudly.
 */
interface DriveHandleFactory
{
    /**
     * Get-or-create the host entity's storage within a panel and open a handle on it.
     */
    public function forOwner(Model $entity, string $panel): DriveHandle;

    /**
     * Open a handle on an already-resolved storage row.
     */
    public function forStorage(Storage $storage): DriveHandle;
}
