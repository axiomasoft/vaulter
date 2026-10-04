<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;

/**
 * Reads the column emitted by a strategy method off a fresh Blueprint, without
 * touching a database — the ColumnDefinition carries the declared type/length.
 */
function columnFor(Closure $define): ColumnDefinition
{
    $table = new Blueprint(DB::connection(), 'probe');
    $define($table);

    $columns = $table->getColumns();

    return $columns[array_key_last($columns)];
}

it('emits a bigIncrements primary key, integer cast, no concern, no generated id for bigint', function (): void {
    $strategy = new AutoIncrementIds;

    $key = columnFor(fn (Blueprint $t) => $strategy->key($t));
    $reference = columnFor(fn (Blueprint $t) => $strategy->reference($t, 'parent_id', nullable: true));
    $actor = columnFor(fn (Blueprint $t) => $strategy->actor($t, 'created_by'));

    expect($key->get('type'))->toBe('bigInteger')
        ->and($key->get('autoIncrement'))->toBeTrue()
        ->and($reference->get('type'))->toBe('bigInteger')
        ->and($reference->get('unsigned'))->toBeTrue()
        ->and($reference->get('nullable'))->toBeTrue()
        ->and($actor->get('type'))->toBe('bigInteger')
        ->and($actor->get('unsigned'))->toBeTrue()
        ->and($strategy->cast())->toBe('integer')
        ->and($strategy->modelConcern())->toBeNull()
        ->and($strategy->newId())->toBeNull();
});

it('emits char(26) keys, string cast, HasUlids concern and a ulid for ulid', function (): void {
    $strategy = new UlidIds;

    $key = columnFor(fn (Blueprint $t) => $strategy->key($t));
    $reference = columnFor(fn (Blueprint $t) => $strategy->reference($t, 'drive_id'));
    $actor = columnFor(fn (Blueprint $t) => $strategy->actor($t, 'created_by'));

    expect($key->get('type'))->toBe('char')
        ->and($key->get('length'))->toBe(26)
        ->and($reference->get('type'))->toBe('char')
        ->and($reference->get('length'))->toBe(26)
        ->and($actor->get('type'))->toBe('char')
        ->and($actor->get('length'))->toBe(26)
        ->and($strategy->cast())->toBe('string')
        ->and($strategy->modelConcern())->toBe(HasUlids::class)
        ->and($strategy->newId())->toBeString()
        ->and(strlen($strategy->newId()))->toBe(26);
});

it('emits uuid keys, string cast, HasUuids concern and a uuid7 for uuid7', function (): void {
    $strategy = new Uuid7Ids;

    $key = columnFor(fn (Blueprint $t) => $strategy->key($t));
    $reference = columnFor(fn (Blueprint $t) => $strategy->reference($t, 'drive_id'));
    $actor = columnFor(fn (Blueprint $t) => $strategy->actor($t, 'created_by'));

    expect($key->get('type'))->toBe('uuid')
        ->and($reference->get('type'))->toBe('uuid')
        ->and($actor->get('type'))->toBe('uuid')
        ->and($strategy->cast())->toBe('string')
        ->and($strategy->modelConcern())->toBe(HasUuids::class)
        ->and($strategy->newId())->toBeString()
        ->and($strategy->newId())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
});
