<?php

declare(strict_types=1);

namespace Vaulter\Drive\Access;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\Access\PermissionDriver;
use Vaulter\Drive\Data\DriveRef;
use Vaulter\Drive\Events\Access\EffectivePermissionsChanged;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Repositories\DriveReadRepository;
use Vaulter\Events\EventRecorder;
use Vaulter\Modules\ModuleId;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\OperationRunner;
use Vaulter\Operations\OperationSpec;
use Vaulter\ValueObjects\Operations\ScopeRef;
use Vaulter\ValueObjects\Operations\TenantRef;

/**
 * How the host tells the drive that effective permissions changed outside it: the roles its
 * {@see PermissionDriver} answers, a drive's policy or profile, a deactivated tenant. The drive cannot see those
 * changes, so whoever keeps derived authority (a collaboration session) would not check it again before it expires.
 *
 * `changed($drive, $reason)` records `drive.access.effective_permissions_changed` for that drive, or for every drive
 * when `$drive` is null, in an operation of its own (`drive.access.invalidate`, a system actor whose reason is
 * `$reason`). Call it after the host's own change committed: the event is what makes a session re-check, and a check
 * before the commit would still read the old rights.
 *
 * In the same transaction, after the event is recorded, the change journal epoch of that drive (or of every drive,
 * in one UPDATE) is raised, so every change cursor expires (410) and its reader bootstraps against the new rights.
 * The journal seq does not move; the event itself is not journaled. A failure rolls back both, and the
 * host may call again.
 */
#[Api]
final readonly class AccessInvalidation
{
    /** The operation that records a host's invalidation. */
    public const string OPERATION = 'drive.access.invalidate';

    #[Internal]
    public function __construct(
        private OperationRunner $runner,
        private EventRecorder $recorder,
        private DriveReadRepository $drives,
        private ChangeHeadRepository $heads,
    ) {}

    /**
     * @param string $reason a lowercase identifier of at most 64 bytes (`host-role`, `tenant.deactivated`, …)
     *
     * @throws InvalidArgumentException for a malformed reason or a drive that does not exist
     */
    public function changed(?DriveRef $drive, string $reason): void
    {
        if (preg_match(EffectivePermissionsChanged::REASON_PATTERN, $reason) !== 1) {
            throw new InvalidArgumentException('[Vaulter] An access invalidation reason must be a lowercase identifier of at most 64 bytes.');
        }
        $spec = new OperationSpec(self::OPERATION, new ModuleId('drive'));

        if (!$drive instanceof DriveRef) {
            $context = OperationContext::system(scope: new ScopeRef('access', 'global'), tenant: TenantRef::none(), reason: $reason);
            $this->runner->write($spec, $context, function () use ($context, $reason): void {
                $this->recorder->record(EffectivePermissionsChanged::global(reason: $reason, actor: $context->actor->ref(), correlationId: $context->correlationId));
                $this->heads->bumpAllEpochs();
            });

            return;
        }

        $model = $this->drives->find($drive->id) ?? throw new InvalidArgumentException('[Vaulter] No drive ['.$drive->id.'] to invalidate the permissions of.');
        $context = DriveContext::system(drive: $model, reason: $reason);
        $this->runner->write($spec, $context->operation, function () use ($context, $reason): void {
            $this->recorder->record(EffectivePermissionsChanged::forDrive(drive: $context->drive, reason: $reason, actor: $context->actor->ref(), correlationId: $context->correlationId));
            $this->heads->bumpEpoch($context->driveId());
        });
    }
}
