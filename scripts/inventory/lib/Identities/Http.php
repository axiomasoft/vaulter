<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Identities;

use Symfony\Component\Yaml\Yaml;
use Throwable;
use Vaulter\Inventory\Identities;
use Vaulter\Inventory\IdentityOwners;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;

require_once dirname(__DIR__).'/Identities.php';
require_once __DIR__.'/Config.php';

/**
 * identities/http.json: the routes a booted host registers (name, methods, uri, middleware, action), and their
 * match with the OpenAPI contract. The routes come from `scripts/inventory/boot/routes.php`, one separate PHP process
 * per run: the defaults, then once per feature flag (`vaulter-drive.features.*` and the opt-in module switches) with the flag
 * flipped. A route that appears or disappears with a flag gets `requires: [flag]`, which is finer than one run with
 * every opt-in flag on. The inventory itself never boots Laravel.
 *
 * @phpstan-import-type Built from Identities
 * @phpstan-import-type Problem from Identities
 */
final class Http
{
    public const BOOT = 'scripts/inventory/boot/routes.php';

    public const OPENAPI = 'openapi/vaulter-v1.yaml';

    /** Switches besides `vaulter-drive.features.*` that mount routes; their default is read from the package config. */
    public const EXTRA_FLAGS = ['vaulter.modules.webhooks' => false, 'vaulter.modules.collaboration' => false];

    private const OPENAPI_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /** @return Built */
    public static function build(Repo $repo): array
    {
        $problems = [];
        foreach (['azguard', 'filament'] as $package) {
            if (is_dir($repo->path("packages/{$package}/routes"))) {
                $problems[] = ['code' => 'unscanned-routes', 'message' => "http: packages/{$package}/routes exists — the boot lists only core, media, drive, documents, collaboration and webhooks; add the package to boot/routes.php", 'exit' => 1];
            }
        }

        $flags = [...Config::featureFlags($repo), ...self::EXTRA_FLAGS];
        $runs = ['' => []];
        foreach ($flags as $flag => $default) {
            $runs[$flag] = [$flag => !$default];
        }
        $booted = self::boot($repo, $runs);
        $merged = self::merge($booted, $flags);
        $routes = $merged['routes'];
        $requires = $merged['requires'];
        $problems = [...$problems, ...$merged['problems']];
        $inDefaults = self::index($booted['']);

        [$operations, $prefix] = self::openapi($repo);
        $matched = [];
        $items = [];
        foreach ($routes as $id => $route) {
            $path = self::relative($route['uri'], $prefix);
            $operation = null;
            foreach ($route['methods'] as $method) {
                $key = strtoupper($method).' '.$path;
                if ($path !== null && isset($operations[$key])) {
                    $operation = $operations[$key];
                    $matched[$key] = true;
                }
            }
            $action = (string) $route['action'];
            $flagsOfRoute = array_values(array_unique($requires[$id] ?? []));
            sort($flagsOfRoute, SORT_STRING);
            $items[] = array_filter([
                'id' => 'route:'.$id,
                'source' => ['file' => self::controllerFile($repo, $action), 'symbol' => $action],
                'name' => $route['name'],
                'methods' => $route['methods'],
                'uri' => $route['uri'],
                'middleware' => $route['middleware'],
                'controller' => str_contains($action, '\\') ? explode('@', $action)[0] : null,
                'action' => $action,
                'default_registered' => isset($inDefaults[$id]),
                'requires' => $flagsOfRoute,
                'openapi' => $operation === null ? null : ['path' => $path, 'operation_id' => $operation['operation_id']],
                'route_only' => $operation === null,
            ], static fn (mixed $v, string $k): bool => $v !== null && !($k === 'route_only' && $v === false), ARRAY_FILTER_USE_BOTH);
        }
        foreach ($operations as $key => $operation) {
            if (!isset($matched[$key])) {
                $items[] = [
                    'id' => 'openapi:'.$key,
                    'source' => ['file' => self::OPENAPI, 'symbol' => $operation['path']],
                    'operation_id' => $operation['operation_id'],
                    'openapi_only' => true,
                ];
            }
        }

        $built = Identities::assemble('http', $items, IdentityOwners::load($repo, 'http'), ['flags' => $flags, 'openapi_prefix' => $prefix], $problems);

        return Identities::withClassOwners($built, $repo, 'controller');
    }

    /**
     * Folds the runs into one route set: which flag mounts (or unmounts) which route. A run that changes anything but
     * the presence of a route (uri, methods, middleware, action) is drift: the fixture keeps one record per route.
     *
     * @param  array<string, list<array<string, mixed>>>                                                                          $booted run name ('' = defaults, else the flag) => routes
     * @param  array<string, bool>                                                                                                $flags  flag => default
     * @return array{routes: array<string, array<string, mixed>>, requires: array<string, list<string>>, problems: list<Problem>}
     */
    public static function merge(array $booted, array $flags): array
    {
        $defaults = $booted[''] ?? [];
        $inDefaults = self::index($defaults);
        $routes = [];
        $requires = [];
        $problems = [];
        foreach ($booted as $flag => $list) {
            foreach ($list as $route) {
                $id = self::id($route);
                if (!isset($routes[$id])) {
                    $routes[$id] = $route;
                } elseif (self::shape($routes[$id]) !== self::shape($route)) {
                    $problems[] = ['code' => 'flag-changes-route', 'message' => sprintf('http: route %s differs between the default boot and the run of %s (uri, methods, middleware or action)', $id, $flag === '' ? 'the defaults' : $flag), 'exit' => 1];
                }
                if ($flag !== '' && !isset($inDefaults[$id])) {
                    $requires[$id][] = $flag;
                }
            }
        }
        foreach ($flags as $flag => $default) {
            // default-on flags: the routes that vanish when it is off
            if ($default) {
                $off = array_flip(array_map(self::id(...), $booted[$flag] ?? []));
                foreach ($defaults as $route) {
                    if (!isset($off[self::id($route)])) {
                        $requires[self::id($route)][] = $flag;
                    }
                }
            }
        }

        return ['routes' => $routes, 'requires' => $requires, 'problems' => $problems];
    }

