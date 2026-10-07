<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use Illuminate\Support\Str;
use LogicException;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanMode;
use Vaulter\Models\Content\ScanPolicyState;
use Vaulter\Models\Drive\Drive;
use Vaulter\Operations\OperationContext;
use Vaulter\Support\Identity;

final readonly class ScanPolicyStateRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database) {}

    public function state(Drive $drive, ScanPolicySpec $spec): ?ScanPolicyState
    {
        $class = $this->config->model('scan_policy_state');
        $driveClass = $this->config->model('drive');
        $authoritative = $driveClass::query()->whereKey($drive->getRawOriginal($drive->getKeyName()))->firstOrFail();

        return $class::query()->where('drive_id', $authoritative->id)->where('tenant_id', $authoritative->tenant_id)->where('predicate_key', $spec->predicateKey)->first();
    }

    public function activate(Drive $drive, ScanPolicySpec $spec, ?string $rolloutKey, OperationContext $context): ScanPolicyState
    {
        return $this->database->transaction(function () use ($drive, $spec, $rolloutKey, $context): ScanPolicyState {
            if (Identity::stringify($drive->getRawOriginal($drive->getKeyName())) !== (string) $context->driveId() || $rolloutKey !== $spec->rolloutKey) {
                throw new LogicException('Scan activation scope or rollout differs.');
            }
            $this->database->lockTree($context->driveId());
            $driveClass = $this->config->model('drive');
            $current = $driveClass::query()->whereKey($context->driveId())->lockForUpdate()->firstOrFail();
            $class = $this->config->model('scan_policy_state');
            $state = $class::query()->where('drive_id', $current->id)->where('predicate_key', $spec->predicateKey)->lockForUpdate()->first();
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
            $state ??= new $class;
            $state->forceFill(['drive_id' => $current->id, 'tenant_id' => $current->tenant_id,
                'predicate_key' => $spec->predicateKey, 'mode' => $spec->mode->value,
                'scanner_key' => $spec->scannerKey, 'scanner_version' => $spec->scannerVersion,
                'policy_version' => $spec->policyVersion, 'spec_key' => $spec->specKey(),
                'config_fingerprint' => $spec->configFingerprint(), 'rollout_key' => $state->rollout_key ?? $rolloutKey,
                'boundary_sequence' => $boundary, 'activated_at' => $now, 'updated_at' => $now])->save();
            if ($spec->mode !== ScanMode::Off) {
                $runClass = $this->config->model('scan_backfill_run');
                $run = $runClass::query()->where('drive_id', $current->id)->where('spec_key', $spec->specKey())->lockForUpdate()->first();
                if ($run === null) {
                    $run = new $runClass;
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
