<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Closure;
use InvalidArgumentException;
use Vaulter\Modules\ModuleId;

/**
 * Every event type of the application (D38): the cases of the enums the modules declare with
 * `ModuleBuilder::events()`. The kernel owns the mechanics, the modules own the values; the catalogue is
 * the single source of the events reference, of the types an outbox consumer may subscribe to and of the
 * types a stored value resolves to.
 *
 * {@see all()}, {@see get()} and {@see owner()} answer for the active modules. {@see resolve()} also reads
 * the types of an installed module that is switched off, so the rows it recorded before stay readable (D22:
 * switching a module off keeps its data).
 */
final class EventCatalog
{
    /** @var array<string, array{type: EventTypeContract, module: string}>|null */
    private ?array $activeEntries = null;

    /** @var array<string, EventTypeContract>|null */
    private ?array $installedEntries = null;

    /**
     * @param Closure(): iterable<string, iterable<class-string>> $active    the enums of the active modules, by module id
     * @param Closure(): iterable<string, iterable<class-string>> $installed the enums of every installed module, by module id
     */
    public function __construct(
        private readonly Closure $active,
        private readonly Closure $installed,
    ) {}

    /** @return list<EventTypeContract> by module id, then in the order each enum declares its cases */
    public function all(): array
    {
        return array_values(array_map(static fn (array $entry): EventTypeContract => $entry['type'], $this->entries()));
    }

    /**
     * The types an outbox consumer outside the process may receive.
     *
     * @return list<EventTypeContract>
     */
    public function external(): array
    {
        return array_values(array_filter($this->all(), static fn (EventTypeContract $type): bool => $type->spec()->external));
    }

    /** @return list<string> the values of {@see external()} */
    public function externalValues(): array
    {
        return array_map(static fn (EventTypeContract $type): string => $type->value, $this->external());
    }

    /** @return list<string> */
    public function values(): array
    {
        return array_keys($this->entries());
    }

    public function has(string $value): bool
    {
        return isset($this->entries()[$value]);
    }

    /** @throws InvalidArgumentException when no active module declares `$value` */
    public function get(string $value): EventTypeContract
    {
        return $this->entries()[$value]['type'] ?? throw new InvalidArgumentException(sprintf('[Vaulter] No active module declares the event type "%s".', $value));
    }

    /** @throws InvalidArgumentException when no active module declares `$value` */
    public function owner(string $value): ModuleId
    {
        $module = $this->entries()[$value]['module'] ?? throw new InvalidArgumentException(sprintf('[Vaulter] No active module declares the event type "%s".', $value));

        return new ModuleId($module);
    }

    /** The type of a stored value, declared by any installed module; null for a value no module knows. */
    public function resolve(string $value): ?EventTypeContract
    {
        return $this->entries()[$value]['type'] ?? $this->installedTypes()[$value] ?? null;
    }

    /** @return array<string, array{type: EventTypeContract, module: string}> */
    private function entries(): array
    {
        if ($this->activeEntries !== null) {
            return $this->activeEntries;
        }

        $entries = [];
        foreach (self::types(($this->active)()) as [$module, $type]) {
            // The module compiler refuses such a graph; a catalogue built by hand refuses it too.
            if (isset($entries[$type->value])) {
                throw new InvalidArgumentException(sprintf('[Vaulter] The event type "%s" is declared by both the module "%s" and the module "%s".', $type->value, $entries[$type->value]['module'], $module));
            }
            $entries[$type->value] = ['type' => $type, 'module' => $module];
        }

        return $this->activeEntries = $entries;
    }

    /** @return array<string, EventTypeContract> */
    private function installedTypes(): array
    {
        if ($this->installedEntries !== null) {
            return $this->installedEntries;
        }

        $types = [];
        foreach (self::types(($this->installed)()) as [, $type]) {
            $types[$type->value] ??= $type;
        }

        return $this->installedEntries = $types;
    }

    /**
     * @param  iterable<string, iterable<class-string>>         $sources
     * @return iterable<array{0: string, 1: EventTypeContract}>
     */
    private static function types(iterable $sources): iterable
    {
        foreach ($sources as $module => $enums) {
            foreach ($enums as $enum) {
                if (!is_subclass_of($enum, EventTypeContract::class)) {
                    continue;
                }
                foreach ($enum::cases() as $type) {
                    yield [(string) $module, $type];
                }
            }
        }
    }
}
