<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Illuminate\Database\Eloquent\Builder;
use RuntimeException;
use Throwable;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Models\Drive\Drive;

final readonly class UploadCleanupReconciler
{
    public function __construct(private VaulterDatabase $database, private UploadSessionStoreRepository $sessions, private StagedUpload $staged, private DriveConfig $config) {}

    /** @param list<int|string>|Builder<Drive>|null $driveIds
     * @return array{cleanup_completed:int,cleanup_failed:int,cleanup_pending:int}
     */
    public function reconcile(int $limit = 100, int $maxSeconds = 30, bool $dryRun = false, array|Builder|null $driveIds = null): array
    {
        $class = $this->config->model('upload_session');
        $query = $class::query()->where('status', UploadStatus::Committed->value)->where('cleanup_pending', true)
            ->where(fn ($query) => $query->whereNull('cleanup_available_at')->orWhere('cleanup_available_at', '<=', now()));
        if ($driveIds !== null) {
            $query->whereIn('drive_id', $driveIds);
        }
        $sessions = $query->orderBy('cleanup_available_at')->orderBy('id')->limit(max(1, $limit))->get();
        $result = ['cleanup_completed' => 0, 'cleanup_failed' => 0, 'cleanup_pending' => $sessions->count()];
        $deadline = microtime(true) + max(1, $maxSeconds);
        if (!$dryRun) {
            foreach ($sessions as $session) {
                if (microtime(true) >= $deadline) {
                    break;
                }
                if ($this->cleanup($session)) {
                    $result['cleanup_completed']++;
                    $result['cleanup_pending']--;
                } else {
                    $result['cleanup_failed']++;
                }
            }
        }

        return $result;
    }

    /** A failed wakeup must never turn a committed upload into a failed action. */
    public function cleanup(UploadSession $session): bool
    {
        try {
            return $this->database->transaction(function () use ($session): bool {
                $locked = $this->sessions->acquireForPatch($session);
                if ($locked->status !== UploadStatus::Committed || !$locked->cleanup_pending) {
                    return true;
                }
                if ($locked->result_node_id === null || $locked->result_revision_id === null || $locked->result_blob_id === null || $locked->input_fingerprint === null
                    || (string) $locked->assembled_blob_id !== (string) $locked->result_blob_id) {
                    throw new RuntimeException('Upload cleanup has incomplete committed pointers.');
                }
                $nodeClass = app(VaulterConfig::class)->model('node');
                $node = $nodeClass::query()->withTrashed()->where('drive_id', $locked->drive_id)->whereKey($locked->result_node_id)->first();
                $revision = $node?->file()->first()?->versions()->whereKey($locked->result_revision_id)->first();
                if ($revision === null || (string) $revision->blob_id !== (string) $locked->result_blob_id) {
                    throw new RuntimeException('Upload cleanup has inconsistent committed pointers.');
                }
                // Reloading prevents a caller-modified disk relation or upload ID
                // from selecting another session's staging object.
                $disk = $this->staged->diskFor($locked);
                $key = $this->staged->keyFor($locked);
                $this->staged->discard($locked);
                if ($disk->exists($key)) {
                    throw new RuntimeException('Upload staging deletion failed.');
                }
                if ($locked->protocol === UploadProtocol::S3Multipart) {
                    foreach ($locked->parts()->get() as $part) {
                        $partKey = $key.'.parts/'.$part->part_no;
                        if ($disk->exists($partKey) && !$disk->delete($partKey)) {
                            throw new RuntimeException('Upload part deletion failed.');
                        }
                    }
                }
                $locked->forceFill(['cleanup_pending' => false, 'cleanup_available_at' => null, 'cleanup_last_reason' => null])->save();

                return true;
            }, attempts: 1);
        } catch (Throwable $exception) {
            try {
                $this->database->transaction(function () use ($session): void {
                    $locked = $this->sessions->acquireForPatch($session);
                    if ($locked->status === UploadStatus::Committed && $locked->cleanup_pending) {
                        $attempt = $locked->cleanup_attempts + 1;
                        $delay = [1 => 30, 2 => 120, 3 => 600, 4 => 1800][$attempt] ?? 3600;
                        $locked->forceFill(['cleanup_attempts' => $attempt, 'cleanup_last_reason' => 'cleanup_failed', 'cleanup_available_at' => now()->addSeconds($delay)])->save();
                    }
                }, attempts: 1);
                report($exception);
            } catch (Throwable) {
                // Persistence/reporting may also be unavailable. The existing
                // durable pending flag still lets the next reconciliation retry.
            }

            return false;
        }
    }
}
