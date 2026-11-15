<?php

declare(strict_types=1);

namespace Vaulter\Operations;

use InvalidArgumentException;
use Vaulter\Attributes\Api;

/**
 * The concurrency and idempotency policy of one mutating operation: the aggregate it changes
 * (`<module>.<aggregate>`), how a concurrent writer is kept from losing its change, what a repeat does and the
 * REST routes that run it (names without the `vaulter.v1.` prefix; none for an operation of the PHP API only).
 */
#[Api]
final readonly class OperationPolicy
{
    public const string AGGREGATE_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public const string ROUTE_PATTERN = '/^[a-z][a-z0-9_-]*(\.[a-z][a-z0-9_-]*)*$/';

    /**
     * @param list<string> $routes
     */
    public function __construct(
        public string $aggregate,
        public ConcurrencyPolicy $concurrency,
        public IdempotencyPolicy $idempotency,
        public array $routes = [],
    ) {
        if (preg_match(self::AGGREGATE_PATTERN, $aggregate) !== 1) {
            throw new InvalidArgumentException(sprintf('[Vaulter] "%s" is not an aggregate: expected "<module>.<aggregate>" in dotted lowercase segments.', $aggregate));
        }
        // A machine protocol fenced by its generation deduplicates by itself: an Idempotency-Key receipt beside it would be a second, conflicting answer.
        if ($concurrency === ConcurrencyPolicy::Generation && $idempotency === IdempotencyPolicy::Receipt) {
            throw new InvalidArgumentException(sprintf('[Vaulter] The aggregate "%s" cannot take a receipt over a generation-fenced protocol.', $aggregate));
        }
        foreach ($routes as $route) {
            if (str_starts_with($route, 'vaulter.')) {
                throw new InvalidArgumentException(sprintf('[Vaulter] The route "%s" of the aggregate "%s" must be named without the "vaulter.v1." prefix.', $route, $aggregate));
            }
            if (preg_match(self::ROUTE_PATTERN, $route) !== 1) {
                throw new InvalidArgumentException(sprintf('[Vaulter] "%s" is not a route name.', $route));
            }
        }
        if (count(array_unique($routes)) !== count($routes)) {
            throw new InvalidArgumentException(sprintf('[Vaulter] A policy of the aggregate "%s" lists a route twice.', $aggregate));
        }
    }

    /** The module the aggregate belongs to: the first segment of its name. */
    public function module(): string
    {
        return explode('.', $this->aggregate, 2)[0];
    }

    /**
     * @return array{aggregate: string, concurrency: string, idempotency: string, routes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'aggregate' => $this->aggregate,
            'concurrency' => $this->concurrency->value,
            'idempotency' => $this->idempotency->value,
            'routes' => $this->routes,
        ];
    }
}
