<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Vaulter\Exceptions\Configuration\InvalidConfigurationException;

/**
 * One validated `schedule.*` config value. The grammar is closed so a typo fails at
 * boot instead of silently never running a maintenance task.
 */
final readonly class ScheduleFrequency
{
    private const array PLAIN = [
        'everyMinute', 'everyTwoMinutes', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes',
        'everyThirtyMinutes', 'hourly', 'daily', 'weekly', 'monthly',
    ];

    private function __construct(private string $method, private string $argument) {}

    /** @throws InvalidConfigurationException */
    public static function parse(string $key, mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $invalid = static fn (): InvalidConfigurationException => new InvalidConfigurationException(
            '[Vaulter] Invalid schedule frequency for '.$key.'.',
        );
        if (!is_string($value) || trim($value) === '') {
            throw $invalid();
        }
        if (in_array($value, self::PLAIN, true)) {
            return new self($value, '');
        }
        if (preg_match('/^dailyAt:([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1) {
            return new self('dailyAt', substr($value, 8));
        }
        if (preg_match('/^hourlyAt:([0-9]|[1-5]\d)$/', $value) === 1) {
            return new self('hourlyAt', substr($value, 9));
        }
        if (preg_match('/^weeklyOn:([0-6]),([01]\d|2[0-3]):([0-5]\d)$/', $value) === 1) {
            return new self('weeklyOn', substr($value, 9));
        }
        if (count(preg_split('/\s+/', trim($value)) ?: []) === 5 && CronExpression::isValidExpression($value)) {
            return new self('cron', trim($value));
        }

        throw $invalid();
    }

    public function applyTo(Event $event): Event
    {
        return match ($this->method) {
            'cron' => $event->cron($this->argument),
            'dailyAt' => $event->dailyAt($this->argument),
            'hourlyAt' => $event->hourlyAt($this->argument),
            'weeklyOn' => $event->weeklyOn((int) explode(',', $this->argument)[0], explode(',', $this->argument)[1]),
            'everyMinute' => $event->everyMinute(),
            'everyTwoMinutes' => $event->everyTwoMinutes(),
            'everyFiveMinutes' => $event->everyFiveMinutes(),
            'everyTenMinutes' => $event->everyTenMinutes(),
            'everyFifteenMinutes' => $event->everyFifteenMinutes(),
            'everyThirtyMinutes' => $event->everyThirtyMinutes(),
            'hourly' => $event->hourly(),
            'daily' => $event->daily(),
            'weekly' => $event->weekly(),
            default => $event->monthly(),
        };
    }
}
