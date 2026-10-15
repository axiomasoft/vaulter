<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use RuntimeException;

/** The owner of a Restriction slot refused a contribution that widened the constraints it was given. */
final class AcmeRestrictionViolation extends RuntimeException
{
    /** @param class-string $restriction */
    public static function widened(string $restriction): self
    {
        return new self(sprintf('%s widened the constraints; a restriction may only narrow them.', $restriction));
    }
}
