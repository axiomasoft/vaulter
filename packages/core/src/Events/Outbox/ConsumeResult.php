<?php

declare(strict_types=1);

namespace Vaulter\Events\Outbox;

/**
 * What an {@see OutboxConsumer} did with one envelope: done, retry later (with backoff until the attempt budget
 * runs out), or dead at once.
 */
final readonly class ConsumeResult
{
    public const string DONE = 'done';

    public const string RETRY = 'retry';

    public const string DEAD = 'dead';

    private function __construct(
        public string $outcome,
        public ?string $error,
    ) {}

    public static function done(): self
    {
        return new self(self::DONE, null);
    }

    public static function retry(string $error): self
    {
        return new self(self::RETRY, $error);
    }

    public static function dead(string $error): self
    {
        return new self(self::DEAD, $error);
    }
}
