<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Vaulter\Actions\Content\RequestScanWorkAction;
use Vaulter\Configuration\ScanConfig;
use Vaulter\Content\ScanInput;
use Vaulter\Content\ScanResult;
use Vaulter\Content\ScanSnapshot;
use Vaulter\Contracts\Cas\ContentScanner;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Content\ScanEvidenceRecord;
use Vaulter\Models\Content\ScanRequest;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanEvidenceRepository;
use Vaulter\Repositories\Content\ScanObligationRepository;

/** Real persisted scope and exact CAS edge, independent from upload orchestration. */
function scanBoundaryFixture(?Blob $sharedBlob = null, ?Drive $drive = null): array
{
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive ??= (new Drive)->forceFill(['owner_type' => 'team', 'owner_id' => random_int(1, 100000000), 'name' => 'scan drive', 'disk' => 'default']);
    if (!$drive->exists) {
        $drive->save();
    }
    $node = (new Node)->forceFill(['drive_id' => $drive->id, 'tenant_id' => $drive->tenant_id,
        'kind' => 'file', 'name' => (string) Str::uuid(), 'parent_id' => $drive->root_node_id]);
    $node->save();
    if ($drive->root_node_id === null) {
        $drive->forceFill(['root_node_id' => $node->id])->save();
    }
    $file = (new File)->forceFill(['node_id' => $node->id, 'tenant_id' => $drive->tenant_id]);
    $file->save();
    $blob = $sharedBlob ?? (new Blob)->forceFill(['scope_key' => 'drive:'.$drive->id, 'drive_id' => $drive->id,
        'sha256' => hash('sha256', 'immutable fixture bytes'), 'size_bytes' => strlen('immutable fixture bytes'),
        'disk' => 'default', 'storage_key' => 'scope/'.Str::uuid(), 'status' => 'committed', 'committed_at' => now('UTC')]);
    if (!$blob->exists) {
        $blob->save();
    }
    $revision = (new FileRevision)->forceFill(['file_id' => $file->id, 'tenant_id' => $drive->tenant_id,
        'number' => 1, 'blob_id' => $blob->id, 'sha256' => $blob->sha256, 'size_bytes' => $blob->size_bytes,
        'mime_type' => 'text/plain', 'original_name' => 'fixture.txt', 'status' => 'committed', 'committed_at' => now('UTC')]);
    $revision->save();
    (new FileRevisionBlob)->forceFill(['file_revision_id' => $revision->id, 'blob_id' => $blob->id, 'role' => 'data', 'ordinal' => 0])->save();
    $file->forceFill(['current_revision_id' => $revision->id])->save();

    return [$drive, $revision, $blob, OperationContext::system($drive, 'scan boundary fixture'), app(ScanConfig::class)->globalSpec()];
}

it('persists work and request atomically and preserves explicit rescan request dedup across generations', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $db = app(VaulterDatabase::class);
    $repository = app(ScanObligationRepository::class);
    $firstKey = (string) Str::uuid();
    $first = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, $firstKey, $context));
    $duplicate = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    expect($duplicate->id)->toBe($first->id)->and(ScanRequest::count())->toBe(1);
    $attempt = $repository->claim($first->id, 1, new DateTimeImmutable('now', new DateTimeZone('UTC')));
    expect($attempt)->not->toBeNull()->and($repository->claim($first->id, 1, new DateTimeImmutable))->toBeNull();
    $secondKey = (string) Str::uuid();
    $second = $db->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, $secondKey, $context));
    $third = $db->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, (string) Str::uuid(), $context));
    $repeatedOld = $db->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, $secondKey, $context));
    expect($second->generation)->toBe(2)->and($third->generation)->toBe(3)->and($repeatedOld->generation)->toBe(3)
        ->and(ScanRequest::count())->toBe(3)->and($repository->settle($attempt, new ScanResult(ScanVerdict::Clean)))->toBeFalse()
        ->and(ScanEvidenceRecord::count())->toBe(0);
    expect(fn () => $db->transaction(function () use ($revision, $spec, $context): void {
        app(RequestScanWorkAction::class)->requestRescan($revision, $spec, (string) Str::uuid(), $context);
        throw new RuntimeException('rollback fixture');
    }))->toThrow(RuntimeException::class);
    expect($first->fresh()->generation)->toBe(3)->and(ScanRequest::count())->toBe(3);
});

