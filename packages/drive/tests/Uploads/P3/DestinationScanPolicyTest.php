<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Content\SettleScanWorkAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Actions\Files\RestoreFileRevisionAction;
use Vaulter\Drive\Actions\Nodes\CopyNodeAction;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Data\Files\RestoreFileRevisionInput;
use Vaulter\Drive\Data\Nodes\CopyNodeInput;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Content\ContentQuarantinedException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Enums\Cas\ScanVerdict;

it('SEC-R03 COPY owns fresh admission and clean evidence without copying a source lease', function (): void {
    Storage::fake('local');
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('copy bytes');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $source = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = $source->scanObligations()->firstOrFail();
    $attempt = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable('now'));
    expect(app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Clean)))->toBeTrue();
    $copy = app(CopyNodeAction::class)->execute($context, new CopyNodeInput($node, $drive->rootNode()->firstOrFail(), 'copy.txt'));
    $target = $copy->file()->firstOrFail()->currentRevision()->firstOrFail();
    expect($target->scan_admission_sequence)->toBeGreaterThan($source->scan_admission_sequence)
        ->and($target->scan_status)->toBe('clean')->and($target->scanObligations()->count())->toBe(1)
        ->and($target->scanEvidence()->first()->reused_from_id)->not->toBeNull();
});

it('SEC-R03 exempt legacy COPY receives required destination work and fresh admission', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('legacy copy');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $source = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $copy = app(CopyNodeAction::class)->execute($context, new CopyNodeInput($node, $drive->rootNode()->firstOrFail(), 'copy.txt'));
    $target = $copy->file()->firstOrFail()->currentRevision()->firstOrFail();
    expect($source->scanObligations()->count())->toBe(0)->and($target->scan_admission_sequence)->toBe(2)
        ->and($target->scan_status)->toBe('pending')->and($target->scanObligations()->count())->toBe(1)
        ->and($target->scanEvidence()->count())->toBe(0);
});

it('SEC-R04 revision restore creates fresh admission and its own destination work', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('historical');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $source = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $target = app(RestoreFileRevisionAction::class)->execute($context,
        new RestoreFileRevisionInput($node, $source));
    expect($target->id)->not->toBe($source->id)->and($target->scan_admission_sequence)->toBe(2)
        ->and($target->scan_status)->toBe('pending')->and($target->scanObligations()->count())->toBe(1)
        ->and($node->refresh()->file()->value('current_revision_id'))->toBe($target->id);
});

it('SEC-R06 legacy infected source refuses COPY and same blob reupload atomically', function (): void {
    Storage::fake('local');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $stream = UploadTestHelpers::streamFor('infected legacy');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    app(VaulterDatabase::class)->write(fn () => $node->file()->firstOrFail()->currentRevision()->firstOrFail()->forceFill(['scan_status' => 'infected'])->save());
    expect(fn () => app(CopyNodeAction::class)->execute($context, new CopyNodeInput($node, $drive->rootNode()->firstOrFail(), 'copy.txt')))
        ->toThrow(ContentQuarantinedException::class);
    $stream = UploadTestHelpers::streamFor('infected legacy');
    try {
        expect(fn () => app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'again.txt', 'text/plain')))
            ->toThrow(ContentQuarantinedException::class);
    } finally {
        fclose($stream);
    }
    expect(FileRevision::query()->count())->toBe(1)
        ->and($drive->refresh()->scan_admission_sequence)->toBe(1)->and($drive->used_bytes)->toBe(strlen('infected legacy'));
});

it('SEC-R03 folder COPY creates every child obligation and later failure rolls back the subtree', function (): void {
    Storage::fake('local');
    Bus::fake();
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    $folder = new Node;
    app(VaulterDatabase::class)->write(fn () => $folder->forceFill(['drive_id' => $drive->id, 'parent_id' => $drive->root_node_id, 'kind' => NodeKind::Folder, 'name' => 'folder', 'size_bytes' => 0])->save());
    foreach (['a', 'b'] as $name) {
        $stream = UploadTestHelpers::streamFor($name);
        app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, $name.'.txt', 'text/plain', $folder));
        fclose($stream);
    }
    config()->set('vaulter-drive.scan.mode', 'new_content_only');
    config()->set('vaulter-drive.scan.rollout_key', 'first');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $copy = app(CopyNodeAction::class)->execute($context, new CopyNodeInput($folder, $drive->rootNode()->firstOrFail(), 'copy'));
    expect($copy->children()->count())->toBe(2);
    $workClass = app(VaulterConfig::class)->model('scan_obligation');
    expect($workClass::query()->count())->toBe(2)->and($drive->refresh()->scan_admission_sequence)->toBe(4);
    $beforeNodes = Node::query()->count();
    $writes = 0;
    app(VaulterDatabase::class)->connection()->listen(function ($query) use (&$writes): void {
        // MySQL quotes identifiers with backticks, the other engines with double quotes
        if (str_starts_with(str_replace('`', '"', $query->sql), 'insert into "v_scan_obligations"') && ++$writes === 2) {
            throw new RuntimeException('fixture obligation failure');
        }
    });
    expect(fn () => app(CopyNodeAction::class)->execute($context, new CopyNodeInput($folder, $drive->rootNode()->firstOrFail(), 'rollback')))
        ->toThrow(RuntimeException::class);
    expect(Node::query()->count())->toBe($beforeNodes)->and($workClass::query()->count())->toBe(2)
        ->and($drive->refresh()->scan_admission_sequence)->toBe(4);
});
