<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

/**
 * Typed accessors over config('vaulter.*'). All package code reads configuration
 * through here so the surface is type-safe and swappable models resolve consistently.
 */
#[Api]
final readonly class RoutesConfig
{
    #[Internal]
    public function __construct(private Repository $config) {}

    public function prefix(): string
    {
        $value = $this->config->get('vaulter.routes.prefix', 'api/vaulter');

        return is_string($value) ? $value : 'api/vaulter';
    }

    /** The prefix of the versioned REST surface, without leading or trailing slash. */
    public function versionedPrefix(): string
    {
        return trim($this->prefix(), '/').'/v1';
    }

    /**
     * The host's authenticated stack followed by the package middleware; the host lists
     * only its own middleware.
     *
     * @return list<string>
     */
    public function authenticatedMiddleware(): array
    {
        return $this->withPackageMiddleware($this->stringList(
            $this->config->get('vaulter.routes.middleware.authenticated'),
            ['api', 'auth:sanctum'],
        ));
    }

    /** @return list<string> */
    public function publicMiddleware(): array
    {
        return $this->withPackageMiddleware($this->stringList($this->config->get('vaulter.routes.middleware.public'), ['api']));
    }

    /** @return list<string> */
    public function hubMiddleware(): array
    {
        return $this->withPackageMiddleware($this->stringList($this->config->get('vaulter.routes.middleware.hub'), ['api']));
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
     * Correlation id first, so every later middleware and the error renderer see it; a
     * class the host already listed keeps the host's position.
     *
     * @param  list<string> $middleware
     * @return list<string>
     */
    private function withPackageMiddleware(array $middleware): array
    {
        foreach ([AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class] as $class) {
            if (!in_array($class, $middleware, true)) {
                $middleware[] = $class;
            }
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
