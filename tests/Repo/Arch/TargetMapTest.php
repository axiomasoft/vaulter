<?php

declare(strict_types=1);

use Vaulter\Inventory\Repo;
use Vaulter\Inventory\Rules;
use Vaulter\Inventory\SourceIndex;
use Vaulter\Inventory\TargetMap;

/*
 * The type-map: who owns every type, file and SchemaBuilder member. The `rule engine:` tests run the engine on
 * synthetic code and rules (strings, never .php files: Pint would reformat them); the last tests check the committed
 * tests/Repo/Arch/Fixtures/target-map.json against the five rule files and the real code.
 * Fix a red fixture with: scripts/php84 php scripts/inventory/inventory.php target-map --write
 */

require_once dirname(__DIR__, 3).'/scripts/inventory/bootstrap.php';
require_once dirname(__DIR__, 3).'/scripts/inventory/lib/TargetMap.php';

const TM_PSR4 = ['Vaulter\\' => 'core', 'Vaulter\\Media\\' => 'media', 'Vaulter\\Drive\\' => 'drive', 'Vaulter\\Documents\\' => 'documents', 'Vaulter\\Filament\\' => 'filament', 'Vaulter\\Collaboration\\' => 'collaboration'];

/** @return array<string, string> a small core with a model, a contract, a service, SchemaBuilder and a migration */
function tm_sources(): array
{
    return [
        'packages/core/src/Access/AccessResolver.php' => '<?php namespace Vaulter\Access; final class AccessResolver {}',
        'packages/core/src/Models/Drive/Node.php' => '<?php namespace Vaulter\Models\Drive; use Illuminate\Database\Eloquent\Model; class Node extends Model {}',
        'packages/core/src/Contracts/Cas/BlobStore.php' => '<?php namespace Vaulter\Contracts\Cas; interface BlobStore {}',
        'packages/core/src/ValueObjects/Access/Actor.php' => '<?php namespace Vaulter\ValueObjects\Access; final class Actor {}',
        'packages/core/src/Schema/SchemaBuilder.php' => '<?php namespace Vaulter\Schema; final class SchemaBuilder { public function createAll(): void {} private function defineNodes(): void {} }',
        'packages/core/database/migrations/2026_10_13_000002_create_v_nodes_table.php' => '<?php return new class { public function up(): void {} };',
        'packages/filament/src/Resources/NodeResource.php' => '<?php namespace Vaulter\Filament\Resources; use Vaulter\Models\Drive\Node; final class NodeResource { public function model(): string { return Node::class; } }',
    ];
}

/** @return list<string> */
function tm_files(array $sources): array
{
    return array_values(array_filter(array_keys($sources), static fn (string $p): bool => preg_match('#^packages/[^/]+/(database|config|routes)/#', $p) === 1));
}

/**
 * @param  array<string, string>                                                                                        $sources
 * @param  array<string, array<string, mixed>>                                                                          $files    rule file name => {scope, rules}
 * @param  array<string, true>                                                                                          $baseline
 * @return array{data: array<string, mixed>, problems: list<array{code: string, message: string, files: list<string>}>}
 */
function tm_build(array $sources, array $files, array $baseline = [], ?string $only = null): array
{
    $index = SourceIndex::fromSources($sources, TM_PSR4, []);

    return TargetMap::build($index, Rules::fromArray($files, TargetMap::OWNERS), tm_files($sources), TM_PSR4, $baseline, $only);
}

/** @return list<string> the problem codes, sorted */
function tm_codes(array $built): array
{
    $codes = array_column($built['problems'], 'code');
    sort($codes);

    return $codes;
}

/** @return array<string, array<string, mixed>> the types of a build keyed by fqcn */
function tm_types(array $built): array
{
    $types = [];
    foreach ($built['data']['types'] as $type) {
        $types[$type['fqcn']] = $type;
    }

    return $types;
}

