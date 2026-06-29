<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\PermissionRevoked;
use Vaulter\Models\Document;
use Vaulter\Repositories\PermissionStoreRepository;

final readonly class RevokePermissionAction
{
    public function __construct(
        private PermissionStoreRepository $permissions,
    ) {}

    public function execute(
        Document $document,
        string $subjectType,
        int|string $subjectId,
        int|string|null $actorId = null,
    ): int {
        $count = DB::transaction(
            fn (): int => $this->permissions->revoke($document, $subjectType, $subjectId),
        );

        event(new PermissionRevoked($document, $subjectType, $subjectId, $actorId));

        return $count;
    }
}
