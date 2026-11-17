<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Override;
use Packman\Attributes\Api;
use Packman\Config\Contracts\OptionsFactory;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Http\Middleware\AddApiVersionHeader;
use Vaulter\Http\Middleware\ApplyResponseCachePolicy;
use Vaulter\Http\Middleware\AssignCorrelationId;
use Vaulter\Http\Middleware\RecordVaulterRequestMetrics;

/**
 * Immutable HTTP routes, middleware, and rate-limit configuration (`vaulter.routes`).
 */
#[Api(since: '1.0')]
final readonly class RoutesOptions implements OptionsFactory
{
    public function __construct(
        public string $prefix = 'api/vaulter',
        public RouteMiddlewareOptions $middleware = new RouteMiddlewareOptions,
        public RouteRateLimitsOptions $rateLimits = new RouteRateLimitsOptions,
    ) {}

    /** @param array<array-key, mixed> $config */
    #[Override]
    public static function fromArray(array $config, string $path = 'vaulter.routes'): self
    {
        $validator = new ConfigurationValidator;
        $validator->rejectUnknownKeys($config, ['prefix', 'middleware', 'rate_limits'], $path);

        $rawPrefix = $config['prefix'] ?? 'api/vaulter';
        $prefix = 'api/vaulter';
        if (!is_string($rawPrefix)) {
            $validator->add(path: $path.'.prefix', message: 'must be a string');
        } else {
            $prefix = $rawPrefix;
        }

        $rawMiddleware = $config['middleware'] ?? [];
        if (!is_array($rawMiddleware)) {
            $validator->add(path: $path.'.middleware', message: 'must be an array');
            $rawMiddleware = [];
        }

        $rawRateLimits = $config['rate_limits'] ?? [];
        if (!is_array($rawRateLimits)) {
            $validator->add(path: $path.'.rate_limits', message: 'must be an array');
            $rawRateLimits = [];
        }

        $validator->failIfAny();

        return new self(
            prefix: $prefix,
            middleware: RouteMiddlewareOptions::fromArray($rawMiddleware, $path.'.middleware'),
            rateLimits: RouteRateLimitsOptions::fromArray($rawRateLimits, $path.'.rate_limits'),
        );
    }

    /** The prefix of the versioned REST surface, without leading or trailing slash. */
    public function versionedPrefix(): string
    {
        return trim($this->prefix, '/').'/v1';
    }

    /**
     * The host's authenticated stack followed by the package middleware; the host lists
     * only its own middleware.
     *
     * @return list<string>
     */
    public function authenticatedMiddleware(): array
    {
        return self::withPackageMiddleware($this->middleware->authenticated);
    }

    /** @return list<string> */
    public function publicMiddleware(): array
    {
        return self::withPackageMiddleware($this->middleware->public);
    }

    /** @return list<string> */
    public function hubMiddleware(): array
    {
        return self::withPackageMiddleware($this->middleware->hub);
    }

    /**
     * Correlation id first, so every later middleware and the error renderer see it; a
     * class the host already listed keeps the host's position.
     *
     * @param  list<string> $middleware
     * @return list<string>
     */
    private static function withPackageMiddleware(array $middleware): array
    {
        foreach ([AssignCorrelationId::class, AddApiVersionHeader::class, ApplyResponseCachePolicy::class, RecordVaulterRequestMetrics::class] as $class) {
            if (!in_array($class, $middleware, true)) {
                $middleware[] = $class;
            }
        }

        return $middleware;
    }
}
