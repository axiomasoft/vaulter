<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Console\PruneChangesCommand;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;

/**
 * Retention of the change journal: vaulter:changes:prune deletes entries
 * older than the window and raises pruned_through with them, batch by batch;
 * vaulter:changes:reset starts a new epoch.
 */
uses(TestCase::class, DatabaseMigrations::class);

function pruneHead(DriveHandle $drive): object
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('drive_change_heads'))
        ->where('drive_id', $drive->model()->getKey())->sole();
}

/** @return list<int> */
function pruneSeqs(DriveHandle $drive): array
{
    return app(VaulterDatabase::class)->connection()->table(app(DatabaseOptions::class)->table('drive_changes'))
        ->where('drive_id', $drive->model()->getKey())->orderBy('seq')->pluck('seq')->map(static fn (mixed $seq): int => (int) $seq)->all();
}

it('deletes entries past the configured window and records how far it pruned', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->folders()->create('old-1');
    $drive->folders()->create('old-2');
    $this->travel(20)->days();
    $drive->folders()->create('recent');
    $this->travel(11)->days();

    $this->artisan('vaulter:changes:prune')->expectsOutputToContain('Pruned 2 change entries from 1 drive')->assertSuccessful();

    expect(pruneSeqs($drive))->toBe([3])
        ->and((int) pruneHead($drive)->pruned_through)->toBe(2)
        ->and((int) pruneHead($drive)->last_seq)->toBe(3);
});

it('takes --days over the configuration and limits the run to --drive', function (): void {
    config()->set('vaulter-drive.changes.retention_days', 365);
    $first = DriveTestHelpers::drive();
    $second = DriveTestHelpers::drive();
    $first->folders()->create('a');
    $second->folders()->create('b');
    $this->travel(3)->days();

    $this->artisan('vaulter:changes:prune', ['--days' => '2', '--drive' => [(string) $first->model()->getKey()]])->assertSuccessful();

    expect(pruneSeqs($first))->toBe([])
        ->and(pruneSeqs($second))->toBe([1])
        ->and((int) pruneHead($second)->pruned_through)->toBe(0);
});

it('never lowers pruned_through and leaves a drive without old entries alone', function (): void {
    $drive = DriveTestHelpers::drive();
    $drive->folders()->create('a');

    $this->artisan('vaulter:changes:prune')->expectsOutputToContain('Pruned 0 change entries from 0 drives')->assertSuccessful();

    expect(pruneSeqs($drive))->toBe([1])->and((int) pruneHead($drive)->pruned_through)->toBe(0);
});

it('rejects a --days that is not a positive integer', function (): void {
    $this->artisan('vaulter:changes:prune', ['--days' => '0'])->assertExitCode(2);
});

it('prunes in batches of a thousand under the head lock', function (): void {
    $drive = DriveTestHelpers::drive();
    $table = app(DatabaseOptions::class)->table('drive_changes');
    $db = app(VaulterDatabase::class)->connection();
    $rows = [];
    foreach (range(1, PruneChangesCommand::BATCH + 5) as $seq) {
        $rows[] = ['drive_id' => $drive->model()->getKey(), 'seq' => $seq, 'change_id' => sprintf('%026d', $seq), 'event_type' => 'node.renamed',
            'node_id' => $drive->model()->root_node_id, 'operation' => 'upsert', 'subtree' => false, 'occurred_at' => now()->subDays(40)];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        $db->table($table)->insert($chunk);
    }
    $db->table(app(DatabaseOptions::class)->table('drive_change_heads'))->where('drive_id', $drive->model()->getKey())->update(['last_seq' => PruneChangesCommand::BATCH + 5]);
    $deletes = 0;
    $db->listen(static function ($query) use (&$deletes, $table): void {
        if (str_starts_with(strtolower($query->sql), 'delete') && str_contains($query->sql, $table)) {
            $deletes++;
        }
    });

    $this->artisan('vaulter:changes:prune')->assertSuccessful();

    expect(pruneSeqs($drive))->toBe([])
        ->and($deletes)->toBe(2)
        ->and((int) pruneHead($drive)->pruned_through)->toBe(PruneChangesCommand::BATCH + 5);
});

it('starts a new epoch on reset and refuses an unknown drive', function (): void {
    $drive = DriveTestHelpers::drive();

    $this->artisan('vaulter:changes:reset', ['drive' => (string) $drive->model()->getKey()])->expectsOutputToContain('epoch 2')->assertSuccessful();
    $this->artisan('vaulter:changes:reset', ['drive' => 'missing'])->assertFailed();

    expect((int) pruneHead($drive)->epoch)->toBe(2);
});

it('refuses a reset key the drive column cannot store', function (string $key): void {
    $this->artisan('vaulter:changes:reset', ['drive' => $key])
        ->expectsOutputToContain('not found')
        ->assertFailed();
})->with(['9999999999999999999', '18446744073709551616']);
