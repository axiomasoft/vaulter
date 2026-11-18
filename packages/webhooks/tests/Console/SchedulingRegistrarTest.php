<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Configuration\VaulterOptions;
use Vaulter\Webhooks\Configuration\WebhooksOptions;

/**
 * The webhooks tasks on the host scheduler (moved from the kernel suite with the module): the keys live in
 * `vaulter-webhooks.schedule`, the global switch `vaulter.schedule.enabled` stays the kernel's.
 *
 * @param  array<string, mixed> $config
 * @return array<string, Event> keyed by the task name
 */
function webhookTasks(array $config = []): array
{
    config($config);
    app()->forgetInstance(Schedule::class);

    $tasks = [];
    foreach (app(Schedule::class)->events() as $event) {
        if (str_starts_with((string) $event->description, 'vaulter:webhooks:')) {
            $tasks[$event->description] = $event;
        }
    }

    return $tasks;
}

it('schedules the retry and the prune with overlap and single-server guards', function (): void {
    $tasks = webhookTasks();

    expect(array_keys($tasks))->toEqualCanonicalizing(['vaulter:webhooks:retry', 'vaulter:webhooks:prune'])
        ->and($tasks['vaulter:webhooks:retry']->expression)->toBe('* * * * *')
        ->and($tasks['vaulter:webhooks:prune']->expression)->toBe('10 3 * * *')
        ->and($tasks['vaulter:webhooks:prune']->expiresAt)->toBe(120);
    foreach ($tasks as $task) {
        expect($task->withoutOverlapping)->toBeTrue()->and($task->onOneServer)->toBeTrue();
    }
});

it('registers nothing while the host scheduler switch is off', function (): void {
    expect(webhookTasks(['vaulter.schedule.enabled' => false]))->toBe([]);
});

it('treats a null frequency as a disabled task', function (): void {
    expect(webhookTasks(['vaulter-webhooks.schedule.webhooks_retry' => null]))
        ->not->toHaveKey('vaulter:webhooks:retry')
        ->toHaveKey('vaulter:webhooks:prune');
});

it('reads the frequencies from the vaulter-webhooks root, not from the kernel schedule', function (): void {
    expect(fn (): VaulterOptions => VaulterOptions::fromArray(['schedule' => ['webhooks_retry' => 'hourly']]))
        ->toThrow(InvalidConfiguration::class, 'vaulter.schedule.webhooks_retry');

    expect(webhookTasks()['vaulter:webhooks:retry']->expression)->toBe('* * * * *');
});

it('fails boot validation on an invalid frequency', function (): void {
    config(['vaulter-webhooks.schedule.webhooks_prune' => 'sometimes']);

    app(WebhooksOptions::class)->validate();
})->throws(InvalidConfiguration::class, 'vaulter-webhooks.schedule.webhooks_prune');
