<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Events\Outbox\OutboxStore;
use Vaulter\Models\Activity\Activity;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Webhooks\WebhookTestHelpers;

uses(EnablesWebhooksModule::class);

/**
 * V07 on PostgreSQL, where a failed statement aborts the whole transaction: a failed
 * outbox write rolls the rename back and leaves the connection usable, and a process
 * killed right after the commit has already stored the activity and outbox rows.
 *
 * Provisioning: DB_CONNECTION=pgsql on a *_test database with pcntl and posix.
 */
beforeEach(function (): void {
    ForkRace::skipUnlessSupported($this);
    $this->drive = DriveTestHelpers::drive();
    $this->user = DriveTestHelpers::actor($this->drive, 7);
    $this->folder = $this->drive->folders()->create('alpha');
});

it('rolls a rename back on PostgreSQL when its outbox write fails, leaving the connection usable', function (): void {
    $outcomes = ForkRace::run([function (): array {
        $table = app(DatabaseConfig::class)->table(OutboxStore::EVENTS);
        DB::connection()->beforeExecuting(static function (string $query) use ($table): void {
            if (str_starts_with($query, 'insert into "'.$table.'"')) {
                // A real failed statement, so PostgreSQL marks the transaction aborted.
                DB::statement('select * from vaulter_missing_outbox_table');
            }
        });
        $failure = null;
        try {
            $this->drive->actingAs($this->user)->nodes()->rename($this->folder, 'beta');
        } catch (Throwable $exception) {
            $failure = $exception::class;
        }

        return ['failure' => $failure, 'level' => DB::transactionLevel(), 'alive' => (int) DB::selectOne('select 1 as one')->one];
    }]);

    expect(array_map(static fn (RaceOutcome $outcome): bool => $outcome->ok, $outcomes))->toBe([true])
        ->and($outcomes[0]->value['failure'])->not->toBeNull()
        ->and($outcomes[0]->value['level'])->toBe(0)
        ->and($outcomes[0]->value['alive'])->toBe(1)
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('alpha')
        ->and(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->exists())->toBeFalse()
        ->and(OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->exists())->toBeFalse();
});

it('keeps the activity and outbox rows of a rename whose process dies right after the commit', function (): void {
    $outcomes = ForkRace::run([function (): never {
        // The first after-commit work kills the process: nothing after the commit runs.
        Event::listen(NodeRenamed::class, static function (): never {
            posix_kill(getmypid(), SIGKILL);

            exit(1);
        });
        $this->drive->actingAs($this->user)->nodes()->rename($this->folder, 'beta');

        throw new LogicException('the listener did not run');
    }]);

    $outbox = OutboxEvent::query()->where('type', DriveEventType::NodeRenamed->value)->sole();

    expect($outcomes[0]->ok)->toBeFalse()
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('beta')
        ->and(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->sole()->event_id)->toBe($outbox->event_id)
        ->and($outbox->external)->toBeTrue()
        ->and(WebhookTestHelpers::outboxDelivery($outbox->event_id)['state'] ?? null)->toBe('pending');
});
