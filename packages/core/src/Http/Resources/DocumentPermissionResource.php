<?php

declare(strict_types=1);

namespace DocVault\Http\Resources;

use DocVault\Models\DocumentPermission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentPermission
 */
final class DocumentPermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentPermission $permission */
        $permission = $this->resource;

        return [
            'id' => $permission->getKey(),
            'document_id' => $permission->document_id,
            'subject' => [
                'type' => $permission->subject_type,
                'id' => $permission->subject_id,
            ],
            'permission' => $permission->permission->value,
            'granted_by' => $permission->granted_by,
        ];
    }
}
