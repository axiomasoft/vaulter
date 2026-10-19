<?php

declare(strict_types=1);

use Vaulter\Media\Cas\BlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;
use Vaulter\Media\Models\Cas\Blob;

return [
    'disk' => env('VAULTER_DISK', 'local'),
    // tenant (default) | drive | off — identical bytes are never shared across tenants.
    'dedup' => ['scope' => env('VAULTER_DEDUP_SCOPE', 'tenant')],
    'gc' => [
        'grace_hours' => env('VAULTER_GC_GRACE_HOURS', 24),
        'orphan_grace_hours' => env('VAULTER_ORPHAN_GRACE_HOURS', env('VAULTER_GC_GRACE_HOURS', 24)),
    ],
    // The integrity scrub (vaulter:blobs:scrub, D49): the sample and budget of one run — the first one used up ends it.
    // Reading a whole object store is expensive, so a run covers a sample; null: no byte or time budget. Not scheduled.
    'scrub' => [
        'limit' => env('VAULTER_SCRUB_LIMIT', 100),
        'max_bytes' => env('VAULTER_SCRUB_MAX_BYTES'),
        'max_seconds' => env('VAULTER_SCRUB_MAX_SECONDS'),
    ],
    // Same value grammar and vaulter.schedule.enabled switch as the core schedule keys.
    'schedule' => ['blobs_gc' => 'dailyAt:04:00', 'blobs_reconcile' => 'weeklyOn:0,04:30'],
    'content_store' => Sha256ContentStore::class,
    'storage_driver' => FlysystemStorageDriver::class,
    'blob_store' => BlobStore::class,
    // Swappable Eloquent model of the blob table (must extend the package model).
    'models' => ['blob' => Blob::class],
];
