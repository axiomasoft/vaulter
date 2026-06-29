<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\DocumentVersion;

/**
 * @mixin DocumentVersion
 */
final class DocumentVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->getKey(),
            'document_id' => $version->document_id,
            'snapshot_label' => $version->snapshot_label,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at,
        ];
    }
}
