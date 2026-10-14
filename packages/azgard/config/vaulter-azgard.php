<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | AzGuard panel
    |--------------------------------------------------------------------------
    |
    | The AzGuard v1 panel the adapter passes as `guard:` to every check. null
    | (the default) lets AzGuard select the panel — by a full name
    | (`app:documents.view`) or a panel prefix in the permission, else the
    | panel of the request, else the panel of the user model. The default map
    | below names local keys (`documents.view`) of that panel, so the panel's
    | resource prefix must not be `documents` (AzGuard refuses such a panel
    | with PrefixConflictException): give the panel another resourcePrefix()
    | or map the abilities below to other permissions. Set
    | an explicit panel id when the user model belongs to several panels or the
    | checks must not depend on the request panel; AzGuard throws when no
    | panel can be selected.
    |
    */
    'panel_id' => env('VAULTER_AZGARD_PANEL'),

    /*
    |--------------------------------------------------------------------------
    | Explicit guard
    |--------------------------------------------------------------------------
    |
    | A class implementing `Vaulter\Azgard\Contracts\AzgardGuard` that answers
    | for EVERY actor (it is asked about the actor of each decision, not about
    | the request user). null (the default) uses the actor itself when it is a
    | guard, else an adapter over the actor when it is AzGuard-backed, else the
    | lane passes through and grants nothing.
    |
    */
    'guard' => null,

    'permissions' => [
        'view' => 'documents.view',
        'download' => 'documents.download',
        'comment' => 'documents.comment',
        'edit' => 'documents.update',
        'create' => 'documents.create',
        'move' => 'documents.update',
        'copy' => 'documents.update',
        'trash' => 'documents.delete',
        'delete' => 'documents.delete',
        'restore' => 'documents.delete',
        'share' => 'documents.manage',
        'manage' => 'documents.manage',
    ],

    'create_permission' => env('VAULTER_AZGARD_CREATE_PERMISSION', 'documents.create'),
];
