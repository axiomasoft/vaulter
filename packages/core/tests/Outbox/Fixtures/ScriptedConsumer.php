<?php

declare(strict_types=1);

namespace Vaulter\Tests\Outbox\Fixtures;

use RuntimeException;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\ConsumeResult;
use Vaulter\Events\Outbox\DeliveryClass;
use Vaulter\Events\Outbox\DeliveryControl;
use Vaulter\Events\Outbox\OutboxConsumer;
use Vaulter\Events\Outbox\OutboxEnvelope;

/**
 * A durable outbox consumer that answers what the test scripted and logs the event ids it consumed. It subscribes
 * to every type except the ones in {@see $ignored}.
 */
class ScriptedConsumer implements OutboxConsumer
{
    public const string ID = 'test.durable';

    /** @var list<string> */
    public static array $consumed = [];

    /** @var list<ConsumeResult|'throw'> answers, one per call; done when empty */
    public static array $answers = [];

    /** @var list<string> */
    public static array $ignored = [];

    public static function reset(): void
    {
        self::$consumed = [];
        self::$answers = [];
        self::$ignored = [];
    }

    public function id(): string
    {
        return static::ID;
    }

    public function deliveryClass(): DeliveryClass
    {
        return DeliveryClass::Durable;
    }

    public function subscribes(EventTypeContract $type): bool
    {
        return !in_array($type->value, self::$ignored, true);
    }

    public function consume(OutboxEnvelope $envelope, DeliveryControl $control): ConsumeResult
    {
        self::$consumed[] = static::ID.':'.$envelope->eventId;
        $answer = array_shift(self::$answers) ?? ConsumeResult::done();

        return $answer === 'throw' ? throw new RuntimeException('consumer failed') : $answer;
    }
}
