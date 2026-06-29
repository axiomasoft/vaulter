<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\GrantPermissionData;
use Vaulter\Events\PermissionGranted;
use Vaulter\Models\Document;
use Vaulter\Models\DocumentPermission;
use Vaulter\Repositories\PermissionStoreRepository;

final readonly class GrantPermissionAction
{
    public function __construct(
        private PermissionStoreRepository $permissions,
    ) {}

    public function execute(Document $document, GrantPermissionData $data): DocumentPermission
    {
        $permission = DB::transaction(fn (): DocumentPermission => $this->permissions->grant($document, $data));

        event(new PermissionGranted($permission, $data->grantedBy));

        return $permission;
    }
}
