<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

/** The honest contribution: it takes `delete` away. */
final class AcmeNarrowing implements AcmeRestriction
{
    public function restrict(AcmeConstraints $constraints): AcmeConstraints
    {
        return $constraints->without('delete');
    }
}
