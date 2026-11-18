<?php

declare(strict_types=1);

namespace Vaulter\Repo\Tests\Support;

use Illuminate\Routing\Route;
use LogicException;
use ReflectionClass;
use Vaulter\Configuration\RoutesOptions;
use Vaulter\Testing\Support\OpenApi\OpenApiContract;
use Vaulter\Testing\Support\OpenApi\ValidateOpenApiResponses;

/** Test-only release boundary. Ownership comes from controller files and the release manifest. */
final class ReleaseOpenApi
{
    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    public static function manifest(): array
    {
        return json_decode(file_get_contents(self::root().'/release/packages.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function moduleChannel(string $module): string
    {
        foreach (self::manifest()['php'] as $owner) {
            if (basename($owner['path']) === $module) {
                return $owner['channel'];
            }
        }
        throw new LogicException('Unowned problem/operation module: '.$module);
    }

    public static function routeChannel(Route $route): string
    {
        $controller = explode('@', $route->getActionName())[0];
        $file = (new ReflectionClass($controller))->getFileName();
        $matches = [];
        foreach (self::manifest()['php'] as $owner) {
            if (str_starts_with($file, self::root().'/'.$owner['path'].'/')) {
                $matches[] = $owner['channel'];
            }
        }
        if (count($matches) !== 1) {
            throw new LogicException('Expected one route owner: '.$route->getName());
        }

        return $matches[0];
    }

    public static function routes(iterable $routes, string $channel): array
    {
        $owned = [];
        foreach ($routes as $route) {
            $name = (string) $route->getName();
            if (str_starts_with($name, ValidateOpenApiResponses::PREFIX) && !ValidateOpenApiResponses::isProbe($name) && self::routeChannel($route) === $channel) {
                $owned[substr($name, strlen(ValidateOpenApiResponses::PREFIX))] = $route;
            }
        }
        ksort($owned);

        return $owned;
    }

    public static function privateContract(): OpenApiContract
    {
        return OpenApiContract::fromFile(self::root().'/packages/collaboration/openapi/hub.yaml');
    }

    /** A structural contract report, not a compatibility classifier. */
    public static function report(OpenApiContract $contract): string
    {
        return hash('sha256', json_encode($contract->document, JSON_THROW_ON_ERROR));
    }

    /** Fail on missing/extra endpoints, dangling refs, missing problem metadata or unowned responses. */
    public static function assertContract(OpenApiContract $contract, array $routes): void
    {
        $operations = $contract->operations();
        $ids = array_keys($operations);
        sort($ids);
        if ($ids !== array_keys($routes)) {
            throw new LogicException('Endpoint ownership mismatch: '.json_encode([array_keys($routes), $ids]));
        }
        foreach ($contract->references() as [$location, $target]) {
            $contract->at($contract->pointerOf($target));
        }
        $problem = $contract->document->components->schemas->Problem;
        if (!isset($problem->{'x-vaulter-problems'}) || get_object_vars($problem->{'x-vaulter-problems'}) === []) {
            throw new LogicException('Missing x-vaulter-problems');
        }
        $codes = array_keys(get_object_vars($problem->{'x-vaulter-problems'}));
        sort($codes);
        if ($codes !== $problem->properties->code->examples) {
            throw new LogicException('Problem examples differ from the problem table');
        }
        foreach ($routes as $id => $route) {
            $entry = $operations[$id];
            $methods = array_values(array_diff($route->methods(), ['HEAD'])) ?: ['HEAD'];
            if ($methods !== [$entry['method']] || '/'.ltrim($route->uri(), '/') !== '/'.app(RoutesOptions::class)->versionedPrefix().$entry['path']) {
                throw new LogicException('Method/path mismatch: '.$id);
            }
            $operation = $entry['operation'];
            if (empty($operation->tags) || empty($operation->summary) || !isset($operation->responses->{'500'})) {
                throw new LogicException('Incomplete operation: '.$id);
            }
            $success = false;
            foreach (get_object_vars($operation->responses) as $status => $response) {
                [$response] = $contract->resolve($response, '');
                $success = $success || str_starts_with((string) $status, '2') || (string) $status === '302';
                foreach (['X-Vaulter-Api-Version', 'X-Request-Id', 'Cache-Control'] as $header) {
                    if (!isset($response->headers->{$header})) {
                        throw new LogicException('Missing response header '.$id.': '.$header);
                    }
                }
                if ((int) $status >= 400 && !isset($response->content->{'application/problem+json'})) {
                    throw new LogicException('Missing problem response: '.$id);
                }
            }
            if (!$success) {
                throw new LogicException('No success response: '.$id);
            }
        }
    }

    public static function assertSharedPrimitives(OpenApiContract $public, OpenApiContract $private): void
    {
        foreach (get_object_vars($private->document->components) as $category => $entries) {
            foreach (get_object_vars($entries) as $name => $definition) {
                if (!isset($public->document->components->{$category}->{$name})) {
                    continue;
                }
                $definition = json_decode(json_encode($definition, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                if ($category === 'schemas' && $name === 'Problem') {
                    $definition['x-vaulter-problems'] = array_filter($definition['x-vaulter-problems'], static fn (array $row): bool => self::moduleChannel($row['module']) === 'public');
                    $definition['properties']['code']['examples'] = array_keys($definition['x-vaulter-problems']);
                    sort($definition['properties']['code']['examples']);
                }
                $source = json_decode(json_encode($public->document->components->{$category}->{$name}, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
                if ($source !== $definition) {
                    throw new LogicException('Shared primitive diverged: '.$category.'/'.$name);
                }
            }
        }
    }
}
