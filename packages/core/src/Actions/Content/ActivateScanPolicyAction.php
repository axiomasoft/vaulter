<?php

declare(strict_types=1);

namespace Vaulter\Actions\Content;

use Illuminate\Support\Str;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Media\Enums\Cas\ScanMode;
use Vaulter\Models\Content\ScanPolicyState;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Content\ScanAuthorityRepository;
use Vaulter\Repositories\Content\ScanPolicyStateRepository;
use Vaulter\Support\Identity;

/**
 * Records a drive's scan activation, fixes its rollout boundary once and opens or reopens its backfill run.
 *
 * Lock order: tree exclusive, drive row, policy state row, backfill run row.
 */
#[Internal]
final readonly class ActivateScanPolicyAction
{
    public function __construct(private VaulterDatabase $database, private ScanAuthorityRepository $authority, private ScanPolicyStateRepository $states) {}

    public function execute(Drive $drive, ScanPolicySpec $spec, ?string $rolloutKey, DriveContext $context): ScanPolicyState
    {
        return $this->database->transaction(function () use ($drive, $spec, $rolloutKey, $context): ScanPolicyState {
            if (Identity::stringify($drive->getRawOriginal($drive->getKeyName())) !== (string) $context->driveId() || $rolloutKey !== $spec->rolloutKey) {
                throw new LogicException('Scan activation scope or rollout differs.');
            }
            $this->database->lockTree($context->driveId());
            $current = $this->authority->lockDriveOrFail($context->driveId());
            $state = $this->states->lockState($current->id, $spec->predicateKey);
            if ($state !== null && (string) $state->tenant_id !== (string) $current->tenant_id) {
                throw new LogicException('Scan activation tenant differs.');
            }
            if ($state?->rollout_key !== null && $rolloutKey !== null && $state->rollout_key !== $rolloutKey) {
                throw new LogicException('A scan rollout boundary cannot be replaced.');
            }
            $boundary = $state?->boundary_sequence;
            if ($spec->mode === ScanMode::NewContentOnly && $boundary === null) {
                if ($rolloutKey === null) {
                    throw new LogicException('New-content scan activation requires a rollout key.');
                }
                $boundary = $current->scan_admission_sequence;
            }
            $now = now('UTC');
            $state ??= $this->states->makeState();
            $state->forceFill(['drive_id' => $current->id, 'tenant_id' => $current->tenant_id,
                'predicate_key' => $spec->predicateKey, 'mode' => $spec->mode->value,
                'scanner_key' => $spec->scannerKey, 'scanner_version' => $spec->scannerVersion,
                'policy_version' => $spec->policyVersion, 'spec_key' => $spec->specKey(),
                'config_fingerprint' => $spec->configFingerprint(), 'rollout_key' => $state->rollout_key ?? $rolloutKey,
                'boundary_sequence' => $boundary, 'activated_at' => $now, 'updated_at' => $now])->save();
            if ($spec->mode !== ScanMode::Off) {
                $run = $this->states->lockBackfillRun($current->id, $spec->specKey());
                if ($run === null) {
                    $run = $this->states->makeBackfillRun();
                    $run->forceFill(['drive_id' => $current->id, 'tenant_id' => $current->tenant_id,
                        'spec_key' => $spec->specKey(), 'request_key' => (string) Str::uuid(),
                        'target_sequence' => $current->scan_admission_sequence, 'last_admission_sequence' => 0,
                        'state' => 'pending', 'created_at' => $now, 'updated_at' => $now])->save();
                } elseif ($run->target_sequence < $current->scan_admission_sequence) {
                    $run->forceFill(['target_sequence' => $current->scan_admission_sequence, 'state' => 'pending', 'updated_at' => $now])->save();
                }
            }

            return $state;
        });
    }
}
