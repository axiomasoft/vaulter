<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Support\UtcDateTimeCast;

it('reads a stored timestamp as a UTC immutable date', function (): void {
    $cast = new UtcDateTimeCast;

    $value = $cast->get(new Blob, 'quarantined_at', '2026-10-16 12:30:00', []);

    expect($value)->toBeInstanceOf(CarbonImmutable::class)
        ->and($value->getTimezone()->getName())->toBe('UTC')
        ->and($value->format('Y-m-d H:i:s'))->toBe('2026-10-16 12:30:00')
        ->and($cast->get(new Blob, 'quarantined_at', null, []))->toBeNull();
});

it('writes any date in UTC and keeps null', function (): void {
    $cast = new UtcDateTimeCast;
    $local = CarbonImmutable::parse('2026-10-16 15:30:00', 'Europe/Moscow');

    expect($cast->set(new Blob, 'quarantined_at', $local, []))->toBe('2026-10-16 12:30:00')
        ->and($cast->set(new Blob, 'quarantined_at', null, []))->toBeNull();
});

it('refuses a value that is neither a string nor a date', function (): void {
    expect(fn (): ?CarbonImmutable => (new UtcDateTimeCast)->get(new Blob, 'quarantined_at', 12, []))
        ->toThrow(InvalidArgumentException::class, 'UTC date');
});

it('is the cast of the quarantine timestamp of the blob model', function (): void {
    expect((new Blob)->getCasts()['quarantined_at'])->toBe(UtcDateTimeCast::class);
});
