<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Uploads;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Uploads\UploadSessionManager;

#[Internal]
final readonly class AbortUploadAction
{
    public function __construct(private UploadSessionManager $manager, private CompleteUploadAction $complete) {}

    public function execute(DriveContext $context, UploadSession $session): void
    {
        $this->manager->withSession($session, function (UploadSession $locked) use ($context): void {
            $this->complete->authorizeSession(context: $context, session: $locked);
            $this->manager->abort(session: $locked, actor: $context->actor->ref(), correlationId: $context->correlationId);
        });
    }
}