/** The one-file rule set that classifies tm_sources() completely. */
function tm_rules(): array
{
    return ['kernel' => ['scope' => ['Vaulter\\', 'path:packages/'], 'rules' => [
        ['match' => 'prefix', 'value' => 'Vaulter\\Access\\', 'owner' => 'drive', 'surface' => 'Internal', 'why' => 'architecture#ownership-map Access'],
        ['match' => 'prefix', 'value' => 'Vaulter\\Models\\Drive\\', 'owner' => 'drive', 'surface' => 'Internal', 'why' => 'architecture#ownership-map Models/Drive'],
        ['match' => 'prefix', 'value' => 'Vaulter\\Contracts\\Cas\\', 'owner' => 'media', 'surface' => 'Spi:Implement', 'why' => 'architecture#ownership-map Cas'],
        ['match' => 'fqcn', 'value' => 'Vaulter\\ValueObjects\\Access\\Actor', 'owner' => 'core', 'surface' => 'Api', 'why' => 'architecture#kernel Actor'],
        ['match' => 'prefix', 'value' => 'Vaulter\\Schema\\', 'owner' => 'core', 'surface' => 'Internal', 'why' => 'architecture#kernel Schema'],
        ['match' => 'prefix', 'value' => 'Vaulter\\Filament\\', 'owner' => 'filament', 'surface' => 'Internal', 'why' => 'architecture#packages filament'],
        ['match' => 'member', 'value' => 'Vaulter\\Schema\\SchemaBuilder::createAll', 'owner' => 'drive', 'action' => 'split', 'note' => 'orchestration goes to owners', 'why' => 'source-review#code-facts SchemaBuilder'],
        ['match' => 'member', 'value' => 'Vaulter\\Schema\\SchemaBuilder::defineNodes', 'owner' => 'drive', 'why' => 'domain DDL'],
        ['match' => 'file', 'value' => 'packages/core/database/migrations/2026_10_13_000002_create_v_nodes_table.php', 'owner' => 'drive', 'why' => 'v_nodes is drive'],
    ]]];
}

it('rule engine: classifies types, files and members and derives the default target_fqcn', function (): void {
    $built = tm_build(tm_sources(), tm_rules());
    $types = tm_types($built);

    expect($built['problems'])->toBe([])
        ->and($types['Vaulter\Models\Drive\Node']['target_fqcn'])->toBe('Vaulter\Drive\Models\Node')
        ->and($types['Vaulter\Models\Drive\Node'])->toMatchArray(['owner' => 'drive', 'action' => 'move', 'phase' => 'P4', 'eloquent' => true, 'package' => 'core'])
        ->and($types['Vaulter\Access\AccessResolver']['target_fqcn'])->toBe('Vaulter\Drive\Access\AccessResolver')
        ->and($types['Vaulter\Contracts\Cas\BlobStore']['target_fqcn'])->toBe('Vaulter\Media\Contracts\Cas\BlobStore')
        ->and($types['Vaulter\ValueObjects\Access\Actor'])->toMatchArray(['action' => 'keep', 'phase' => 'stay', 'target_fqcn' => 'Vaulter\ValueObjects\Access\Actor', 'surface' => 'Api'])
        ->and($types['Vaulter\Schema\SchemaBuilder'])->toMatchArray(['action' => 'keep', 'eloquent' => false, 'rule' => 'kernel:prefix:Vaulter\Schema\\'])
        ->and(array_column($built['data']['members'], 'member'))->toBe(['createAll', 'defineNodes'])
        ->and($built['data']['members'][0])->toMatchArray(['owner' => 'drive', 'action' => 'split', 'phase' => 'P4'])
        ->and($built['data']['files'])->toBe([[
            'file' => 'packages/core/database/migrations/2026_10_13_000002_create_v_nodes_table.php', 'package' => 'core', 'owner' => 'drive',
            'role' => 'migration', 'action' => 'move', 'phase' => 'P4', 'note' => null,
        ]]);
});

