<?php

declare(strict_types=1);

namespace Vaulter\Inventory\Scans;

use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\SourceIndex;

/**
 * Everything the boundary scans (I1–I4) read, in one value: the source index, the packages fixture, the owners of the
 * type-map, the owners of tables/config keys/route names from the identities. The scans are pure functions of it, so a
 * test builds a context from synthetic parts and the CLI builds one from the committed fixtures.
 *
 * @phpstan-type Packages array<string, array<string, mixed>>
 * @phpstan-type Identity array{owner: string, package: ?string}
 */
final class ScanContext
{
    /** @var array<string, list<string>> package => transitive target_require, computed once */
    private array $closure = [];

    /** @var list<array{key: string, owner: string, package: ?string}> */
    private array $configKeys;

    /**
     * @param Packages                $packages     the packages fixture
     * @param array<string, string>   $typeOwners   current fqcn => target owner
     * @param array<string, string>   $fileOwners   current file => target owner (types, migrations, config, routes)
     * @param array<string, string>   $memberOwners `Fqcn::member` => target owner (SchemaBuilder members)
     * @param array<string, string>   $tableOwners  `v_x` => owner
     * @param array<string, Identity> $configs      dotted config key => owner and the package of the defining file
     * @param array<string, Identity> $routes       route name => owner and the package of the defining file
     */
    public function __construct(
        public readonly SourceIndex $index,
        public readonly array $packages,
        public readonly array $typeOwners,
        public readonly array $fileOwners,
        public readonly array $memberOwners,
        public readonly array $tableOwners,
        public readonly array $configs,
        public readonly array $routes,
    ) {
        $keys = [];
        foreach ($configs as $key => $identity) {
            $keys[] = ['key' => $key, 'owner' => $identity['owner'], 'package' => $identity['package']];
        }
        $this->configKeys = $keys;
    }

    public static function forRepo(Repo $repo): self
    {
        $map = $repo->readJson('tests/Repo/Arch/Fixtures/target-map.json');
        $typeOwners = [];
        $fileOwners = [];
        $memberOwners = [];
        foreach (self::list($map, 'types') as $type) {
            $typeOwners[(string) $type['fqcn']] = (string) $type['owner'];
            $fileOwners[(string) $type['file']] ??= (string) $type['owner'];
        }
        foreach (self::list($map, 'files') as $file) {
            $fileOwners[(string) $file['file']] ??= (string) $file['owner'];
        }
        foreach (self::list($map, 'members') as $member) {
            $memberOwners[$member['fqcn'].'::'.$member['member']] = (string) $member['owner'];
        }

        $tableOwners = [];
        foreach (self::list($repo->readJson('tests/Repo/Arch/Fixtures/identities/schema.json'), 'items') as $item) {
            if (str_starts_with((string) $item['id'], 'table:')) {
                $tableOwners[substr((string) $item['id'], 6)] = (string) $item['owner'];
            }
        }

        return new self(
            SourceIndex::forRepo($repo),
            $repo->packages(),
            $typeOwners,
            $fileOwners,
            $memberOwners,
            $tableOwners,
            self::identities($repo, 'config', 'config:'),
            self::identities($repo, 'http', 'route:'),
        );
    }

    /** The package directory a file lives in today. */
    public function currentPackage(string $file): ?string
    {
        return SourceIndex::packageOfFile($file);
    }

    /**
     * The package a type lives in today: where it is declared (the 0.x core still holds the namespaces of packages that
     * do not exist yet, e.g. `Vaulter\Webhooks`), else the PSR-4 package of its name.
     */
    public function typePackage(string $fqcn): ?string
    {
        return $this->index->declaration($fqcn)['package'] ?? $this->index->packageOf($fqcn);
    }

    /** The target owner of the code at a symbol: its member, its class, else the file. */
    public function ownerAt(string $file, string $symbol): ?string
    {
        if (isset($this->memberOwners[$symbol])) {
            return $this->memberOwners[$symbol];
        }
        $class = preg_replace('/@anonymous.*$/', '', explode('::', $symbol)[0]) ?? '';

        return $this->typeOwners[$class] ?? $this->fileOwners[$file] ?? null;
    }

