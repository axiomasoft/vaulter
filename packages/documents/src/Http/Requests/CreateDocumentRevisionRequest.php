<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Requests;

use Vaulter\Http\Requests\VaulterFormRequest;

/** POST documents/{node}/revisions: an optional label and whether retention keeps the revision forever. */
final class CreateDocumentRevisionRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:255'],
            'keep_forever' => ['nullable', 'boolean'],
        ];
    }

    public function label(): ?string
    {
        $label = $this->input('label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    public function keepForever(): bool
    {
        return $this->boolean('keep_forever');
    }
}
