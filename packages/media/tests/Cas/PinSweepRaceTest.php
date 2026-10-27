<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Cas\BlobGarbageCollector;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentStore;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Media\Tests\Cas\CasTestHelpers;
use Vaulter\Media\Tests\Fixtures\Cas\FailingDeleteContentStore;
use Vaulter\Media\ValueObjects\Cas\BlobScope;
use Vaulter\Testing\Concurrency\ForkRace;

/*
 * D23, P1: a pin and the collector of the same blob are ordered by lock(scope, sha256) and the row lock, so in every
 * interleaving a blob that ends up pinned has its bytes. Real processes on PostgreSQL (ForkRace); serial + database,
 * engine pgsql — written and classified in PLAN3, run in PLAN4 (D41). The slow side pauses INSIDE its transaction,
 * with its locks held; the other side starts while it waits.
 */

const PIN_RACE_PAUSE_US = 800_000;

const PIN_RACE_HEAD_START_US = 200_000;

beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    config()->set('vaulter.ids.strategy', 'ulid');
    $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    Storage::fake('local');
});

function pinRaceScope(): BlobScope
{
    return BlobScope::tenant('019a0000-1234-7000-8000-123456789abc');
}

function pinRaceBlob(string $bytes, BlobStatus $status = BlobStatus::Committed): Blob
{
    $blob = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), pinRaceScope()));
    $blob->forceFill([
        'status' => $status,
        'ref_count' => 0,
        'delete_after_at' => $status === BlobStatus::Tombstoned ? now()->subDay() : null,
    ])->save();

    return $blob;
}

/** A pinned blob has its bytes, whatever the interleaving. */
function pinRaceAssertPinnedHasBytes(Blob $blob, string $bytes): void
{
    $fresh = $blob->fresh();
    if (CasTestHelpers::pins($fresh) === 0) {
        return;
    }

    expect($fresh->status)->toBe(BlobStatus::Committed)
        ->and(app(ContentStore::class)->exists($fresh->storage_key, $fresh->disk))->toBeTrue()
        ->and(stream_get_contents(app(BlobStore::class)->read($fresh->getKey())))->toBe($bytes);
}

it('orders an ingest of the same bytes after a sweep paused between the unlink and its end', function (bool $sweepCommits): void {
    $bytes = 'swept while ingested '.($sweepCommits ? 'commit' : 'rollback');
    $blob = pinRaceBlob($bytes, BlobStatus::Tombstoned);

    $outcomes = ForkRace::run([
        function () use ($sweepCommits): array {
            app()->instance(ContentStore::class, new FailingDeleteContentStore(app(ContentStore::class), static function () use ($sweepCommits): void {
                usleep(PIN_RACE_PAUSE_US);
                if (!$sweepCommits) {
                    throw new RuntimeException('sweep rolled back after the unlink');
                }
            }));

            return app(BlobGarbageCollector::class)->sweep();
        },
        function () use ($bytes): string {
            usleep(PIN_RACE_HEAD_START_US);

            // The owner's transaction: commit the bytes (waits for the sweep's lock), then pin what it got.
            return app(VaulterDatabase::class)->transaction(function () use ($bytes): string {
                $fresh = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), pinRaceScope()));
                app(BlobPins::class)->pin($fresh->getKey(), CasTestHelpers::OWNER, 'ingest');

                return (string) $fresh->getKey();
            });
        },
    ]);

    expect($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($outcomes[1]->value)->toBe((string) $blob->getKey())
        ->and($sweepCommits ? $outcomes[0]->ok : $outcomes[0]->failedWith(RuntimeException::class))->toBeTrue($outcomes[0]->message ?? '')
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    pinRaceAssertPinnedHasBytes($blob, $bytes);
})->with(['sweep commits' => true, 'sweep rolls back' => false]);

