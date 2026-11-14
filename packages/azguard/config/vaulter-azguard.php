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
    'panel_id' => env('VAULTER_AZGUARD_PANEL'),

    /*
    |--------------------------------------------------------------------------
    | Explicit guard
    |--------------------------------------------------------------------------
    |
    | A class implementing `Vaulter\AzGuard\Contracts\AzGuardGuard` that answers
    | for the required `$actor` argument of each check. It must not substitute
    | the request user or switch authentication state. null (the default) uses the actor itself when it is a
    | guard, else an adapter over the actor when it is AzGuard-backed, else the
    | lane passes through and grants nothing.
    |
    */
    'guard' => null,

    // A role is granted only when every ability of its registered version has a mapping
    // and the actor holds every mapped permission. Missing mappings never imply permission.
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

    'create_permission' => env('VAULTER_AZGUARD_CREATE_PERMISSION', 'documents.create'),
];
