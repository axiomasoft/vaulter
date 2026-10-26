<?php

declare(strict_types=1);

namespace Vaulter\Integration\Tests\Outbox\Fixtures;

use Vaulter\Events\Outbox\DeliveryClass;

/** An ephemeral consumer (control traffic): holds no retention and records no gap when detached. */
final class EphemeralConsumer extends ScriptedConsumer
{
    public const string ID = 'test.ephemeral';

    #[\Override]
    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Ephemeral;
    }
}