it('rule engine: default target drops the owner segment after the top directory and never doubles the owner namespace', function (): void {
    expect(TargetMap::defaultTarget('Vaulter\Models\Drive\Node', 'Vaulter\\', 'drive'))->toBe('Vaulter\Drive\Models\Node')
        ->and(TargetMap::defaultTarget('Vaulter\Contracts\Webhooks\X', 'Vaulter\\', 'webhooks'))->toBe('Vaulter\Webhooks\Contracts\X')
        ->and(TargetMap::defaultTarget('Vaulter\Contracts\Cas\BlobStore', 'Vaulter\\', 'media'))->toBe('Vaulter\Media\Contracts\Cas\BlobStore')
        ->and(TargetMap::defaultTarget('Vaulter\Webhooks\Endpoint', 'Vaulter\\', 'webhooks'))->toBe('Vaulter\Webhooks\Endpoint')
        ->and(TargetMap::defaultTarget('Vaulter\VaulterManager', 'Vaulter\\', 'drive'))->toBe('Vaulter\Drive\VaulterManager')
        ->and(TargetMap::defaultTarget('Vaulter\Documents\Collaboration\Hub', 'Vaulter\Documents\\', 'collaboration'))->toBe('Vaulter\Collaboration\Hub')
        ->and(TargetMap::defaultTarget('Vaulter\Documents\Http\Controllers\Collaboration\C', 'Vaulter\Documents\\', 'collaboration'))->toBe('Vaulter\Collaboration\Http\Controllers\Collaboration\C');
});

it('rule engine: an fqcn rule beats the longest prefix and may name an explicit target', function (): void {
    $files = tm_rules();
    $files['kernel']['rules'][] = ['match' => 'fqcn', 'value' => 'Vaulter\Access\AccessResolver', 'owner' => 'drive', 'action' => 'replace', 'target' => '\Vaulter\Drive\Access\Resolver', 'surface' => 'Internal', 'why' => 'architecture#entry-point'];
    $types = tm_types(tm_build(tm_sources(), $files));

    expect($types['Vaulter\Access\AccessResolver'])->toMatchArray(['action' => 'replace', 'target_fqcn' => 'Vaulter\Drive\Access\Resolver', 'rule' => 'kernel:fqcn:Vaulter\Access\AccessResolver']);
});

it('rule engine: fills surface from a map by declaration kind with a class group and a fallback', function (): void {
    $files = tm_rules();
    $files['kernel']['rules'][0]['surface'] = ['interface' => 'Spi:Call', 'class' => 'Api', '*' => 'Internal'];
    $files['kernel']['rules'][2]['surface'] = ['interface' => 'Spi:Implement', 'final-class' => 'Api'];
    $built = tm_build(tm_sources(), $files);
    $types = tm_types($built);

    expect($types['Vaulter\Access\AccessResolver']['surface'])->toBe('Api')
        ->and($built['problems'])->toBe([]);

    $files['kernel']['rules'][0]['surface'] = ['interface' => 'Spi:Call'];
    expect(tm_codes(tm_build(tm_sources(), $files)))->toContain('undecided-without-note');
});

it('rule engine: the longest scope answers for a subject, a rule outside its file scope and equal prefixes are refused', function (): void {
    $files = [
        'wide' => ['scope' => ['Vaulter\\', 'path:packages/'], 'rules' => [
            ['match' => 'prefix', 'value' => 'Vaulter\\', 'owner' => 'core', 'surface' => 'Internal', 'why' => 'rest'],
            ['match' => 'file', 'value' => 'packages/core/database/migrations/2026_10_13_000002_create_v_nodes_table.php', 'owner' => 'drive', 'why' => 'v_nodes'],
            ['match' => 'member', 'value' => 'Vaulter\\Schema\\SchemaBuilder::createAll', 'owner' => 'core', 'why' => 'generic'],
            ['match' => 'member', 'value' => 'Vaulter\\Schema\\SchemaBuilder::defineNodes', 'owner' => 'drive', 'why' => 'domain'],
        ]],
        'access' => ['scope' => ['Vaulter\\Access\\'], 'rules' => [
            ['match' => 'prefix', 'value' => 'Vaulter\\Access\\', 'owner' => 'drive', 'surface' => 'Internal', 'why' => 'architecture#ownership-map Access'],
        ]],
    ];
    expect(tm_build(tm_sources(), $files)['problems'])->toBe([])
        ->and(tm_types(tm_build(tm_sources(), $files))['Vaulter\Access\AccessResolver']['rule'])->toBe('access:prefix:Vaulter\Access\\');

    $misplaced = $files;
    $misplaced['wide']['rules'][] = array_pop($misplaced['access']['rules']);
    $built = tm_build(tm_sources(), $misplaced);
    expect(tm_codes($built))->toBe(['out-of-scope'])->and($built['problems'][0]['files'])->toBe(['wide', 'access']);

    $files['access']['rules'][] = ['match' => 'prefix', 'value' => 'Vaulter\\Access\\', 'owner' => 'drive', 'why' => 'dup'];
    expect(tm_codes(tm_build(tm_sources(), $files, [], 'access')))->toBe(['conflict']);
});

