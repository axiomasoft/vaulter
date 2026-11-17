<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\IdStrategyResolver;
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
    $model->getConnection()->setPdo(static fn (): never => throw new RuntimeException('The key check opened PDO.'));

    expect(Identity::fitsKey($model, '9223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '9223372036854775808'))->toBeFalse()
        ->and(Identity::fitsKey($model, '9999999999999999999'))->toBeFalse()
        ->and(Identity::fitsKey($model, '0009223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '18446744073709551615'))->toBeFalse()
        ->and(Identity::fitsKey($model, '-1'))->toBeFalse()
        ->and(Identity::fitsKey($model, '1e2'))->toBeFalse()
        ->and(Identity::fitsKey($model, "42\n"))->toBeFalse();
})->with(['pgsql', 'sqlite', 'mysql', 'mariadb']);

it('keeps bigint primary keys lossless while retaining native integers when they fit', function (int|string $stored, int|string $expected): void {
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = new class extends Model
    {
        use HasVaulterKeys;

        protected function casts(): array
        {
            return ['id' => 'integer', 'name' => 'string'];
        }
    };
    $model->setRawAttributes(['id' => $stored, 'name' => 42], sync: true);

    expect($model->getKey())->toBe($expected)
        ->and($model->id)->toBe($expected)
        ->and($model->toArray()['id'])->toBe($expected)
        ->and($model->name)->toBe('42')
        ->and($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeTrue();
})->with([
    'native integer' => [42, 42],
    'numeric string' => ['42', 42],
    'leading zeros' => ['00042', 42],
    'signed minimum' => ['-9223372036854775808', PHP_INT_MIN],
    'signed maximum' => ['9223372036854775807', PHP_INT_MAX],
    'first unsigned-only value' => ['9223372036854775808', '9223372036854775808'],
    'unsigned maximum' => ['18446744073709551615', '18446744073709551615'],
]);

it('uses the final native table key without losing unrelated model casts', function (): void {
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = new #[Table(key: 'probe_id', keyType: 'int', incrementing: true)] class extends Model
    {
        use HasVaulterKeys;

        protected function casts(): array
        {
            return ['active' => 'boolean'];
        }
    };
    $model->setRawAttributes(['probe_id' => '18446744073709551615', 'active' => 1], sync: true);

    expect($model->getKey())->toBe('18446744073709551615')
        ->and($model->getKeyName())->toBe('probe_id')
        ->and($model->getKeyType())->toBe('string')
        ->and($model->active)->toBeTrue();
});

it('keeps numeric admission for an alias of the built-in bigint strategy', function (): void {
    config()->set('vaulter.ids.strategies.counter', AutoIncrementIds::class);
    config()->set('vaulter.ids.strategy', 'counter');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    config()->set('database.connections.identity_bounds', ['driver' => 'mysql', 'database' => ':memory:']);
    $model = (new class extends Model
    {
        use HasVaulterKeys;
    })->setConnection('identity_bounds');
    $model->getConnection()->setPdo(static fn (): never => throw new RuntimeException('The key check opened PDO.'));

    expect(Identity::fitsKey($model, '18446744073709551615'))->toBeTrue()
        ->and(Identity::fitsKey($model, '18446744073709551616'))->toBeFalse()
        ->and(Identity::fitsKey($model, 'no-such-node'))->toBeFalse();
});

it('preserves a configured model that explicitly declares opaque string keys', function (string $key): void {
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = new class extends Model
    {
        use HasVaulterKeys;

        protected $primaryKey = 'host_key';

        protected $keyType = 'string';

        public $incrementing = false;
    };
    $model->setRawAttributes(['host_key' => $key], sync: true);

    expect($model->getKey())->toBe($key)
        ->and($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and(Identity::fitsKey($model, $key))->toBeTrue();
})->with(['node-A', '00000000-0000-7000-8000-000000000003', '00042']);

it('preserves opaque keys declared by native table metadata after trait initialization', function (): void {
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = new #[Table(key: 'host_key', keyType: 'string', incrementing: false)] class extends Model
    {
        use HasVaulterKeys;
    };
    $model->setRawAttributes(['host_key' => 'opaque-key'], sync: true);

    expect($model->getKey())->toBe('opaque-key')
        ->and($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and(Identity::fitsKey($model, 'opaque-key'))->toBeTrue();
});

it('preserves native unique string key traits alongside the bigint strategy', function (string $kind): void {
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = $kind === 'uuid' ? new class extends Model
    {
        use HasUuids;
        use HasVaulterKeys;
    } : new class extends Model
    {
        use HasUlids;
        use HasVaulterKeys;
    };
    $key = $kind === 'uuid' ? (string) Str::uuid7() : (string) Str::ulid();
    $model->setRawAttributes(['id' => $key], sync: true);

    expect($model->getKey())->toBe($key)
        ->and($model->getKeyType())->toBe('string')
        ->and($model->getIncrementing())->toBeFalse()
        ->and(Identity::fitsKey($model, $key))->toBeTrue();
})->with(['uuid', 'ulid']);

it('keeps Vaulter unsigned bigint keys on MySQL within the actual column range', function (string $driver): void {
    config()->set('database.connections.identity_bounds', ['driver' => $driver, 'database' => ':memory:']);
    config()->set('vaulter.ids.strategy', 'bigint');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = (new class extends Model
    {
        use HasVaulterKeys;
    })->setConnection('identity_bounds');
    $model->getConnection()->setPdo(static fn (): never => throw new RuntimeException('The key check opened PDO.'));
    $unsigned = in_array($driver, ['mysql', 'mariadb'], true);

    expect(Identity::fitsKey($model, '9223372036854775807'))->toBeTrue()
        ->and(Identity::fitsKey($model, '9223372036854775808'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '18446744073709551615'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '00018446744073709551615'))->toBe($unsigned)
        ->and(Identity::fitsKey($model, '18446744073709551616'))->toBeFalse()
        ->and(Identity::fitsKey($model, str_repeat('9', 200)))->toBeFalse();
})->with(['pgsql', 'sqlite', 'mysql', 'mariadb']);
