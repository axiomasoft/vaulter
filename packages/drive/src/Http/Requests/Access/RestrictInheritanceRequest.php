<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Requests\Access;

use Illuminate\Validation\Rule;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Enums\Access\InheritanceRestriction;
use Vaulter\Http\Requests\VaulterFormRequest;

/**
 * Stop inherited grants at a node. `mode` decides what the node keeps: nothing
 * (`empty`, the default) or copies of the grants it inherited until now.
 */
#[Internal]
final class RestrictInheritanceRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['nullable', Rule::enum(InheritanceRestriction::class)],
        ];
    }

    public function mode(): InheritanceRestriction
    {
        return InheritanceRestriction::from($this->string('mode', InheritanceRestriction::Empty->value)->toString());
    }
}
