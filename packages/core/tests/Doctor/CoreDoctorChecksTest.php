<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Data\Drive\UploadOptions;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;
use Vaulter\Doctor\DoctorRegistry;
use Vaulter\Doctor\DoctorRunner;
use Vaulter\Doctor\DoctorSeverity;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Testing\NullPermissionDriver;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Tenancy\MutableTenantResolver;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

uses(EnablesWebhooksModule::class);

// MySQL commits DDL implicitly, so the tables these tests drop stay dropped after the test (SQLite and PostgreSQL roll them back with
// the test transaction). Start the next test from an empty database that RefreshDatabase migrates afresh.
afterEach(function (): void {
    if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
        Schema::dropAllTables();
        RefreshDatabaseState::$migrated = false;
    }
});

/** @return list<DoctorFinding> */
function coreFindings(string $key, bool $production = false): array
{
    $report = app(DoctorRunner::class)->run(new DoctorContext(production: $production, probeStorage: false, sampleLimit: 1000, now: CarbonImmutable::now('UTC')), [$key]);

    expect($report->checks)->toHaveCount(1);

    return $report->checks[0]->findings;
}

function coreSeverities(string $key, bool $production = false): array
{
    return array_map(static fn (DoctorFinding $finding): string => $finding->severity->value, coreFindings($key, $production));
}

function coreCodes(string $key, bool $production = false): array
{
    return array_map(static fn (DoctorFinding $finding): string => $finding->code, coreFindings($key, $production));
}

function coreTable(string $entity): string
{
    return app(DatabaseConfig::class)->table($entity);
}

it('reports the sqlite test database as a warning, an error in production', function (): void {
    expect(coreCodes('core.database.connection'))->toBe(['core.database.sqlite'])
        ->and(coreSeverities('core.database.connection'))->toBe(['warning'])
        ->and(coreSeverities('core.database.connection', production: true))->toBe(['error']);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'SQLite-only: a server engine is not the sqlite warning');

it('reports an unreachable database by exception class only', function (): void {
    config([
        'database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-doctor-dir/vaulter.sqlite'],
        'vaulter.database.connection' => 'broken',
    ]);

    $finding = coreFindings('core.database.connection')[0];

    expect($finding->code)->toBe('core.database.unreachable')
        ->and($finding->severity)->toBe(DoctorSeverity::Error)
        ->and(json_encode($finding->evidence))->not->toContain('nonexistent-doctor-dir');
});

it('finds every table and migration of a migrated schema', function (): void {
    expect(coreCodes('core.schema.tables'))->toBe(['core.schema.tables_present', 'core.schema.migrations_applied']);
});

it('reports a missing table and a pending migration as errors', function (): void {
    // MySQL commits DDL implicitly: the drop would outlive the test transaction and break the next test.
    if (DB::connection()->getDriverName() === 'mysql') {
        $this->markTestSkipped('DDL is not transactional on MySQL.');
    }

    Schema::drop(coreTable('drive_changes'));
    Schema::drop(coreTable('drive_change_heads'));
    DB::table('migrations')->where('migration', 'like', '%_create_v_drive_changes_table')->delete();

    $findings = coreFindings('core.schema.tables');

    expect(array_map(static fn (DoctorFinding $finding): string => $finding->code, $findings))->toBe(['core.schema.tables_missing', 'core.schema.migrations_pending'])
        ->and($findings[0]->evidence['count'])->toBe(2)
        ->and($findings[1]->evidence['first'])->toEndWith('_create_v_drive_changes_table')
        ->and(coreSeverities('core.schema.tables', production: false))->toBe(['error', 'error']);
});

it('treats a published migration under a new timestamp as applied', function (): void {
    DB::table('migrations')->where('migration', 'like', '%_create_v_drive_changes_table')->update(['migration' => '2030_01_01_000000_create_v_drive_changes_table']);

    expect(coreCodes('core.schema.tables'))->toBe(['core.schema.tables_present', 'core.schema.migrations_applied']);
});

it('reports a missing migrations table', function (): void {
    if (DB::connection()->getDriverName() === 'mysql') {
        $this->markTestSkipped('DDL is not transactional on MySQL.');
    }

    Schema::drop('migrations');

    expect(coreCodes('core.schema.tables'))->toContain('core.schema.migrations_table_missing');
});

