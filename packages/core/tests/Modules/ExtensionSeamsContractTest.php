<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Configuration\ScheduleFrequency;
use Vaulter\Modules\GuardsRemoval;
use Vaulter\Modules\OwnsSchema;
use Vaulter\Modules\RemovalGuard;
use Vaulter\Providers\SchedulingRegistrar;
use Vaulter\Schema\ModuleSchema;
use Vaulter\Schema\ValueObjects\KeyColumnExpectation;
use Vaulter\Support\LoadTier;
use Vaulter\Tests\TestCase;

uses(TestCase::class);

// The stable extension seams of the kernel: a module outside the kernel implements the schema and removal
// hooks and calls the scheduling and tier seams. The fixtures below are the whole module; nothing above the kernel
// is loaded.

final class SeamFixtureSchema implements ModuleSchema
{
    public const string TABLE = 'seam_fixture_items';

    public function entities(): array
    {
        return ['items'];
    }

    public function keyColumns(): array
    {
        return [new KeyColumnExpectation(self::TABLE, 'id', 'REF'), new KeyColumnExpectation(self::TABLE, 'name', 'STRING', 64)];
    }

    public function create(string $entity): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);
        });
    }

    public function drop(string $entity): void
    {
        Schema::dropIfExists(self::TABLE);
    }
}

final class SeamFixtureGuard implements RemovalGuard
{
    public function blockers(string $module): array
    {
        if (!Schema::hasTable(SeamFixtureSchema::TABLE)) {
            return [];
        }

        $pending = DB::table(SeamFixtureSchema::TABLE)->count();

        return $pending > 0 ? ['item(s) not settled yet' => $pending] : [];
    }
}

final class SeamFixtureManifest implements GuardsRemoval, OwnsSchema
{
    public static function ownedSchema(): string
    {
        return SeamFixtureSchema::class;
    }

    public static function removalGuard(): string
    {
        return SeamFixtureGuard::class;
    }
}

afterEach(function (): void {
    Schema::dropIfExists(SeamFixtureSchema::TABLE);
    Context::forget(LoadTier::REQUEST_ATTRIBUTE);
});

it('creates and drops the tables of a module schema read from its manifest', function (): void {
    expect(is_subclass_of(SeamFixtureManifest::class, OwnsSchema::class))->toBeTrue();

    $schema = $this->app->make(SeamFixtureManifest::ownedSchema());
    expect($schema)->toBeInstanceOf(ModuleSchema::class);

    foreach ($schema->entities() as $entity) {
        $schema->create($entity);
    }
    expect(Schema::hasTable(SeamFixtureSchema::TABLE))->toBeTrue()
        ->and(Schema::hasColumns(SeamFixtureSchema::TABLE, ['id', 'name']))->toBeTrue();

    foreach (array_reverse($schema->entities()) as $entity) {
        $schema->drop($entity);
    }
    expect(Schema::hasTable(SeamFixtureSchema::TABLE))->toBeFalse();
});

it('describes the key columns with the existing expectation value', function (): void {
    [$id, $name] = (new SeamFixtureSchema)->keyColumns();

    expect([$id->table, $id->column, $id->kind, $id->length])->toBe([SeamFixtureSchema::TABLE, 'id', 'REF', null])
        ->and([$name->kind, $name->length])->toBe(['STRING', 64]);
});

it('refuses the removal while the guard reports blockers and reads a missing table as empty', function (): void {
    expect(is_subclass_of(SeamFixtureManifest::class, GuardsRemoval::class))->toBeTrue();

    $guard = $this->app->make(SeamFixtureManifest::removalGuard());
    expect($guard)->toBeInstanceOf(RemovalGuard::class)
        ->and($guard->blockers('seam-fixture'))->toBe([]);

    (new SeamFixtureSchema)->create('items');
    expect($guard->blockers('seam-fixture'))->toBe([]);

    DB::table(SeamFixtureSchema::TABLE)->insert([['name' => 'a'], ['name' => 'b']]);
    expect($guard->blockers('seam-fixture'))->toBe(['item(s) not settled yet' => 2]);

    DB::table(SeamFixtureSchema::TABLE)->delete();
    expect($guard->blockers('seam-fixture'))->toBe([]);
});

it('schedules a task with its cron, overlap expiry and single-server guards', function (): void {
    $frequency = ScheduleFrequency::parse('seam_fixture', 'hourlyAt:15');
    expect($frequency)->not->toBeNull();

    $event = SchedulingRegistrar::task(new Schedule, 'vaulter:seam-fixture', $frequency, SchedulingRegistrar::RECOVERY_EXPIRY, ['--quiet']);

    expect($event->expression)->toBe('15 * * * *')
        ->and($event->description)->toBe('vaulter:seam-fixture')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(SchedulingRegistrar::RECOVERY_EXPIRY)
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->command)->toContain('vaulter:seam-fixture')
        ->and(SchedulingRegistrar::RECOVERY_EXPIRY)->toBe(10)
        ->and(SchedulingRegistrar::DEFAULT_EXPIRY)->toBe(120);
});

it('reads the tier label with its default, normalization and request before context', function (): void {
    expect(LoadTier::label())->toBe('S0');

    Context::add(LoadTier::REQUEST_ATTRIBUTE, 'l');
    expect(LoadTier::label())->toBe('L');

    Context::add(LoadTier::REQUEST_ATTRIBUTE, 'XL');
    expect(LoadTier::label())->toBe('S0');

    Context::add(LoadTier::REQUEST_ATTRIBUTE, 'L');
    $this->app->make(Request::class)->attributes->set(LoadTier::REQUEST_ATTRIBUTE, 'm');
    expect(LoadTier::label())->toBe('M');

    $this->app->make(Request::class)->attributes->remove(LoadTier::REQUEST_ATTRIBUTE);
    LoadTier::set('s');
    expect(LoadTier::label())->toBe('S');
});
