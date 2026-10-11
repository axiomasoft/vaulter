<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Vaulter\Configuration\ConfigValidator;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Providers\SchedulingRegistrar;

/** @return array<string, Event> keyed by the task name */
function vaulterCoreTasks(): array
{
    $tasks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (preg_match('/^vaulter:(webhooks|changes):/', (string) $event->description) === 1) {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

function bootCoreSchedule(array $config = []): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    return vaulterCoreTasks();
}

it('schedules the webhook and journal tasks with overlap and single-server guards', function (): void {
    $tasks = bootCoreSchedule();

    expect(array_keys($tasks))->toEqualCanonicalizing(['vaulter:webhooks:relay', 'vaulter:webhooks:retry', 'vaulter:webhooks:prune', 'vaulter:changes:prune'])
        ->and($tasks['vaulter:webhooks:relay']->expression)->toBe('* * * * *')
        ->and($tasks['vaulter:webhooks:prune']->expression)->toBe('10 3 * * *')
        ->and($tasks['vaulter:changes:prune']->expression)->toBe('20 3 * * *')
        ->and($tasks['vaulter:webhooks:relay']->expiresAt)->toBe(10)
        ->and($tasks['vaulter:webhooks:prune']->expiresAt)->toBe(120);
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue();
    }
});

it('registers nothing when scheduling is disabled', function (): void {
    expect(bootCoreSchedule(['vaulter.schedule.enabled' => false]))->toBe([]);
});

it('skips the webhook tasks when webhooks are off but keeps the journal prune', function (): void {
    expect(array_keys(bootCoreSchedule(['vaulter.features.webhooks' => false])))->toBe(['vaulter:changes:prune']);
});

it('skips the journal prune when the change journal is off', function (): void {
    expect(bootCoreSchedule(['vaulter.features.changes' => false]))->not->toHaveKey('vaulter:changes:prune');
});

it('treats a null frequency as a disabled task', function (): void {
    expect(bootCoreSchedule(['vaulter.schedule.webhooks_retry' => null]))->not->toHaveKey('vaulter:webhooks:retry');
});

it('fails boot validation on an invalid frequency', function (): void {
    config(['vaulter.schedule.webhooks_prune' => 'sometimes']);

    app(ConfigValidator::class)->validate(app(VaulterConfig::class), app());
})->throws(InvalidConfigurationException::class);

it('fails registration on an invalid frequency', function (): void {
    config(['vaulter.schedule.webhooks_prune' => 'sometimes']);

    (new SchedulingRegistrar)->register(app());
})->throws(InvalidConfigurationException::class);
