<?php

declare(strict_types=1);

namespace Vaulter\Access\Abilities;

use Illuminate\Contracts\Auth\Authenticatable;
use Vaulter\Models\Documents\Document;
use Vaulter\VaulterManager;

/**
 * Base for computed-permission DTOs shipped to the front-end. Subclasses map a
 * set of named abilities to PermissionResolver actions; the resulting boolean
 * flags drive what the UI renders.
 */
abstract readonly class AbilitiesDto
{
    /**
     * @param  array<string, string> $abilityMap flag key => resolver action
     * @return array<string, bool>
     */
    protected static function resolveFlags(
        ?Authenticatable $user,
        Document $document,
        VaulterManager $manager,
        array $abilityMap,
    ): array {
        $resolver = $manager->permissionResolver();

        $flags = [];

        foreach ($abilityMap as $key => $action) {
            if ($resolver === null) {
                $flags[$key] = true;

                continue;
            }

            $flags[$key] = $user !== null && $resolver->can($user, $action, $document);
        }

        return $flags;
    }

    /**
     * @return array<string, bool>
     */
    abstract public function toArray(): array;
}
