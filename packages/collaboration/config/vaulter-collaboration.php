<?php

declare(strict_types=1);

/**
 * The configuration of the vaulter-collaboration package, published with the vaulter-collaboration-config tag.
 * The module is opt-in: it is active only after `vaulter.modules.collaboration` is set to `true`, and an active
 * module needs the hub URL and both secrets below.
 */
return [
    'driver' => env('VAULTER_COLLAB_DRIVER', 'hocuspocus'),
    'hub' => [
        'url' => env('VAULTER_COLLAB_URL', 'ws://localhost:1234'),
        // Signs the room tokens (Laravel only); must differ from the webhook secret the hub holds.
        'jwt_secret' => env('VAULTER_COLLAB_SECRET'),
        'webhook_secret' => env('VAULTER_COLLAB_WEBHOOK_SECRET'),
        'token_ttl' => 3600,
        'timestamp_tolerance' => 300,
    ],
    // The largest binary Yjs state one hub store may write (413 above it).
    'max_state_bytes' => 16777216,
    // When an edit counts as saved (protocol 2): `buffered` — the hub may sync it before the backend committed it;
    // `durable` — the hub acknowledges an edit only with the backend receipt.
    'mode' => env('VAULTER_COLLAB_MODE', 'buffered'),
    'limits' => [
        // The largest decoded Yjs update one append carries, and the largest append request body (413 above either,
        // checked before the body is decoded).
        'update_bytes' => 1048576,
        'request_bytes' => 2097152,
    ],
];
