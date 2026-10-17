<?php

declare(strict_types=1);

use Vaulter\Inventory\Allowlist;
use Vaulter\Inventory\InventoryError;
use Vaulter\Inventory\Json;
use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Scans\CoreIsolation;
use Vaulter\Inventory\Scans\DependencyDirection;
use Vaulter\Inventory\Scans\ForeignKeys;
use Vaulter\Inventory\Scans\ImplicitActivation;
use Vaulter\Inventory\Scans\ScanContext;
use Vaulter\Inventory\Scans\ScanGenerator;
use Vaulter\Inventory\SourceIndex;

/*
 * The boundary scans I1–I4 (I3 and I4 block from P3/P4.5, I1 and I2 from P4.16): today's violations are listed in tests/Repo/Arch/Fixtures/allowlists/iN.json
 * and must match them exactly (key and count), a new or a stale entry is red. The `scan:` tests run the same code on
 * synthetic sources, so they prove the red as well as the green.
 * Seed or refresh an allowlist from: scripts/php84 php scripts/inventory/inventory.php scan-iN --report --proposals=<path>
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/ScanGenerator.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/CoreIsolation.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/DependencyDirection.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/ImplicitActivation.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/Scans/ForeignKeys.php';

const BS_PSR4 = ['Vaulter\\' => 'core', 'Vaulter\\Media\\' => 'media', 'Vaulter\\Drive\\' => 'drive', 'Vaulter\\Documents\\' => 'documents'];

const BS_PACKAGES = [
    'core' => ['current_require' => [], 'target_require' => []],
    'media' => ['current_require' => ['core'], 'target_require' => ['core']],
    'drive' => ['current_require' => ['core', 'media'], 'target_require' => ['core', 'media']],
    'documents' => ['current_require' => ['core', 'drive'], 'target_require' => ['drive']],
];

/** @return array<string, string> a kernel that names a drive type and a drive that names a media one and probes a class */
function bs_sources(): array
{
    return [
        'packages/core/src/Kernel.php' => '<?php namespace Vaulter; use Vaulter\Drive\Node; final class Kernel { public function node(): string { return Node::class; } }',
        'packages/drive/src/Node.php' => '<?php namespace Vaulter\Drive; final class Node { public function blob(): string { return \Vaulter\Media\Blob::class; } public function boot(): bool { return class_exists(\Vendor\Optional::class); } }',
        'packages/media/src/Blob.php' => '<?php namespace Vaulter\Media; final class Blob {}',
    ];
}

/** @param array<string, string> $sources */
function bs_context(array $sources): ScanContext
{
    $owners = ['Vaulter\Kernel' => 'core', 'Vaulter\Drive\Node' => 'drive', 'Vaulter\Media\Blob' => 'media', 'Vaulter\Extra' => 'core', 'Vaulter\Documents\Doc' => 'documents'];
    $files = ['packages/core/src/Kernel.php' => 'core', 'packages/drive/src/Node.php' => 'drive', 'packages/media/src/Blob.php' => 'media', 'packages/core/src/Extra.php' => 'core', 'packages/documents/src/Doc.php' => 'documents'];

    return new ScanContext(SourceIndex::fromSources($sources, BS_PSR4, []), BS_PACKAGES, $owners, $files, [], ['v_nodes' => 'drive', 'v_blobs' => 'media', 'v_outbox' => 'core'], [], []);
}

/** @return list<array<string, mixed>> */
function bs_found(string $invariant, ScanContext $context): array
{
    return match ($invariant) {
        'I1' => CoreIsolation::found($context),
        'I2' => DependencyDirection::found($context),
        'I4' => ImplicitActivation::found($context),
    };
}

/**
 * An allowlist that accepts exactly the given violations.
 *
 * @param list<array<string, mixed>> $found
 * @param array<string, mixed>       $change per-entry override, e.g. ['count' => 2]
 */
