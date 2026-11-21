<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Data;

/**
 * Target: packages/core/src/Data/CommandOutcome.php in vaulter-ai. What an owner reports for one key.
 * committed/rejected are durable at the owner; running = intent registered, owner has no outcome;
 * unavailable = current access hides everything; conflict = same key or proposal, other fingerprint.
 */
final readonly class CommandOutcome
{
    private function __construct(
        public string $state,
        public ?string $receiptId = null,
        public ?int $generation = null,
        public ?int $position = null,
        public ?string $reason = null,
    ) {}

    public static function committed(string $receiptId, int $generation, int $position): self
    {
        return new self('committed', $receiptId, $generation, $position);
    }

    public static function rejected(string $reason): self
    {
        return new self('rejected', reason: $reason);
    }

    public static function running(): self
    {
        return new self('running');
    }

    public static function unavailable(): self
    {
        return new self('unavailable');
    }

    public static function conflict(): self
    {
        return new self('conflict');
    }
}
