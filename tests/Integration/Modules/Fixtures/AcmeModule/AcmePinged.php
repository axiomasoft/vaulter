<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Modules\Fixtures\AcmeModule;

/** The event the module listens to; the module's listener is registered only while it is active. */
final readonly class AcmePinged
{
    public function __construct(public string $by = 'host') {}
}
