<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * PUT documents/{node}/content: the whole new content. The document's type decodes and
 * validates it; the lost-update guard is the If-Match header, not a body field.
 */
#[Internal]
final class UpdateDocumentContentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return $this->arrayInput('content');
    }
}
