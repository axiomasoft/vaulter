<?php

declare(strict_types=1);

namespace Vaulter\Http\Controllers\Drive;

use Illuminate\Http\Request;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\StorageResource;
use Vaulter\Models\Storage;

/**
 * Read surface for a storage container (§2.1): its meta, the root node the client
 * opens into, and the used/quota accounting. Thin — the storage arrives
 * route-model resolved (swappable model bound in the provider); the Resource
 * shapes the response.
 */
final class StorageController extends Controller
{
    public function show(Request $request, Storage $storage): StorageResource
    {
        // M2: authorize 'view' on $storage.
        return StorageResource::make($storage);
    }
}