it('SEC-R20-scan-validator reads actual immutable input and settles only the exact live attempt without changing head or sequence', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $work = app(VaulterDatabase::class)->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $repository = app(ScanObligationRepository::class);
    $attempt = $repository->claim($work->id, 1, new DateTimeImmutable);
    $before = [$revision->file->current_revision_id, $revision->file->node->sequence, $revision->file->revision_count];
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, 'immutable fixture bytes');
    rewind($stream);
    $scanner = new class implements ContentScanner
    {
        public function key(): string
        {
            return 'fixture';
        }

        public function version(): string
        {
            return '1';
        }

        public function scan(ScanInput $input): ScanResult
        {
            $hash = hash_init('sha256');
            while (!feof($input->stream)) {
                hash_update($hash, fread($input->stream, 8));
            }

            return hash_final($hash) === $input->sha256 ? new ScanResult(ScanVerdict::Clean) : new ScanResult(ScanVerdict::Failed, 'content_identity_mismatch');
        }
    };
    try {
        $result = $scanner->scan(new ScanInput($stream, $revision->id, $blob->id, $drive->id, $drive->tenant_id,
            $blob->scope_key, $revision->sha256, $revision->size_bytes, 'text/plain', '1', '1', $attempt->deadline));
    } finally {
        fclose($stream);
    }
    expect($repository->settle($attempt, $result))->toBeTrue()->and($repository->settle($attempt, $result))->toBeFalse()
        ->and(ScanEvidenceRecord::count())->toBe(1)->and(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->not->toBeNull()
        ->and([$revision->file->fresh()->current_revision_id, $revision->file->node->fresh()->sequence, $revision->file->fresh()->revision_count])->toBe($before);
});

it('SEC-R11 rejects altered identity and keeps sticky infection through later clean rescan and config off', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $repository = app(ScanObligationRepository::class);
    $db = app(VaulterDatabase::class);
    $work = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $attempt = $repository->claim($work->id, 1, new DateTimeImmutable);
    $blob->forceFill(['storage_version' => 'changed'])->save();
    expect($repository->fencedAttempt($attempt))->toBeNull()->and($repository->settle($attempt, new ScanResult(ScanVerdict::Clean)))->toBeFalse();
    $blob->forceFill(['storage_version' => null])->save();
    expect($repository->settle($attempt, new ScanResult(ScanVerdict::Infected)))->toBeTrue();
    $revision->refresh();
    $blob->refresh();
    expect($revision->quarantined_at)->not->toBeNull()->and($blob->quarantined_at)->not->toBeNull();
    $next = $db->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, (string) Str::uuid(), $context));
    $cleanAttempt = $repository->claim($next->id, 2, new DateTimeImmutable);
    expect($repository->settle($cleanAttempt, new ScanResult(ScanVerdict::Clean)))->toBeTrue()
        ->and(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->toBeNull()
        ->and(app(ScanEvidenceRepository::class)->infected($revision))->toBeTrue()
        ->and($revision->fresh()->scan_status)->toBe('infected');
});

it('SEC-R06 reuses only exact same-drive clean evidence and revocation immediately removes compatibility', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $repository = app(ScanObligationRepository::class);
    $db = app(VaulterDatabase::class);
    $work = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $repository->settle($repository->claim($work->id, 1, new DateTimeImmutable), new ScanResult(ScanVerdict::Clean));
    $sourceEvidence = app(ScanEvidenceRepository::class)->compatible($revision, $spec);
    [, $copy] = scanBoundaryFixture($blob, $drive);
    $own = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($copy, $spec, (string) Str::uuid(), $context));
    expect($own->state)->toBe('settled')->and($own->outcome)->toBe('clean')->and(ScanRequest::count())->toBe(2)
        ->and(ScanEvidenceRecord::query()->where('revision_id', $copy->id)->first()->reused_from_id)->toBe($sourceEvidence->id);
    // Tenant-global physical storage can be shared, but evidence never grants
    // another drive authority, even for byte-for-byte identical immutable identity.
    $blob->forceFill(['drive_id' => null])->save();
    [$foreignDrive, $foreignRevision, , $foreignContext] = scanBoundaryFixture($blob);
    $foreignWork = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($foreignRevision, $spec, (string) Str::uuid(), $foreignContext));
    expect($foreignWork->state)->toBe('pending');
    expect(fn () => $db->transaction(fn () => app(ScanEvidenceRepository::class)->reuse($foreignRevision, $sourceEvidence, $spec, $foreignWork)))
        ->toThrow(LogicException::class);
    // Equality on spec_key is independently significant; a persisted corrupt
    // key with unchanged scanner identifiers cannot be inferred as compatible.
    $sourceRecord = ScanEvidenceRecord::query()->whereKey($sourceEvidence->id)->firstOrFail();
    $sourceRecord->forceFill(['spec_key' => str_repeat('e', 64)])->save();
    expect(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->toBeNull();
    $sourceRecord->forceFill(['spec_key' => $spec->specKey()])->save();
    app(ScanEvidenceRepository::class)->revoke($sourceEvidence->id, 'fixture revocation', $context);
    expect(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->toBeNull()
        ->and(app(ScanEvidenceRepository::class)->compatible($copy, $spec))->not->toBeNull();
    $legacy = $revision->forceFill(['scan_status' => 'infected']);
    $legacy->save();
    expect(app(ScanEvidenceRepository::class)->infected($copy))->toBeTrue()
        ->and(app(ScanEvidenceRepository::class)->compatible($copy, $spec))->toBeNull();
});

