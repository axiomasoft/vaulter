<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Fruitcake\Cors\CorsService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Override;
use Packman\Attributes\Internal;

/**
 * Adds the v1 headers when Laravel's global CORS middleware configures its service.
 *
 * @phpstan-import-type CorsInputOptions from CorsService
 */
#[Internal]
final class ExposeVaulterCorsHeaders extends CorsService
{
    public function __construct(private readonly Container $app, private readonly Repository $config)
    {
        parent::__construct();
    }

    /** @param CorsInputOptions $options */
    #[Override]
    public function setOptions(array $options): void
    {
        $prefix = $this->config->get('vaulter.routes.prefix', 'api/vaulter');
        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        $allowed = $options['allowedHeaders'] ?? $options['allowed_headers'] ?? [];
        $exposed = $options['exposedHeaders'] ?? $options['exposed_headers'] ?? [];

        if (is_string($prefix) && $request instanceof Request && $request->is(trim(trim($prefix, '/').'/v1/*', '/'))) {
            if (!in_array('*', $allowed, true)) {
                $allowed = self::merge(['If-Match', 'X-Vaulter-If-Match', 'Idempotency-Key', 'Authorization', 'Content-Type'], $allowed);
            }
            $exposed = self::merge(['ETag', 'X-Vaulter-ETag', 'Retry-After', 'Idempotent-Replayed', 'X-Vaulter-Api-Version'], $exposed === false ? [] : $exposed);
        }

        parent::setOptions([...$options, 'allowedHeaders' => $allowed, 'exposedHeaders' => $exposed]);
    }

    /**
     * @param  list<string>  $required
     * @param  array<string> $existing
     * @return list<string>
     */
    private static function merge(array $required, array $existing): array
    {
        $result = [];
        foreach ([...$required, ...$existing] as $header) {
            $result[strtolower($header)] ??= $header;
        }

        return array_values($result);
    }
}
