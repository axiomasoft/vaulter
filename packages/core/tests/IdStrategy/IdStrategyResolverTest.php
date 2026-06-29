<?php

declare(strict_types=1);

use Vaulter\Contracts\IdStrategy;
use Vaulter\Exceptions\UnknownIdStrategyException;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;

it('resolves each strategy key to its class', function (): void {
    $resolver = app(IdStrategyResolver::class);

    expect($resolver->resolve('bigint'))->toBeInstanceOf(AutoIncrementIds::class)
        ->and($resolver->resolve('ulid'))->toBeInstanceOf(UlidIds::class)
        ->and($resolver->resolve('uuid7'))->toBeInstanceOf(Uuid7Ids::class);
});

it('falls back to the configured default when no key is given', function (): void {
    config()->set('vaulter.ids.default', 'ulid');

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