    /**
     * @param  array<string, mixed> $route
     * @return array<string, mixed>
     */
    private static function shape(array $route): array
    {
        return array_intersect_key($route, array_flip(['uri', 'methods', 'middleware', 'action']));
    }

    /** @param array<string, mixed> $route */
    private static function id(array $route): string
    {
        return is_string($route['name']) && $route['name'] !== '' ? $route['name'] : implode('|', (array) $route['methods']).' '.$route['uri'];
    }

    /**
     * @param  list<array<string, mixed>> $routes
     * @return array<string, true>
     */
    private static function index(array $routes): array
    {
        $index = [];
        foreach ($routes as $route) {
            $index[self::id($route)] = true;
        }

        return $index;
    }

    private static function relative(string $uri, string $prefix): ?string
    {
        $prefix = trim($prefix, '/');
        if (!str_starts_with($uri, $prefix.'/')) {
            return null;
        }

        return '/'.preg_replace('/\{(\w+)\?\}/', '{$1}', substr($uri, strlen($prefix) + 1));
    }

    /** The controller file of a `Class` / `Class@method` action, or the route files when it is not a class. */
    private static function controllerFile(Repo $repo, string $action): string
    {
        $class = explode('@', $action)[0];
        if (!str_contains($class, '\\')) {
            return 'packages/core/src/VaulterServiceProvider.php';
        }
        foreach (SourceIndex::forRepo($repo)->declarations() as $declaration) {
            if ($declaration['fqcn'] === $class) {
                return $declaration['file'];
            }
        }

        return 'packages/core/src/VaulterServiceProvider.php';
    }

    /**
     * Operations of the OpenAPI document keyed `METHOD /path`.
     *
     * @return array{0: array<string, array{path: string, operation_id: string}>, 1: string}
     */
    private static function openapi(Repo $repo): array
    {
        try {
            $document = Yaml::parse($repo->read(self::OPENAPI));
        } catch (Throwable $e) {
            throw new InventoryError(self::OPENAPI.': '.$e->getMessage(), 0, $e);
        }
        if (!is_array($document) || !is_array($document['paths'] ?? null)) {
            throw new InventoryError(self::OPENAPI.' has no paths');
        }
        $server = $document['servers'][0]['url'] ?? '';
        $operations = [];
        foreach ($document['paths'] as $path => $item) {
            foreach (self::OPENAPI_METHODS as $method) {
                if (is_array($item) && isset($item[$method]) && is_array($item[$method])) {
                    $operations[strtoupper($method).' '.$path] = ['path' => (string) $path, 'operation_id' => (string) ($item[$method]['operationId'] ?? '')];
                }
            }
        }
        ksort($operations, SORT_STRING);

        return [$operations, is_string($server) ? $server : ''];
    }

    /**
     * Runs the boot script once per run, in parallel; returns the routes of each run, sorted by id.
     *
     * @param  array<string, array<string, bool>>        $runs run name => config values to set
     * @return array<string, list<array<string, mixed>>>
     */
    private static function boot(Repo $repo, array $runs): array
    {
        $processes = [];
        foreach ($runs as $name => $set) {
            $command = [PHP_BINARY, $repo->path(self::BOOT)];
            foreach ($set as $key => $value) {
                $command[] = '--set='.$key.'='.json_encode($value, JSON_THROW_ON_ERROR);
            }
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo->root());
            if (!is_resource($process)) {
                throw new InventoryError('cannot start '.self::BOOT);
            }
            $processes[$name] = ['process' => $process, 'pipes' => $pipes];
        }

        $routes = [];
        foreach ($processes as $name => $run) {
            $out = stream_get_contents($run['pipes'][1]);
            $err = stream_get_contents($run['pipes'][2]);
            fclose($run['pipes'][1]);
            fclose($run['pipes'][2]);
            $code = proc_close($run['process']);
            $lines = array_values(array_filter(explode("\n", (string) $out), static fn (string $l): bool => trim($l) !== ''));
            $last = $lines === [] ? '' : $lines[count($lines) - 1];
            if ($code !== 0 || !str_starts_with($last, '[')) {
                throw new InventoryError(sprintf('%s failed (exit %d)%s: %s', self::BOOT, $code, $name === '' ? '' : " with {$name}", trim((string) $err) !== '' ? trim((string) $err) : trim((string) $out)));
            }
            /** @var list<array<string, mixed>> $list */
            $list = json_decode($last, true, flags: JSON_THROW_ON_ERROR);
            usort($list, static fn (array $a, array $b): int => strcmp(self::id($a), self::id($b)));
            $routes[$name] = $list;
        }

        return $routes;
    }
}
