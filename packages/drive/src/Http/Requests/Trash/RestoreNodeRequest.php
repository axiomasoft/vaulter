<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Trash;

use Illuminate\Validation\Rule;
use Packman\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * POST nodes/{node}/restore: an explicit destination folder (otherwise the folder the
 * node was trashed from) and what a name clash there does — by default the restored
 * node takes a free name.
 */
#[Internal]
final class RestoreNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_id' => ['nullable', 'string', 'max:191'],
            'on_conflict' => ['sometimes', Rule::in([OnNameConflict::Fail->value, OnNameConflict::Rename->value])],
        ];
    }

    public function destinationId(): ?string
    {
        $value = $this->input('destination_id');

        return is_string($value) ? $value : null;
    }

    public function onConflict(): OnNameConflict
    {
        return OnNameConflict::tryFrom($this->string('on_conflict')->toString()) ?? OnNameConflict::Rename;
    }
}