it('rule engine: equal scope elements in two files are a tie and an uncovered subject is a gap', function (): void {
    $files = tm_rules();
    $files['twin'] = ['scope' => ['Vaulter\\Access\\'], 'rules' => []];
    $files['kernel']['scope'] = ['Vaulter\\Access\\', 'path:packages/'];

    $codes = array_count_values(tm_codes(tm_build(tm_sources(), $files)));
    expect($codes['scope-tie'])->toBe(1)->and($codes['uncovered'])->toBeGreaterThan(0);
});

it('rule engine: a rule that wins nothing is stale and a subject nobody classifies is a gap', function (): void {
    $files = tm_rules();
    $files['kernel']['rules'][] = ['match' => 'prefix', 'value' => 'Vaulter\\Gone\\', 'owner' => 'drive', 'why' => 'architecture#ownership-map'];
    $files['kernel']['rules'][] = ['match' => 'member', 'value' => 'Vaulter\\Schema\\SchemaBuilder::dropAll', 'owner' => 'drive', 'why' => 'no such method'];
    $built = tm_build(tm_sources(), $files);
    expect(tm_codes($built))->toBe(['stale-rule', 'stale-rule'])
        ->and($built['problems'][0]['message'])->toContain('Vaulter\Gone\\');

    array_pop($files['kernel']['rules']);
    array_pop($files['kernel']['rules']);
    $files['kernel']['rules'] = array_values(array_filter($files['kernel']['rules'], static fn (array $r): bool => $r['match'] !== 'member' && $r['match'] !== 'file'));
    $built = tm_build(tm_sources(), $files);
    expect(array_count_values(tm_codes($built)))->toBe(['unclassified' => 3]);
});

it('rule engine: a rule without why or with an unknown owner or match is refused', function (): void {
    $files = tm_rules();
    unset($files['kernel']['rules'][0]['why']);
    $files['kernel']['rules'][1]['owner'] = 'billing';
    expect(tm_codes(tm_build(tm_sources(), $files)))->toContain('invalid-rule')
        ->and(array_count_values(tm_codes(tm_build(tm_sources(), $files)))['invalid-rule'])->toBe(2);
});

it('rule engine: a target claimed twice, also by an existing type of the target package, is a collision', function (): void {
    $sources = tm_sources() + ['packages/drive/src/Models/Node.php' => '<?php namespace Vaulter\Drive\Models; class Node {}'];
    $files = tm_rules();
    $files['kernel']['rules'][] = ['match' => 'prefix', 'value' => 'Vaulter\\Drive\\', 'owner' => 'drive', 'surface' => 'Internal', 'why' => 'architecture#packages drive'];

    $built = tm_build($sources, $files);
    expect(tm_codes($built))->toBe(['target-collision'])
        ->and($built['problems'][0]['message'])->toContain('Vaulter\Drive\Models\Node');

    $files['kernel']['rules'] = array_values(array_filter($files['kernel']['rules'], static fn (array $r): bool => $r['value'] !== 'Vaulter\\Models\\Drive\\'));
    $files['kernel']['rules'][] = ['match' => 'fqcn', 'value' => 'Vaulter\Models\Drive\Node', 'owner' => 'drive', 'target' => 'Vaulter\Drive\Models\CoreNode', 'surface' => 'Internal', 'note' => 'name taken by drive', 'why' => 'architecture#ownership-map'];
    expect(tm_build($sources, $files)['problems'])->toBe([]);
});

