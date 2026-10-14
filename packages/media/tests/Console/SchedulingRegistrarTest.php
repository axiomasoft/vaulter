<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;
use Vaulter\Media\Providers\BindingsRegistrar;

/** @return array<string, Event> keyed by the task name */
function bootMediaSchedule(array $config = []): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    $tasks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (str_starts_with((string) $event->description, 'vaulter:blobs:')) {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

it('schedules blob gc and reconcile with overlap and single-server guards', function (): void {
    $tasks = bootMediaSchedule();

    expect(array_keys($tasks))->toEqualCanonicalizing(['vaulter:blobs:gc', 'vaulter:blobs:reconcile'])
        ->and($tasks['vaulter:blobs:gc']->expression)->toBe('0 4 * * *')
        ->and($tasks['vaulter:blobs:reconcile']->expression)->toBe('30 4 * * 0');
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue()->and($task->expiresAt)->toBe(120);
    }
});

it('registers nothing when scheduling is disabled', function (): void {
    expect(bootMediaSchedule(['vaulter.schedule.enabled' => false]))->toBe([]);
});

it('turns a task off with null', function (): void {
    expect(bootMediaSchedule(['vaulter-media.schedule.blobs_gc' => null]))->toHaveKey('vaulter:blobs:reconcile')->not->toHaveKey('vaulter:blobs:gc');
});

it('rejects an invalid frequency at boot', function (): void {
    config(['vaulter-media.schedule.blobs_gc' => 'whenever']);

    (new BindingsRegistrar)->boot(app());
})->throws(InvalidConfigurationException::class);
