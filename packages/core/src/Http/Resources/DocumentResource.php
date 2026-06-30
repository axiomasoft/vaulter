<?php

declare(strict_types=1);

namespace Vaulter\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Access\Abilities\DocumentAbilities;
use Vaulter\Models\Documents\Document;
use Vaulter\VaulterManager;

/**
 * @mixin Document
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var Document $document */
        $document = $this->resource;
        $manager = app(VaulterManager::class);

        return [
            'id' => $document->getKey(),
            'workspace' => [
                'type' => $document->workspace_type,
                'id' => $document->workspace_id,
            ],
            'folder_id' => $document->folder_id,
            'type' => $document->type->value,
            'title' => $document->title,
            'content' => $document->content,
            'meta' => $document->meta,
            'created_at' => $document->created_at,
            'updated_at' => $document->updated_at,
            'abilities' => DocumentAbilities::for($request->user(), $document, $manager)->toArray(),
        ];
    }
}
