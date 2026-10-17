<?php

declare(strict_types=1);

use Vaulter\Inventory\Allowlist;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\ScanGenerator;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\Surface;

/*
 * I13 (D27, report mode until P7): a type without a surface attribute is unclassified, and a supported (Api or Spi) signature
 * must not name a type marked Internal or an Eloquent model of the domain. Today's findings are listed in
 * tests/Repo/Arch/Fixtures/allowlists/i13.json and must match exactly; the `rules:` tests run the scan on synthetic sources.
 * Seed or refresh: scripts/php84 php scripts/inventory/inventory.php scan-i13 --report --proposals=<path>
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Surface.php';

const SR_PSR4 = ['Vaulter\\' => 'core'];

/**
 * @param  array<string, string>      $sources
 * @return list<array<string, mixed>>
 */
function sr_found(array $sources): array
{
    return Surface::violations(Surface::fromSources($sources), SourceIndex::fromSources($sources, SR_PSR4, []));
}

/** @return array<string, string> a supported contract whose signatures name an Internal type three ways, plus clean neighbours */
function sr_sources(): array
{
    return [
        'packages/core/src/Row.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] final class Row {}',
        'packages/core/src/Model.php' => '<?php namespace Vaulter; use Illuminate\Database\Eloquent\Model as Base; use Vaulter\Attributes\Api; #[Api] class Note extends Base {}',
        'packages/core/src/Port.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] final class Port {'
            .' public Row $row; public function take(Row $row, ?Row $again, int $n): void {} public function give(): Row|null { return null; } public function note(Note $note): void {}'
            .' #[Internal] public function hook(Row $row): Row { return $row; } public function clean(string $a): self { return $this; } private function secret(Row $row): void {} }',
        'packages/core/src/Quiet.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Internal; #[Internal] final class Quiet { public function take(Row $row): Row { return $row; } }',
    ];
}

it('rules: a supported signature that names an Internal type or an Eloquent model is a violation, at the member that names it', function (): void {
    $found = sr_found(sr_sources());
    $keys = array_map(static fn (array $v): string => $v['symbol'].' '.$v['kind'].' '.$v['target'].' x'.$v['count'].' '.$v['category'], $found);

    expect($keys)->toBe([
        'Vaulter\Port::$row property Vaulter\Row x1 internal-type',
        'Vaulter\Port::give() return Vaulter\Row x1 internal-type',
        'Vaulter\Port::note() param Vaulter\Note x1 domain-model',
        'Vaulter\Port::take() param Vaulter\Row x2 internal-type',
    ]);
});

it('rules: an Internal member, a private member and an Internal type are not checked', function (): void {
    $found = sr_found(sr_sources());

    expect(array_column($found, 'symbol'))->not->toContain('Vaulter\Port::hook()')
        ->and(array_column($found, 'symbol'))->not->toContain('Vaulter\Port::secret()')
        ->and(array_column($found, 'file'))->not->toContain('packages/core/src/Quiet.php');
});

it('rules: a type without a surface attribute is unclassified, and so is a type with two', function (): void {
    $found = sr_found([
        'packages/core/src/Bare.php' => '<?php namespace Vaulter; final class Bare {}',
        'packages/core/src/Both.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; use Vaulter\Attributes\Internal; #[Api] #[Internal] final class Both {}',
        'packages/core/src/Fine.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Fine {}',
    ]);

    expect(array_column($found, 'symbol'))->toBe(['Vaulter\Bare', 'Vaulter\Both'])
        ->and(array_unique(array_column($found, 'view')))->toBe(['unclassified']);
});

it('rules: an exact allowlist is green, an extra finding is new and a gone one is stale', function (): void {
    $found = sr_found(sr_sources());
    $entries = array_map(static fn (array $v): array => ['view' => $v['view'], 'file' => $v['file'], 'symbol' => $v['symbol'], 'kind' => $v['kind'], 'target' => $v['target'], 'count' => $v['count'], 'category' => $v['category'], 'reason' => 'synthetic', 'removed_in' => 'P7'], $found);
    $allowlist = Allowlist::fromArray(['invariant' => 'I13', 'mode' => 'report', 'entries' => $entries]);

    expect(ScanGenerator::evaluate('I13', $found, $allowlist))->toBe(['code' => 0, 'lines' => []]);

    $grown = sr_found([...sr_sources(), 'packages/core/src/Extra.php' => '<?php namespace Vaulter; use Vaulter\Attributes\Api; #[Api] final class Extra { public function row(): Row { return new Row; } }']);
    $new = ScanGenerator::evaluate('I13', $grown, $allowlist);
    expect($new['code'])->toBe(1)->and($new['lines'][0])->toStartWith('new   signature|packages/core/src/Extra.php');

    $shrunk = ScanGenerator::evaluate('I13', array_slice($found, 1), $allowlist);
    expect($shrunk['code'])->toBe(1)->and($shrunk['lines'][0])->toStartWith('stale ');
});

it('rules: I13 entries may be removed in P7, and only a host input stays for ever', function (): void {
    $entry = ['view' => 'signature', 'file' => 'packages/core/src/Port.php', 'symbol' => 'Vaulter\Port::take()', 'kind' => 'param', 'target' => 'Vaulter\Row', 'count' => 1, 'reason' => 'D27', 'removed_in' => 'P7'];
    $load = static fn (string $invariant, array $change): Allowlist => Allowlist::fromArray(['invariant' => $invariant, 'mode' => 'report', 'entries' => [[...$entry, ...$change]]]);

    expect($load('I13', [])->entries())->toHaveCount(1)
        ->and($load('I13', ['removed_in' => 'never', 'category' => 'host-input'])->entries())->toHaveCount(1)
        ->and(fn () => $load('I13', ['removed_in' => 'never', 'category' => 'internal-type']))->toThrow(InventoryError::class, '"never" is only for')
        ->and(fn () => $load('I13', ['removed_in' => 'never']))->toThrow(InventoryError::class, '"never" is only for')
        ->and(fn () => $load('I1', ['category' => 'domain-in-core']))->toThrow(InventoryError::class, 'later than I1 blocks');
});

it('matches the committed I13 allowlist to the current tree, exactly', function (): void {
    $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/scan-i13.php';
    $outcome = $generator->check(Repo::discover());

    expect($outcome['lines'])->toBe([])->and($outcome['code'])->toBe(0);
});

it('keeps every committed I13 entry reasoned, in report mode and never a type without an attribute', function (): void {
    $allowlist = Allowlist::load(Repo::discover(), ScanGenerator::allowlistPath('I13'));
    ScanGenerator::assertReasons($allowlist);

    expect($allowlist->mode)->toBe('report')
        ->and(array_unique(array_column($allowlist->entries(), 'view')))->toBe(['signature'])
        ->and(array_unique(array_column($allowlist->entries(), 'removed_in')))->toBe(['P7']);
});
