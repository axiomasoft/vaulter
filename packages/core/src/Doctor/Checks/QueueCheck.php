<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Contracts\Config\Repository;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

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
        $driver = self::text($this->config->get('queue.connections.'.$default.'.driver'), $default);
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

    private static function text(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
