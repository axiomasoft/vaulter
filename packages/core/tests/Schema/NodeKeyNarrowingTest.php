<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

it('preserves string keys and base filters in a large escaped key predicate (R27)', function (): void {
    Schema::create('r27_string_keys', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->integer('position');
    });

    $keys = ["a') OR 1=1 --", "quote'back\\slash", '9007199254740993', '01ARZ3NDEK0000000000000001'];
    foreach ([...$keys, 'outside'] as $key) {
        DB::table('r27_string_keys')->insert(['id' => $key, 'position' => 1]);
    }
    DB::table('r27_string_keys')->insert(['id' => 'other-position', 'position' => 2]);

    $model = (new Node)->setTable('r27_string_keys')->setKeyType('string');
    $query = $model->newQuery()->where('position', 1);
    // Exercise the common private predicate also used by sharedWith and anchor
    // loading, independently of the candidate-set producer and its key format.
    $method = new ReflectionMethod(NodeReadRepository::class, 'whereKeyInBounded');
    $ids = [...$keys, 'other-position', ...array_map(static fn (int $i): string => 'missing-'.$i, range(1, 901))];
    $method->invoke(app(NodeReadRepository::class), $query, $ids);

    expect($query->toBase()->pluck('id')->all())->toEqualCanonicalizing($keys)
        ->and($query->getBindings())->toBe([1]);
});

it('keeps empty and small key sets exact at the binding boundary (R27)', function (int $size): void {
    $method = new ReflectionMethod(NodeReadRepository::class, 'whereKeyInBounded');
    $query = Node::query();
    $ids = $size === 0 ? [] : range(1, $size);
    $method->invoke(app(NodeReadRepository::class), $query, $ids);

    expect($query->count())->toBe(0)
        ->and(count($query->getBindings()))->toBeLessThanOrEqual(900);
})->with([0, 900, 901]);
