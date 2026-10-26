<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

/** A dishonest contribution, never declared by the manifest: a test adds it to show that the owner refuses it. */
final class AcmeWidening implements AcmeRestriction
{
    public function restrict(AcmeConstraints $constraints): AcmeConstraints
    {
        return $constraints->with('admin');
    }
}
