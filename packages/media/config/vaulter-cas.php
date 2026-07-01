<?php

declare(strict_types=1);

use Vaulter\Media\Cas\BlobStore;
use Vaulter\Media\Cas\FlysystemStorageDriver;
use Vaulter\Media\Cas\Sha256ContentStore;

/*
|--------------------------------------------------------------------------
| CAS engine defaults (owned by vaulter-media)
|--------------------------------------------------------------------------
| The swappable CAS service implementations. vaulter-media merges these under
| the existing `vaulter.cas` key (VaulterMediaServiceProvider::mergeConfigFrom),
| so hosts keep reading/overriding config('vaulter.cas.*') with NO path change —
| the ownership of the concrete engine simply moved out of core. Point any of
| these at your own FQCN to change how bytes are staged/hashed/promoted, which
| Flysystem disk holds the canonical objects, or the high-level blob lifecycle.
|
| The non-engine CAS knobs — dedup.scope (D18), gc.* grace windows, storage
| default_disk — stay in the core config, since the v_blobs SCHEMA (owned by
| core) reads dedup.scope independently of whether this package is installed.
*/

return [
    'content_store' => Sha256ContentStore::class,
    'storage_driver' => FlysystemStorageDriver::class,
    'blob_store' => BlobStore::class,
];
