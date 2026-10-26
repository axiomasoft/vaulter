<?php

declare(strict_types=1);

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Symfony\Component\Finder\Finder;

/*
 * Service-locator ratchet (audit F07). Services take their collaborators through the constructor;
 * `app(...)` calls hide dependencies and doubled during P3. The baseline pins today's count per file:
 * a file may never grow (and a new file starts at zero), and every conversion must lower the
 * baseline, so the number only goes down. Providers and facades are exempt by nature.
 *
 * Lower the baseline after converting calls with:
 *   UPDATE_SNAPSHOT=1 scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch/ServiceLocatorRatchetTest.php
 */

/** @return array<string, int> relative path (from packages/) => number of app() calls */
function serviceLocatorCounts(string $packages): array
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $counts = [];
    $directories = array_filter(glob($packages.'/*/src', GLOB_ONLYDIR) ?: []);
    if ($directories === []) {
        return [];
    }
    foreach ((new Finder)->files()->in($directories)->name('*.php') as $file) {
        $path = substr($file->getPathname(), strlen($packages) + 1);
        if (str_contains($path, 'Provider') || str_contains($path, '/Facades/') || str_contains($path, '/src/Testing/')) {
            continue;
        }
        $calls = (new NodeFinder)->find($parser->parse($file->getContents()) ?? [], static fn ($node): bool => $node instanceof FuncCall
            && $node->name instanceof Name && $node->name->toString() === 'app');
        if ($calls !== []) {
            $counts[$path] = count($calls);
        }
    }
    ksort($counts);

    return $counts;
}

it('never lets the number of app() calls per file grow and forces the baseline down on conversions', function (): void {
    $packages = __DIR__.'/../../../packages';
    $golden = __DIR__.'/__snapshots__/service-locator.json';
    $current = serviceLocatorCounts($packages);

    if (getenv('UPDATE_SNAPSHOT') === '1') {
        file_put_contents($golden, json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @var array<string, int> $baseline */
    $baseline = json_decode((string) file_get_contents($golden), true, flags: JSON_THROW_ON_ERROR);
    $grown = [];
    foreach ($current as $path => $count) {
        if ($count > ($baseline[$path] ?? 0)) {
            $grown[] = $path.': '.($baseline[$path] ?? 0).' -> '.$count;
        }
    }
    $stale = [];
    foreach ($baseline as $path => $count) {
        // A split checkout may not contain every package; only compare files that exist here.
        if (is_file($packages.'/'.$path) && ($current[$path] ?? 0) < $count) {
            $stale[] = $path.': '.$count.' -> '.($current[$path] ?? 0);
        }
    }

    expect($grown)->toBe([], 'Inject the dependency through the constructor instead of calling app().')
        ->and($stale)->toBe([], 'Fewer app() calls: lower the baseline with UPDATE_SNAPSHOT=1.');
});
