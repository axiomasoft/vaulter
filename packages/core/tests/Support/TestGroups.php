<?php

declare(strict_types=1);

namespace Vaulter\Tests\Support;

use Closure;
use InvalidArgumentException;

/**
 * Binds the Pest groups of one suite from its `groups.php` (PLAN3 P1.5). Every suite keeps ONE declarative file next to its
 * tests: `return ['database' => [paths], 'serial' => [paths], 'extended' => [...], 'stand' => [...], 'engine:server' => [...]]`,
 * paths relative to that tests directory (a test file or a directory). This class turns each declared group into the Pest groups
 * the runner and the CI lanes select by:
 *
 *   database       → database, database:<zone>      the result depends on the engine (DDL, locks, raw SQL, binary/JSON columns, races)
 *   serial         → serial, nofast                 does not survive parallel workers (fork races, shared temp/global state)
 *   extended       → extended, nofast               scale / the whole seed range; every member names its cheap PR equivalent
 *   stand          → stand, nofast, nofull          needs a real external service or is a functional load test; run by `scripts/t stand`
 *   engine:<name>  → engine:<name>                  skipped BEFORE the schema is built on any other engine (see SuiteDatabaseConnection)
 *
 * `nofast` is what the fast lane excludes, `nofull` what the full run excludes; they are derived here, never written by hand.
 */
final class TestGroups
{
    /** @var array<string, list<string>> declared group → every Pest group a member gets */
    private const array COMPOSITE = [
        'serial' => ['serial', 'nofast'],
        'extended' => ['extended', 'nofast'],
        'stand' => ['stand', 'nofast', 'nofull'],
    ];

    /** @return array<string, list<string>> declared group → paths relative to $testsDir */
    public static function declared(string $testsDir): array
    {
        /** @var array<string, list<string>> $groups */
        $groups = require $testsDir.'/groups.php';

        foreach ($groups as $name => $paths) {
            if (!in_array($name, ['database', 'serial', 'extended', 'stand'], true) && !str_starts_with($name, 'engine:')) {
                throw new InvalidArgumentException("Unknown test group [{$name}] in {$testsDir}/groups.php");
            }
        }

        return $groups;
    }

    /** @return list<string> the Pest groups a member of the declared group carries (the zone is appended for `database`) */
    public static function expand(string $declared, ?string $zone = null): array
    {
        if ($declared === 'database') {
            return $zone === null ? ['database'] : ['database', 'database:'.$zone];
        }

        return self::COMPOSITE[$declared] ?? [$declared];
    }

    /**
     * @param string|Closure(string): string $zone a zone name for the whole suite, or a resolver absolute path → zone name
     */
    public static function bind(string $testsDir, string|Closure $zone): void
    {
        foreach (self::declared($testsDir) as $declared => $relative) {
            if ($relative === []) {
                continue;
            }
            $paths = array_map(static fn (string $path): string => rtrim($testsDir.'/'.$path, '/'), $relative);

            if ($declared !== 'database') {
                pest()->group(...self::expand($declared))->in(...$paths);

                continue;
            }

            $byZone = [];
            foreach ($paths as $path) {
                $byZone[is_string($zone) ? $zone : $zone($path)][] = $path;
            }
            foreach ($byZone as $name => $zonePaths) {
                pest()->group(...self::expand('database', (string) $name))->in(...$zonePaths);
            }
        }
    }
}
