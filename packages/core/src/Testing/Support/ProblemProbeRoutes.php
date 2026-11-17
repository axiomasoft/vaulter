<?php

declare(strict_types=1);

namespace Vaulter\Testing\Support;

use Closure;
use Illuminate\Support\Facades\Route;
use LogicException;
use Packman\Attributes\Internal;
use Throwable;
use Vaulter\Configuration\RoutesOptions;

/**
 * Test-only versioned route that throws what a test hands it, so the problem renderer
 * is exercised through the real HTTP pipeline before any v1 route exists.
 */
#[Internal]
final class ProblemProbeRoutes
{
    public const string NAME = 'vaulter.v1.__probe';

    /**
     * @param array<string, Throwable|Closure(): Throwable> $throwables Keyed by the probe key of the URL
     */
    public static function register(array $throwables): void
    {
        Route::get(self::path('{key}'), static function (string $key) use ($throwables): never {
            $throwable = $throwables[$key] ?? null;
            if ($throwable === null) {
                throw new LogicException('No probe registered for ['.$key.'].');
            }

            throw $throwable instanceof Closure ? $throwable() : $throwable;
        })->where('key', '[A-Za-z0-9_]+')->name(self::NAME);
    }

    public static function path(string $key): string
    {
        return app(RoutesOptions::class)->versionedPrefix().'/__probe/'.$key;
    }
}
