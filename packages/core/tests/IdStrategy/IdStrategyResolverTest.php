<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Vaulter\Configuration\IdsOptions;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Contracts\IdStrategy;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Schema\UnknownIdStrategyException;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Support\Identity;

it('resolves each strategy key to its class', function (): void {
    $resolver = app(IdStrategyResolver::class);

    expect($resolver->resolve('bigint'))->toBeInstanceOf(AutoIncrementIds::class)
        ->and($resolver->resolve('ulid'))->toBeInstanceOf(UlidIds::class)
        ->and($resolver->resolve('uuid7'))->toBeInstanceOf(Uuid7Ids::class);
});

it('rejects a non-uuid for a Vaulter uuid7 model that does not use HasUuids', function (): void {
    config()->set('vaulter.ids.strategy', 'uuid7');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);
    $model = new class extends Model
    {
        use HasVaulterKeys;
    };

    expect(Identity::fitsKey($model, (string) Str::uuid7()))->toBeTrue()
        ->and(Identity::fitsKey($model, 'no-such-node'))->toBeFalse();
});

it('falls back to the configured default when no key is given', function (): void {
    config()->set('vaulter.ids.strategy', 'ulid');
    app()->forgetInstance(VaulterOptions::class);
    app()->forgetInstance(IdsOptions::class);
    app()->forgetInstance(IdStrategyResolver::class);

    expect(app(IdStrategyResolver::class)->resolve())->toBeInstanceOf(UlidIds::class);
});

it('caches the resolved instance per key (singleton)', function (): void {
    $resolver = app(IdStrategyResolver::class);

    expect($resolver->resolve('ulid'))->toBe($resolver->resolve('ulid'))
        ->and(app(IdStrategyResolver::class))->toBe($resolver);
});

it('throws a typed exception on an unknown strategy key', function (): void {
    expect(fn () => app(IdStrategyResolver::class)->resolve('snowflake'))
        ->toThrow(UnknownIdStrategyException::class, 'Unknown Vaulter id strategy [snowflake]');
});

it('returns an IdStrategy contract instance', function (): void {
    expect(app(IdStrategyResolver::class)->resolve('bigint'))->toBeInstanceOf(IdStrategy::class);
});

final class BigintIdentityProbe extends Model
{
    use HasVaulterKeys;

    protected $table = 'uint64_identity_probe';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['id' => 'integer', 'parent_id' => (new AutoIncrementIds)->cast(), 'active' => 'boolean'];
    }

    public function parentProbe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}

function withBigintIdentityProbe(Closure $assert): void
{
    $db = app(VaulterDatabase::class)->connection();
    $sql = match ($db->getDriverName()) {
        'mysql', 'mariadb' => 'CREATE TEMPORARY TABLE uint64_identity_probe (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id BIGINT UNSIGNED NULL, active BOOLEAN NOT NULL DEFAULT TRUE, name VARCHAR(255) NOT NULL) AUTO_INCREMENT=9223372036854775808',
        'pgsql' => 'CREATE TEMPORARY TABLE uint64_identity_probe (id BIGSERIAL PRIMARY KEY, parent_id BIGINT NULL, active BOOLEAN NOT NULL DEFAULT TRUE, name VARCHAR(255) NOT NULL)',
        default => 'CREATE TEMPORARY TABLE uint64_identity_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NULL, active BOOLEAN NOT NULL DEFAULT TRUE, name VARCHAR(255) NOT NULL)',
    };
    $db->statement($sql);

    try {
        $assert($db, in_array($db->getDriverName(), ['mysql', 'mariadb'], true));
    } finally {
        $db->statement(in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'DROP TEMPORARY TABLE uint64_identity_probe' : 'DROP TABLE uint64_identity_probe');
    }
}

it('keeps stored bigint identity through array queries, relations and model writes', function (): void {
    withBigintIdentityProbe(function ($db, bool $unsigned): void {
        $target = $unsigned ? '18446744073709551615' : '9223372036854775807';
        $expected = $unsigned ? $target : PHP_INT_MAX;
        $rows = [
            ['id' => '7', 'parent_id' => $target, 'active' => true, 'name' => 'child'],
            ['id' => '9223372036854775807', 'parent_id' => null, 'active' => true, 'name' => 'signed limit'],
        ];
        if ($unsigned) {
            $rows[] = ['id' => $target, 'parent_id' => null, 'active' => true, 'name' => 'unsigned limit'];
        }
        $db->table('uint64_identity_probe')->insert($rows);
        $model = BigintIdentityProbe::query()->findOrFail($target);

        expect($model->getKey())->toBe($expected)
            ->and(BigintIdentityProbe::query()->whereKey([$target])->sole()->getKey())->toBe($expected)
            ->and(BigintIdentityProbe::query()->whereKeyNot([$target])->whereKey(7)->exists())->toBeTrue()
            ->and(BigintIdentityProbe::query()->with('parentProbe')->findOrFail(7)->parentProbe->getKey())->toBe($expected)
            ->and($model->active)->toBeTrue();
        if ($unsigned) {
            expect(BigintIdentityProbe::query()->whereKeyNot([$target])->whereKey(PHP_INT_MAX)->exists())->toBeTrue();
        }
        $model->name = 'changed';
        $model->save();
        $model->refresh();
        expect($model->getKey())->toBe($expected)
            ->and($db->table('uint64_identity_probe')->where('id', $target)->value('name'))->toBe('changed');
        if ($unsigned) {
            expect($db->table('uint64_identity_probe')->where('id', PHP_INT_MAX)->value('name'))->toBe('signed limit');
        }
    });
});

it('keeps the database-assigned bigint key before created observers and refresh', function (): void {
    withBigintIdentityProbe(function ($db, bool $unsigned): void {
        $seen = null;
        BigintIdentityProbe::created(function (BigintIdentityProbe $model) use (&$seen): void {
            $seen = $model->getKey();
        });
        $expected = $unsigned ? '9223372036854775808' : 1;
        $model = BigintIdentityProbe::query()->create(['name' => 'automatic', 'active' => true]);

        expect($model->getKey())->toBe($expected)
            ->and($seen)->toBe($expected)
            ->and((string) $model->getRawOriginal('id'))->toBe((string) $expected)
            ->and($db->table('uint64_identity_probe')->where('id', $expected)->value('name'))->toBe('automatic')
            ->and($model->refresh()->getKey())->toBe($expected);
    });
});
