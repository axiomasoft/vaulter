<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\GrantPermissionData;
use DocVault\Events\PermissionGranted;
use DocVault\Models\Document;
use DocVault\Models\DocumentPermission;
use DocVault\Repositories\PermissionStoreRepository;
use Illuminate\Support\Facades\DB;

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
