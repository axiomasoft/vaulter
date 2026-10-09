<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Tests\Upgrade\LoadsV02Fixture;

/**
 * 2026_10_10_000002_webhook_fanout on a 0.2 database: rows the old relay marked
 * 'dispatched' become completed + legacy_fanout with fanned_out_at = dispatched_at,
 * deliveries get the event id of their payload and UNIQUE(event_id, endpoint_id), and
 * endpoints get tenant_id/drive_id (null for every existing endpoint).
 */
uses(LoadsV02Fixture::class);

afterEach(function (): void {
    $db = app(VaulterDatabase::class)->connection();
    if ($db->getDriverName() === 'pgsql' && str_ends_with($db->getDatabaseName(), '_test') && $db->transactionLevel() === 0) {
        $db->getSchemaBuilder()->dropAllTables();
    }

    RefreshDatabaseState::$migrated = false;
});

function webhookFanoutMigration(): object
{
    return require __DIR__.'/../../database/migrations/2026_10_10_000002_webhook_fanout.php';
}

it('settles legacy dispatched rows as completed legacy fan-outs and keys deliveries by event', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);

    $db = app(VaulterDatabase::class)->connection();
    $dispatched = $db->table('v_outbox')->where('status', 'dispatched')->orderBy('id')->get(['event_id', 'dispatched_at']);
    $pending = $db->table('v_outbox')->where('status', 'pending')->count();
    $payloadIds = $db->table('v_webhook_deliveries')->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): string => json_decode($payload, true)['event_id'])->all();
    expect($dispatched)->not->toBeEmpty()->and($payloadIds)->not->toBeEmpty();

    $this->artisan('migrate', ['--force' => true])->assertSuccessful();

    $schema = $db->getSchemaBuilder();
    $outbox = app(DatabaseConfig::class)->table('outbox');
    $deliveries = app(DatabaseConfig::class)->table('webhook_deliveries');
    $endpoints = app(DatabaseConfig::class)->table('webhook_endpoints');

    $settled = $db->table($outbox)->whereIn('event_id', $dispatched->pluck('event_id'))->orderBy('id')->get();
    expect($settled)->toHaveCount($dispatched->count())
        ->and($settled->pluck('status')->unique()->all())->toBe(['completed'])
        ->and($settled->pluck('legacy_fanout')->unique()->all())->toBe([true])
        ->and($settled->pluck('fanned_out_at')->all())->toBe($dispatched->pluck('dispatched_at')->all())
        ->and($db->table($outbox)->where('status', 'pending')->count())->toBe($pending)
        ->and($db->table($outbox)->whereNotIn('status', ['pending', 'completed'])->count())->toBe(0)
        ->and($schema->hasColumn($outbox, 'dispatched_at'))->toBeFalse()
        ->and(collect($schema->getIndexes($outbox))->firstWhere('name', $outbox.'_prune_idx')['columns'])->toBe(['status', 'fanned_out_at']);

    expect($db->table($deliveries)->orderBy('id')->pluck('event_id')->all())->toBe($payloadIds)
        ->and($schema->hasIndex($deliveries, $deliveries.'_event_endpoint_unique'))->toBeTrue();

    expect($schema->hasColumns($endpoints, ['tenant_id', 'drive_id']))->toBeTrue()
        ->and($db->table($endpoints)->whereNotNull('tenant_id')->orWhereNotNull('drive_id')->count())->toBe(0)
        ->and(collect($schema->getForeignKeys($endpoints))->pluck('columns')->all())->toContain(['drive_id']);

    // The fixture's legacy rows without deliveries are listed for the operator.
    Artisan::call('vaulter:webhooks:legacy-fanout');
    $withDeliveries = $db->table($deliveries)->distinct()->count('event_id');
    expect(Artisan::output())->toContain(sprintf('%d legacy outbox rows without a provable fan-out', $dispatched->count() - $settled->whereIn('event_id', $payloadIds)->count()))
        ->and($withDeliveries)->toBeGreaterThan(0);

    webhookFanoutMigration()->down();
    expect($schema->hasColumn($outbox, 'dispatched_at'))->toBeTrue()
        ->and($schema->hasColumn($outbox, 'claim_token'))->toBeFalse()
        ->and($db->table($outbox)->where('status', 'dispatched')->count())->toBe($dispatched->count())
        ->and($schema->hasColumn($deliveries, 'event_id'))->toBeFalse()
        ->and($schema->hasColumn($endpoints, 'drive_id'))->toBeFalse();

    webhookFanoutMigration()->up();
    expect($db->table($outbox)->where('status', 'completed')->where('legacy_fanout', true)->count())->toBe($dispatched->count())
        ->and($schema->hasIndex($deliveries, $deliveries.'_event_endpoint_unique'))->toBeTrue();
})->with(['bigint', 'ulid']);

it('keeps the earliest delivery of a repeated pair keyed and leaves the others without event id', function (string $strategy): void {
    if (app(VaulterDatabase::class)->driver() !== 'pgsql') {
        $this->markTestSkipped('Historical fixtures require PostgreSQL.');
    }
    $this->loadV02Fixture($strategy);
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    webhookFanoutMigration()->down();

    $db = app(VaulterDatabase::class)->connection();
    $deliveries = app(DatabaseConfig::class)->table('webhook_deliveries');
    $original = $db->table($deliveries)->orderBy('id')->first();
    $copy = (array) $original;
    unset($copy['id']);
    if ($strategy === 'ulid') {
        $copy['id'] = (string) Str::ulid();
    }
    $copy['created_at'] = now()->addDay();
    $db->table($deliveries)->insert($copy);

    webhookFanoutMigration()->up();

    $eventId = json_decode($original->payload, true)['event_id'];
    $rows = $db->table($deliveries)->where('endpoint_id', $original->endpoint_id)
        ->whereRaw('payload::text = ?', [$original->payload])->orderBy('created_at')->get(['id', 'event_id']);
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->id)->toBe($original->id)
        ->and($rows[0]->event_id)->toBe($eventId)
        ->and($rows[1]->event_id)->toBeNull();
})->with(['bigint', 'ulid']);