function bs_allowlist(string $invariant, array $found, array $change = []): Allowlist
{
    $entries = [];
    foreach ($found as $violation) {
        $entries[] = [
            'view' => $violation['view'], 'file' => $violation['file'], 'symbol' => $violation['symbol'], 'kind' => $violation['kind'], 'target' => $violation['target'],
            'count' => $violation['count'], 'category' => $invariant === 'I4' ? 'activation' : 'domain-in-core', 'removed_in' => 'P3', 'reason' => 'synthetic: the fixture knows this violation',
            ...$change,
        ];
    }

    return Allowlist::fromArray(['invariant' => $invariant, 'mode' => 'report', 'entries' => $entries]);
}

/**
 * @param  list<array<string, mixed>> $found
 * @return list<array<string, mixed>>
 */
function bs_where(array $found, string $view, ?string $file = null): array
{
    return array_values(array_filter($found, static fn (array $v): bool => $v['view'] === $view && ($file === null || $v['file'] === $file)));
}

it('scan: a kernel that names a drive type is a violation of I1 and of I2, a probe one of I4', function (): void {
    $doc = 'packages/documents/src/Doc.php';
    $context = bs_context([...bs_sources(), $doc => '<?php namespace Vaulter\\Documents; use Vaulter\\Kernel; final class Doc { public function k(): string { return Kernel::class; } }']);
    $i1 = bs_where(bs_found('I1', $context), 'target');
    $i2 = bs_where(bs_found('I2', $context), 'target');

    expect(array_column($i1, 'kind'))->toEqualCanonicalizing(['name', 'use'])
        ->and(array_unique(array_column($i1, 'target_owner')))->toBe(['drive'])
        ->and(array_unique(array_column($i1, 'file')))->toBe(['packages/core/src/Kernel.php'])
        ->and(array_column(array_filter($i2, static fn (array $v): bool => $v['file'] === 'packages/core/src/Kernel.php'), 'category'))->toBe(['reverse-edge', 'reverse-edge'])
        ->and(array_column(array_filter($i2, static fn (array $v): bool => $v['file'] === $doc), 'category'))->toBe(['needs-explicit-require', 'needs-explicit-require']);

    $probe = bs_found('I4', $context);
    expect($probe)->toHaveCount(1)
        ->and($probe[0])->toMatchArray(['file' => 'packages/drive/src/Node.php', 'symbol' => 'Vaulter\\Drive\\Node::boot', 'kind' => 'probe', 'target' => '\\Vendor\\Optional::class']);
});

it('scan: the current view counts a type of a package outside the current require, wherever the namespace says it lives', function (): void {
    $leak = 'packages/media/src/Leak.php';
    $sources = [...bs_sources(), $leak => '<?php namespace Vaulter\Media; use Vaulter\Drive\Node; final class Leak { public function n(): string { return Node::class; } }'];
    $current = bs_where(bs_found('I2', bs_context($sources)), 'current', $leak);

    expect(array_column($current, 'kind'))->toEqualCanonicalizing(['name', 'use'])
        ->and(array_unique(array_column($current, 'target_owner')))->toBe(['drive'])
        ->and(bs_where(bs_found('I2', bs_context(bs_sources())), 'current', 'packages/drive/src/Node.php'))->toBe([]);
});

it('scan: an exact allowlist is green', function (string $invariant): void {
    $found = bs_found($invariant, bs_context(bs_sources()));

    expect($found)->not->toBe([])
        ->and(ScanGenerator::evaluate($invariant, $found, bs_allowlist($invariant, $found)))->toBe(['code' => 0, 'lines' => []]);
})->with(['I1', 'I2', 'I4']);

