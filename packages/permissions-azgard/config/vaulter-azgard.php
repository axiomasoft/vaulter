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
];
