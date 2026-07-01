<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AzGuard panel context
    |--------------------------------------------------------------------------
    |
    | The AzGuard panel the adapter threads through every check. null (the
    | default) lets AzGuard resolve the panel from each permission key's first
    | segment — e.g. `documents.view` resolves the `documents` panel — which
    | matches Vaulter's `documents.*` ability→permission map. Set an explicit
    | panel id only when the host groups Vaulter permissions under one.
    |
    */
    'panel_id' => env('VAULTER_AZGARD_PANEL'),

    /*
    |--------------------------------------------------------------------------
    | SuperAdmin permission
    |--------------------------------------------------------------------------
    |
    | The AzGuard permission key whose grant means "global super-admin". The
    | adapter's isSuperAdmin() probes it via hasPermission(); when held, the
    | Vaulter AzgardPermissionResolver short-circuits to an absolute allow
    | override. AzGuard's wildcard is '*'.
    |
    */
    'super_admin_permission' => env('VAULTER_AZGARD_SUPER_ADMIN_PERMISSION', '*'),
];
