<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UpgradePreflightException;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

/**
 * 2026_10_10_000001_event_pipeline on a 0.2 database: v_activity.event_id becomes
 * unique (replacing the plain index) and v_outbox gets tenant_id. Duplicate event ids
 * stop it before any change, with their count and the query that lists them.
 */
uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

function eventPipelineMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_10_000001_event_pipeline.php';
}

it('upgrades a 0.2 database to a unique activity event id and a scoped outbox', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $schema = app(VaulterDatabase::class)->connection()->getSchemaBuilder();
    $activity = app(DatabaseConfig::class)->table('activity');
    $outbox = app(DatabaseConfig::class)->table('outbox');

    expect($schema->hasIndex($activity, $activity.'_event_id_unique'))->toBeTrue()
        ->and($schema->hasIndex($activity, $activity.'_event_id_index'))->toBeFalse()
        ->and($schema->hasColumn($outbox, 'tenant_id'))->toBeTrue()
        ->and($schema->hasIndex($outbox, $outbox.'_tenant_id_index'))->toBeTrue();

    eventPipelineMigration()->down();
    expect($schema->hasIndex($activity, $activity.'_event_id_unique'))->toBeFalse()
        ->and($schema->hasIndex($activity, $activity.'_event_id_index'))->toBeTrue()
        ->and($schema->hasColumn($outbox, 'tenant_id'))->toBeFalse();

    eventPipelineMigration()->up();
    expect($schema->hasIndex($activity, $activity.'_event_id_unique'))->toBeTrue()
        ->and($schema->hasColumn($outbox, 'tenant_id'))->toBeTrue();
})->with(['bigint', 'ulid']);

it('stops on duplicate activity event ids and changes nothing', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    eventPipelineMigration()->down();

    $db = app(VaulterDatabase::class)->connection();
    $activity = app(DatabaseConfig::class)->table('activity');
    $outbox = app(DatabaseConfig::class)->table('outbox');
    $eventId = (string) Str::ulid();
    foreach ([1, 2] as $copy) {
        $db->table($activity)->insert([
            ...($strategy === 'ulid' ? ['id' => (string) Str::ulid()] : []),
            'verb' => 'folder.created',
            'event_id' => $eventId,
            'created_at' => now(),
        ]);
    }

    expect(fn () => eventPipelineMigration()->up())->toThrow(UpgradePreflightException::class, '1 event ids occur more than once');

    $schema = $db->getSchemaBuilder();
    expect($schema->hasIndex($activity, $activity.'_event_id_unique'))->toBeFalse()
        ->and($schema->hasColumn($outbox, 'tenant_id'))->toBeFalse()
        ->and($db->table($activity)->where('event_id', $eventId)->count())->toBe(2);
})->with(['bigint', 'ulid']);
