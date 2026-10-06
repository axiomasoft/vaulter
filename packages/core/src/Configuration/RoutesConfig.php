<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
final readonly class RoutesConfig
{
    public function __construct(private Repository $config) {}

    public function prefix(): string
    {
        $value = $this->config->get('vaulter.routes.prefix', 'api/vaulter');

        return is_string($value) ? $value : 'api/vaulter';
    }

    /** @return list<string> */
    public function authenticatedMiddleware(): array
    {
        return $this->withRequestMetrics($this->stringList(
            $this->config->get('vaulter.routes.middleware.authenticated'),
            ['api', 'auth:sanctum'],
        ));
    }

    /** @return list<string> */
    public function publicMiddleware(): array
    {
        return $this->withRequestMetrics($this->stringList($this->config->get('vaulter.routes.middleware.public'), ['api']));
    }

    /** @return list<string> */
    public function hubMiddleware(): array
    {
        return $this->withRequestMetrics($this->stringList($this->config->get('vaulter.routes.middleware.hub'), ['api']));
    }

    public function publicRateLimit(): int
    {
        $value = $this->config->get('vaulter.routes.rate_limits.public', 60);

        $limit = is_numeric($value) ? (int) $value : 60;

        return max($limit, 1);
    }

    public function uploadsRateLimit(): int
    {
        $value = $this->config->get('vaulter.routes.rate_limits.uploads', 120);

        $limit = is_numeric($value) ? (int) $value : 120;

        return max($limit, 1);
    }

    /**
     * @param  list<string> $middleware
     * @return list<string>
     */
    private function withRequestMetrics(array $middleware): array
    {
        if (!in_array(RecordVaulterRequestMetrics::class, $middleware, true)) {
            $middleware[] = RecordVaulterRequestMetrics::class;
        }

        return $middleware;
    }

    /**
     * @param  list<string> $default
     * @return list<string>
     */
    private function stringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
