<?php

declare(strict_types=1);

namespace Vaulter\Http\Middleware;

use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Routing\RouteLabelSource;
use Vaulter\Kernel;

/**
 * Server-side `operation` labels for {@see RecordVaulterRequestMetrics}, merged from the RouteLabelSource
 * contributions of the active modules.
 *
 * Routes are labelled by name. The path rules exist for collectors that see only a URI (php-fpm status, the load
 * stand) and cover the versioned routes whose cost differs most: bytes in or out, document reads and writes, the hub.
 */
#[Internal]
final class VaulterHttpOperationMap
{
    /** @var array<string, string>|null */
    private ?array $named = null;

    /** @var list<array{methods: list<string>, pattern: string, operation: string}>|null */
    private ?array $rules = null;

    public function __construct(private readonly Kernel $kernel) {}

    public function operationFor(string $routeName): ?string
    {
        return ($this->named ??= $this->mergedNames())[$routeName] ?? null;
    }

    public function resolve(string $method, string $pathAfterPrefix): ?string
    {
        $method = strtoupper($method);
        $normalized = trim($pathAfterPrefix, '/');

        foreach ($this->rules ??= $this->mergedRules() as $rule) {
            if (!in_array($method, $rule['methods'], true)) {
                continue;
            }

            $regex = '#^'.preg_replace('#\{id\}#', '[^/]+', $rule['pattern']).'$#';

            if (preg_match($regex, $normalized) === 1) {
                return $rule['operation'];
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function mergedNames(): array
    {
        $names = [];
        foreach ($this->sources() as $source) {
            $names += $source->operations();
        }

        return $names;
    }

    /** @return list<array{methods: list<string>, pattern: string, operation: string}> */
    private function mergedRules(): array
    {
        $rules = [];
        foreach ($this->sources() as $source) {
            array_push($rules, ...$source->operationPaths());
        }

        return $rules;
    }

    /** @return list<RouteLabelSource> */
    private function sources(): array
    {
        return $this->kernel->isValid() ? $this->kernel->extensions()->resolve(RouteLabelSource::class) : [];
    }
}
