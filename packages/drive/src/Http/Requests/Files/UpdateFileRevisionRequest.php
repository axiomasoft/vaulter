<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Files;

use Vaulter\Http\Requests\VaulterFormRequest;

/** PATCH files/{node}/revisions/{revision}: whether retention keeps the revision forever. */
final class UpdateFileRevisionRequest extends VaulterFormRequest
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
