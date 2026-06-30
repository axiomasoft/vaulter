<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Drive;

use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Rules\Access\RegisteredSubjectType;

/**
 * Validates a revoke-access request (§2.6): the subject (type + id) whose grant is
 * dropped. subject_type is held to the same registered-morph-alias bar as the grant
 * path, so a revoke can never be addressed to a bogus subject type.
 */
final class DestroyNodeGrantRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'max:255', new RegisteredSubjectType],
            'subject_id' => ['required'],
        ];
    }
}
