<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\PermissionRevoked;
use DocVault\Models\Document;
use DocVault\Repositories\PermissionStoreRepository;
use Illuminate\Support\Facades\DB;

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
