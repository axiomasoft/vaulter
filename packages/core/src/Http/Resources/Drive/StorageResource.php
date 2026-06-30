<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources\Drive;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Drive\Storage;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The read shape of a storage container (§2.1): its meta, the root node the
 * client opens into, and the used/quota accounting the UI shows as a usage bar.
 * Read-only — it transforms an already-loaded Storage, never WRITES (it resolves the
 * root node through the read repository, never the provisioning StorageManager).
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
            // What the request actor may do on the storage ROOT (§2.6 / H.2),
            // resolved through the AccessResolver so the Vue client gates its
            // toolbar (open the storage, create folders, upload, manage).
            'abilities' => $this->abilities($request, $storage),
        ];
    }

    /**
     * The storage-root ability flags the Vue toolbar binds against, resolved through
     * the AccessResolver for the request's actor (§2.6 / H.2). Mapped to the four
     * frontend keys: view (View on root), create_folder + upload (Create on root,
     * AND the workspace-create gate), manage (Manage on root).
     *
     * Resilience: with no acting user OR no provisioned root node yet, every flag is
     * false — a read-only shell (the SAFE default; the toolbar advertises nothing it
     * cannot back). The Resource never WRITES, so a missing root is resolved as
     * "nothing yet", never auto-provisioned here.
     *
     * @return array<string, bool>
     */
    private function abilities(Request $request, Storage $storage): array
    {
        $actor = $this->actor($request);
        $root = $this->root($storage);

        if ($actor === null || !$root instanceof Node) {
            return [
                'view' => false,
                'create_folder' => false,
                'upload' => false,
                'manage' => false,
            ];
        }

        $resolver = app(AccessResolver::class);
        $flags = $resolver->abilitiesFor($actor, $root);

        $canCreate = ($flags[Ability::Create->value] ?? false)
            && $resolver->canCreateInWorkspace($actor, $storage);

        return [
            'view' => $flags[Ability::View->value] ?? false,
            'create_folder' => $canCreate,
            'upload' => $canCreate,
            'manage' => $flags[Ability::Manage->value] ?? false,
        ];
    }

    /**
     * The storage root node, resolved READ-ONLY through the read repository (never the
     * provisioning StorageManager — a Resource must not write). Null when the storage
     * has no root_node_id yet, or the row is missing.
     */
    private function root(Storage $storage): ?Node
    {
        if ($storage->root_node_id === null) {
            return null;
        }

        return app(NodeReadRepository::class)->find($storage->root_node_id);
    }

    /**
     * The acting authorizable for the request, or null when unauthenticated.
     */
    private function actor(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
