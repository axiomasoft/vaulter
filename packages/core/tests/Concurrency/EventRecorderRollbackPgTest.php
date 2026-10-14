<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Nodes\NodeRenamed;
use Vaulter\Enums\Events\DriveEventType;
use Vaulter\Events\DomainEvent;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Schema\IdStrategyResolver;
use Vaulter\Tests\Concerns\EnablesWebhooksModule;
use Vaulter\Tests\Concurrency\ForkRace;
use Vaulter\Tests\Concurrency\RaceOutcome;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Webhooks\OutboxRecorder;

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
        app()->bind(OutboxRecorder::class, fn (): OutboxRecorder => new class(app(VaulterConfig::class), app(MetricsRecorder::class), app(VaulterDatabase::class), app(IdStrategyResolver::class)) extends OutboxRecorder
        {
            #[Override]
            public function stage(DomainEvent $event, array $envelope): bool
            {
                // A real failed statement, so PostgreSQL marks the transaction aborted.
                DB::statement('select * from vaulter_missing_outbox_table');

                return true;
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
        ->and(Outbox::query()->where('event', DriveEventType::NodeRenamed->value)->exists())->toBeFalse();
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

    $outbox = Outbox::query()->where('event', DriveEventType::NodeRenamed->value)->sole();

    expect($outcomes[0]->ok)->toBeFalse()
        ->and(DriveTestHelpers::model($this->folder->id)->name)->toBe('beta')
        ->and(Activity::query()->where('verb', DriveEventType::NodeRenamed->value)->sole()->event_id)->toBe($outbox->event_id)
        ->and($outbox->status)->toBe('pending');
});