it('rule engine: validates action, phase, keep/move direction and the notes delete, split and undecided need', function (): void {
    $cases = [
        'invalid-field' => [['action' => 'keep'], ['action' => 'move', 'owner' => 'core'], ['phase' => 'P9'], ['action' => 'copy'], ['phase' => 'stay']],
        'delete-without-note' => [['action' => 'delete']],
        'split-without-note' => [['action' => 'split']],
        'undecided-without-note' => [['surface' => 'undecided']],
    ];
    foreach ($cases as $code => $variants) {
        foreach ($variants as $patch) {
            $files = tm_rules();
            $files['kernel']['rules'][0] = $patch + $files['kernel']['rules'][0];
            expect(tm_codes(tm_build(tm_sources(), $files)))->toContain($code);
        }
    }

    $files = tm_rules();
    $files['kernel']['rules'][0] = ['action' => 'delete', 'note' => 'removed by D12', 'surface' => 'Internal'] + $files['kernel']['rules'][0];
    $built = tm_build(tm_sources(), $files);
    expect($built['problems'])->toBe([])
        ->and(tm_types($built)['Vaulter\Access\AccessResolver']['target_fqcn'])->toBeNull();
});

it('rule engine: a type of the current public API snapshot is not Internal without a note', function (): void {
    $baseline = ['Vaulter\Access\AccessResolver' => true];
    $built = tm_build(tm_sources(), tm_rules(), $baseline);
    expect(tm_codes($built))->toBe(['baseline-internal']);

    $files = tm_rules();
    $files['kernel']['rules'][0]['note'] = 'internal helper kept only for the snapshot';
    expect(tm_build(tm_sources(), $files, $baseline)['problems'])->toBe([]);

    $files = tm_rules();
    $files['kernel']['rules'][0]['surface'] = 'Api';
    expect(tm_build(tm_sources(), $files, $baseline)['problems'])->toBe([]);
});

it('rule engine: lists the Filament reads of Eloquent models once per file, symbol and model', function (): void {
    $built = tm_build(tm_sources(), tm_rules());

    expect($built['data']['consumers']['filament_model_reads'])->toBe([
        ['file' => 'packages/filament/src/Resources/NodeResource.php', 'symbol' => '<file>', 'model' => 'Vaulter\Models\Drive\Node', 'owner' => 'drive', 'replacement' => TargetMap::REPLACEMENT],
        ['file' => 'packages/filament/src/Resources/NodeResource.php', 'symbol' => 'Vaulter\Filament\Resources\NodeResource::model', 'model' => 'Vaulter\Models\Drive\Node', 'owner' => 'drive', 'replacement' => TargetMap::REPLACEMENT],
    ]);
});

it('rule engine: output is deterministic and carries no line numbers or absolute paths', function (): void {
    $a = json_encode(tm_build(tm_sources(), tm_rules())['data']);
    $b = json_encode(tm_build(array_reverse(tm_sources(), true), tm_rules())['data']);

    expect($a)->toBe($b)->and($a)->not->toContain('"line"')->not->toContain('/home/');
});

it('rule engine: the human summary counts what the data holds', function (): void {
    $markdown = TargetMap::markdown(tm_build(tm_sources(), tm_rules())['data']);

    expect($markdown)->toContain('| core | drive | move | P4 | 2 |')
        ->and($markdown)->toContain('## Members (2)')
        ->and($markdown)->toContain('`Vaulter\Models\Drive\Node` × 2');
});

it('rejects an unknown rules file with exit 2 and judges a known one on its own', function (): void {
    [$code, , $err] = (static function (): array {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 3).'/scripts/inventory/inventory.php', 'target-map', '--report', '--rules=nope'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3));
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);

        return [proc_close($process), $out, $err];
    })();

    expect($code)->toBe(2)->and($err)->toContain('unknown rules file nope');
});

// --- the committed map ----------------------------------------------------------------------------------------

it('matches the committed target-map', function (): void {
    $generator = require dirname(__DIR__, 3).'/scripts/inventory/generators/target-map.php';
    $outcome = $generator->check(Repo::discover());

    expect(implode("\n", $outcome['lines']))->toBe('')->and($outcome['code'])->toBe(0);
});

