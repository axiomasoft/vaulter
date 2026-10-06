<?php

declare(strict_types=1);

use Vaulter\Data\Drive\MoveOptions;
use Vaulter\Data\Drive\RevisionOptions;
use Vaulter\Data\Drive\UpdateNodeOptions;

it('preserves optional signed int64 sequence bounds at constructor and fluent boundaries', function (string $class): void {
    expect(PHP_INT_SIZE)->toBe(8);
    foreach ([null, 0, 7, PHP_INT_MAX] as $sequence) {
        expect((new $class(expectedSequence: $sequence))->expectedSequence)->toBe($sequence)
            ->and($class::make()->expectedSequence($sequence)->expectedSequence)->toBe($sequence);
    }
    expect(fn () => new $class(expectedSequence: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $class::make()->expectedSequence(-1))->toThrow(InvalidArgumentException::class);
    foreach ([9223372036854775808.0, '9223372036854775808', '7', 1.5] as $invalid) {
        expect(fn () => new $class(expectedSequence: $invalid))->toThrow(TypeError::class)
            ->and(fn () => $class::make()->expectedSequence($invalid))->toThrow(TypeError::class);
    }
})->with([MoveOptions::class, RevisionOptions::class, UpdateNodeOptions::class]);
