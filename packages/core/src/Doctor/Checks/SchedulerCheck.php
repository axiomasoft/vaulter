<?php

declare(strict_types=1);

namespace Vaulter\Doctor\Checks;

use Illuminate\Console\Scheduling\Schedule;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\ScheduleConfig;
use Vaulter\Contracts\Doctor\DoctorCheck;
use Vaulter\Doctor\DoctorContext;
use Vaulter\Doctor\DoctorFinding;

#[Internal]
final readonly class SchedulerCheck implements DoctorCheck
{
    public function __construct(private ScheduleConfig $schedule, private Schedule $scheduler) {}

    public function key(): string
    {
        return 'core.scheduler';
    }

    public function title(): string
    {
        return 'Scheduled maintenance';
    }

    public function run(DoctorContext $context): array
    {
        $scheduled = 0;
        foreach ($this->scheduler->events() as $event) {
            if (str_contains((string) $event->command, 'vaulter:')) {
                $scheduled++;
            }
        }

        if ($this->schedule->enabled() || $scheduled > 0) {
            return [DoctorFinding::ok(code: 'core.scheduler.scheduled', message: 'Vaulter maintenance is on the scheduler.', evidence: ['enabled' => $this->schedule->enabled(), 'vaulter_tasks' => $scheduled])];
        }

        return [DoctorFinding::warning(
            code: 'core.scheduler.not_scheduled',
            message: 'No Vaulter maintenance task is scheduled: outbox relay, retries and pruning will not run.',
            hint: 'set vaulter.schedule.enabled to true, or schedule the vaulter:* commands yourself',
            evidence: ['enabled' => false, 'vaulter_tasks' => 0],
        )];
    }
}