it('classifies the special cases of the architecture by name', function (): void {
    $map = Repo::discover()->readJson(TargetMap::FIXTURE);
    $types = [];
    foreach ($map['types'] as $type) {
        $types[$type['fqcn']] = $type;
    }
    $members = [];
    foreach ($map['members'] as $member) {
        $members[$member['fqcn'].'::'.$member['member']] = $member;
    }

    expect($types['Vaulter\VaulterManager'])->toMatchArray(['owner' => 'drive', 'action' => 'replace', 'phase' => 'P4', 'target_fqcn' => 'Vaulter\Drive\Vaulter'])
        ->and($types['Vaulter\Facades\Vaulter'])->toMatchArray(['owner' => 'drive', 'action' => 'replace', 'target_fqcn' => 'Vaulter\Drive\Facades\Vaulter'])
        ->and($types['Vaulter\ValueObjects\Access\Actor'])->toMatchArray(['owner' => 'core', 'action' => 'keep'])
        ->and($types['Vaulter\Events\Outbox\Models\OutboxEvent'])->toMatchArray(['owner' => 'core', 'action' => 'keep'])
        ->and($types['Vaulter\Models\Drive\Node'])->toMatchArray(['owner' => 'drive', 'target_fqcn' => 'Vaulter\Drive\Models\Node', 'eloquent' => true])
        ->and($types['Vaulter\Contracts\Cas\BlobStore']['owner'])->toBe('media')
        ->and($types['Vaulter\Documents\Collaboration\CollaborationHub'])->toMatchArray(['owner' => 'collaboration', 'phase' => 'P5'])
        ->and($members['Vaulter\Media\Cas\BlobStore::attach']['owner'])->toBe('drive')
        ->and($members['Vaulter\Schema\SchemaBuilder::addHeadForeignKey']['action'])->toBe('split')
        ->and($members['Vaulter\Schema\SchemaBuilder::createAll']['action'])->toBe('split')
        ->and($members['Vaulter\Schema\SchemaBuilder::dropAll']['action'])->toBe('split');
});

it('keeps every public-API baseline type out of Internal unless a note says why', function (): void {
    $repo = Repo::discover();
    $baseline = TargetMap::baseline($repo);
    $types = [];
    foreach ($repo->readJson(TargetMap::FIXTURE)['types'] as $type) {
        $types[$type['fqcn']] = $type;
    }

    $offenders = [];
    foreach (array_keys($baseline) as $fqcn) {
        if (isset($types[$fqcn]) && $types[$fqcn]['surface'] === 'Internal' && $types[$fqcn]['note'] === null) {
            $offenders[] = $fqcn;
        }
    }

    expect($baseline)->not->toBe([])->and($offenders)->toBe([]);
});

it('covers every type, file and SchemaBuilder method of the repository exactly once', function (): void {
    $repo = Repo::discover();
    $map = $repo->readJson(TargetMap::FIXTURE);
    $index = SourceIndex::forRepo($repo);

    $declared = array_values(array_unique(array_filter(array_column($index->declarations(), 'fqcn'), static fn (string $f): bool => !str_contains($f, '@anonymous'))));
    sort($declared, SORT_STRING);
    $builder = array_column($index->declaration(TargetMap::SCHEMA_BUILDER)['methods'] ?? [], 'name');
    $declaredFiles = array_column($index->declarations(), 'file');

    expect(array_column($map['types'], 'fqcn'))->toBe($declared)
        ->and(array_column(array_filter($map['members'], static fn (array $m): bool => $m['fqcn'] === TargetMap::SCHEMA_BUILDER), 'member'))->toEqualCanonicalizing($builder)
        ->and(array_column($map['files'], 'file'))->toBe(array_values(array_filter(TargetMap::sourceFiles($repo), static fn (string $f): bool => !in_array($f, $declaredFiles, true))))
        ->and($map['owners'])->toBe(TargetMap::OWNERS);

    $targets = array_filter(array_column($map['types'], 'target_fqcn'));
    expect(count($targets))->toBe(count(array_unique($targets)));
});
