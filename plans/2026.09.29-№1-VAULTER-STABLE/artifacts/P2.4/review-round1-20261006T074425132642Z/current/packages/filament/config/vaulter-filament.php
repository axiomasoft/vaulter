<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Navigation group
    |--------------------------------------------------------------------------
    | The Filament navigation group the Vaulter resources are listed under.
    */
    'navigation_group' => 'Vaulter',

    /*
    |--------------------------------------------------------------------------
    | Enabled resources
    |--------------------------------------------------------------------------
    | Toggle which Vaulter Filament resources the plugin registers — the
    | Drive/permissions domain (drive, node, grant, share, activity) over the
    | unified v_nodes tree. Each can be turned off independently; every entry
    | defaults to true when omitted.
    */
    'resources' => [
        // Drive / permissions domain (M1–M4 core).
        'drive' => true,
        'node' => true,
        'grant' => true,
        'share' => true,
        'activity' => true,
    ],
];
