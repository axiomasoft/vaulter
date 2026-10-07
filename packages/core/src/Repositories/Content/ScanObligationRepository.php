<?php

declare(strict_types=1);

namespace Vaulter\Repositories\Content;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use LogicException;
use Vaulter\Configuration\TenancyConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\ScanAttempt;
use Vaulter\Content\ScanPolicySpec;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Contracts\Content\ScanPolicyResolver;
use Vaulter\Contracts\Content\ScanWorkSettings;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Tenancy\TenancyBoundary;

/**
 * SQL owns retries and generation fencing; no scanner IO runs in these transactions.
 *
 * @internal The scan work state machine; hosts reach it through the drive scan commands and jobs.
 */
final readonly class ScanObligationRepository
{
    public function __construct(private VaulterConfig $config, private VaulterDatabase $database, private ScanSnapshot $snapshots, private ScanEvidenceRepository $evidence, private ScanWorkSettings $settings, private TenancyConfig $tenancy, private TenancyBoundary $boundary, private ScanPolicyResolver $policies) {}

    public function find(int|string $id): ?ScanObligation
    {
        $class = $this->config->model('scan_obligation');

        return $class::query()->whereKey($id)->first();
    }

    public function ensure(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context): ScanObligation
    {
        return $this->request($revision, $spec, $requestKey, $context, false);
    }

    public function requestRescan(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context): ScanObligation
    {
        return $this->request($revision, $spec, $requestKey, $context, true);
    }

    private function request(FileRevision $revision, ScanPolicySpec $spec, string $requestKey, OperationContext $context, bool $rescan): ScanObligation
    {
        if ($this->database->connection()->transactionLevel() === 0 || !Str::isUuid($requestKey)) {
            throw new LogicException('Scan requests require an owning transaction and UUID request key.');
        }
        if ($spec->scannerKey === 'none' || $spec->scannerVersion === 'none') {
            throw new LogicException('Scan work requires a configured scanner identity.');
        }
        $snapshot = $this->snapshots->load($revision, true);
        if ($this->tenancy->enabled()) {
            $this->boundary->assertDrive($snapshot['drive']);
        }
        if ((string) $snapshot['drive']->id !== (string) $context->driveId()) {
            throw new LogicException('Scan request scope differs.');
        }
        $this->currentSpec($snapshot['drive'], $spec->specKey());
        $class = $this->config->model('scan_obligation');
        $requestClass = $this->config->model('scan_request');
        $work = $class::query()->where('revision_id', $snapshot['revision']->id)->where('spec_key', $spec->specKey())->lockForUpdate()->first();
        if ($work !== null && (!$rescan || $requestClass::query()->where('obligation_id', $work->id)->where('request_key', $requestKey)->exists())) {
            return $work;
        }
        $generation = $work === null ? 1 : $this->increment($work->generation);
        $identity = $snapshot['identity'];
        $r = $snapshot['revision'];
        $blob = $snapshot['blob'];
        if ($identity === null && $r->manifest_blob_id !== null) {
            $blobClass = $this->config->model('blob');
            $blob = $blobClass::query()->whereKey($r->manifest_blob_id)->first();
        }
        $unsupported = $identity === null;
        $identityHash = $identity?->fingerprint() ?? hash('sha256', json_encode(['manifest_blob_id' => $r->manifest_blob_id, 'payload_kind' => $r->manifest_blob_id !== null ? 'manifest' : 'missing', 'revision_id' => (string) $r->id, 'sha256' => $r->sha256, 'size' => $r->size_bytes], JSON_THROW_ON_ERROR));
        $now = $this->clock();
        $work ??= new $class;
        $createdAt = $work->getAttribute('created_at') ?? $now;
        $work->forceFill(['revision_id' => $r->id, 'drive_id' => $snapshot['drive']->id,
            'tenant_id' => $snapshot['drive']->tenant_id, 'blob_id' => $blob?->id, 'scope_key' => $blob === null ? 'unavailable' : $blob->scope_key,
            'content_identity' => $identityHash, 'sha256' => $r->sha256, 'size_bytes' => $r->size_bytes,
            'spec_key' => $spec->specKey(), 'scanner_key' => $spec->scannerKey, 'scanner_version' => $spec->scannerVersion,
            'policy_version' => $spec->policyVersion, 'generation' => $generation, 'attempt' => 0,
            'request_key' => $requestKey, 'state' => 'pending', 'outcome' => null, 'reason' => null, 'retryable' => false,
            'available_at' => $now, 'lease_token' => null, 'lease_expires_at' => null, 'last_published_at' => null,
            'created_at' => $createdAt, 'updated_at' => $now])->save();
        $request = new $requestClass;
        $request->forceFill(['obligation_id' => $work->id, 'request_key' => $requestKey, 'generation' => $generation,
            'requested_at' => $now, 'reason' => $rescan ? 'explicit_rescan' : 'admission', 'correlation_id' => $context->correlationId])->save();
        if ($unsupported) {
            $work->forceFill(['attempt' => 1])->save();
            $this->append($work, new ScanResult(ScanVerdict::Failed, $r->manifest_blob_id !== null || $blob?->chunked ? 'unsupported' : 'unknown'), $now);
            $work->forceFill(['state' => 'settled', 'outcome' => 'failed', 'reason' => $r->manifest_blob_id !== null || $blob?->chunked ? 'unsupported' : 'unknown'])->save();
        } elseif (!$rescan) {
            // A same-drive reupload may reuse existing exact-byte proof, but always
            // materializes its own request and evidence row before commit.
            $evidenceClass = $this->config->model('scan_evidence');
            $candidate = $evidenceClass::query()->where('drive_id', $work->drive_id)->where('tenant_id', $work->tenant_id)
                ->where('blob_id', $work->blob_id)->where('content_identity', $work->content_identity)
                ->where('spec_key', $spec->specKey())->where('outcome', 'clean')->whereNull('revoked_at')->orderByDesc('id')->first();
            if ($candidate !== null && !$this->evidence->infected($r)) {
                $this->evidence->reuse($r, $this->evidence->value($candidate), $spec, $work);
                $work->refresh();
            }
        }

        return $work;
    }

    public function claim(int|string $id, int $generation, DateTimeImmutable $now): ?ScanAttempt
    {
        $preview = $this->find($id);
        if ($preview === null || $preview->generation !== $generation) {
            return null;
        }
        $driveClass = $this->config->model('drive');
        $drive = $driveClass::query()->whereKey($preview->drive_id)->first();
        if ($drive === null) {
            return null;
        }
        $spec = $this->currentSpec($drive, $preview->spec_key);

        return $this->database->transaction(function () use ($id, $generation, $now, $spec): ?ScanAttempt {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($id)->lockForUpdate()->first();
            if ($work === null || $work->generation !== $generation || $work->state === 'settled'
                || $work->available_at > $now || ($work->state === 'leased' && $work->lease_expires_at > $now)) {
                return null;
            }
            if ($work->attempt >= $this->maxAttempts()) {
                $this->terminal($work, 'attempts_exhausted', $now, true);

                return null;
            }
            $attempt = $this->increment($work->attempt);
            $token = (string) Str::uuid();
            $lease = $now->modify('+'.$this->settings->leaseSeconds().' seconds');
            $deadline = $now->modify('+'.$this->settings->scannerTimeoutSeconds().' seconds');
            $work->forceFill(['state' => 'leased', 'attempt' => $attempt, 'lease_token' => $token,
                'lease_expires_at' => $lease, 'updated_at' => $now])->save();

            return new ScanAttempt($work->id, $work->revision_id, $work->blob_id, $work->drive_id, $work->tenant_id,
                $work->content_identity, $spec, $generation, $attempt, $token, $lease, $deadline);
        });
    }

    public function fencedAttempt(ScanAttempt $attempt): ?FileRevision
    {
        $work = $this->find($attempt->obligationId);
        if ($work === null || !$this->fence($work, $attempt, $this->clock())) {
            return null;
        }
        $revisionClass = $this->config->model('file_revision');
        $revision = $revisionClass::query()->whereKey($work->revision_id)->first();
        if ($revision === null) {
            return null;
        }
        $snapshot = $this->snapshots->load($revision);
        $this->currentSpec($snapshot['drive'], $work->spec_key);

        return $this->snapshotMatches($snapshot, $work) ? $snapshot['revision'] : null;
    }

    public function settle(ScanAttempt $attempt, ScanResult $result): bool
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertJobDrive($attempt->driveId, $attempt->tenantId);
        }

        return $this->database->transaction(function () use ($attempt, $result): bool {
            // Freeze the owning tree before any preliminary scope reads. A purge
            // that wins first leaves no revision; settlement then safely no-ops.
            $this->database->lockTreeShared($attempt->driveId);
            $revisionClass = $this->config->model('file_revision');
            $revision = $revisionClass::query()->whereKey($attempt->revisionId)->first();
            if ($revision === null) {
                return false;
            }
            $snapshot = $this->snapshots->load($revision, true);
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($attempt->obligationId)->lockForUpdate()->first();
            $now = $this->clock();
            if ($work === null || !$this->fence($work, $attempt, $now) || !$this->snapshotMatches($snapshot, $work)) {
                return false;
            }
            $this->currentSpec($snapshot['drive'], $work->spec_key);
            if ($result->retryable && $work->attempt >= $this->maxAttempts()) {
                $result = new ScanResult(ScanVerdict::Failed, 'attempts_exhausted');
            }
            $this->append($work, $result, $now);
            $retry = $result->retryable && $work->attempt < $this->maxAttempts();
            $delay = [1 => 30, 2 => 120, 3 => 600, 4 => 1800][$work->attempt] ?? 1800;
            $work->forceFill(['state' => $retry ? 'retry_wait' : 'settled', 'outcome' => $result->verdict->value,
                'reason' => $result->reason, 'retryable' => $result->retryable, 'available_at' => $retry ? $now->modify('+'.$delay.' seconds') : $now,
                'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();
            $revision = $snapshot['revision'];
            if ($result->verdict === ScanVerdict::Infected) {
                $revision->forceFill(['quarantined_at' => $revision->quarantined_at ?? $now, 'scan_status' => 'infected'])->save();
                if ($snapshot['blob'] === null) {
                    throw new LogicException('Infection requires an exact blob.');
                }
                $snapshot['blob']->forceFill(['quarantined_at' => $snapshot['blob']->quarantined_at ?? $now])->save();
            } elseif ($this->evidence->infected($revision)) {
                $revision->forceFill(['scan_status' => 'infected'])->save();
            } else {
                $clean = $this->evidence->compatible($revision, $attempt->spec) !== null;
                $revision->forceFill(['scan_status' => $clean ? 'clean' : ($retry ? 'pending' : 'failed')])->save();
            }

            return true;
        });
    }

    /** @internal A live trusted attempt may record a deterministic failure without trusting altered content. */
    public function failAttempt(ScanAttempt $attempt, string $reason): bool
    {
        if ($this->tenancy->enabled()) {
            $this->boundary->assertJobDrive($attempt->driveId, $attempt->tenantId);
        }
        if (!in_array($reason, ['content_identity_mismatch', 'access_denied', 'unsupported', 'unknown'], true)) {
            throw new LogicException('Invalid deterministic attempt failure.');
        }

        return $this->database->transaction(function () use ($attempt, $reason): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($attempt->obligationId)->lockForUpdate()->first();
            $now = $this->clock();
            if ($work === null || !$this->fence($work, $attempt, $now)) {
                return false;
            }
            $this->terminal($work, $reason, $now, true);

            return true;
        });
    }

    /** Recovery only writes a failed snapshot, never invokes clean settle after lease expiry. */
    public function recover(ScanObligation $obligation, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($obligation, $now): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->first();
            if ($work === null || $work->generation !== $obligation->generation || $work->state !== 'leased'
                || $work->lease_expires_at > $now) {
                return false;
            }
            if ($work->attempt < $this->maxAttempts()) {
                $work->forceFill(['state' => 'retry_wait', 'available_at' => $now, 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();

                return true;
            }
            $this->terminal($work, 'attempts_exhausted', $now, true);

            return true;
        });
    }

    public function supersede(ScanObligation $obligation): bool
    {
        return $this->database->transaction(function () use ($obligation): bool {
            $class = $this->config->model('scan_obligation');
            $work = $class::query()->whereKey($obligation->getRawOriginal($obligation->getKeyName()))->lockForUpdate()->first();
            if ($work === null || $work->generation !== $obligation->generation || $work->state === 'settled') {
                return false;
            }
            $driveClass = $this->config->model('drive');
            $drive = $driveClass::query()->whereKey($work->drive_id)->firstOrFail();
            $specs = $this->policies->forDrive($drive);
            if (array_any($specs, static fn (ScanPolicySpec $spec): bool => $spec->specKey() === $work->spec_key)) {
                return false;
            }
            $this->terminal($work, 'superseded_policy', $this->clock(), true);

            return true;
        });
    }

    private function terminal(ScanObligation $work, string $reason, DateTimeImmutable $now, bool $append): void
    {
        if ($append) {
            if ($work->attempt === 0) {
                $work->forceFill(['attempt' => 1])->save();
            }
            $evidenceClass = $this->config->model('scan_evidence');
            $requestClass = $this->config->model('scan_request');
            $request = $requestClass::query()->where('obligation_id', $work->id)->where('generation', $work->generation)->firstOrFail();
            if (!$evidenceClass::query()->where('request_id', $request->id)->where('attempt', $work->attempt)->exists()) {
                $this->append($work, new ScanResult(ScanVerdict::Failed, $reason), $now);
            }
        }
        $work->forceFill(['state' => 'settled', 'outcome' => 'failed', 'reason' => $reason, 'retryable' => false,
            'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => $now])->save();
    }

    private function append(ScanObligation $work, ScanResult $result, DateTimeImmutable $now): void
    {
        $requestClass = $this->config->model('scan_request');
        $class = $this->config->model('scan_evidence');
        $request = $requestClass::query()->where('obligation_id', $work->id)->where('generation', $work->generation)->firstOrFail();
        $record = new $class;
        $record->forceFill($work->only(['revision_id', 'drive_id', 'tenant_id', 'blob_id', 'scope_key', 'content_identity', 'sha256', 'size_bytes', 'spec_key', 'scanner_key', 'scanner_version', 'policy_version', 'generation', 'attempt']));
        $record->forceFill(['obligation_id' => $work->id, 'request_id' => $request->id, 'outcome' => $result->verdict->value,
            'reason' => $result->reason, 'retryable' => $result->retryable, 'observed_at' => $now])->save();
    }

    private function fence(ScanObligation $work, ScanAttempt $attempt, DateTimeImmutable $now): bool
    {
        return $work->state === 'leased' && $work->generation === $attempt->generation && $work->attempt === $attempt->attempt
            && $work->lease_token === $attempt->leaseToken && $work->lease_expires_at > $now
            && (string) $work->revision_id === (string) $attempt->revisionId && (string) $work->blob_id === (string) $attempt->blobId
            && (string) $work->drive_id === (string) $attempt->driveId && (string) $work->tenant_id === (string) $attempt->tenantId
            && $work->content_identity === $attempt->contentIdentity && $work->spec_key === $attempt->spec->specKey();
    }

    /** @param array{revision:FileRevision,file:File,node:Node,drive:Drive,blob:?Blob,identity:?ImmutableContentIdentity} $snapshot */
    private function snapshotMatches(array $snapshot, ScanObligation $work): bool
    {
        return (string) $snapshot['drive']->id === (string) $work->drive_id
            && (string) $snapshot['drive']->tenant_id === (string) $work->tenant_id
            && $snapshot['identity'] !== null && $snapshot['identity']->fingerprint() === $work->content_identity
            && $snapshot['identity']->sha256 === $work->sha256 && $snapshot['identity']->size === $work->size_bytes;
    }

    private function currentSpec(Drive $drive, string $specKey): ScanPolicySpec
    {
        foreach ($this->policies->forDrive($drive) as $spec) {
            if ($spec->specKey() === $specKey) {
                return $spec;
            }
        }
        throw new LogicException('Scan engine policy is no longer current.');
    }

    private function increment(int $value): int
    {
        if ($value < 0 || $value === PHP_INT_MAX) {
            throw new LogicException('Scan counter is exhausted.');
        }

        return $value + 1;
    }

    private function maxAttempts(): int
    {
        return $this->settings->maxAttempts();
    }

    /** @internal The database clock is the only time source for leases, fences and backoff. */
    public function now(): DateTimeImmutable
    {
        return $this->clock();
    }

    private function clock(): DateTimeImmutable
    {
        // Scan timestamps are stored as naive UTC, so each driver must answer in naive UTC.
        // PostgreSQL CURRENT_TIMESTAMP is neither: it carries the session offset and stays frozen at
        // transaction start, which would age every lease and backoff by the time spent waiting on locks.
        $row = $this->database->connection()->selectOne(match ($this->database->driver()) {
            'mysql' => 'SELECT UTC_TIMESTAMP() AS scan_now',
            'pgsql' => "SELECT clock_timestamp() AT TIME ZONE 'UTC' AS scan_now",
            default => 'SELECT CURRENT_TIMESTAMP AS scan_now',
        });
        if (!$row instanceof \stdClass || !isset($row->scan_now) || !is_string($row->scan_now)) {
            throw new LogicException('Database clock is unavailable.');
        }

        return new DateTimeImmutable($row->scan_now, new DateTimeZone('UTC'));
    }
}
