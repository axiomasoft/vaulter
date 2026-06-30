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
    | Toggle which Vaulter Filament resources the plugin registers. The NEW
    | Drive/permissions domain (storage, node, grant, share, activity) and the
    | LEGACY document surface (document, folder, legacy_share) can each be turned
    | off independently. Every entry defaults to true when omitted.
    */
    'resources' => [
        // Drive / permissions domain (M1–M4 core).
        'storage' => true,
        'node' => true,
        'grant' => true,
        'share' => true,
        'activity' => true,

        // Legacy document-centric surface.
        'document' => true,
        'folder' => true,
        'legacy_share' => true,
    ],
];
