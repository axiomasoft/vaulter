<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Vaulter\Attributes\Internal;

/**
 * What a permanent removal keeps in its receipt (D25 «для delete квитанция содержит доказуемый исходный authorization
 * scope», ID2): the id of what is gone and the scope the right to remove it was proven in, never its content. A replay
 * checks the recorded ability there, because the removed thing no longer exists to ask.
 *
 * `$kind` is `node` (a purged trash root: the scope is the parent it hung under), `revision` (a deleted file revision:
 * the scope is the file node) or `trash` (an emptied trash: the scope is the drive, `$count` the roots purged).
 */
#[Internal]
final readonly class PurgeReceipt
{
    public const string NODE = 'node';

    public const string REVISION = 'revision';

    public const string TRASH = 'trash';

    public function __construct(
        public string $kind,
        public string $id,
        public string $driveId,
        public ?string $scopeNodeId,
        public string $ability,
        public int $count = 1,
    ) {}
}