it('records retry evidence, recovers lost leases and terminates exhausted attempts', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $repository = app(ScanObligationRepository::class);
    $work = app(VaulterDatabase::class)->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $attempt = $repository->claim($work->id, 1, new DateTimeImmutable);
    expect($repository->settle($attempt, new ScanResult(ScanVerdict::Failed, 'scanner_timeout', true)))->toBeTrue();
    $work->refresh();
    expect($work->state)->toBe('retry_wait')->and($work->lease_token)->toBeNull()->and(ScanEvidenceRecord::count())->toBe(1)
        ->and($repository->claim($work->id, 1, new DateTimeImmutable))->toBeNull();
    $work->forceFill(['available_at' => now()->subMinute()])->save();
    $second = $repository->claim($work->id, 1, new DateTimeImmutable);
    $work->refresh()->forceFill(['lease_expires_at' => now()->subSecond()])->save();
    expect($repository->recover($work, new DateTimeImmutable))->toBeTrue()->and($work->fresh()->state)->toBe('retry_wait')
        ->and($repository->settle($second, new ScanResult(ScanVerdict::Clean)))->toBeFalse();
    $third = $repository->claim($work->id, 1, new DateTimeImmutable);
    $work->refresh()->forceFill(['attempt' => 5, 'lease_expires_at' => now()->subSecond()])->save();
    expect($repository->recover($work, new DateTimeImmutable))->toBeTrue()->and($work->fresh()->state)->toBe('settled')
        ->and($work->fresh()->reason)->toBe('attempts_exhausted')->and(ScanEvidenceRecord::count())->toBe(2);
});

it('settles unsupported manifests without opening bytes and rejects fabricated passed scope attributes', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $revision->forceFill(['blob_id' => null, 'manifest_blob_id' => $blob->id])->save();
    $work = app(VaulterDatabase::class)->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    expect($work->state)->toBe('settled')->and($work->reason)->toBe('unsupported')->and($work->attempt)->toBe(1)
        ->and(ScanEvidenceRecord::count())->toBe(1)->and(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->toBeNull();
    $revision->forceFill(['tenant_id' => 'fabricated', 'sha256' => str_repeat('f', 64), 'blob_id' => $blob->id]);
    $snapshot = app(ScanSnapshot::class)->load($revision);
    expect($snapshot['revision']->tenant_id)->toBeNull()->and($snapshot['identity'])->toBeNull();
});

it('SEC-R07 validates structured verdicts before persistence', function (): void {
    expect(fn () => new ScanResult(ScanVerdict::Clean, 'unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScanResult(ScanVerdict::Failed, 'raw scanner details'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ScanResult(ScanVerdict::Failed, 'unsupported', true))->toThrow(InvalidArgumentException::class);
});

it('SEC-R08 optional failed rescan retains exact clean proof and a new scanner epoch refuses old evidence', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $db = app(VaulterDatabase::class);
    $repository = app(ScanObligationRepository::class);
    $work = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $repository->settle($repository->claim($work->id, 1, new DateTimeImmutable), new ScanResult(ScanVerdict::Clean));
    $rescan = $db->transaction(fn () => app(RequestScanWorkAction::class)->requestRescan($revision, $spec, (string) Str::uuid(), $context));
    expect($repository->settle($repository->claim($rescan->id, 2, new DateTimeImmutable), new ScanResult(ScanVerdict::Failed, 'unknown')))->toBeTrue()
        ->and($revision->fresh()->scan_status)->toBe('clean')->and($rescan->fresh()->outcome)->toBe('failed')
        ->and(app(ScanEvidenceRepository::class)->compatible($revision, $spec))->not->toBeNull();
    config()->set('vaulter.scan.scanner_version', '2');
    $newSpec = app(ScanConfig::class)->globalSpec();
    expect(app(ScanEvidenceRepository::class)->compatible($revision, $newSpec))->toBeNull();
    $newWork = $db->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $newSpec, (string) Str::uuid(), $context));
    expect($newWork->id)->not->toBe($work->id)->and($newWork->generation)->toBe(1)->and($newWork->state)->toBe('pending');
});

it('SEC-R12 deterministic identity failure settles the current fenced snapshot without retry or blob mutation', function (): void {
    [$drive, $revision, $blob, $context, $spec] = scanBoundaryFixture();
    $repository = app(ScanObligationRepository::class);
    $work = app(VaulterDatabase::class)->transaction(fn () => app(RequestScanWorkAction::class)->ensure($revision, $spec, (string) Str::uuid(), $context));
    $attempt = $repository->claim($work->id, 1, new DateTimeImmutable);
    $blob->forceFill(['storage_version' => 'invalid changed object'])->save();
    expect($repository->fencedAttempt($attempt))->toBeNull()
        ->and($repository->failAttempt($attempt, 'content_identity_mismatch'))->toBeTrue()
        ->and($repository->failAttempt($attempt, 'content_identity_mismatch'))->toBeFalse()
        ->and($work->fresh()->state)->toBe('settled')->and($work->fresh()->reason)->toBe('content_identity_mismatch')
        ->and($work->fresh()->retryable)->toBeFalse()->and($blob->fresh()->quarantined_at)->toBeNull()
        ->and(ScanEvidenceRecord::query()->first()->outcome)->toBe('failed');
});
