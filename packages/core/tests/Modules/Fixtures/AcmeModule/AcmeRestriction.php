<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

/** The slot the AcmeModule defines (kind Restriction): a contribution may narrow the constraints, never widen them. */
interface AcmeRestriction
{
    public function restrict(AcmeConstraints $constraints): AcmeConstraints;
}
