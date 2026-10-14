<?php

declare(strict_types=1);

namespace Vaulter\Events\Concerns;

use LogicException;
use ReflectionEnumUnitCase;
use Vaulter\Attributes\EventSpec;
use Vaulter\Events\EventTypeContract;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The {@see EventTypeContract} of a module's event enum: the spec is the {@see EventSpec} attribute of the
 * case, read once per value without the container (the module compiler and the inventory read enums too).
 *
 * @phpstan-require-implements EventTypeContract
 */
trait DescribesEvent
{
    use HasLabel;

    public function spec(): EventSpec
    {
        /** @var array<string, EventSpec> $specs */
        static $specs = [];

        return $specs[$this->value] ??= ((new ReflectionEnumUnitCase(self::class, $this->name))->getAttributes(EventSpec::class)[0] ?? null)?->newInstance()
            ?? throw new LogicException(sprintf('[Vaulter] The event type %s::%s has no %s.', self::class, $this->name, EventSpec::class));
    }
}
