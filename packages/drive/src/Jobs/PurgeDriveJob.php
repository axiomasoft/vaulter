<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Support\Identity;

/**
 * Purge a drive that is being deleted (PLAN2.D27), one resumable step per run
 * ({@see PurgeDriveStepAction}). The job queues itself again while children remain, so
 * one run never holds a worker for long; re-running a finished purge does nothing.
 */
final class PurgeDriveJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BATCH = PurgeDriveStepAction::BATCH;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int|string $driveId) {}

    public function uniqueId(): string
    {
        return 'vaulter-drive-purge:'.Identity::stringify($this->driveId);
    }

    public function handle(PurgeDriveStepAction $step, VaulterDatabase $database): void
    {
        if (!$database->write(fn (): bool => $step->execute($this->driveId))) {
            self::dispatch($this->driveId);
        }
    }
}
