<?php

declare(strict_types=1);

namespace Vaulter\Tests\Modules\Fixtures\AcmeModule;

use RuntimeException;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxEnvelope;

/** A durable consumer that audits the external events it subscribes to; it fails when a test says so. */
final class AcmeOutboxConsumer implements OutboxConsumer
{
    public const string ID = 'acme.audit';

    /** @var list<string> event ids consumed */
    public static array $consumed = [];

    public static bool $failing = false;

    public function __construct()
    {
        AcmeProbe::resolved(self::class);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Durable;
    }

    public function subscribes(EventTypeContract $type): bool
    {
        return $type->value === 'folder.created';
    }

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult
    {
        if (self::$failing) {
            throw new RuntimeException('acme audit failed');
        }
        self::$consumed[] = $envelope->eventId;

        return ConsumeResult::done();
    }
}
