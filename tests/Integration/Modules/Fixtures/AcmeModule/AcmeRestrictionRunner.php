<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

use Vaulter\Kernel;

/** The consumer of the slot, written by its owner: contributions run in slot order and each must stay a subset of its input. */
final readonly class AcmeRestrictionRunner
{
    public function __construct(private Kernel $kernel) {}

    public function apply(AcmeConstraints $constraints): AcmeConstraints
    {
        foreach ($this->kernel->extensions()->resolve(AcmeRestriction::class) as $restriction) {
            $narrowed = $restriction->restrict($constraints);
            if (!$narrowed->isSubsetOf($constraints)) {
                throw AcmeRestrictionViolation::widened($restriction::class);
            }
            $constraints = $narrowed;
        }

        return $constraints;
    }
}
