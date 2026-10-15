<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Vaulter\Support\Identity;

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
