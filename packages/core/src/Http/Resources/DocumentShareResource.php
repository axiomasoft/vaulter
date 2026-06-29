<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Models\DocumentShare;

/**
 * @mixin DocumentShare
 */
final class DocumentShareResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var DocumentShare $share */
        $share = $this->resource;

        return [
            'id' => $share->getKey(),
            'document_id' => $share->document_id,
            'token' => $share->token,
            'permission' => $share->permission->value,
            'expires_at' => $share->expires_at,
            'created_at' => $share->created_at,
        ];
    }
}
