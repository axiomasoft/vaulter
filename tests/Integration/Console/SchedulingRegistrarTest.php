<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Providers\SchedulingRegistrar as DriveSchedulingRegistrar;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/** @return array<string, Event> keyed by the task name */
function vaulterCoreTasks(Application $app): array
{
    $tasks = [];
    foreach ($app->make(Schedule::class)->events() as $event) {
        if (preg_match('/^vaulter:(changes:|relay$|outbox:)/', (string) $event->description) === 1) {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

function bootCoreSchedule(array $config = []): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    return vaulterCoreTasks(app());
}

it('schedules the outbox and journal tasks with overlap and single-server guards', function (): void {
    $tasks = bootCoreSchedule();

    expect(array_keys($tasks))->toEqualCanonicalizing(['vaulter:relay', 'vaulter:outbox:prune', 'vaulter:changes:prune'])
        ->and($tasks['vaulter:relay']->expression)->toBe('* * * * *')
        ->and($tasks['vaulter:outbox:prune']->expression)->toBe('0 * * * *')
        ->and($tasks['vaulter:changes:prune']->expression)->toBe('20 3 * * *')
        ->and($tasks['vaulter:relay']->expiresAt)->toBe(10);
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue();
    }
});

it('registers nothing when scheduling is disabled', function (): void {
    expect(bootCoreSchedule(['vaulter.schedule.enabled' => false]))->toBe([]);
});

it('skips the webhook tasks when webhooks are off but keeps the outbox relay and the journal prune', function (): void {
    expect(array_keys(bootCoreSchedule()))->toEqualCanonicalizing(['vaulter:relay', 'vaulter:outbox:prune', 'vaulter:changes:prune']);
});

it('skips the journal prune when the change journal is off', function (): void {
    expect(bootCoreSchedule(['vaulter-drive.features.changes' => false]))->not->toHaveKey('vaulter:changes:prune');
});

it('treats a null frequency as a disabled task', function (): void {
    expect(bootCoreSchedule(['vaulter.schedule.outbox_prune' => null]))->not->toHaveKey('vaulter:outbox:prune')
        ->and(bootCoreSchedule())->toHaveKey('vaulter:relay');
});

it('fails boot validation on an invalid frequency', function (): void {
    config(['vaulter.schedule.outbox_prune' => 'sometimes']);

    app(ConfigValidator::class)->validate(app(VaulterConfig::class), app());
})->throws(InvalidConfigurationException::class);

it('fails registration on an invalid frequency', function (): void {
    config(['vaulter-drive.schedule.changes_prune' => 'sometimes']);

    // The change-journal prune is a drive task: the drive's registrar validates its key.
    (new DriveSchedulingRegistrar)->define(app(Schedule::class), app('config'));
})->throws(InvalidConfigurationException::class);
