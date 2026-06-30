<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Vaulter\ValueObjects\Documents\TipTapContent;

final class SyncCollaborationRequest extends VaulterFormRequest
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
