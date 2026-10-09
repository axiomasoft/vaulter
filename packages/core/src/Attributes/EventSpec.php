<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;
use Vaulter\Enums\Events\EventType;

/**
 * The contract of one {@see EventType}: what it is about, which
 * sinks record it and the shape of its webhook data. It is the single source for the
 * sink matrix, the generated events reference and the conformance tests.
 *
 * `data` maps each key to a short type (`string`, `int`, `bool`, `string|null`,
 * `list<string>`, `object`); a trailing `?` on the key marks a key that may be absent.
 * `journal` is the change-journal operation the event produces (`upsert`, `remove`)
 * or null when it is not journalled.
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class EventSpec
{
    /**
     * @param array<string, string> $data
     */
    public function __construct(
        public string $subject,
        public array $data,
        public bool $activity,
        public bool $webhook,
        public ?string $journal,
        public string $description,
    ) {}

    /** @return list<string> the keys data() always carries */
    public function requiredKeys(): array
    {
        return array_values(array_filter(array_keys($this->data), static fn (string $key): bool => !str_ends_with($key, '?')));
    }

    /** @return list<string> every key data() may carry */
    public function allowedKeys(): array
    {
        return array_map(static fn (string $key): string => rtrim($key, '?'), array_keys($this->data));
    }
}
