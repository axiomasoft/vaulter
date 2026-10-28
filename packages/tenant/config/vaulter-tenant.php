<?php

declare(strict_types=1);

return [
    'namespace' => null,
    'queues' => ['default'],
    'connection' => 'database',
    'lock_seconds' => 3600,
    // An explicit stable setup/control connection; never a guarded tenant connection at boot.
    'schema_connection' => null,
    'tenant_columns' => ['v_drives' => 'tenant_id'],
];
