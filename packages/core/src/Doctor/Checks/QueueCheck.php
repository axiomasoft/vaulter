<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

#[Internal]
final readonly class QueueCheck implements DoctorCheck
{
    public function __construct(private Repository $config) {}

    public function key(): string
    {
        return 'core.queue';
    }

    public function title(): string
    {
        return 'Queue connection';
    }

    public function run(DoctorContext $context): array
    {
        $default = self::text($this->config->get('queue.default'), 'sync');
        $driver = $this->driver($default);
        if ($driver === null) {
            return [DoctorFinding::error(
                code: 'core.queue.missing',
                message: 'The default queue connection is not configured.',
                hint: 'define queue.connections.'.$default.' with a driver, or point QUEUE_CONNECTION at a connection that exists',
                evidence: ['connection' => $default],
            )];
        }

        if (in_array($driver, ['sync', 'null'], true)) {
            return [DoctorFinding::warning(
                code: 'core.queue.synchronous',
                message: 'The default queue runs jobs inline or drops them: webhook deliveries and content scans do not run in the background.',
                hint: 'set QUEUE_CONNECTION to database, redis or another asynchronous driver',
                evidence: ['connection' => $default, 'driver' => $driver],
            )];
        }

        return [DoctorFinding::ok(code: 'core.queue.asynchronous', message: 'The default queue is asynchronous.', evidence: ['connection' => $default, 'driver' => $driver])];
    }

    /**
     * The configured driver, or null when that connection (or its driver) is absent.
     * The connection name `null` is Laravel's dropping driver even without a config entry.
     */
    private function driver(string $connection): ?string
    {
        $configured = $this->config->get('queue.connections.'.$connection);
        if (!is_array($configured)) {
            return $connection === 'null' ? 'null' : null;
        }

        $driver = self::text($configured['driver'] ?? null, '');

        return $driver === '' ? null : $driver;
    }

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
