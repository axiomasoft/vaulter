<?php

declare(strict_types=1);

namespace Vaulter\Configuration;

use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Packman\Attributes\Api;
use Packman\Config\Exceptions\InvalidConfiguration;

/**
 * One validated `schedule.*` config value. The grammar is closed so a typo fails at
 * boot instead of silently never running a maintenance task.
 */
#[Api(since: '1.0')]
final readonly class ScheduleFrequency
{
    private const array PLAIN = [
        'everyMinute', 'everyTwoMinutes', 'everyFiveMinutes', 'everyTenMinutes', 'everyFifteenMinutes',
        'everyThirtyMinutes', 'hourly', 'daily', 'weekly', 'monthly',
    ];

    private function __construct(private string $method, private string $argument) {}

    /** @throws InvalidConfiguration */
    public static function parse(string $key, mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }
        $invalid = static fn (): InvalidConfiguration => InvalidConfiguration::forMessage(
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
        $parts = preg_split('/\s+/', trim($value));
        if (is_array($parts) && count($parts) === 5 && CronExpression::isValidExpression($value)) {
            return new self('cron', trim($value));
        }

        throw $invalid();
    }

    /**
     * @param list<string> $keys
     *
     * @throws InvalidConfiguration
     *
     * @return array<string, ?self>
     */
    public static function fromMap(string $path, mixed $values, array $keys): array
    {
        if (!is_array($values)) {
            throw InvalidConfiguration::forMessage('[Vaulter] '.$path.' must be an array.');
        }

        $frequencies = [];
        foreach ($keys as $key) {
            $frequencies[$key] = self::parse($path.'.'.$key, $values[$key] ?? null);
        }

        return $frequencies;
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
