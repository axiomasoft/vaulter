<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Illuminate\Validation\Rule;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\OnNameConflict;
use Vaulter\Http\Requests\VaulterFormRequest;

/** POST nodes/{node}/move: the destination folder (the drive root when null) and what a name clash does. */
#[Internal]
final class MoveNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_id' => ['present', 'nullable', 'string', 'max:191'],
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
        return OnNameConflict::tryFrom($this->string('on_conflict')->toString()) ?? OnNameConflict::Fail;
    }
}
