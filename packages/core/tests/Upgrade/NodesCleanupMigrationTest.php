<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Support\Config;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null) {
        $this->markTestSkipped('Upgrade fixture requires PostgreSQL and psql.');
    }
});
afterEach(function (): void {
    RefreshDatabaseState::$migrated = false;
});

function nodesCleanupMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_01_000006_nodes_cleanup.php';
}

it('moves every second root under its drive root, logs each move and adds the root index', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $nodes = Config::table('nodes');
    $roots = $db->table(Config::table('storages'))->pluck('root_node_id', 'id')->map(static fn ($id): string => (string) $id);
    $orphans = $db->table($nodes)->whereNull('parent_id')->whereNotIn('id', $roots->values())->orderBy('id')->get();

    expect($db->table($nodes)->whereNull('parent_id')->count())->toBeGreaterThan($roots->count())
        ->and($orphans)->not->toBeEmpty();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $parentless = $db->table($nodes)->whereNull('parent_id')->get(['id', 'storage_id']);
    expect($parentless)->toHaveCount($roots->count());
    foreach ($parentless as $root) {
        expect((string) $root->id)->toBe($roots[$root->storage_id]);
    }

    foreach ($orphans as $orphan) {
        expect((string) $db->table($nodes)->where('id', $orphan->id)->value('parent_id'))->toBe($roots[$orphan->storage_id]);
    }

    // The fixture's "Projects" document clashed with the live "Projects" folder under the root.
    $renamed = $db->table($nodes)->where('id', 40)->first();
    expect($renamed->name)->toBe('Projects (legacy 1)')
        ->and($db->table($nodes)->where('id', 39)->value('name'))->toBe('Loose note');

    $activity = $db->table(Config::table('activity'))->whereRaw("context->>'reason' = 'n04_repair'")->orderBy('target_id')->get();
    expect($activity)->toHaveCount($orphans->count());

    $row = $activity->firstWhere('target_id', 40);
    expect($row->verb)->toBe('node.moved')
        ->and($row->actor_type)->toBe('vaulter:system')
        ->and($row->actor_id)->toBeNull()
        ->and((string) $row->storage_id)->toBe('1')
        ->and($row->created_at)->not->toBeNull()
        ->and(json_decode($row->context, true))->toEqual([
            'from_parent_id' => null,
            'to_parent_id' => '1',
            'reason' => 'n04_repair',
            'renamed_from' => 'Projects',
        ]);

    $before = $db->table($nodes)->orderBy('id')->get()->all();
    $activityCount = $db->table(Config::table('activity'))->count();

    nodesCleanupMigration()->up();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    expect($db->table($nodes)->orderBy('id')->get()->all())->toEqual($before)
        ->and($db->table(Config::table('activity'))->count())->toBe($activityCount);

    expect(fn () => $db->table($nodes)->insert([
        'panel' => 'default',
        'storage_id' => 1,
        'parent_id' => null,
        'kind' => 'folder',
        'name' => 'Second root',
    ]))->toThrow(QueryException::class, 'v_nodes_root_uniq');
});

it('drops the root index on rollback and keeps the repaired tree', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $nodes = Config::table('nodes');

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    nodesCleanupMigration()->down();

    expect($db->getSchemaBuilder()->hasIndex($nodes, $nodes.'_root_uniq'))->toBeFalse()
        ->and($db->table($nodes)->where('id', 40)->value('name'))->toBe('Projects (legacy 1)');

    nodesCleanupMigration()->up();

    expect($db->getSchemaBuilder()->hasIndex($nodes, $nodes.'_root_uniq'))->toBeTrue();
});

it('refuses to guess the root of a drive without root_node_id', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $nodes = Config::table('nodes');
    $storages = $db->table(Config::table('storages'));
    $storages->clone()->where('id', 1)->update(['root_node_id' => null]);
    $before = $db->table($nodes)->orderBy('id')->get()->all();

    try {
        expect(fn () => $this->artisan('migrate', ['--force' => true])->run())
            ->toThrow(RuntimeException::class, 'Storages [1] have several parentless nodes');

        expect($db->table($nodes)->orderBy('id')->get()->all())->toEqual($before);
    } finally {
        // The next test's setUp migrates whatever schema is left behind.
        $storages->clone()->where('id', 1)->update(['root_node_id' => 1]);
    }
});

it('keeps a renamed long name within the name column', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $nodes = Config::table('nodes');
    $long = str_repeat('a', 246).'.txt';

    $db->table($nodes)->insert([
        ['id' => 1001, 'panel' => 'default', 'storage_id' => 2, 'parent_id' => 11, 'kind' => 'file', 'name' => $long],
        ['id' => 1002, 'panel' => 'default', 'storage_id' => 2, 'parent_id' => null, 'kind' => 'file', 'name' => strtoupper($long)],
    ]);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $renamed = $db->table($nodes)->where('id', 1002)->first();
    expect((string) $renamed->parent_id)->toBe('11')
        ->and(strlen($renamed->name))->toBeLessThanOrEqual(255)
        ->and($renamed->name)->toEndWith(' (legacy 1).TXT');
});

it('shortens an extension that cannot fit beside the legacy marker', function (): void {
    $this->loadV02Fixture('bigint');
    $db = app(VaulterDatabase::class)->connection();
    $nodes = Config::table('nodes');
    $long = 'a.'.str_repeat('b', 253);

    $db->table($nodes)->insert([
        ['id' => 1001, 'panel' => 'default', 'storage_id' => 2, 'parent_id' => 11, 'kind' => 'file', 'name' => $long],
        ['id' => 1002, 'panel' => 'default', 'storage_id' => 2, 'parent_id' => null, 'kind' => 'file', 'name' => strtoupper($long)],
    ]);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $renamed = $db->table($nodes)->where('id', 1002)->first();
    expect((string) $renamed->parent_id)->toBe('11')
        ->and(strlen($renamed->name))->toBeLessThanOrEqual(255)
        ->and($renamed->name)->toEndWith(' (legacy 1)')
        ->and($renamed->name)->toStartWith('A.BBB');
});