it('skips the key types where the driver cannot report them', function (): void {
    $finding = coreFindings('core.schema.key_types')[0];

    expect($finding->code)->toBe('core.schema.key_types_skipped')
        ->and($finding->severity)->toBe(DoctorSeverity::Ok)
        ->and($finding->evidence['skipped'])->toBeTrue();
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'SQLite-only: a server driver reports its key types (key_types_match)');

it('finds no broken invariant in consistent data', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload'), 'doc.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    expect(coreCodes('core.schema.integrity'))->toBe(['core.schema.integrity_ok']);
});

it('samples a file node without a file and a foreign head', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('payload'), 'doc.txt'), new UploadOptions(declaredMimeType: 'text/plain'));
    $other = DriveTestHelpers::drive()->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('other'), 'other.txt'), new UploadOptions(declaredMimeType: 'text/plain'));

    $foreign = DB::table(coreTable('files'))->where('node_id', $other->id)->value('current_revision_id');
    DB::table(coreTable('files'))->where('node_id', $node->id)->update(['current_revision_id' => $foreign]);

    $finding = coreFindings('core.schema.integrity')[0];
    expect($finding->code)->toBe('core.schema.integrity_broken')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence)->toHaveKey('i6_files_with_foreign_head', 1)
        ->and($finding->evidence)->not->toHaveKey('i5_file_nodes_without_file');

    DB::table(coreTable('files'))->where('node_id', $node->id)->delete();
    $finding = coreFindings('core.schema.integrity')[0];
    expect($finding->evidence)->toHaveKey('i5_file_nodes_without_file', 1);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'sqlite', 'SQLite-only: a server engine\'s foreign key refuses the foreign head this test plants');

it('samples drives without a single root and limits the sample', function (): void {
    foreach (range(1, 3) as $_) {
        DriveTestHelpers::drive();
    }
    DB::table(coreTable('nodes'))->whereNull('parent_id')->delete();

    $finding = app(DoctorRunner::class)->run(new DoctorContext(production: false, probeStorage: false, sampleLimit: 2, now: CarbonImmutable::now('UTC')), ['core.schema.integrity'])->checks[0]->findings[0];

    expect($finding->code)->toBe('core.schema.integrity_broken')
        ->and($finding->evidence['i1_drives_without_single_root'])->toBe(2);
});

it('lists every failing validator, not the first one', function (): void {
    config(['vaulter.models.drive' => stdClass::class, 'vaulter.access.permission_driver' => 'nonexistent']);

    $findings = coreFindings('core.config.valid');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBe(DoctorSeverity::Error)
        ->and($findings[0]->code)->toBe('core.config.invalid')
        ->and($findings[0]->evidence['validator'])->toBe('core');
});

it('runs the validators the other packages tag', function (): void {
    $this->app->bind('doctor.test.validator', static fn (): Closure => static function (): void {
        throw new RuntimeException('secret=hunter2');
    });
    $this->app->tag(['doctor.test.validator'], 'vaulter.config_validators');

    $findings = coreFindings('core.config.valid');

    expect($findings[0]->severity)->toBe(DoctorSeverity::Error)
        ->and($findings[0]->message)->toBe(RuntimeException::class)
        ->and(json_encode($findings))->not->toContain('hunter2');
});

it('runs two validators tagged by the same class', function (): void {
    foreach (['first', 'second'] as $name) {
        $this->app->bind('doctor.test.same.'.$name, static fn (): Closure => static function () use ($name): void {
            throw new InvalidConfigurationException('marker-'.$name);
        });
        $this->app->tag(['doctor.test.same.'.$name], 'vaulter.config_validators');
    }

    $messages = array_map(static fn (DoctorFinding $finding): string => $finding->message, coreFindings('core.config.valid'));

    expect($messages)->toContain('marker-first')->toContain('marker-second');
});

it('requires an auth middleware with a defined guard on the authenticated routes', function (): void {
    config(['vaulter.routes.middleware.authenticated' => ['api']]);
    expect(coreCodes('core.routes.auth'))->toBe(['core.routes.no_auth_middleware'])
        ->and(coreSeverities('core.routes.auth'))->toBe(['error']);

    config(['vaulter.routes.middleware.authenticated' => ['api', 'auth:sanctum'], 'auth.guards' => ['web' => []]]);
    expect(coreCodes('core.routes.auth'))->toBe(['core.routes.unknown_guard']);

    config(['vaulter.routes.middleware.authenticated' => ['api', 'auth:sanctum'], 'auth.guards' => ['sanctum' => []]]);
    expect(coreCodes('core.routes.auth'))->toBe(['core.routes.auth_ok']);

    config(['vaulter.routes.middleware.authenticated' => ['api', 'auth'], 'auth.guards' => ['web' => []]]);
    expect(coreCodes('core.routes.auth'))->toBe(['core.routes.auth_ok']);
});

