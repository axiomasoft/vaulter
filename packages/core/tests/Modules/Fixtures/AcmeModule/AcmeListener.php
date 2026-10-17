<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

final class AcmeListener
{
    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function handle(AcmePinged $event): void
    {
        AcmeProbe::$log[] = 'listener:'.$event->by;
    }
}
