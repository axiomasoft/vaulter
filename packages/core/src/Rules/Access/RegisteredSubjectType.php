<?php

declare(strict_types=1);

namespace Vaulter\Rules\Access;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A grant subject_type must resolve to a REAL host model:
 * either a registered morph alias (user|role|team / a host alias) or — when the host
 * does not enforce a morph map — a concrete Eloquent model FQCN. An arbitrary or
 * typo'd string is rejected here so it can never be written into v_node_grants: a
 * grant addressed to a non-existent subject_type is dead data that matches nothing
 * the PermissionDriver's subjectsFor() can ever produce.
 */
final class RegisteredSubjectType implements ValidationRule
{
    /**
     * @param Closure(string): PotentiallyTranslatedString $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && $this->resolvesToModel($value)) {
            return;
        }

        $fail('[Vaulter] The :attribute is not a registered grant subject type.');
    }

    /**
     * Does the type map to a concrete Eloquent model? A registered morph alias wins
     * first; failing that — only when the host has NOT enforced a morph map — a real
     * model class FQCN is accepted (Laravel's own morph fallback). With an enforced
     * morph map an unaliased FQCN is rejected, exactly as the host intends.
     */
    private function resolvesToModel(string $type): bool
    {
        $mapped = Relation::getMorphedModel($type);

        if ($mapped !== null) {
            return is_subclass_of($mapped, Model::class);
        }

        if (Relation::requiresMorphMap()) {
            return false;
        }

        return is_subclass_of($type, Model::class);
    }
}
