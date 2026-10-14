<?php

declare(strict_types=1);

namespace Vaulter\Events;

use BackedEnum;
use Vaulter\Attributes\EventSpec;

/**
 * An event type a module contributes to the {@see EventCatalog} (D38): a backed string enum of the
 * owning module, one case per type, each case carrying its {@see EventSpec}. The string value is the
 * identity stored in the activity feed, the change journal and the outbox, and the `type` of an
 * outbound envelope; two modules declaring the same value make the module graph invalid.
 *
 * Implement it with {@see Concerns\DescribesEvent}, which reads the spec from the case attribute.
 *
 * @property-read string $value
 */
interface EventTypeContract extends BackedEnum
{
    public function spec(): EventSpec;

    public function label(): string;
}
