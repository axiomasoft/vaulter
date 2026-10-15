<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Providers\SchedulingRegistrar as DriveSchedulingRegistrar;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Tests\Concerns\FreshApplication;

/** @return array<string, Event> keyed by the task name */
function vaulterCoreTasks(Application $app): array
{
    $tasks = [];
    foreach ($app->make(Schedule::class)->events() as $event) {
        if (preg_match('/^vaulter:(webhooks:|changes:|relay$|outbox:)/', (string) $event->description) === 1) {
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

/** The tasks of a fresh application with the opt-in webhooks module switched on. */
function bootWebhooksSchedule(array $config = []): array
{
    return vaulterCoreTasks(FreshApplication::boot(['vaulter.modules.webhooks' => true, ...$config]));
}

it('schedules the outbox, webhook and journal tasks with overlap and single-server guards', function (): void {
    $tasks = bootWebhooksSchedule();

    expect(array_keys($tasks))->toEqualCanonicalizing(['vaulter:relay', 'vaulter:outbox:prune', 'vaulter:webhooks:retry', 'vaulter:webhooks:prune', 'vaulter:changes:prune'])
        ->and($tasks['vaulter:relay']->expression)->toBe('* * * * *')
        ->and($tasks['vaulter:outbox:prune']->expression)->toBe('0 * * * *')
        ->and($tasks['vaulter:webhooks:prune']->expression)->toBe('10 3 * * *')
        ->and($tasks['vaulter:changes:prune']->expression)->toBe('20 3 * * *')
        ->and($tasks['vaulter:relay']->expiresAt)->toBe(10)
        ->and($tasks['vaulter:webhooks:prune']->expiresAt)->toBe(120);
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue();
    }
});

it('registers nothing when scheduling is disabled', function (): void {
    expect(bootWebhooksSchedule(['vaulter.schedule.enabled' => false]))->toBe([])
        ->and(bootCoreSchedule(['vaulter.schedule.enabled' => false]))->toBe([]);
});

it('skips the webhook tasks when webhooks are off but keeps the outbox relay and the journal prune', function (): void {
    expect(array_keys(bootCoreSchedule()))->toEqualCanonicalizing(['vaulter:relay', 'vaulter:outbox:prune', 'vaulter:changes:prune']);
});

it('skips the journal prune when the change journal is off', function (): void {
    expect(bootCoreSchedule(['vaulter.features.changes' => false]))->not->toHaveKey('vaulter:changes:prune');
});

it('treats a null frequency as a disabled task', function (): void {
    expect(bootWebhooksSchedule(['vaulter.schedule.webhooks_retry' => null]))->not->toHaveKey('vaulter:webhooks:retry');
});

it('fails boot validation on an invalid frequency', function (): void {
    config(['vaulter.schedule.webhooks_prune' => 'sometimes']);

    app(ConfigValidator::class)->validate(app(VaulterConfig::class), app());
})->throws(InvalidConfigurationException::class);

it('fails registration on an invalid frequency', function (): void {
    config(['vaulter.schedule.changes_prune' => 'sometimes']);

    // The change-journal prune is a drive task located in core (D39): DriveModule schedules it.
    (new DriveSchedulingRegistrar)->define(app(Schedule::class), app('config'));
})->throws(InvalidConfigurationException::class);
