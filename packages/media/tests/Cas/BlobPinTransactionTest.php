<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * A pin belongs to the transaction that writes its owner row (D23): outside a VaulterDatabase transaction every
 * mutation is a LogicException and writes nothing. Committed migrations (no wrapping test transaction), so the
 * connection really is at level 0.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

it('refuses pin, unpin and releaseOwner outside a transaction', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('outside'), BlobScope::tenant('1'));
    $pins = app(BlobPins::class);
    expect(app(VaulterDatabase::class)->connection()->transactionLevel())->toBe(0);

    expect(fn () => $pins->pin($blob, CasTestHelpers::OWNER, 'r'))->toThrow(LogicException::class, 'must run inside')
        ->and(fn () => $pins->unpin($blob, CasTestHelpers::OWNER, 'r'))->toThrow(LogicException::class, 'must run inside')
        ->and(fn () => $pins->releaseOwner(CasTestHelpers::OWNER))->toThrow(LogicException::class, 'must run inside')
        ->and(CasTestHelpers::pins($blob))->toBe(0);
});

it('pins inside a transaction and loses the pin with its rolled-back owner transaction', function (): void {
    $blob = app(BlobStore::class)->commit(CasTestHelpers::streamFor('rolled back'), BlobScope::tenant('1'));

    expect(fn () => app(VaulterDatabase::class)->transaction(function () use ($blob): void {
        app(BlobPins::class)->pin($blob, CasTestHelpers::OWNER, 'r');
        throw new RuntimeException('owner row failed');
    }))->toThrow(RuntimeException::class, 'owner row failed')
        ->and(CasTestHelpers::pins($blob))->toBe(0);

    CasTestHelpers::pin($blob, 'r');

    expect(CasTestHelpers::pins($blob))->toBe(1)
        ->and(app(BlobPins::class)->countByOwner())->toBe([CasTestHelpers::OWNER => 1]);
});
