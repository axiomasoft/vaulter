<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Illuminate\Http\Request;
use Packman\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Kernel;

/**
 * The logical `route` ids for `vaulter_throttled_total` (throttle:vaulter-public / uploads), merged from the
 * RouteLabelSource contributions of the active modules. Routes are resolved by their name.
 */
#[Internal]
final class VaulterThrottledRouteMap
{
    /** @var array<string, string>|null */
    private ?array $named = null;

    public function __construct(private readonly Kernel $kernel) {}

    public function routeFor(string $routeName): ?string
    {
        return ($this->named ??= $this->merged())[$routeName] ?? null;
    }

    public function resolve(Request $request): ?string
    {
        $name = $request->route()?->getName();

        return is_string($name) ? $this->routeFor($name) : null;
    }

    /** @return array<string, string> */
    private function merged(): array
    {
        $names = [];
        if ($this->kernel->isValid()) {
            foreach ($this->kernel->extensions()->resolve(RouteLabelSource::class) as $source) {
                $names += $source->throttled();
            }
        }

        return $names;
    }
}
