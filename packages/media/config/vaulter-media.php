<?php

declare(strict_types=1);

use Vaulter\Media\Cas\BlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

return [
    'disk' => env('VAULTER_DISK', 'local'),
    'dedup' => ['scope' => env('VAULTER_DEDUP_SCOPE', 'tenant')],
    'gc' => [
        'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24),
        'orphan_grace_hours' => env('VAULTER_ORPHAN_GRACE_HOURS', env('VAULTER_GC_GRACE_HOURS', 24)),
    ],
    'content_store' => Sha256ContentStore::class,
    'storage_driver' => FlysystemStorageDriver::class,
    'blob_store' => BlobStore::class,
];
