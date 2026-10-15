<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Access;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Attributes\Internal;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Rules\Access\RegisteredSubjectType;

/**
 * Grant a role on a node to a subject: `{subject: {type, id}, role, scope?, expires_at?}`.
 * The subject type must be one the permission driver can enumerate; Manage on the node
 * is checked by the operation.
 */
#[Internal]
final class CreateGrantRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'array:type,id'],
            'subject.type' => ['required', 'string', 'max:255', new RegisteredSubjectType],
            'subject.id' => ['required', 'max:191'],
            'role' => ['required', 'string', Rule::in((new RoleRegistry)->keys())],
            'scope' => ['nullable', Rule::enum(GrantScope::class)],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function toData(): GrantInput
    {
        $expiresAt = $this->input('expires_at');

        return new GrantInput(
            subject: new Subject(
                type: $this->string('subject.type')->toString(),
                id: $this->scalarId('subject.id'),
            ),
            role: $this->string('role')->toString(),
            scope: GrantScope::from($this->string('scope', GrantScope::Subtree->value)->toString()),
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
        );
    }
}
