<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Console\Scheduling\Schedule;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Configuration\ScheduleFrequency;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

dataset('frequencies', [
    'every minute' => ['everyMinute', '* * * * *'],
    'five minutes' => ['everyFiveMinutes', '*/5 * * * *'],
    'hourly' => ['hourly', '0 * * * *'],
    'daily' => ['daily', '0 0 * * *'],
    'weekly' => ['weekly', '0 0 * * 0'],
    'daily at' => ['dailyAt:03:10', '10 3 * * *'],
    'hourly at' => ['hourlyAt:15', '15 * * * *'],
    'weekly on' => ['weeklyOn:0,04:30', '30 4 * * 0'],
    'cron' => ['7 */2 * * 1-5', '7 */2 * * 1-5'],
]);

it('maps a valid value to its cron expression', function (string $value, string $expression): void {
    $event = ScheduleFrequency::parse('k', $value)->applyTo((new Schedule)->command('x'));

    expect($event->expression)->toBe($expression);
})->with('frequencies');

it('treats null as a disabled task', function (): void {
    expect(ScheduleFrequency::parse('k', null))->toBeNull();
});

it('rejects an unknown value', function (mixed $value): void {
    ScheduleFrequency::parse('vaulter.schedule.k', $value);
})->with(['everySecond', 'dailyAt:25:00', 'dailyAt:3:10', 'hourlyAt:60', 'weeklyOn:7,01:00', '* * * *', '61 * * * *', '', 5, true, [['daily']]])
    ->throws(InvalidConfigurationException::class);

it('leaves keys missing from the host config off', function (): void {
    $config = new ScheduleConfig(new Repository(['vaulter-x' => ['schedule' => ['a' => 'daily']]]));

    expect($config->frequencies('vaulter-x', ['a', 'b']))->toHaveKeys(['a', 'b'])
        ->and($config->frequencies('vaulter-x', ['b'])['b'])->toBeNull();
});

it('requires a boolean enabled flag', function (): void {
    (new ScheduleConfig(new Repository(['vaulter' => ['schedule' => ['enabled' => 'yes']]])))->enabled();
})->throws(InvalidConfigurationException::class);
