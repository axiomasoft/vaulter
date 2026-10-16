<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;
use Vaulter\Events\EventTypeContract;

/**
 * The contract of one {@see EventTypeContract} case: what it is about, the shape of its outbound data and
 * the facts the sinks of the owning module read. It is the single source for the generated events
 * reference, the conformance tests and the inventory.
 *
 * `data` maps each key to a short type (`string`, `int`, `bool`, `string|null`, `list<string>`, `object`);
 * a trailing `?` on the key marks a key that may be absent. `external` says the type may leave the process
 * through the outbox consumers (D38): the kernel knows no webhook. `activity` and `journal` are data for
 * the drive's event sinks — the activity feed and the change journal (`upsert`, `remove`, or null when the
 * type is not journalled); the kernel does not read them.
 */
#[Api]
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
        public bool $external,
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
