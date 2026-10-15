<?php

declare(strict_types=1);

namespace Vaulter\Events;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Vaulter\Attributes\Internal;

/**
 * A stored event type value read back as the {@see EventTypeContract} case of the module that declares it.
 * A module switched off still resolves its values, so the rows it recorded stay readable (D22).
 *
 * @implements CastsAttributes<EventTypeContract, EventTypeContract|string>
 */
#[Internal]
final class EventTypeCast implements CastsAttributes
{
    /** @param array<string, mixed> $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?EventTypeContract
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('[Vaulter] %s.%s holds a %s, not an event type value.', $model->getTable(), $key, get_debug_type($value)));
        }

        return app(EventCatalog::class)->resolve($value)
            ?? throw new InvalidArgumentException(sprintf('[Vaulter] No installed module declares the event type "%s" stored in %s.%s.', $value, $model->getTable(), $key));
    }

    /**
     * @param EventTypeContract|string|null $value
     * @param array<string, mixed>          $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value instanceof EventTypeContract ? $value->value : $value;
    }
}
