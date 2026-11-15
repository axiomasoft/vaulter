<?php

declare(strict_types=1);

use Vaulter\Inventory\Diff;
use Vaulter\Inventory\Generator;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;

/**
 * tests/Repo/Arch/Fixtures/packages.json: the fixture is hand-written for `target_require` and `dir`/`exists`;
 * `composer`, `psr4` and `current_require` are verified against packages/<p>/composer.json, and --write rewrites only those.
 *
 * @phpstan-import-type Outcome from Generator
 */
return new class implements Generator
{
    private const VENDOR_PREFIX = 'axioma-studio/vaulter-';

    public function name(): string
    {
        return 'packages';
    }

    public function write(Repo $repo, array $options = []): array
    {
        $synced = $this->synced($repo);
        $repo->write(Repo::PACKAGES_FIXTURE, Json::encode($synced));

        return [Repo::PACKAGES_FIXTURE];
    }

    public function check(Repo $repo, array $options = []): array
    {
        try {
            $diff = $this->diff($repo);
        } catch (InventoryError $e) {
            return ['code' => 2, 'lines' => [$e->getMessage()]];
        }

        return $diff === []
            ? ['code' => 0, 'lines' => []]
            : ['code' => 1, 'lines' => [...$diff, 'fix: scripts/php84 php scripts/inventory/inventory.php packages --write']];
    }

    public function report(Repo $repo, array $options = []): array
    {
        $outcome = $this->check($repo, $options);
        if ($outcome['code'] === 2) {
            return $outcome;
        }

        $lines = [];
        foreach ($this->synced($repo)['packages'] as $name => $package) {
            $current = $package['current_require'] ?? [];
            $target = $package['target_require'] ?? [];
            $lines[] = sprintf(
                '%-14s %s current=[%s] target=[%s] add=[%s] drop=[%s]',
                $name,
                ($package['exists'] ?? false) ? 'exists' : 'planned',
                implode(',', $current),
                implode(',', $target),
                implode(',', array_diff($target, $current)),
                implode(',', array_diff($current, $target)),
            );
        }

        return ['code' => $outcome['code'], 'lines' => [...$lines, ...$outcome['lines']]];
    }

    /** @return list<string> unified diff of the committed fixture against the synced one */
    private function diff(Repo $repo): array
    {
        return Diff::unified($repo->read(Repo::PACKAGES_FIXTURE), Json::encode($this->synced($repo)), Repo::PACKAGES_FIXTURE);
    }

    /**
     * The fixture with the verified fields taken from composer.json; throws on a graph or fixture error.
     *
     * @return array{packages: array<string, array<string, mixed>>}
     */
    private function synced(Repo $repo): array
    {
        $packages = $repo->packages();
        ksort($packages, SORT_STRING);

        $prefixes = [];
        foreach ($packages as $name => &$package) {
            if (($package['exists'] ?? null) === true) {
                $package = $this->fromComposer($repo, $name, $package, array_keys($packages));
            }
            $target = $package['target_require'] ?? null;
            if (!is_array($target) || !array_is_list($target)) {
                throw new InventoryError("package {$name}: \"target_require\" must be a list");
            }
            sort($target, SORT_STRING);
            $package['target_require'] = $target;

            foreach (array_keys((array) ($package['psr4'] ?? [])) as $prefix) {
                if (isset($prefixes[$prefix])) {
                    throw new InventoryError("PSR-4 prefix {$prefix} is claimed by {$prefixes[$prefix]} and {$name}");
                }
                $prefixes[$prefix] = $name;
            }
        }
        unset($package);

        $this->assertGraph($packages);

        return ['packages' => $packages];
    }

    /**
     * @param  array<string, mixed> $package
     * @param  list<string>         $known
     * @return array<string, mixed>
     */
    private function fromComposer(Repo $repo, string $name, array $package, array $known): array
    {
        $dir = $package['dir'] ?? null;
        if (!is_string($dir) || $dir === '') {
            throw new InventoryError("package {$name}: \"dir\" is required when exists is true");
        }
        $file = $dir.'/composer.json';
        if (!$repo->exists($file)) {
            throw new InventoryError("package {$name}: {$file} not found");
        }
        $composer = $repo->readJson($file);

        $psr4 = $composer['autoload']['psr-4'] ?? [];
        foreach ((array) $psr4 as $prefix => $path) {
            if (!is_string($path)) {
                throw new InventoryError("package {$name}: psr-4 {$prefix} must map to one directory");
            }
        }

        $current = [];
        foreach (array_keys((array) ($composer['require'] ?? [])) as $require) {
            if (is_string($require) && str_starts_with($require, self::VENDOR_PREFIX)) {
                $short = substr($require, strlen(self::VENDOR_PREFIX));
                if (!in_array($short, $known, true)) {
                    throw new InventoryError("package {$name}: requires {$require}, which is not in the packages fixture");
                }
                $current[] = $short;
            }
        }
        sort($current, SORT_STRING);

        $package['composer'] = $composer['name'] ?? null;
        $package['psr4'] = $psr4;
        $package['current_require'] = $current;

        return $package;
    }

    /** @param  array<string, array<string, mixed>>  $packages */
    private function assertGraph(array $packages): void
    {
        $edges = [];
        foreach ($packages as $name => $package) {
            foreach ((array) $package['target_require'] as $dependency) {
                if (!isset($packages[$dependency])) {
                    throw new InventoryError("package {$name}: target_require names unknown package {$dependency}");
                }
                if ($dependency === $name) {
                    throw new InventoryError("package {$name}: target_require names itself");
                }
                $edges[$name][] = $dependency;
            }
        }

        $state = [];
        $visit = function (string $node, array $path) use (&$visit, &$state, $edges): void {
            if (($state[$node] ?? 0) === 2) {
                return;
            }
            if (($state[$node] ?? 0) === 1) {
                throw new InventoryError('target_require cycle: '.implode(' -> ', [...$path, $node]));
            }
            $state[$node] = 1;
            foreach ($edges[$node] ?? [] as $next) {
                $visit($next, [...$path, $node]);
            }
            $state[$node] = 2;
        };
        foreach (array_keys($packages) as $name) {
            $visit($name, []);
        }
    }
};
