<?php

declare(strict_types=1);

namespace DocVault\Http\Requests;

use DocVault\ValueObjects\TipTapContent;

final class SyncCollaborationRequest extends DocVaultFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
            'content.type' => ['required', 'in:doc'],
        ];
    }

    public function content(): TipTapContent
    {
        return TipTapContent::fromArray($this->arrayInput('content'));
    }
}