it('refuses the null driver always and a non-production driver in production', function (): void {
    expect(coreCodes('core.access.driver'))->toBe(['core.access.null_driver']);

    config(['vaulter.access.permission_driver' => 'database']);
    expect(coreCodes('core.access.driver'))->toBe(['core.access.driver_ok']);

    config(['vaulter.access.permission_drivers.test_double' => NullPermissionDriver::class, 'vaulter.access.permission_driver' => 'test_double']);
    expect(coreCodes('core.access.driver'))->toBe(['core.access.driver_ok'])
        ->and(coreCodes('core.access.driver', production: true))->toBe(['core.access.non_production_driver']);

    config(['vaulter.access.permission_drivers.broken' => 'Missing\\Driver', 'vaulter.access.permission_driver' => 'broken']);
    expect(coreCodes('core.access.driver'))->toBe(['core.access.unresolved']);
});

it('checks the tenant resolver class, not its value', function (): void {
    expect(coreCodes('core.tenancy'))->toBe(['core.tenancy.disabled']);

    config(['vaulter.tenancy.enabled' => true, 'vaulter.tenancy.resolver' => 'Missing\\Resolver']);
    expect(coreCodes('core.tenancy'))->toBe(['core.tenancy.resolver_invalid']);

    config(['vaulter.tenancy.resolver' => MutableTenantResolver::class]);
    $finding = coreFindings('core.tenancy')[0];
    expect($finding->code)->toBe('core.tenancy.resolver_ok')
        ->and($finding->evidence['http_context'])->toBeFalse();
});

it('warns about a synchronous queue', function (): void {
    config(['queue.default' => 'sync']);
    expect(coreSeverities('core.queue'))->toBe(['warning'])
        ->and(coreSeverities('core.queue', production: true))->toBe(['warning']);

    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database']]);
    expect(coreCodes('core.queue'))->toBe(['core.queue.asynchronous']);
});

it('warns when nothing schedules the maintenance tasks', function (): void {
    expect(coreCodes('core.scheduler'))->toBe(['core.scheduler.scheduled']);

    config(['vaulter.schedule.enabled' => false]);
    app()->forgetInstance(Schedule::class);
    expect(coreCodes('core.scheduler'))->toBe(['core.scheduler.not_scheduled']);

    app(Schedule::class)->command('vaulter:relay');
    expect(coreCodes('core.scheduler'))->toBe(['core.scheduler.scheduled']);
});

it('warns about a cache that is not shared across instances', function (): void {
    expect(coreCodes('core.cache'))->toBe(['core.cache.not_shared']);

    config(['cache.default' => 'database', 'cache.stores.database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks']]);
    expect(coreCodes('core.cache'))->toBe(['core.cache.shared']);
});

it('measures the outbox lag per consumer', function (): void {
    expect(coreCodes('core.outbox.lag'))->toBe(['core.outbox.current']);

    insertOutboxRow(['created_at' => now()->subMinutes(30)]);
    $finding = coreFindings('core.outbox.lag')[0];
    expect($finding->code)->toBe('core.outbox.lagging')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence['consumers'])->toBe('webhooks.fanout')
        ->and(coreSeverities('core.outbox.lag', production: true))->toBe(['warning']);

    // The outbox checks are the kernel's: they stay with the webhooks module off.
    WebhookTestHelpers::switchModule($this->app, false);
    expect(app(DoctorRegistry::class)->unmatched(['core.outbox', 'core.webhooks']))->toBe(['core.webhooks']);
});

it('does not count a delivery that is not due yet as lag', function (): void {
    insertOutboxRow(['created_at' => now()->subDay()], ['next_attempt_at' => now()->addHour()]);

    expect(coreCodes('core.outbox.lag'))->toBe(['core.outbox.current']);
});

