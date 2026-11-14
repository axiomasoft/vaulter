<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Identity;
use Vaulter\Testing\Concerns\WithoutDatabaseSchema;
use Vaulter\Tests\TestCase;

uses(TestCase::class, WithoutDatabaseSchema::class);

it('lets only a key the column can hold reach a query', function (): void {
    $bigint = new class extends Model {};
    $uuid = new class extends Model
    {
        use HasUuids;
    };
    $ulid = new class extends Model
    {
        use HasUlids;
    };

    expect(Identity::fitsKey($bigint, 42))->toBeTrue()
        ->and(Identity::fitsKey($bigint, '42'))->toBeTrue()
        ->and(Identity::fitsKey($bigint, 'no-such-node'))->toBeFalse()
        ->and(Identity::fitsKey($bigint, '01'.str_repeat('9', 19)))->toBeFalse()
        ->and(Identity::fitsKey($uuid, (string) Str::uuid7()))->toBeTrue()
        ->and(Identity::fitsKey($uuid, 'no-such-node'))->toBeFalse()
        ->and(Identity::fitsKey($ulid, (string) Str::ulid()))->toBeTrue()
        ->and(Identity::fitsKey($ulid, 'no-such-node'))->toBeFalse();
});

it('checks the signed bigint boundary without opening a database connection', function (string $driver): void {
    config()->set('database.connections.identity_bounds', ['driver' => $driver, 'database' => ':memory:']);
    $model = (new class extends Model {})->setConnection('identity_bounds');
    $model->getConnection()->setPdo(static fn () => throw new RuntimeException('The key check opened PDO.'));

    expect(Identity::fitsKey($model, '9223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '9223372036854775808'))->toBeFalse()
        ->and(Identity::fitsKey($model, '9999999999999999999'))->toBeFalse()
        ->and(Identity::fitsKey($model, '0009223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '18446744073709551615'))->toBeFalse()
        ->and(Identity::fitsKey($model, '-1'))->toBeFalse()
        ->and(Identity::fitsKey($model, '1e2'))->toBeFalse()
        ->and(Identity::fitsKey($model, "42\n"))->toBeFalse();
})->with(['pgsql', 'sqlite', 'mysql', 'mariadb']);

it('keeps Vaulter unsigned bigint keys on MySQL within the actual column range', function (string $driver): void {
    config()->set('database.connections.identity_bounds', ['driver' => $driver, 'database' => ':memory:']);
    config()->set('vaulter.ids.strategy', 'bigint');
    $model = (new class extends Model
    {
        use HasVaulterKeys;
    })->setConnection('identity_bounds');
    $model->getConnection()->setPdo(static fn () => throw new RuntimeException('The key check opened PDO.'));
    $unsigned = in_array($driver, ['mysql', 'mariadb'], true);

    expect(Identity::fitsKey($model, '9223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '9223372036854775808'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '18446744073709551615'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '00018446744073709551615'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '18446744073709551616'))->toBeFalse()
        ->and(Identity::fitsKey($model, str_repeat('9', 200)))->toBeFalse();
})->with(['pgsql', 'sqlite', 'mysql', 'mariadb']);