it('scan: an extra reference is new, a removed one is stale, a changed count is stale', function (string $invariant, string $extra): void {
    $found = bs_found($invariant, bs_context(bs_sources()));
    $allowlist = bs_allowlist($invariant, $found);

    $grown = bs_found($invariant, bs_context([...bs_sources(), 'packages/core/src/Extra.php' => $extra]));
    $new = ScanGenerator::evaluate($invariant, $grown, $allowlist);
    expect($new['code'])->toBe(1)->and($new['lines'][0])->toStartWith('new   ')->and(implode("\n", array_slice($new['lines'], 0, -1)))->not->toContain('stale ');

    $shrunk = bs_found($invariant, bs_context(array_diff_key(bs_sources(), ['packages/core/src/Kernel.php' => 1, 'packages/drive/src/Node.php' => 1])));
    $stale = ScanGenerator::evaluate($invariant, $shrunk, $allowlist);
    expect($stale['code'])->toBe(1)->and($stale['lines'][0])->toStartWith('stale ')->and(implode("\n", array_slice($stale['lines'], 0, -1)))->not->toContain('new   ');

    $recounted = ScanGenerator::evaluate($invariant, $found, bs_allowlist($invariant, $found, ['count' => 3]));
    expect($recounted['code'])->toBe(1)->and($recounted['lines'][0])->toContain('allowed x3, found x1');
})->with([
    'I1' => ['I1', '<?php namespace Vaulter; use Vaulter\Media\Blob; final class Extra { public function b(): string { return Blob::class; } }'],
    'I2' => ['I2', '<?php namespace Vaulter; use Vaulter\Media\Blob; final class Extra { public function b(): string { return Blob::class; } }'],
    'I4' => ['I4', '<?php namespace Vaulter; final class Extra { public function ok(): bool { return interface_exists(\Vendor\Other::class); } }'],
]);

it('scan: the same reference counted twice in one place is one entry with count 2', function (): void {
    $sources = ['packages/core/src/Kernel.php' => '<?php namespace Vaulter; final class Kernel { public function a(): array { return [\Vaulter\Drive\Node::class, \Vaulter\Drive\Node::class]; } }'];
    $found = bs_where(CoreIsolation::found(bs_context($sources)), 'target');

    expect($found)->toHaveCount(1)->and($found[0]['count'])->toBe(2);
});

it('scan: an entry without a reason, a placeholder reason or a wildcard-ish removed_in is refused', function (): void {
    $found = bs_found('I1', bs_context(bs_sources()));
    $entry = bs_allowlist('I1', $found)->entries()[0];
    $with = static fn (array $change): array => ['invariant' => 'I1', 'mode' => 'report', 'entries' => [[...$entry, ...$change]]];

    expect(fn () => Allowlist::fromArray($with(['reason' => ''])))->toThrow(InventoryError::class, '"reason" is required')
        ->and(fn () => Allowlist::fromArray($with(['removed_in' => 'later'])))->toThrow(InventoryError::class, '"removed_in" must be one of')
        ->and(fn () => Allowlist::fromArray($with(['removed_in' => 'P5'])))->toThrow(InventoryError::class, 'later than I1 blocks (P4)')
        ->and(fn () => Allowlist::fromArray($with(['removed_in' => 'never'])))->toThrow(InventoryError::class, 'only for I4');

    foreach (['TODO', '?', ' todo '] as $placeholder) {
        expect(fn () => ScanGenerator::evaluate('I1', $found, Allowlist::fromArray($with(['reason' => $placeholder]))))->toThrow(InventoryError::class, 'placeholder reason');
    }
});

it('scan: I4 needs a category and keeps `never` for the permanent ones', function (): void {
    $found = bs_found('I4', bs_context(bs_sources()));
    $entry = bs_allowlist('I4', $found)->entries()[0];
    $with = static fn (array $change): array => ['invariant' => 'I4', 'mode' => 'report', 'entries' => [[...$entry, ...$change]]];

    expect(fn () => Allowlist::fromArray($with(['category' => 'whatever'])))->toThrow(InventoryError::class, 'I4 needs a "category"')
        ->and(fn () => Allowlist::fromArray($with(['removed_in' => 'never'])))->toThrow(InventoryError::class, 'only for I4 categories')
        ->and(Allowlist::fromArray($with(['removed_in' => 'never', 'category' => 'config-validation']))->entries()[0]['removed_in'])->toBe('never');
});

