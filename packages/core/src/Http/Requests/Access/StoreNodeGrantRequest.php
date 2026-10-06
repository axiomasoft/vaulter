<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests\Access;

use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Data\Access\GrantInput;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Access\Permission;
use Vaulter\Http\Requests\VaulterFormRequest;
use Vaulter\Rules\Access\RegisteredSubjectType;

/**
 * Validates a grant-access request (§2.6) and assembles its GrantInput
 * command. The subject (type + id), level, effect, scope and optional expiry are
 * host-supplied; the controller authorizes Share/Manage on the node first.
 */
final class StoreNodeGrantRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_type' => ['required', 'string', 'max:255', new RegisteredSubjectType],
            'subject_id' => ['required'],
            'level' => ['required', Rule::enum(GrantLevel::class)],
            'effect' => ['nullable', Rule::enum(Effect::class)],
            'scope' => ['nullable', Rule::enum(GrantScope::class)],
            'permissions' => ['nullable', 'integer', 'min:0', 'max:'.Permission::all()],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    public function toData(): GrantInput
    {
        $expiresAt = $this->input('expires_at');

        return new GrantInput(
            subject: new Subject(
                type: $this->string('subject_type')->toString(),
                id: $this->scalarId('subject_id'),
            ),
            level: GrantLevel::from($this->string('level')->toString()),
            effect: Effect::from($this->string('effect', Effect::Allow->value)->toString()),
            scope: GrantScope::from($this->string('scope', GrantScope::Subtree->value)->toString()),
            permissions: (int) $this->integer('permissions', 0),
            expiresAt: is_string($expiresAt) ? Carbon::parse($expiresAt) : null,
        );
    }
}
