<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Media\Cas\BlobPinStore;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\ValueObjects\Cas\BlobScope;

/*
 * The pin protocol (D23, P2): a committed blob is pinned; a tombstoned (or pending) one only while its object exists
 * and is then committed again; a deleting or deleted one never. A refusal is blob_unavailable and a caller that holds
 * the bytes recovers by committing them again.
 */

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

function pinStatusBlob(string $bytes): Blob
{
    return app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), BlobScope::tenant('7'));
}

/** @return list<object> */
function pinStatusRows(): array
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseConfig::class)->table('blob_references'))
        ->orderBy('reference_key')->get()->all();
}

it('pins a committed blob once and treats a repeated pin as a no-op', function (): void {
    $blob = pinStatusBlob('committed bytes');

    CasTestHelpers::pin($blob, 'r1');
    CasTestHelpers::pin($blob, 'r1');

    $rows = pinStatusRows();
    expect($rows)->toHaveCount(1)
        ->and((string) $rows[0]->blob_id)->toBe((string) $blob->getKey())
        ->and($rows[0]->owner_type)->toBe(CasTestHelpers::OWNER)
        ->and($rows[0]->reference_key)->toBe('r1')
        ->and($rows[0]->created_at)->not->toBeNull()
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});

it('keeps one pin per owner reference and several references per blob', function (): void {
    $blob = pinStatusBlob('shared bytes');

    CasTestHelpers::pin($blob, 'r1');
    CasTestHelpers::pin($blob, 'r2');
    CasTestHelpers::pin($blob, 'r1', 'other.owner');

    expect(CasTestHelpers::pins($blob))->toBe(3)
        ->and(app(BlobPins::class)->countByOwner())->toBe(['other.owner' => 1, CasTestHelpers::OWNER => 2]);
});

it('pins a tombstoned blob whose object exists and commits it again', function (BlobStatus $status): void {
    $blob = pinStatusBlob('tombstoned with bytes');
    $blob->forceFill(['status' => $status, 'delete_after_at' => now()->subMinute()])->save();

    CasTestHelpers::pin($blob, 'revived');

    $fresh = $blob->fresh();
    expect($fresh->status)->toBe(BlobStatus::Committed)
        ->and($fresh->delete_after_at)->toBeNull()
        ->and($fresh->committed_at)->not->toBeNull()
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    Storage::disk('local')->assertExists($blob->storage_key);
})->with([BlobStatus::Tombstoned, BlobStatus::Pending]);

it('refuses a tombstoned or pending blob whose object is gone and writes nothing', function (BlobStatus $status): void {
    $blob = pinStatusBlob('tombstoned without bytes');
    $blob->forceFill(['status' => $status, 'delete_after_at' => now()->subMinute()])->save();
    Storage::disk('local')->delete($blob->storage_key);

    expect(fn () => CasTestHelpers::pin($blob, 'late'))->toThrow(BlobUnavailableException::class)
        ->and(CasTestHelpers::pins($blob))->toBe(0)
        ->and($blob->fresh()->status)->toBe($status);
})->with([BlobStatus::Tombstoned, BlobStatus::Pending]);

it('always refuses a deleting or deleted blob, even with its object still on disk', function (BlobStatus $status): void {
    $blob = pinStatusBlob('being swept');
    $blob->forceFill(['status' => $status])->save();
    Storage::disk('local')->assertExists($blob->storage_key);

    expect(fn () => CasTestHelpers::pin($blob, 'late'))->toThrow(BlobUnavailableException::class)
        ->and(CasTestHelpers::pins($blob))->toBe(0)
        ->and($blob->fresh()->status)->toBe($status);
})->with([BlobStatus::Deleting, BlobStatus::Deleted]);

it('decides on the row read under the lock, not on the caller\'s stale model', function (): void {
    $blob = pinStatusBlob('stale model');
    $stale = $blob->fresh();
    $blob->forceFill(['status' => BlobStatus::Deleted])->save();

    expect($stale->status)->toBe(BlobStatus::Committed)
        ->and(fn () => CasTestHelpers::pin($stale, 'stale'))->toThrow(BlobUnavailableException::class);
});

