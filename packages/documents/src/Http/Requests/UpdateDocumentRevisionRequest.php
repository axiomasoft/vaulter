<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Http\Requests\VaulterFormRequest;

/** PATCH documents/{node}/revisions/{revision}: whether retention keeps the revision forever. A label is fixed when the revision is made. */
final class UpdateDocumentRevisionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'keep_forever' => ['required', 'boolean'],
        ];
    }

    public function keepForever(): bool
    {
        return $this->boolean('keep_forever');
    }
}