it('escalates a stuck relay lease in production', function (): void {
    insertOutboxRow([], ['state' => 'leased', 'lease_owner' => 'live', 'lease_generation' => 1, 'lease_expires_at' => now()->subMinutes(2)]);
    expect(coreCodes('core.outbox.leases'))->toBe(['core.outbox.leases_ok']);

    insertOutboxRow([], ['state' => 'leased', 'lease_owner' => 'dead', 'lease_generation' => 1, 'lease_expires_at' => now()->subMinutes(20)]);
    insertOutboxRow([], ['state' => 'done', 'lease_expires_at' => now()->subMinutes(20)]);

    $finding = coreFindings('core.outbox.leases')[0];
    expect($finding->code)->toBe('core.outbox.leases_stuck')
        ->and($finding->severity)->toBe(DoctorSeverity::Warning)
        ->and($finding->evidence['stuck'])->toBe(1)
        ->and(coreSeverities('core.outbox.leases', production: true))->toBe(['error']);
});

it('compares the settle window with the transaction limits of the database', function (): void {
    $codes = coreCodes('core.outbox.settle_window');

    // SQLite serialises writers: there is no limit to compare, the window passes.
    expect($codes)->toHaveCount(1)
        ->and($codes[0])->toBeIn(DB::connection()->getDriverName() === 'sqlite' ? ['core.outbox.settle_window_ok'] : ['core.outbox.settle_window_ok', 'core.outbox.settle_window_short', 'core.outbox.settle_window_unbounded']);

    config(['vaulter.outbox.settle_window_seconds' => 1]);
    if (DB::connection()->getDriverName() === 'mysql') {
        expect(coreCodes('core.outbox.settle_window'))->toBe(['core.outbox.settle_window_short']);
    }
});

it('does not count an endpoint switched off by its owner nor the deliveries parked with it', function (): void {
    $endpoint = DB::table(coreTable('webhook_endpoints'))->insertGetId([
        'url' => 'https://hooks.test/manual', 'events' => '[]', 'signing_secret' => 'x', 'is_active' => false,
        'disabled_at' => now(), 'disabled_reason' => 'manual', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table(coreTable('webhook_deliveries'))->insert([
        'endpoint_id' => $endpoint, 'event' => 'file.uploaded', 'payload' => '{}', 'status' => 'dead', 'attempts' => 2,
        'last_error' => 'endpoint_disabled', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(coreCodes('core.webhooks.deliveries'))->toBe(['core.webhooks.deliveries_ok']);
});

it('counts disabled endpoints and dead deliveries', function (): void {
    expect(coreCodes('core.webhooks.deliveries'))->toBe(['core.webhooks.deliveries_ok']);

    $endpoint = DB::table(coreTable('webhook_endpoints'))->insertGetId([
        'url' => 'https://hooks.test/a', 'events' => '[]', 'signing_secret' => 'x', 'is_active' => false,
        'disabled_at' => now(), 'disabled_reason' => 'failing', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table(coreTable('webhook_deliveries'))->insert([
        'endpoint_id' => $endpoint, 'event' => 'file.uploaded', 'payload' => '{}', 'status' => 'dead', 'attempts' => 10, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $finding = coreFindings('core.webhooks.deliveries')[0];
    expect($finding->code)->toBe('core.webhooks.deliveries_failing')
        ->and($finding->evidence)->toBe(['disabled_endpoints' => 1, 'dead_deliveries' => 1])
        ->and($finding->hint)->toContain('vaulter:webhooks:redeliver');
});

/**
 * An outbox event and its webhooks.fanout delivery row.
 *
 * @param array<string, mixed> $event
 * @param array<string, mixed> $delivery
 */
function insertOutboxRow(array $event, array $delivery = []): void
{
    static $next = 0;
    $next++;
    $row = ['event_id' => 'doctor-event-'.$next, 'scope_kind' => 'tenant', 'type' => 'file.uploaded', 'external' => true, 'payload' => '{}', 'created_at' => now(), ...$event];
    DB::table(coreTable('outbox'))->insert($row);
    DB::table(coreTable('outbox_deliveries'))->insert([
        'event_id' => $row['event_id'], 'consumer_id' => 'webhooks.fanout', 'outbox_seq' => DB::table(coreTable('outbox'))->where('event_id', $row['event_id'])->value('outbox_seq'),
        'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'lease_generation' => 0, 'created_at' => now(), 'updated_at' => now(),
        ...$delivery,
    ]);
}