it('scan: a blocking list admits only never and late-category entries', function (): void {
    $found = bs_found('I4', bs_context(bs_sources()));
    $entry = bs_allowlist('I4', $found)->entries()[0];
    $block = static fn (array $change): array => ['invariant' => 'I4', 'mode' => 'block', 'entries' => [[...$entry, ...$change]]];

    expect(fn () => Allowlist::fromArray($block([])))->toThrow(InventoryError::class, 'mode "block" admits only')
        ->and(Allowlist::fromArray($block(['removed_in' => 'never', 'category' => 'config-validation']))->mode)->toBe('block')
        ->and(Allowlist::fromArray($block(['removed_in' => 'P5', 'category' => 'jwt-optional']))->mode)->toBe('block');
});

it('scan: a red scan names the command that reports and seeds it', function (): void {
    $found = bs_found('I1', bs_context(bs_sources()));
    $outcome = ScanGenerator::evaluate('I1', $found, Allowlist::fromArray(['invariant' => 'I1', 'mode' => 'report', 'entries' => []]));

    expect($outcome['code'])->toBe(1)->and(end($outcome['lines']))->toContain('inventory.php scan-i1 --report')->toContain('allowlists/i1.json');
});

it('scan: an I3 reverse foreign key of a synthetic fk-map is new, an ok one is not', function (): void {
    $schema = [
        ['id' => 'table:v_blobs', 'owner' => 'media'], ['id' => 'table:v_nodes', 'owner' => 'drive'],
        ['id' => 'fk:v_blobs.v_blobs_node_id_foreign', 'table' => 'v_blobs', 'engines' => ['mysql' => ['columns' => ['node_id'], 'ref_table' => 'v_nodes', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => 'NO ACTION'], 'pgsql' => ['columns' => ['node_id'], 'ref_table' => 'v_nodes', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => 'NO ACTION']]],
        ['id' => 'fk:v_nodes.v_nodes_blob_id_foreign', 'table' => 'v_nodes', 'engines' => ['mysql' => ['columns' => ['blob_id'], 'ref_table' => 'v_blobs', 'ref_columns' => ['id'], 'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION']]],
    ];
    $map = ForeignKeys::build($schema, BS_PACKAGES);
    $verdicts = array_column($map['fks'], 'verdict', 'name');

    expect($verdicts)->toBe(['v_blobs_node_id_foreign' => 'reverse', 'v_nodes_blob_id_foreign' => 'ok'])
        ->and($map['fks'][1])->toMatchArray(['on_delete' => 'restrict', 'engines' => ['mysql'], 'from_owner' => 'drive', 'to_owner' => 'media', 'head' => false]);

    $found = ForeignKeys::found($map);
    expect($found)->toHaveCount(1)->and($found[0])->toMatchArray(['view' => 'target', 'kind' => 'fk', 'symbol' => 'v_blobs', 'target' => 'v_blobs_node_id_foreign', 'target_owner' => 'drive']);

    $empty = Allowlist::fromArray(['invariant' => 'I3', 'mode' => 'report', 'entries' => []]);
    $red = ScanGenerator::evaluate('I3', $found, $empty);
    expect($red['code'])->toBe(1)->and($red['lines'][0])->toStartWith('new   ')
        ->and(ScanGenerator::evaluate('I3', ForeignKeys::found(ForeignKeys::build(array_slice($schema, 0, 2), BS_PACKAGES)), $empty)['code'])->toBe(0);
});

