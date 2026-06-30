<?php

declare(strict_types=1);

namespace Vaulter\Events\Drive;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;

/**
 * A storage container was provisioned for a host entity, together with its
 * auto-created root folder node (§2.1).
 */
final readonly class StorageCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public Storage $storage,
        public Node $root,
        public int|string|null $actorId = null,
    ) {}
}
