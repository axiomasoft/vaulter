<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Tenancy\TenantResolver;
use Vaulter\Drive\Actions\Content\ClaimScanWorkAction;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Content\PrivateScanReader;
use Vaulter\Drive\Content\ScanAttempt;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Facades\Vaulter;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\Content\ScanObligationRepository;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Exceptions\Tenancy\TenancyMisconfiguredException;
use Vaulter\Media\Content\ScanInput;
use Vaulter\Media\Content\ScanResult;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Media\Contracts\Cas\ContentScanner;
use Vaulter\Media\Enums\Cas\ScanVerdict;
use Vaulter\Testing\Tenancy\MutableTenantResolver;

beforeEach(fn () => DriveTestHelpers::insideOperation());

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    Bus::fake();
    config()->set('vaulter.tenancy.enabled', true);
    config()->set('vaulter.tenancy.resolver', MutableTenantResolver::class);
    app()->instance(TenantResolver::class, new MutableTenantResolver);
    config()->set('vaulter-drive.scan.mode', 'all_content');
    config()->set('vaulter-drive.scan.scanner_key', 'fixture');
    config()->set('vaulter-drive.scan.scanner_version', '1');
    $this->scanner = new class implements ContentScanner
    {
        public int $reads = 0;

        public mixed $stream = null;

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
            $this->stream = $input->stream;
            expect(stream_get_contents($input->stream))->toBe('scan bytes');

            return new ScanResult(ScanVerdict::Clean);
        }
    };
    UploadTestHelpers::scanner($this->scanner);
    $this->drive = Vaulter::drive(DriveTestHelpers::host())->model();
    $stream = UploadTestHelpers::streamFor('scan bytes');
    try {
        $this->node = app(IngestFileAction::class)->execute(DriveContext::system($this->drive, 'fixture'), new IngestFileInput($this->drive, $stream, 'private.txt', 'text/plain'));
    } finally {
        fclose($stream);
    }
    $this->revision = $this->node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $this->work = $this->revision->scanObligations()->firstOrFail();
});

afterEach(fn () => MutableTenantResolver::clearFixtureStamps());

it('SEC-R12 V08 private scanner A B A refuses mismatch null and maintenance inheritance before any reads', function (): void {
    $job = new ScanFileRevision($this->work->id, $this->work->generation, $this->drive->id, 'A');
    $resolver = app(TenantResolver::class);
    $resolver->tenant = 'B';
    expect(fn () => app()->call([$job, 'handle']))->toThrow(NodeNotFoundException::class);
    expect(fn () => Vaulter::withoutTenancy(fn () => app()->call([$job, 'handle'])))->toThrow(NodeNotFoundException::class);
    $resolver->tenant = null;
    expect(fn () => app()->call([$job, 'handle']))->toThrow(TenancyMisconfiguredException::class);
    $resolver->tenant = 'A';
    expect(fn () => app()->call([new ScanFileRevision($this->work->id, $this->work->generation, $this->drive->id, 'B'), 'handle']))->toThrow(NodeNotFoundException::class);
    expect($this->scanner->reads)->toBe(0)->and($this->work->refresh()->attempt)->toBe(0);
    app()->call([$job, 'handle']);
    expect($this->scanner->reads)->toBe(1)->and(is_resource($this->scanner->stream))->toBeFalse()
        ->and($this->work->refresh()->outcome)->toBe('clean');
});

it('SEC-R12 private capability validates lease scope immutable blob revision and generation before byte open', function (): void {
    $repository = app(ScanObligationRepository::class);
    $attempt = app(ClaimScanWorkAction::class)->execute($this->work->id, $this->work->generation, new DateTimeImmutable('now'));
    expect($attempt)->not->toBeNull();
    $actualStore = app(BlobStore::class);
    $spy = Mockery::mock(BlobStore::class);
    $byteReads = 0;
    $spy->shouldReceive('read')->andReturnUsing(function ($blob) use ($actualStore, &$byteReads) {
        $byteReads++;

        return $actualStore->read($blob);
    });
    app()->instance(BlobStore::class, $spy);
    $this->revision->forceFill(['quarantined_at' => now(), 'scan_status' => 'infected'])->save();
    $this->revision->blob()->firstOrFail()->forceFill(['quarantined_at' => now()])->save();
    $reader = app(PrivateScanReader::class);
    app(TenantResolver::class)->tenant = 'B';
    expect(fn () => $reader->open($attempt))->toThrow(NodeNotFoundException::class);
    app(TenantResolver::class)->tenant = 'A';
    $args = (array) $attempt;
    foreach (['revisionId' => 'foreign', 'blobId' => 'foreign', 'generation' => $attempt->generation + 1, 'leaseToken' => 'forged', 'contentIdentity' => 'forged'] as $key => $value) {
        $forged = new ScanAttempt(...array_replace($args, [$key => $value]));
        expect($reader->open($forged))->toBeNull();
    }
    expect($byteReads)->toBe(0);
    $stream = $reader->open($attempt);
    expect(is_resource($stream))->toBeTrue();
    try {
        expect(stream_get_contents($stream))->toBe('scan bytes');
    } finally {
        fclose($stream);
    }
    expect($this->scanner->reads)->toBe(0)->and($byteReads)->toBe(1);
});

it('SEC-R12 recovery only publishes current tenant work and all-tenant publication gives no job bypass', function (): void {
    $resolver = app(TenantResolver::class);
    $resolver->tenant = 'B';
    Bus::fake();
    expect(app(ScanWorkReconciler::class)->reconcile()['scanned'])->toBe(0);
    Bus::assertNothingDispatched();
    Vaulter::withoutTenancy(function (): void {
        expect(app(ScanWorkReconciler::class)->reconcile()['published'])->toBe(1);
    });
    Bus::assertDispatched(ScanFileRevision::class, fn ($job): bool => $job->tenantId === 'A');
    $job = new ScanFileRevision($this->work->id, $this->work->generation, $this->drive->id, 'A');
    $middleware = $job->middleware()[0];
    $called = false;
    Vaulter::withoutTenancy(fn () => $middleware->handle($job, function () use (&$called): void {
        $called = true;
    }));
    expect($called)->toBeFalse()->and($this->scanner->reads)->toBe(0)->and($resolver->current())->toBe('B');
});
