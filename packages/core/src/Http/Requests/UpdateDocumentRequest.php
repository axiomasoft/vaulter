<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\Data\UpdateDocumentData;
use DocVault\ValueObjects\TipTapContent;

final class UpdateDocumentRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'array'],
            'meta' => ['sometimes', 'array'],
        ];
    }

    public function toData(): UpdateDocumentData
    {
        return new UpdateDocumentData(
            title: $this->has('title') ? $this->string('title')->toString() : null,
            content: $this->has('content') ? TipTapContent::fromArray($this->arrayInput('content')) : null,
            meta: $this->has('meta') ? $this->nullableArrayInput('meta') : null,
            actorId: $this->actorId(),
        );
    }
}