    /** The owner of a table by its logical (`nodes`) or physical (`v_nodes`) name. */
    public function tableOwner(string $table): ?string
    {
        return $this->tableOwners[str_starts_with($table, 'v_') ? $table : 'v_'.$table] ?? null;
    }

    /**
     * The owners (target) or defining packages (current) of the config keys a literal names: the key itself, or the
     * keys below it when it names a branch (`vaulter.drive` reads every key under it).
     *
     * @return list<string>
     */
    public function configOwners(string $key, string $view): array
    {
        $owners = [];
        foreach ($this->configKeys as $identity) {
            if ($identity['key'] === $key || str_starts_with($identity['key'], $key.'.') || str_starts_with($key, $identity['key'].'.')) {
                $owners[] = $view === 'current' ? ($identity['package'] ?? $identity['owner']) : $identity['owner'];
            }
        }
        if ($view === 'current') {
            // a vaulter-<package>.* root belongs to that package by name, even without an identity
            if (preg_match('/^vaulter-([a-z]+)\./', $key, $m) === 1 && isset($this->packages[$m[1]])) {
                $owners[] = $m[1];
            }
        }
        $owners = array_values(array_unique($owners));
        sort($owners, SORT_STRING);

        return $owners;
    }

    public function routeOwner(string $name, string $view): ?string
    {
        $identity = $this->routes[$name] ?? null;

        return $identity === null ? null : ($view === 'current' ? ($identity['package'] ?? $identity['owner']) : $identity['owner']);
    }

    /** @return list<string> every package an owner needs, transitively, per the target graph (architecture#packages) */
    public function targetClosure(string $owner): array
    {
        return $this->closure[$owner] ??= self::closureOf($this->packages, $owner);
    }

    /**
     * @param  Packages     $packages
     * @return list<string>
     */
    public static function closureOf(array $packages, string $owner): array
    {
        $require = static fn (string $name): array => is_array($packages[$name]['target_require'] ?? null) ? array_values(array_map('strval', $packages[$name]['target_require'])) : [];
        $seen = [];
        $queue = $require($owner);
        while ($queue !== []) {
            $next = array_shift($queue);
            if (isset($seen[$next])) {
                continue;
            }
            $seen[$next] = true;
            array_push($queue, ...$require($next));
        }
        $closure = array_keys($seen);
        sort($closure, SORT_STRING);

        return $closure;
    }

    /** @return list<string> the direct `target_require` of an owner */
    public function targetRequire(string $owner): array
    {
        return $this->require($owner, 'target_require');
    }

    /** @return list<string> the direct `current_require` of an existing package */
    public function currentRequire(string $package): array
    {
        return $this->require($package, 'current_require');
    }

    /** @return list<string> */
    private function require(string $package, string $field): array
    {
        $declared = $this->packages[$package][$field] ?? [];

        return is_array($declared) ? array_values(array_map('strval', $declared)) : [];
    }

    /**
     * @param  array<string, mixed>       $data
     * @return list<array<string, mixed>>
     */
    private static function list(array $data, string $key): array
    {
        $list = $data[$key] ?? null;
        if (!is_array($list)) {
            throw new InventoryError("fixture has no \"{$key}\" list");
        }

        /** @var list<array<string, mixed>> $list */
        return $list;
    }

    /** @return array<string, Identity> */
    private static function identities(Repo $repo, string $kind, string $prefix): array
    {
        $found = [];
        foreach (self::list($repo->readJson("tests/Repo/Arch/Fixtures/identities/{$kind}.json"), 'items') as $item) {
            $id = (string) $item['id'];
            if (!str_starts_with($id, $prefix)) {
                continue;
            }
            $source = is_array($item['source'] ?? null) ? $item['source'] : [];
            $found[substr($id, strlen($prefix))] = [
                'owner' => (string) $item['owner'],
                'package' => SourceIndex::packageOfFile((string) ($source['file'] ?? '')),
            ];
        }

        return $found;
    }
}
