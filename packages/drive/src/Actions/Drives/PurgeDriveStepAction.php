<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Drives;

use Vaulter\Access\OperationGuard;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Trash\PurgeNodeAction;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Data\Trash\PurgeNodeInput;
use Vaulter\Drive\Events\Drives\DriveDeleted;
use Vaulter\Drive\Uploads\UploadSessionManager;
use Vaulter\Enums\Drive\DriveStatus;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Changes\ChangeEntryRepository;
use Vaulter\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\VaulterManager;

/**
 * One resumable step of purging a drive that is being deleted: open uploads
 * are aborted, up to BATCH children of the root go one by one — each in its own
 * transaction through PurgeNodeAction as the system actor 'drive-purge', the same
 * lifecycle, CAS and quota path as an ordinary purge — and when the root has no
 * children left one last transaction removes the root, the drive's change journal and
 * the drive row. Activity and outbox rows stay as history. Safe to repeat after any crash.
 */
final readonly class PurgeDriveStepAction
{
    public const int BATCH = 100;

    public function __construct(
        private VaulterConfig $config,
        private VaulterDatabase $database,
        private VaulterManager $manager,
        private PurgeNodeAction $purge,
        private UploadSessionManager $uploads,
        private DriveConfig $driveConfig,
        private NodeStoreRepository $nodes,
        private EventRecorder $recorder,
        private ChangeEntryRepository $changes,
        private ChangeHeadRepository $heads,
    ) {}

    /** @return bool true when the drive is gone (or was never being deleted), false while children remain */
    public function execute(int|string $driveId): bool
    {
        return $this->manager->withoutTenancy(function () use ($driveId): bool {
            $drive = $this->config->model('drive')::query()->whereKey($driveId)->first();
            if (!$drive instanceof Drive || $drive->status !== DriveStatus::Deleting) {
                return true;
            }
            $context = DriveContext::system(drive: $drive, reason: OperationGuard::PURGE_REASON);
            $this->abortUploads($context, $drive);

            $nodeClass = $this->config->model('node');
            $root = $drive->root_node_id === null ? null : $nodeClass::query()->whereKey($drive->root_node_id)->first();
            if (!$root instanceof Node) {
                return $this->finish($context, null);
            }
            $children = $nodeClass::query()->where('drive_id', $drive->getKey())->where('parent_id', $root->getKey())->orderBy('id')->limit(self::BATCH)->get();
            foreach ($children as $child) {
                try {
                    $this->purge->execute($context, new PurgeNodeInput(node: $child, bypassTrashRequirement: true));
                } catch (NodeNotFoundException) {
                    // Gone since the read: the purge converges.
                }
            }
            if ($children->count() === self::BATCH) {
                return false;
            }

            return $this->finish($context, $root);
        });
    }

    private function abortUploads(DriveContext $context, Drive $drive): void
    {
        $class = $this->driveConfig->model('upload_session');
        foreach ($class::query()->where('drive_id', $drive->getKey())->whereIn('status', ['initiated', 'uploading', 'assembled'])->cursor() as $session) {
            $this->uploads->abort(session: $session, actor: $context->actor->ref(), correlationId: $context->correlationId);
        }
    }

    /**
     * The last transaction: the root and the drive row go together, only when nothing
     * is left under the root. A child created since the last batch keeps the purge going.
     *
     * @return bool true when nothing is left to do, false when children appeared
     */
    private function finish(DriveContext $context, ?Node $root): bool
    {
        return $this->database->transaction(function () use ($context, $root): bool {
            $this->database->lockTree($context->driveId());
            $drive = $this->config->model('drive')::query()->whereKey($context->driveId())->lockForUpdate()->first();
            if (!$drive instanceof Drive || $drive->status !== DriveStatus::Deleting) {
                return true;
            }
            $nodeClass = $this->config->model('node');
            if ($root !== null && $nodeClass::query()->where('drive_id', $drive->getKey())->where('parent_id', $root->getKey())->exists()) {
                return false;
            }
            if ($root !== null) {
                $this->nodes->purgeSubtree(collect([$root->id]));
            }
            // The foreign keys cascade, but not on a SQLite connection without them: the
            // journal goes explicitly, head last (the journal lock comes after the drive's).
            $this->changes->deleteForDrive($context->driveId());
            $this->heads->delete($context->driveId());
            $drive->delete();
            $this->recorder->record(new DriveDeleted(driveId: Identity::stringify($drive->getKey()), ownerType: $drive->owner_type,
                ownerId: Identity::stringify($drive->owner_id), tenantId: $drive->tenant_id,
                profile: $drive->profile, actor: $context->actor->ref(), correlationId: $context->correlationId));

            return true;
        });
    }
}
