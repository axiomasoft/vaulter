<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage as StorageFacade;
use Vaulter\Drive\Data\UploadSource;
use Vaulter\Drive\Enums\Events\DriveEventType;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\Fixtures\RevisionBlobPins;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Operations\OperationRunner;

beforeEach(fn () => DriveTestHelpers::insideOperation());

/**
 * vaulter:trash:purge: nodes trashed past the retention
 * window are PHYSICALLY purged — the stage-2 force-delete detaches version↔blob
 * edges (the bytes become GC-eligible), then the subtree's own rows are hard-deleted
 * (not just soft-deleted) so FK cascade removes dependent rows too. A
 * node still inside retention is left untouched.
 */
beforeEach(function (): void {
    StorageFacade::fake('local');
    config()->set('vaulter-drive.trash.retention_days', 30);
});

it('physically purges a node trashed past the retention window', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'old');

    // Trashed 40 days ago — past the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $node->getKey()])->save();

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    // The node is now PHYSICALLY gone (purge means purge; nodes have no
    // soft-delete tombstone a forensic query could still see).
    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});

it('leaves a node still inside the retention window untouched', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'recent');

    // Trashed 5 days ago — well inside the 30-day retention.
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(5), 'trash_root_id' => $node->getKey()])->save();

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeTrue();
});

it('frees the blob edges of a purged file subtree (GC-eligible)', function (): void {
    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('purge me'), 'p.txt'));

    // The blob is referenced (one live edge) while the file lives.
    $blob = Blob::query()->firstOrFail();
    expect(RevisionBlobPins::edges($blob))->toBe(1)->and(RevisionBlobPins::pins($blob))->toBe(1);

    DriveTestHelpers::model($fileNode)->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $fileNode->id])->save();

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    // The edge is detached — the blob is now unreferenced (GC-eligible).
    expect(RevisionBlobPins::edges($blob->refresh()))->toBe(0)->and(RevisionBlobPins::pins($blob->refresh()))->toBe(0);
});

it('cascades the physical purge to file/version rows on PostgreSQL', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('FK cascadeOnDelete is not enforced on the default SQLite test connection.');
    }

    $drive = Vaulter::drive(DriveTestHelpers::host())->asSystem('test fixture');
    $fileNode = $drive->files()->upload(UploadSource::fromStream(DriveTestHelpers::streamFor('purge me too'), 'p2.txt'));
    $fileId = DriveTestHelpers::model($fileNode)->file()->first()->getKey();

    DriveTestHelpers::model($fileNode)->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $fileNode->id])->save();

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    // The node AND every row that FK-cascades off it are physically gone — not
    // just the version↔blob edge (already covered by the GC-eligibility test
    // above), the v_files/v_file_revisions rows themselves.
    expect(Node::query()->whereKey($fileNode->id)->exists())->toBeFalse()
        ->and(DB::table('v_files')->where('id', $fileId)->exists())->toBeFalse()
        ->and(DB::table('v_file_revisions')->where('file_id', $fileId)->exists())->toBeFalse();
});

it('does not physically purge a node restored between candidate materialisation and the purge transaction, TOCTOU guard', function (): void {
    $drive = DriveTestHelpers::drive();
    $node = $drive->folders()->create('raced');

    $drive->nodes()->trash($node);
    // Backdate past the retention window so it becomes a purge candidate.
    DriveTestHelpers::model($node)->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $node->id])->save();

    $targetId = $node->id;
    $restoredOnce = false;

    // Fires when the candidate row is hydrated by PurgeTrashCommand's initial
    // `trashedBefore()->get()` — i.e. strictly BEFORE the per-node purge
    // transaction opens. Simulates the owning user calling `restore` in the
    // TOCTOU window between candidate materialisation and the transaction that
    // physically (and irreversibly, ADR-0016) deletes the subtree.
    Node::retrieved(function (Node $model) use ($drive, $targetId, &$restoredOnce): void {
        if ($restoredOnce || (string) $model->getKey() !== $targetId) {
            return;
        }

        $restoredOnce = true;
        $drive->trash()->restore($targetId);
    });

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    // The re-check + row-lock inside the purge transaction sees the CURRENT
    // (restored) state and aborts the purge for this subtree — the node
    // survives, live and un-trashed, instead of being physically deleted out
    // from under the user who just restored it.
    expect(Node::query()->whereKey($targetId)->exists())->toBeTrue()
        ->and(DriveTestHelpers::model($node)->trashed_at)->toBeNull();
});

it('honours the --days override', function (): void {
    $storage = AccessTestHelpers::storage();
    $node = AccessTestHelpers::folder($storage, null, 'tenday');
    $node->forceFill(['trashed_at' => Carbon::now()->subDays(10), 'trash_root_id' => $node->getKey()])->save();

    // With a 7-day override, a 10-day-old trashed node IS purged.
    $this->artisan('vaulter:trash:purge', ['--days' => '7'])->assertSuccessful();

    expect(Node::query()->whereKey($node->getKey())->exists())->toBeFalse();
});

it('purges each root as its own root operation of the system actor "retention", recording one purge event per root', function (): void {
    $storage = AccessTestHelpers::storage();
    $nodes = [];
    foreach (['a', 'b'] as $name) {
        $nodes[$name] = AccessTestHelpers::folder($storage, null, $name);
        $nodes[$name]->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $nodes[$name]->getKey()])->save();
    }
    $seen = [];
    $candidates = array_map(static fn (Node $node): string => (string) $node->getKey(), $nodes);
    Node::retrieved(function (Node $model) use (&$seen, $candidates): void {
        if (in_array((string) $model->getKey(), $candidates, true)) {
            $seen[] = app(OperationRunner::class)->inOperation();
        }
    });

    $this->artisan('vaulter:trash:purge')->assertSuccessful();

    $events = OutboxEvent::query()->where('type', DriveEventType::NodePurged->value)->get();
    expect($events)->toHaveCount(2)
        ->and(Activity::query()->where('verb', DriveEventType::NodePurged->value)->get()->every(fn (Activity $row): bool => ($row->context['system_reason'] ?? null) === 'retention'))->toBeTrue()
        // Candidates are read outside the runner; each root is then located and purged inside its own operation.
        ->and($seen)->toContain(false)->toContain(true);
});

it('prints and counts the skipped roots when one root fails and still purges the others', function (): void {
    $storage = AccessTestHelpers::storage();
    $ok = AccessTestHelpers::folder($storage, null, 'ok');
    $bad = AccessTestHelpers::folder($storage, null, 'bad');
    foreach ([$ok, $bad] as $node) {
        $node->forceFill(['trashed_at' => Carbon::now()->subDays(40), 'trash_root_id' => $node->getKey()])->save();
    }
    // The failing root: an exception raised while its purge locates it.
    Node::retrieved(static function (Node $model) use ($bad): void {
        if ((string) $model->getKey() === (string) $bad->getKey() && app(OperationRunner::class)->inOperation()) {
            throw new RuntimeException('boom');
        }
    });

    $this->artisan('vaulter:trash:purge')->expectsOutputToContain('Purged 1 trashed subtree. Skipped 1.')->assertExitCode(1);

    expect(Node::query()->whereKey($ok->getKey())->exists())->toBeFalse()
        ->and(Node::query()->whereKey($bad->getKey())->exists())->toBeTrue()
        ->and(OutboxEvent::query()->where('type', DriveEventType::NodePurged->value)->count())->toBe(1);
});
