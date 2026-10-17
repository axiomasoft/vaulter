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
    // The hub's compaction worker leases a document with uncheckpointed edits once its log holds this many updates,
    // this many bytes of them, or its oldest one is this many seconds old; a drain request always qualifies. A lease
    // lasts lease_ttl seconds.
    'compaction' => [
        'trigger_updates' => 500,
        'trigger_bytes' => 1048576,
        'trigger_age' => 600,
        'lease_ttl' => 60,
    ],
    'drain' => [
        // How long a restore, REST replace, content read or export of a document with uncheckpointed edits waits for
        // the hub to compact them (seconds); after it the request answers 409 document_state_pending.
        'restore_deadline' => 10,
        // The cache store of the draining flag vaulter:collaboration:drain sets (null: the default store). Every PHP
        // process must see it, so not an `array` or `null` store (the doctor reports one as an error).
        'cache_store' => null,
        // The longest an append transaction lasts, lock waits included (seconds): a drain reports its outcome no earlier
        // than this after it set the flag, so an append that read the flag before it was set has committed by then.
        'max_append_tx' => 5,
    ],
    'retention' => [
        // Days the log and receipts of a closed generation stay after its last token and retry could still arrive.
        'retired_generation_days' => 7,
    ],
    // Same value grammar and vaulter.schedule.enabled switch as the core schedule keys.
    'schedule' => [
        'collaboration_prune' => 'dailyAt:03:40',
    ],
];
