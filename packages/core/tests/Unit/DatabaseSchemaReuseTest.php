<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Facades\Vaulter;
use Vaulter\Tests\Fixtures\DatabaseSchemaProbe;

beforeEach(function (): void {
    RefreshDatabaseState::$migrated = false;
    DatabaseSchemaProbe::$migrations = 0;
});

it('reuses the migrated server schema while rolling back each test fixture', function (): void {
    (new DatabaseSchemaProbe('exercise'))->exercise(function (): void {
        Vaulter::drive(DriveTestHelpers::host())->model();
        expect(DB::table('v_drives')->count())->toBe(1);
    });

    (new DatabaseSchemaProbe('exercise'))->exercise(function (): void {
        expect(DB::table('v_drives')->count())->toBe(0)
            ->and(Schema::hasTable('v_nodes'))->toBeTrue();
    });

    expect(DatabaseSchemaProbe::$migrations)->toBe(1);
})->skip(fn (): bool => !in_array(env('DB_CONNECTION'), ['pgsql', 'mysql', 'mariadb'], true), 'Schema reuse applies to server databases.');

it('rebuilds on prefix and id strategy changes and restores the default schema', function (): void {
    foreach ([['v_', 'bigint'], ['custom_', 'bigint'], ['custom_', 'ulid'], ['v_', 'bigint']] as [$prefix, $strategy]) {
        $probe = new DatabaseSchemaProbe('exercise');
        $probe->prefix = $prefix;
        $probe->strategy = $strategy;
        $probe->exercise(function () use ($prefix, $strategy): void {
            $types = DB::connection()->getDriverName() === 'pgsql'
                ? ['bigint' => 'int8', 'ulid' => 'bpchar']
                : ['bigint' => 'bigint', 'ulid' => 'char'];

            expect(Schema::hasTable($prefix.'nodes'))->toBeTrue()
                ->and(Schema::hasTable(($prefix === 'v_' ? 'custom_' : 'v_').'nodes'))->toBeFalse()
                ->and(Schema::getColumnType($prefix.'nodes', 'id'))->toBe($types[$strategy]);
        });
    }

    expect(DatabaseSchemaProbe::$migrations)->toBe(4);
})->skip(fn (): bool => !in_array(env('DB_CONNECTION'), ['pgsql', 'mysql', 'mariadb'], true), 'Schema reuse applies to server databases.');

it('keeps additional provider migrations reusable and removes them on returning to the base schema', function (): void {
    foreach ([true, true, false] as $permissions) {
        $probe = new DatabaseSchemaProbe('exercise');
        $probe->permissions = $permissions;
        $probe->exercise(function () use ($permissions): void {
            expect(Schema::hasTable('users'))->toBe($permissions)
                ->and(Schema::hasTable('permissions'))->toBe($permissions)
                ->and(Schema::hasTable('v_nodes'))->toBeTrue();
        });
    }

    expect(DatabaseSchemaProbe::$migrations)->toBe(2);
})->skip(fn (): bool => !in_array(env('DB_CONNECTION'), ['pgsql', 'mysql', 'mariadb'], true), 'Schema reuse applies to server databases.');
