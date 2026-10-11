<?php

declare(strict_types=1);

use Vaulter\Media\Cas\BlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

return [
    'disk' => env('VAULTER_DISK', 'local'),
    // tenant (default) | drive | off — identical bytes are never shared across tenants.
    'dedup' => ['scope' => env('VAULTER_DEDUP_SCOPE', 'tenant')],
    'gc' => [
        'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24),
        'orphan_grace_hours' => env('VAULTER_ORPHAN_GRACE_HOURS', env('VAULTER_GC_GRACE_HOURS', 24)),
    ],
    // Same value grammar and vaulter.schedule.enabled switch as the core schedule keys.
    'schedule' => ['blobs_gc' => 'dailyAt:04:00', 'blobs_reconcile' => 'weeklyOn:0,04:30'],
    'content_store' => Sha256ContentStore::class,
    'storage_driver' => FlysystemStorageDriver::class,
    'blob_store' => BlobStore::class,
];
