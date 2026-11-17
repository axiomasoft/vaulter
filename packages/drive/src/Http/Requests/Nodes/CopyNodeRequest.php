<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Packman\Attributes\Internal;
use Vaulter\Drive\Support\DriveNameRules;
use Vaulter\Http\Requests\VaulterFormRequest;

/** POST nodes/{node}/copy: the destination folder (the drive root when absent) and an optional new name. */
#[Internal]
final class CopyNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'destination_id' => ['nullable', 'string', 'max:191'],
            'name' => ['nullable', ...array_values(array_diff(DriveNameRules::rules(), ['required']))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function destinationId(): ?string
    {
        $value = $this->input('destination_id');

        return is_string($value) ? $value : null;
    }

    public function name(): ?string
    {
        $name = $this->input('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
