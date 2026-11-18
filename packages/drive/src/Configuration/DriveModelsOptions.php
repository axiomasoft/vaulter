<?php

declare(strict_types=1);

namespace Vaulter\Drive\Configuration;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Packman\Config\Exceptions\InvalidConfiguration;
use Packman\Config\Laravel\ConfigurationValidator;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Content\ScanBackfillRun;
use Vaulter\Drive\Models\Content\ScanEvidenceRecord;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Content\ScanPolicyState;
use Vaulter\Drive\Models\Content\ScanRequest;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;

/**
 * Immutable compiled swappable Eloquent model classes for `vaulter-drive.models.*`.
 */
#[Api(since: '1.0')]
final readonly class DriveModelsOptions
{
    /** @var array<string, class-string<Model>> */
    private const array DEFAULTS = [
        'drive' => Drive::class,
        'node' => Node::class,
        'file' => File::class,
        'scan_obligation' => ScanObligation::class,
        'scan_request' => ScanRequest::class,
        'scan_evidence' => ScanEvidenceRecord::class,
        'scan_policy_state' => ScanPolicyState::class,
        'scan_backfill_run' => ScanBackfillRun::class,
        'file_revision' => FileRevision::class,
        'file_revision_blob' => FileRevisionBlob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,
        'activity' => Activity::class,
        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
    ];

    /**
     * @param class-string<Drive>              $drive
     * @param class-string<Node>               $node
     * @param class-string<File>               $file
     * @param class-string<ScanObligation>     $scanObligation
     * @param class-string<ScanRequest>        $scanRequest
     * @param class-string<ScanEvidenceRecord> $scanEvidence
     * @param class-string<ScanPolicyState>    $scanPolicyState
     * @param class-string<ScanBackfillRun>    $scanBackfillRun
     * @param class-string<FileRevision>       $fileRevision
     * @param class-string<FileRevisionBlob>   $fileRevisionBlob
     * @param class-string<NodeGrant>          $grant
     * @param class-string<NodeShare>          $nodeShare
     * @param class-string<Activity>           $activity
     * @param class-string<UploadSession>      $uploadSession
     * @param class-string<UploadPart>         $uploadPart
     */
    #[Internal]
    public function __construct(
        #[Internal]
        public string $drive = Drive::class,
        #[Internal]
        public string $node = Node::class,
        #[Internal]
        public string $file = File::class,
        #[Internal]
        public string $scanObligation = ScanObligation::class,
        #[Internal]
        public string $scanRequest = ScanRequest::class,
        #[Internal]
        public string $scanEvidence = ScanEvidenceRecord::class,
        #[Internal]
        public string $scanPolicyState = ScanPolicyState::class,
        #[Internal]
        public string $scanBackfillRun = ScanBackfillRun::class,
        #[Internal]
        public string $fileRevision = FileRevision::class,
        #[Internal]
        public string $fileRevisionBlob = FileRevisionBlob::class,
        #[Internal]
        public string $grant = NodeGrant::class,
        #[Internal]
        public string $nodeShare = NodeShare::class,
        #[Internal]
        public string $activity = Activity::class,
        #[Internal]
        public string $uploadSession = UploadSession::class,
        #[Internal]
        public string $uploadPart = UploadPart::class,
    ) {}

    public static function fromArray(mixed $raw, string $path, ConfigurationValidator $validator): self
    {
        if (!is_array($raw)) {
            $validator->add($path, 'must be an array.');

            return new self;
        }

        $validator->rejectUnknownKeys($raw, array_keys(self::DEFAULTS), $path);

        /** @var array<string, class-string<Model>> $resolved */
        $resolved = [];
        foreach (self::DEFAULTS as $key => $baseClass) {
            $value = $raw[$key] ?? $baseClass;
            if (!is_string($value) || $value === '' || !class_exists($value) || !is_a($value, $baseClass, true)) {
                $validator->add($path.'.'.$key, '[Vaulter] Invalid model class for '.$path.'.'.$key.'.');
                $resolved[$key] = $baseClass;

                continue;
            }
            $resolved[$key] = $value;
        }

        /**
         * @var class-string<Drive>              $drive
         * @var class-string<Node>               $node
         * @var class-string<File>               $file
         * @var class-string<ScanObligation>     $scanObligation
         * @var class-string<ScanRequest>        $scanRequest
         * @var class-string<ScanEvidenceRecord> $scanEvidence
         * @var class-string<ScanPolicyState>    $scanPolicyState
         * @var class-string<ScanBackfillRun>    $scanBackfillRun
         * @var class-string<FileRevision>       $fileRevision
         * @var class-string<FileRevisionBlob>   $fileRevisionBlob
         * @var class-string<NodeGrant>          $grant
         * @var class-string<NodeShare>          $nodeShare
         * @var class-string<Activity>           $activity
         * @var class-string<UploadSession>      $uploadSession
         * @var class-string<UploadPart>         $uploadPart
         */
        [
            'drive' => $drive,
            'node' => $node,
            'file' => $file,
            'scan_obligation' => $scanObligation,
            'scan_request' => $scanRequest,
            'scan_evidence' => $scanEvidence,
            'scan_policy_state' => $scanPolicyState,
            'scan_backfill_run' => $scanBackfillRun,
            'file_revision' => $fileRevision,
            'file_revision_blob' => $fileRevisionBlob,
            'grant' => $grant,
            'node_share' => $nodeShare,
            'activity' => $activity,
            'upload_session' => $uploadSession,
            'upload_part' => $uploadPart,
        ] = $resolved;

        return new self(
            drive: $drive,
            node: $node,
            file: $file,
            scanObligation: $scanObligation,
            scanRequest: $scanRequest,
            scanEvidence: $scanEvidence,
            scanPolicyState: $scanPolicyState,
            scanBackfillRun: $scanBackfillRun,
            fileRevision: $fileRevision,
            fileRevisionBlob: $fileRevisionBlob,
            grant: $grant,
            nodeShare: $nodeShare,
            activity: $activity,
            uploadSession: $uploadSession,
            uploadPart: $uploadPart,
        );
    }

    /**
     * @return (
     *     $key is 'scan_backfill_run' ? class-string<ScanBackfillRun> : (
     *     $key is 'scan_policy_state' ? class-string<ScanPolicyState> : (
     *     $key is 'scan_evidence' ? class-string<ScanEvidenceRecord> : (
     *     $key is 'scan_request' ? class-string<ScanRequest> : (
     *     $key is 'scan_obligation' ? class-string<ScanObligation> : (
     *     $key is 'drive' ? class-string<Drive> : (
     *     $key is 'node' ? class-string<Node> : (
     *     $key is 'file' ? class-string<File> : (
     *     $key is 'file_revision' ? class-string<FileRevision> : (
     *     $key is 'file_revision_blob' ? class-string<FileRevisionBlob> : (
     *     $key is 'grant' ? class-string<NodeGrant> : (
     *     $key is 'node_share' ? class-string<NodeShare> : (
     *     $key is 'activity' ? class-string<Activity> : (
     *     $key is 'upload_session' ? class-string<UploadSession> : (
     *     $key is 'upload_part' ? class-string<UploadPart> : class-string<Model>))))))))))))))
     * )
     */
    #[Internal]
    public function classFor(string $key): string
    {
        return match ($key) {
            'drive' => $this->drive,
            'node' => $this->node,
            'file' => $this->file,
            'scan_obligation' => $this->scanObligation,
            'scan_request' => $this->scanRequest,
            'scan_evidence' => $this->scanEvidence,
            'scan_policy_state' => $this->scanPolicyState,
            'scan_backfill_run' => $this->scanBackfillRun,
            'file_revision' => $this->fileRevision,
            'file_revision_blob' => $this->fileRevisionBlob,
            'grant' => $this->grant,
            'node_share' => $this->nodeShare,
            'activity' => $this->activity,
            'upload_session' => $this->uploadSession,
            'upload_part' => $this->uploadPart,
            default => throw InvalidConfiguration::forMessage('[Vaulter] Invalid model class for vaulter-drive.models.'.$key.'.'),
        };
    }
}