it('scan: foreign keys that the engines disagree about, or whose table has no owner, are rule errors', function (): void {
    $fk = static fn (array $mysql, array $pgsql): array => ['id' => 'fk:v_a.v_a_b_foreign', 'table' => 'v_a', 'engines' => ['mysql' => $mysql, 'pgsql' => $pgsql]];
    $shape = ['columns' => ['b_id'], 'ref_table' => 'v_b', 'ref_columns' => ['id'], 'on_delete' => 'CASCADE', 'on_update' => 'NO ACTION'];
    $tables = [['id' => 'table:v_a', 'owner' => 'drive'], ['id' => 'table:v_b', 'owner' => 'drive']];

    expect(fn () => ForeignKeys::build([...$tables, $fk($shape, [...$shape, 'on_delete' => 'SET NULL'])], BS_PACKAGES))->toThrow(InventoryError::class, 'engines disagree')
        ->and(fn () => ForeignKeys::build([$tables[0], $fk($shape, $shape)], BS_PACKAGES))->toThrow(InventoryError::class, 'v_b has no owner')
        ->and(ForeignKeys::build([...$tables, $fk($shape, $shape)], BS_PACKAGES)['fks'][0]['verdict'])->toBe('ok');
});

it('scan: the head foreign keys are flagged and a table owned by the dependency is fine', function (): void {
    $shape = static fn (string $to): array => ['columns' => ['current_revision_id', 'id'], 'ref_table' => $to, 'ref_columns' => ['id', 'file_id'], 'on_delete' => 'RESTRICT', 'on_update' => 'NO ACTION'];
    $schema = [
        ['id' => 'table:v_files', 'owner' => 'drive'], ['id' => 'table:v_file_revisions', 'owner' => 'drive'],
        ['id' => 'fk:v_files.v_files_head_fk', 'table' => 'v_files', 'engines' => ['pgsql' => $shape('v_file_revisions')]],
    ];

    expect(ForeignKeys::build($schema, BS_PACKAGES)['fks'][0])->toMatchArray(['name' => 'v_files_head_fk', 'head' => true, 'verdict' => 'ok', 'to_columns' => ['id', 'file_id']]);
});

it('matches the committed allowlist of every scan to the current tree, exactly', function (string $name): void {
    $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/'.$name.'.php';
    $outcome = $generator->check(Repo::discover());

    expect($outcome['lines'])->toBe([])->and($outcome['code'])->toBe(0);
})->with(['scan-i1', 'scan-i2', 'scan-i3', 'scan-i4']);

it('keeps fk-map.json equal to the foreign keys of the schema identities', function (): void {
    $repo = Repo::discover();
    $map = ForeignKeys::map($repo);

    expect($repo->read(ForeignKeys::FIXTURE))->toBe(Json::encode($map))
        ->and($map['base'])->toBe('35718fd2')
        ->and(array_unique(array_column($map['fks'], 'verdict')))->toBe(['ok']);

    $names = array_column($map['fks'], 'name');
    expect($names)->toBe(array_values(array_unique($names)))
        ->and(array_column(array_filter($map['fks'], static fn (array $fk): bool => $fk['head']), 'name'))->toBe(['v_documents_head_fk', 'v_files_head_fk']);
});

it('keeps every committed allowlist entry reasoned, categorised and no later than the invariant blocks (all four block: I1 and I2 since P4.16, with the named collaboration residual of D32)', function (): void {
    $repo = Repo::discover();
    $count = 0;
    foreach (['I1', 'I2', 'I3', 'I4'] as $invariant) {
        $allowlist = Allowlist::load($repo, ScanGenerator::allowlistPath($invariant));
        ScanGenerator::assertReasons($allowlist);
        foreach ($allowlist->entries() as $entry) {
            expect($entry['reason'])->not->toBe('')->and($entry)->toHaveKey('category');
            $count++;
        }
        expect($allowlist->mode)->toBe('block');
        if ($invariant === 'I4') {
            expect(array_unique(array_column($allowlist->entries(), 'category')))->not->toContain('activation');
        }
    }

    // I1 is empty; what stays is the I2 residual of D32 (documents -> collaboration, removed in P5) and the I4 ones.
    expect($count)->toBeGreaterThan(0)
        ->and(Allowlist::load($repo, ScanGenerator::allowlistPath('I1'))->entries())->toBe([])
        ->and(array_unique(array_column(Allowlist::load($repo, ScanGenerator::allowlistPath('I2'))->entries(), 'removed_in')))->toBe(['P5']);
});
