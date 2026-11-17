<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Drive\Providers\BindingsRegistrar;

/** @return array<string, Event> keyed by the task name */
function bootDriveSchedule(array $config = []): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    $tasks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (preg_match('/^vaulter:(uploads|trash|drives|revisions|scan):/', (string) $event->description) === 1) {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

it('schedules the drive maintenance tasks with overlap and single-server guards', function (): void {
    $tasks = bootDriveSchedule(['vaulter-drive.scan.mode' => 'all_content']);

    expect(array_keys($tasks))->toEqualCanonicalizing([
        'vaulter:uploads:sweep', 'vaulter:trash:purge', 'vaulter:drives:purge', 'vaulter:revisions:prune', 'vaulter:scan:reconcile',
    ])
        ->and($tasks['vaulter:uploads:sweep']->expression)->toBe('15 * * * *')
        ->and($tasks['vaulter:trash:purge']->expression)->toBe('30 3 * * *')
        ->and($tasks['vaulter:scan:reconcile']->expression)->toBe('*/5 * * * *')
        ->and($tasks['vaulter:scan:reconcile']->expiresAt)->toBe(10)
        ->and($tasks['vaulter:scan:reconcile']->command)->toContain('--all-tenants', '--reason=scheduled', '--limit=100', '--max-seconds=30');
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue();
    }
});

it('schedules scan reconcile without the webhooks feature', function (): void {
    $tasks = bootDriveSchedule(['vaulter-drive.scan.mode' => 'all_content']);

    expect($tasks)->toHaveKey('vaulter:scan:reconcile');
});

it('does not schedule scan reconcile while scanning is off', function (): void {
    expect(bootDriveSchedule(['vaulter-drive.scan.mode' => null]))->not->toHaveKey('vaulter:scan:reconcile');
});

it('registers nothing when scheduling is disabled', function (): void {
    expect(bootDriveSchedule(['vaulter.schedule.enabled' => false, 'vaulter-drive.scan.mode' => 'all_content']))->toBe([]);
});

it('schedules the recount only as a dry run when a frequency is set', function (): void {
    expect(bootDriveSchedule())->not->toHaveKey('vaulter:drives:recount');

    $tasks = bootDriveSchedule(['vaulter-drive.schedule.drives_recount' => 'weekly']);

    expect($tasks['vaulter:drives:recount']->command)->toContain('--dry-run');
});

it('turns a task off with null', function (): void {
    expect(bootDriveSchedule(['vaulter-drive.schedule.trash_purge' => null]))->not->toHaveKey('vaulter:trash:purge');
});

it('rejects an invalid frequency at boot', function (): void {
    config(['vaulter-drive.schedule.uploads_sweep' => 'often']);

    (new BindingsRegistrar)->boot(app());
})->throws(InvalidConfiguration::class);

/** @return array<string, Event> the change-journal prune keyed by its description */
function bootChangesPruneSchedule(array $config): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    $tasks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if ((string) $event->description === 'vaulter:changes:prune') {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

it('schedules the change-journal prune from vaulter-drive.schedule.changes_prune while the journal is on', function (): void {
    $tasks = bootChangesPruneSchedule(['vaulter-drive.features.changes' => true]);

    expect(array_keys($tasks))->toBe(['vaulter:changes:prune'])
        ->and($tasks['vaulter:changes:prune']->expression)->toBe('20 3 * * *')
        ->and($tasks['vaulter:changes:prune']->withoutOverlapping)->toBeTrue();
});

it('leaves the change-journal prune off when the journal is off or the key is null', function (): void {
    expect(bootChangesPruneSchedule(['vaulter-drive.features.changes' => false]))->toBe([])
        ->and(bootChangesPruneSchedule(['vaulter-drive.features.changes' => true, 'vaulter-drive.schedule.changes_prune' => null]))->toBe([]);
});