it('answers a refusal as the never-cached problem blob_unavailable without leaking the storage key', function (): void {
    $blob = pinStatusBlob('problem');
    $blob->forceFill(['status' => BlobStatus::Deleted])->save();

    try {
        CasTestHelpers::pin($blob, 'problem');
        $this->fail('The pin was not refused.');
    } catch (BlobUnavailableException $exception) {
        expect($exception)->toBeInstanceOf(ProblemException::class)
            ->and($exception->problemCode())->toBe('blob_unavailable')
            ->and($exception->httpStatus())->toBe(409)
            ->and($exception->getMessage())->toContain((string) $blob->getKey())->toContain('deleted')
            ->and($exception->getMessage())->not->toContain($blob->storage_key);
    }
});

it('recovers a refused pin by committing the bytes again: the row is resurrected and the object promoted', function (BlobStatus $status, bool $bytesGone): void {
    $blob = pinStatusBlob('recover me');
    $blob->forceFill(['status' => $status, 'delete_after_at' => null])->save();
    if ($bytesGone) {
        Storage::disk('local')->delete($blob->storage_key);
    }
    expect(fn () => CasTestHelpers::pin($blob, 'owner-row'))->toThrow(BlobUnavailableException::class);

    // The owner still holds the bytes: commit them again in the same scope and pin what comes back.
    app(VaulterDatabase::class)->transaction(function () use ($blob): void {
        $again = app(BlobStore::class)->commit(CasTestHelpers::streamFor('recover me'), BlobScope::tenant('7'));
        expect((string) $again->getKey())->toBe((string) $blob->getKey());
        app(BlobPins::class)->pin($again, CasTestHelpers::OWNER, 'owner-row');
    });

    expect($blob->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(CasTestHelpers::pins($blob))->toBe(1)
        ->and(app(ContentStore::class)->exists($blob->storage_key, $blob->disk))->toBeTrue()
        ->and(stream_get_contents(app(BlobStore::class)->read($blob->fresh())))->toBe('recover me');
})->with([
    'tombstoned, object gone (rolled-back sweep)' => [BlobStatus::Tombstoned, true],
    'deleting, object gone' => [BlobStatus::Deleting, true],
    'deleted, object gone' => [BlobStatus::Deleted, true],
    'deleted, object left behind' => [BlobStatus::Deleted, false],
]);

it('unpins one reference idempotently and by the blob key alone', function (): void {
    $blob = pinStatusBlob('unpin');
    CasTestHelpers::pin($blob, 'a');
    CasTestHelpers::pin($blob, 'b');

    CasTestHelpers::unpin($blob, 'a');
    CasTestHelpers::unpin($blob, 'a');
    app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->unpin($blob->getKey(), CasTestHelpers::OWNER, 'missing'));

    expect(array_column(pinStatusRows(), 'reference_key'))->toBe(['b']);

    app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->unpin((string) $blob->getKey(), CasTestHelpers::OWNER, 'b'));

    expect(pinStatusRows())->toBe([])
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed);
});

it('releases only the pins of the named owner type, all of them or the keys it yields', function (): void {
    $one = pinStatusBlob('release one');
    $two = pinStatusBlob('release two');
    CasTestHelpers::pin($one, 'k1', 'drive.revision_blob');
    CasTestHelpers::pin($two, 'k2', 'drive.revision_blob');
    CasTestHelpers::pin($two, 'k3', 'drive.revision_blob');
    CasTestHelpers::pin($one, 'k1', 'other.owner');
    $pins = app(BlobPins::class);

    $narrowed = app(VaulterDatabase::class)->transaction(fn (): int => $pins->releaseOwner('drive.revision_blob', static fn (): iterable => ['k2', 'unknown']));
    expect($narrowed)->toBe(1)
        ->and($pins->countByOwner())->toBe(['drive.revision_blob' => 2, 'other.owner' => 1]);

    $all = app(VaulterDatabase::class)->transaction(fn (): int => $pins->releaseOwner('drive.revision_blob'));
    expect($all)->toBe(2)
        ->and($pins->countByOwner())->toBe(['other.owner' => 1])
        ->and(app(VaulterDatabase::class)->transaction(fn (): int => $pins->releaseOwner('drive.revision_blob')))->toBe(0);
});