it('refuses a reference-only pin after a paused sweep and recovers by a re-commit', function (bool $sweepCommits): void {
    $bytes = 'swept while referenced '.($sweepCommits ? 'commit' : 'rollback');
    $blob = pinRaceBlob($bytes, BlobStatus::Tombstoned);

    $outcomes = ForkRace::run([
        function () use ($sweepCommits): array {
            app()->instance(ContentStore::class, new FailingDeleteContentStore(app(ContentStore::class), static function () use ($sweepCommits): void {
                usleep(PIN_RACE_PAUSE_US);
                if (!$sweepCommits) {
                    throw new RuntimeException('sweep rolled back after the unlink');
                }
            }));

            return app(BlobGarbageCollector::class)->sweep();
        },
        function () use ($blob, $bytes): string {
            usleep(PIN_RACE_HEAD_START_US);
            try {
                // Waits for the sweep's lock, then reads deleted (commit) or tombstoned without bytes (rollback).
                app(VaulterDatabase::class)->transaction(fn () => app(BlobPins::class)->pin($blob->getKey(), CasTestHelpers::OWNER, 'reference'));

                return 'pinned';
            } catch (BlobUnavailableException) {
                app(VaulterDatabase::class)->transaction(function () use ($bytes): void {
                    $again = CasTestHelpers::row(app(BlobStore::class)->commit(CasTestHelpers::streamFor($bytes), pinRaceScope()));
                    app(BlobPins::class)->pin($again->getKey(), CasTestHelpers::OWNER, 'reference');
                });

                return 'recovered';
            }
        },
    ]);

    expect($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($outcomes[1]->value)->toBe('recovered')
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    pinRaceAssertPinnedHasBytes($blob, $bytes);
})->with(['sweep commits' => true, 'sweep rolls back' => false]);

it('lets a sweep that comes second see the pin and keep the bytes', function (): void {
    $bytes = 'pinned first';
    $blob = pinRaceBlob($bytes, BlobStatus::Tombstoned);

    $outcomes = ForkRace::run([
        function () use ($blob): string {
            app(VaulterDatabase::class)->transaction(function () use ($blob): void {
                app(BlobPins::class)->pin($blob->getKey(), CasTestHelpers::OWNER, 'first');
                usleep(PIN_RACE_PAUSE_US);
            });

            return 'pinned';
        },
        function (): array {
            usleep(PIN_RACE_HEAD_START_US);

            return app(BlobGarbageCollector::class)->sweep();
        },
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($outcomes[1]->value['swept'])->toBe(0)
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    pinRaceAssertPinnedHasBytes($blob, $bytes);
});

it('orders a pin and a mark of the same committed blob in either direction', function (bool $markFirst): void {
    $bytes = 'mark vs pin '.($markFirst ? 'mark first' : 'pin first');
    $blob = pinRaceBlob($bytes);

    $mark = function () use ($markFirst): array {
        if (!$markFirst) {
            usleep(PIN_RACE_HEAD_START_US);
        }

        return app(VaulterDatabase::class)->transaction(function () use ($markFirst): array {
            $result = app(BlobGarbageCollector::class)->mark(graceHours: 24);
            if ($markFirst) {
                usleep(PIN_RACE_PAUSE_US);
            }

            return $result;
        });
    };
    $pin = function () use ($blob, $markFirst): string {
        if ($markFirst) {
            usleep(PIN_RACE_HEAD_START_US);
        }
        app(VaulterDatabase::class)->transaction(function () use ($blob, $markFirst): void {
            app(BlobPins::class)->pin($blob->getKey(), CasTestHelpers::OWNER, 'racer');
            if (!$markFirst) {
                usleep(PIN_RACE_PAUSE_US);
            }
        });

        return 'pinned';
    };

    $outcomes = ForkRace::run([$mark, $pin]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        // Mark first: it tombstoned, the pin then committed the row again. Pin first: the mark saw the pin.
        ->and($outcomes[0]->value['tombstoned'])->toBe($markFirst ? 1 : 0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed)
        ->and($blob->fresh()->delete_after_at)->toBeNull()
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    pinRaceAssertPinnedHasBytes($blob, $bytes);
})->with(['mark first' => true, 'pin first' => false]);

it('keeps a blob a concurrent detach leaves pinned by another owner', function (bool $markFirst): void {
    $bytes = 'mark vs detach '.($markFirst ? 'mark first' : 'detach first');
    $blob = pinRaceBlob($bytes);
    CasTestHelpers::pin($blob, 'kept', 'other.owner');
    CasTestHelpers::pin($blob, 'detached');

    $outcomes = ForkRace::run([
        function () use ($markFirst): array {
            if (!$markFirst) {
                usleep(PIN_RACE_HEAD_START_US);
            }

            return app(VaulterDatabase::class)->transaction(function () use ($markFirst): array {
                $result = app(BlobGarbageCollector::class)->mark(graceHours: 24);
                if ($markFirst) {
                    usleep(PIN_RACE_PAUSE_US);
                }

                return $result;
            });
        },
        function () use ($blob, $markFirst): string {
            if ($markFirst) {
                usleep(PIN_RACE_HEAD_START_US);
            }
            app(VaulterDatabase::class)->transaction(function () use ($blob, $markFirst): void {
                app(BlobPins::class)->unpin($blob->getKey(), CasTestHelpers::OWNER, 'detached');
                if (!$markFirst) {
                    usleep(PIN_RACE_PAUSE_US);
                }
            });

            return 'detached';
        },
    ]);

    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and($outcomes[0]->value['tombstoned'])->toBe(0)
        ->and($blob->fresh()->status)->toBe(BlobStatus::Committed)
        ->and(CasTestHelpers::pins($blob))->toBe(1);
    pinRaceAssertPinnedHasBytes($blob, $bytes);
})->with(['mark first' => true, 'detach first' => false]);

it('tombstones a blob whose last pin a concurrent detach removes only when the mark reads after it', function (): void {
    $bytes = 'mark vs last detach';
    $blob = pinRaceBlob($bytes);
    CasTestHelpers::pin($blob, 'last');

    $outcomes = ForkRace::run([
        function (): array {
            usleep(PIN_RACE_HEAD_START_US);

            return app(BlobGarbageCollector::class)->mark(graceHours: 24);
        },
        function () use ($blob): string {
            app(VaulterDatabase::class)->transaction(function () use ($blob): void {
                app(BlobPins::class)->unpin($blob->getKey(), CasTestHelpers::OWNER, 'last');
                usleep(PIN_RACE_PAUSE_US);
            });

            return 'detached';
        },
    ]);

    // The unpin takes no blob lock: the mark either read the pin still committed-visible (skip) or ran after it.
    expect($outcomes[0]->ok)->toBeTrue($outcomes[0]->message ?? '')
        ->and($outcomes[1]->ok)->toBeTrue($outcomes[1]->message ?? '')
        ->and(CasTestHelpers::pins($blob))->toBe(0)
        ->and(in_array($blob->fresh()->status, [BlobStatus::Committed, BlobStatus::Tombstoned], true))->toBeTrue();
    Storage::disk('local')->assertExists($blob->storage_key);
});
