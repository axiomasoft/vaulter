<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Actions\Content\ClaimScanWorkAction;
use Vaulter\Actions\Content\SettleScanWorkAction;
use Vaulter\Content\ContentDelivery;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\ScanResult;
use Vaulter\Content\SignedContentUrl;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Profiles\ProfileRestrictionException;
use Vaulter\Facades\Vaulter;
use Vaulter\Handlers\FileDescriptorFactory;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Tests\Access\AccessTestHelpers;
use Vaulter\Tests\Drive\DriveTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    $this->system = Vaulter::drive(DriveTestHelpers::host())->asSystem('content egress fixture');
    $this->context = OperationContext::system($this->system->model(), 'content egress fixture');
});

function p37DeliveryRequireScan($test): void
{
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($test->system->model(), $test->context);
}

function p37DeliverySettle($node, ScanVerdict $verdict): void
{
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = ScanObligation::query()->where('revision_id', $revision->id)->firstOrFail();
    $repo = app(ScanObligationRepository::class);
    $attempt = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable);
    if ($attempt === null || !app(SettleScanWorkAction::class)->execute($attempt, new ScanResult($verdict))) {
        throw new RuntimeException('Fixture did not settle a real scan attempt.');
    }
}

it('SEC-R17 checks pending head, explicit older clean, metadata and sticky infection before opening bytes', function (): void {
    p37DeliveryRequireScan($this);
    $node = $this->system->files()->upload(UploadSource::fromString('clean old', 'file.txt'));
    $old = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    expect(fn () => $this->system->files()->read($node))->toThrow(ContentUnavailableException::class);
    p37DeliverySettle($node, ScanVerdict::Clean);
    $descriptor = app(FileDescriptorFactory::class)->forNode($node);
    $this->system->files()->addRevision($node, UploadSource::fromString('pending new', 'file.txt'));
    expect($this->system->nodes()->find($node)->name)->toBe('file.txt');
    expect(fn () => $this->system->files()->read($node))->toThrow(ContentUnavailableException::class);
    $stream = $this->system->files()->read($node, $old);
    expect(stream_get_contents($stream->stream))->toBe('clean old');
    fclose($stream->stream);
    expect($descriptor->filename)->toBe('file.txt');
    p37DeliverySettle($node->refresh(), ScanVerdict::Infected);
    config()->set('vaulter.scan.mode', 'off');
    app(ScanPolicyResolver::class)->initialize($this->system->model(), $this->context);
    expect(fn () => $this->system->files()->read($node))->toThrow(ContentUnavailableException::class);
});

it('SEC-R17 PHP authorization is fresh with a retained model and descriptor after revoke', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('secret', 'file.txt'));
    config()->set('vaulter.access.permission_driver', 'database');
    $user = AccessTestHelpers::user(87);
    $grant = AccessTestHelpers::grant($node, RoleRegistry::READER, subjectId: 87);
    $actor = Vaulter::drive($this->system->model())->actingAs($user);
    $descriptor = app(FileDescriptorFactory::class)->forNode($node);
    $stream = $actor->files()->read($node);
    expect(stream_get_contents($stream->stream))->toBe('secret');
    fclose($stream->stream);
    $grant->delete();
    expect($descriptor->filename)->toBe('file.txt');
    expect(fn () => $actor->files()->read($node))->toThrow(NodeNotFoundException::class);
});

it('SEC-R16 strict refuses bearer URLs before touching a signer', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('content', 'file.txt'));
    $signer = Mockery::mock(DirectContentUrlSigner::class);
    $signer->shouldNotReceive('sign');
    app()->instance(DirectContentUrlSigner::class, $signer);
    expect(fn () => app(ContentDelivery::class)->directUrl($this->context, $node, $node->file()->firstOrFail()->currentRevision()->firstOrFail(), 60))->toThrow(ProfileRestrictionException::class);
});

it('SEC-R16 explicit direct delivery binds exact immutable bytes and TTL with bounded revoke', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('content', 'file.txt'));
    $revision = $node->file()->firstOrFail()->currentRevision()->firstOrFail();
    config()->set('vaulter.downloads.delivery_mode', 'direct_signed');
    $signer = Mockery::mock(DirectContentUrlSigner::class);
    $signer->shouldReceive('sign')->once()->andReturnUsing(static fn (ImmutableContentIdentity $identity, DateTimeImmutable $expiry): SignedContentUrl => new SignedContentUrl('https://storage.test/exact-capability', $expiry, $identity->fingerprint(), $identity->storageVersion));
    app()->instance(DirectContentUrlSigner::class, $signer);
    $url = app(ContentDelivery::class)->directUrl($this->context, $node, $revision, 30);
    expect($url->expiresAt->getTimestamp())->toBeLessThanOrEqual(time() + 30);
    expect(fn () => app(ContentDelivery::class)->directUrl($this->context, $node, $revision, 61))->toThrow(InvalidArgumentException::class);
    $revision->forceFill(['scan_status' => 'infected'])->save();
    expect(fn () => app(ContentDelivery::class)->directUrl($this->context, $node, $revision, 30))->toThrow(ContentUnavailableException::class);
    expect($url->url)->toBe('https://storage.test/exact-capability'); // Issued bearer capability remains bounded by expiry.
});
