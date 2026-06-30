<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\Drive\Storage;

/**
 * The read shape of a storage container (§2.1): its meta, the root node the
 * client opens into, and the used/quota accounting the UI shows as a usage bar.
 * Read-only — it transforms an already-loaded Storage, never queries.
 *
 * @mixin Storage
 */
final class StorageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var Storage $storage */
        $storage = $this->resource;

        return [
            'id' => $storage->getKey(),
            'panel' => $storage->panel,
            'owner' => [
                'type' => $storage->owner_type,
                'id' => $storage->owner_id,
            ],
            'name' => $storage->name,
            'slug' => $storage->slug,
            'root_node_id' => $storage->root_node_id,
            'disk' => $storage->disk,
            'storage_type' => $storage->storage_type,
            'used_bytes' => $storage->used_bytes,
            'quota_bytes' => $storage->quota_bytes,
            'status' => $storage->status,
            'created_at' => $storage->created_at,
            'updated_at' => $storage->updated_at,
            // M2: populate from the AccessResolver — what the actor may do on the
            // storage root (create folders, upload, manage). Open for now.
            'abilities' => [
                'view' => true,
                'create_folder' => true,
                'upload' => true,
                'manage' => true,
            ],
        ];
    }
}
