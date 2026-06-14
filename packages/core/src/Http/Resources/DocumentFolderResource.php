<?php

declare(strict_types=1);

namespace DocVault\Http\Resources;

use DocVault\Models\DocumentFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentFolder
 */
final class DocumentFolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentFolder $folder */
        $folder = $this->resource;

        return [
            'id' => $folder->getKey(),
            'workspace' => [
                'type' => $folder->workspace_type,
                'id' => $folder->workspace_id,
            ],
            'parent_id' => $folder->parent_id,
            'name' => $folder->name,
            'order' => $folder->order,
        ];
    }
}