it('releases a long key list in chunks', function (): void {
    $blob = pinStatusBlob('many keys');
    for ($i = 0; $i < 520; $i++) {
        CasTestHelpers::seedPin($blob, 'key-'.$i, 'drive.revision_blob');
    }

    $released = app(VaulterDatabase::class)->transaction(fn (): int => app(BlobPins::class)->releaseOwner(
        'drive.revision_blob',
        static function (): iterable {
            for ($i = 0; $i < 510; $i++) {
                yield 'key-'.$i;
            }
        },
    ));

    expect($released)->toBe(510)->and(CasTestHelpers::pins($blob))->toBe(10);
});

it('counts pins per blob for the collector and answers whether a blob is pinned', function (): void {
    $pinned = pinStatusBlob('pinned twice');
    $free = pinStatusBlob('free');
    CasTestHelpers::pin($pinned, 'a');
    CasTestHelpers::pin($pinned, 'b');
    $store = app(BlobPinStore::class);

    expect($store->countsFor([$pinned->getKey(), $free->getKey()]))->toBe([(string) $pinned->getKey() => 2])
        ->and($store->countsFor([]))->toBe([])
        ->and($store->isPinned($pinned))->toBeTrue()
        ->and($store->isPinned($free->getKey()))->toBeFalse();
});

it('refuses a malformed owner type or reference key before touching anything', function (string $ownerType, string $reference): void {
    $blob = pinStatusBlob('malformed');

    expect(fn () => CasTestHelpers::pin($blob, $reference, $ownerType))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CasTestHelpers::unpin($blob, $reference, $ownerType))->toThrow(InvalidArgumentException::class)
        ->and(CasTestHelpers::pins($blob))->toBe(0);
})->with([
    'no module prefix' => ['revision_blob', 'k'],
    'upper case' => ['Drive.Revision', 'k'],
    'owner type too long' => ['drive.'.str_repeat('x', 59), 'k'],
    'empty key' => ['drive.revision_blob', ''],
    'key too long' => ['drive.revision_blob', str_repeat('k', 192)],
]);

it('accepts the longest owner type and reference key the columns hold', function (): void {
    $blob = pinStatusBlob('limits');
    $owner = 'drive.'.str_repeat('x', 58);

    CasTestHelpers::pin($blob, str_repeat('k', 191), $owner);

    expect(strlen($owner))->toBe(64)->and(CasTestHelpers::pins($blob))->toBe(1);
});

it('lets the pin point at the blob and never the other way: the blob row cannot be deleted while pinned', function (): void {
    $blob = pinStatusBlob('restrict');
    CasTestHelpers::pin($blob, 'held');
    $table = app(DatabaseConfig::class)->table('blob_references');

    $foreign = Schema::connection(app(VaulterDatabase::class)->connection()->getName())->getForeignKeys($table);
    expect($foreign)->toHaveCount(1)
        ->and($foreign[0]['columns'])->toBe(['blob_id'])
        ->and($foreign[0]['foreign_table'])->toBe(app(DatabaseConfig::class)->table('blobs'))
        ->and(strtolower((string) $foreign[0]['on_delete']))->toBe('restrict')
        ->and(Schema::getForeignKeys(app(DatabaseConfig::class)->table('blobs')))->toBe([]);

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => Blob::query()->whereKey($blob->getKey())->delete()))
        ->toThrow(QueryException::class)
        ->and(Blob::query()->whereKey($blob->getKey())->exists())->toBeTrue();
});

it('keeps the pin unique per blob, owner type and reference key in the schema itself', function (): void {
    $blob = pinStatusBlob('unique');
    CasTestHelpers::seedPin($blob, 'dup');

    expect(fn () => app(VaulterDatabase::class)->transaction(fn () => CasTestHelpers::seedPin($blob, 'dup')))
        ->toThrow(QueryException::class)
        ->and(CasTestHelpers::pins($blob))->toBe(1);
});
