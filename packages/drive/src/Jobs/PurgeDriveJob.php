<?php

declare(strict_types=1);

namespace Vaulter\Drive\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Drives\PurgeDriveStepAction;
use Vaulter\Jobs\JobRequest;
use Vaulter\Jobs\PublicationContext;
use Vaulter\Support\Identity;

/**
 * Purge a drive that is being deleted, one resumable step per run
 * ({@see PurgeDriveStepAction}). The job queues itself again while children remain, so
 * one run never holds a worker for long; re-running a finished purge does nothing.
 */
#[Internal]
final class PurgeDriveJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const int BATCH = PurgeDriveStepAction::BATCH;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public int|string $driveId, public ?PublicationContext $publicationContext = null) {}

    public function uniqueId(): string
    {
        return 'vaulter-drive-purge:'.Identity::stringify($this->driveId);
    }

    public function handle(PurgeDriveStepAction $step, VaulterDatabase $database): void
    {
        if (!$database->write(fn (): bool => $step->execute($this->driveId))) {
            $publisher = app(JobPublisher::class);
            $prepared = $publisher->prepare(new JobRequest('vaulter.drive:purge', ['driveId' => (string) $this->driveId]), $this->publicationContext);
            $publisher->publish($prepared);
        }
    }
}
