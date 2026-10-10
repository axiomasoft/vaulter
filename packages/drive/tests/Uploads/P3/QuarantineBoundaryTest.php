<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Actions\Content\ClaimScanWorkAction;
use Vaulter\Actions\Content\SettleScanWorkAction;
use Vaulter\Content\ScanResult;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Content\ScanPolicyResolver;
use Vaulter\Enums\Cas\ScanVerdict;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Operations\OperationContext;
use Vaulter\Repositories\Content\ScanObligationRepository;
use Vaulter\Sharing\ShareResolver;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Sharing\ShareTestHelpers;

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.features.share_links', true);
    $this->system = Vaulter::drive(DriveTestHelpers::host())->asSystem('quarantine fixture');
    $this->context = OperationContext::system($this->system->model(), 'quarantine fixture');
});

it('SEC-R01 legacy not_required and bare clean cannot authorize required public bytes', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('legacy', 'legacy.txt'));
    $revision = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(fn () => $revision->forceFill(['scan_status' => 'clean'])->save());
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($this->system->model(), $this->context);
    expect(fn () => $this->system->files()->read($node))->toThrow(ContentUnavailableException::class);
    expect($this->system->nodes()->find($node)->id)->toBe($node->id);
    ShareTestHelpers::share($node, token: 'quarantine-legacy');
    $this->get('api/vaulter/v1/shares/quarantine-legacy/download')->assertStatus(423);
});

it('SEC-R07 required structured failure never grants PHP or share bytes', function (string $reason, bool $retryable): void {
    config()->set('vaulter.scan.mode', 'all_content');
    config()->set('vaulter.scan.scanner_key', 'fixture');
    config()->set('vaulter.scan.scanner_version', '1');
    app(ScanPolicyResolver::class)->initialize($this->system->model(), $this->context);
    $node = $this->system->files()->upload(UploadSource::fromString('payload', 'file.txt'));
    $revision = DriveTestHelpers::model($node)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $work = ScanObligation::query()->where('revision_id', $revision->id)->firstOrFail();
    $repo = app(ScanObligationRepository::class);
    $attempt = app(ClaimScanWorkAction::class)->execute($work->id, $work->generation, new DateTimeImmutable);
    expect($attempt)->not->toBeNull();
    expect(app(SettleScanWorkAction::class)->execute($attempt, new ScanResult(ScanVerdict::Failed, $reason, $retryable)))->toBeTrue();
    expect(fn () => $this->system->files()->read($node))->toThrow(ContentUnavailableException::class);
    ShareTestHelpers::share($node, token: 'quarantine-failed');
    $this->get('api/vaulter/v1/shares/quarantine-failed/download')->assertStatus(423);
})->with([
    ['scanner_timeout', true], ['scanner_unavailable', true], ['transient_io', true],
    ['unsupported', false], ['too_large', false], ['password_protected', false], ['access_denied', false], ['unknown', false],
]);

it('SEC-R17 public share stream rechecks revocation after descriptor resolution and before byte open', function (): void {
    $node = $this->system->files()->upload(UploadSource::fromString('protected', 'file.txt'));
    $share = ShareTestHelpers::share($node, token: 'revoked-at-open');
    $resolved = app(ShareResolver::class)->resolve('revoked-at-open');
    expect($resolved->isOk())->toBeTrue();
    app(VaulterDatabase::class)->write(fn () => $share->forceFill(['revoked_at' => now()])->save());
    $this->get('api/vaulter/v1/shares/revoked-at-open/download')->assertNotFound();
});
