<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Symfony\Component\Process\ExecutableFinder;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

uses(LoadsV02Fixture::class);

beforeEach(function (): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql' || (new ExecutableFinder)->find('psql') === null) {
        $this->markTestSkipped('Upgrade fixtures require PostgreSQL and psql.');
    }
});

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

it('preserves historical rows and event subscriptions through upgrade rollback and retry', function (string $strategy): void {
    $this->loadV02Fixture($strategy);
    $db = app(VaulterDatabase::class)->connection();
    $map = ['storage.created' => 'drive.created', 'node.created' => 'folder.created', 'version.created' => 'file.revision_added', 'node.force_deleted' => 'node.purged', 'upload_session.initiated' => 'upload.initiated', 'upload_session.aborted' => 'upload.aborted', 'upload_session.expired' => 'upload.expired'];
    $subscriptions = ['storage.created', 'node.created', 'storage.created', '*', 'third.party'];
    $db->table('v_webhook_endpoints')->update(['events' => json_encode($subscriptions)]);
    $shares = $db->table('v_node_shares')->orderBy('id')->get()->mapWithKeys(fn ($row): array => [$row->id => $row->revoked_at ?? $row->deleted_at]);
    $owners = $db->table('v_storages')->orderBy('id')->pluck('owner_id')->map(fn ($id): string => (string) $id)->all();
    $events = [];
    foreach (['v_activity' => 'verb', 'v_outbox' => 'event', 'v_webhook_deliveries' => 'event'] as $table => $column) {
        $events[$table] = $db->table($table)->orderBy('id')->get(['id', $column])->mapWithKeys(fn ($row): array => [$row->id => $map[$row->$column] ?? $row->$column])->all();
    }

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    foreach (['drives' => 3, 'nodes' => 40, 'files' => 16, 'file_revisions' => 22, 'file_revision_blobs' => 22, 'node_grants' => 3, 'node_shares' => 4, 'activity' => 74, 'outbox' => 68, 'webhook_deliveries' => 4, 'webhook_endpoints' => 1] as $entity => $count) {
        expect($db->table(app(DatabaseConfig::class)->table($entity))->count())->toBe($count);
    }

    expect($db->table('v_drives')->orderBy('id')->pluck('owner_id')->all())->toBe($owners);
    expect($db->table('v_node_shares')->orderBy('id')->pluck('revoked_at', 'id')->all())->toBe($shares->all());

    $file = $db->table('v_files')->join('v_nodes', 'v_nodes.id', '=', 'v_files.node_id')->where('v_nodes.name', 'q1.csv')->orderBy('v_files.id')->value('v_files.id');
    expect($db->table('v_file_revisions')->where('file_id', $file)->orderBy('number')->pluck('number')->all())->toBe([1, 2, 3])
        ->and($db->table('v_file_revisions')->where('scan_status', '<>', 'not_required')->count())->toBe(0);
    foreach (['v_activity' => 'verb', 'v_outbox' => 'event', 'v_webhook_deliveries' => 'event'] as $table => $column) {
        expect($db->table($table)->whereIn($column, array_keys($map))->count())->toBe(0)
            ->and($db->table($table)->whereIn('id', array_keys($events[$table]))->orderBy('id')->pluck($column, 'id')->all())->toBe($events[$table]);
    }

    expect(json_decode((string) $db->table('v_webhook_endpoints')->value('events'), true))->toBe(array_map(fn (string $event): string => $map[$event] ?? $event, $subscriptions));
    $before = $db->table('migrations')->count();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($db->table('migrations')->count())->toBe($before);
    // Replaying each new migration also proves step-level guards.
    foreach (['000001_vaulter_preflight', '000002_rename_storages_to_drives', '000004_file_revisions', '000008_grants_shares_cleanup', '000009_events'] as $name) {
        (require __DIR__.'/../../database/migrations/2026_10_01_'.$name.'.php')->up();
    }

    $this->artisan('migrate:rollback', ['--force' => true])->assertSuccessful();
    expect($db->getSchemaBuilder()->hasTable('v_storages'))->toBeTrue()
        ->and($db->getSchemaBuilder()->hasTable('v_file_versions'))->toBeTrue()
        ->and(json_decode((string) $db->table('v_webhook_endpoints')->value('events'), true))->toBe($subscriptions);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    expect($db->table('v_drives')->count())->toBe(3)
        ->and($db->table('v_file_revisions')->count())->toBe(22)
        ->and($db->table('v_node_grants')->count())->toBe(3);
})->with(['bigint', 'ulid']);
