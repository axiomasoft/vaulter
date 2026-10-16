<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Nodes;

use Vaulter\Attributes\Internal;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Support\DriveNameRules;

/** PATCH nodes/{node}: the only field a node update changes is its name. */
#[Internal]
final class RenameNodeRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => DriveNameRules::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return DriveNameRules::messages();
    }

    public function name(): string
    {
        return $this->string('name')->toString();
    }
}
