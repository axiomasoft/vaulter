<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Operations\DriveContext;

it('SEC-R12 refuses wrong scoped jobs before scanner reads and closes the trusted stream', function (): void {
    Storage::fake('local');
    Bus::fake();
    $scanner = new class implements ContentScanner
    {
        public int $reads = 0;

        public mixed $borrowed = null;

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
            $this->reads++;
            $this->borrowed = $input->stream;
            expect(stream_get_contents($input->stream))->toBe('scan bytes');

            return new ScanResult(ScanVerdict::Clean);
        }
    };
    UploadTestHelpers::scanner($scanner);
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('scan bytes');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = $revision->scanObligations()->firstOrFail();
    app()->call([new ScanFileRevision($work->id, $work->generation, 'foreign', $work->tenant_id), 'handle']);
    expect($scanner->reads)->toBe(0)->and($work->refresh()->attempt)->toBe(0);
    app()->call([new ScanFileRevision($work->id, $work->generation, $drive->id, $work->tenant_id), 'handle']);
    expect($scanner->reads)->toBe(1)->and(is_resource($scanner->borrowed))->toBeFalse()
        ->and($work->refresh()->outcome)->toBe('clean')->and($revision->refresh()->scan_status)->toBe('clean');
    app()->call([new ScanFileRevision($work->id, $work->generation, $drive->id, $work->tenant_id), 'handle']);
    expect($scanner->reads)->toBe(1);
});

it('SEC-R12 corrupted immutable identity fails terminally before scanner bytes', function (): void {
    Storage::fake('local');
    Bus::fake();
    $scanner = new class implements ContentScanner
    {
        public int $reads = 0;

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
            $this->reads++;

            return new ScanResult(ScanVerdict::Clean);
        }
    };
    UploadTestHelpers::scanner($scanner);
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    $drive = UploadTestHelpers::storage();
    $context = DriveContext::system($drive, 'fixture');
    app(ScanPolicyResolver::class)->initialize($drive, $context);
    $stream = UploadTestHelpers::streamFor('identity');
    $node = app(IngestFileAction::class)->execute($context, new IngestFileInput($drive, $stream, 'p.txt', 'text/plain'));
    fclose($stream);
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $row = $revision->scanObligations()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $revision->blob()->firstOrFail()->forceFill(['storage_key' => 'changed-key'])->save());
    app()->call([new ScanFileRevision($row->id, $row->generation, $drive->id, $row->tenant_id), 'handle']);
    expect($scanner->reads)->toBe(0)->and($row->refresh()->state)->toBe('settled')
        ->and($row->outcome)->toBe('failed')->and($row->reason)->toBe('content_identity_mismatch')
        ->and($row->retryable)->toBeFalse()->and($row->lease_token)->toBeNull();
    app()->call([new ScanFileRevision($row->id, $row->generation, $drive->id, $row->tenant_id), 'handle']);
    expect($scanner->reads)->toBe(0)->and($row->refresh()->attempt)->toBe(1)
        ->and($node->refresh()->file()->value('current_revision_id'))->toBe($revision->id);
});
