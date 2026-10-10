<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\SignedContentUrl;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Http\V1;

/**
 * In the opt-in direct_signed mode a download is an uncached 302 to a short-lived URL of
 * the exact revision asked for, issued only after the same access and scan checks.
 */
uses(RefreshDatabase::class);

final class DriveRecordingSigner implements DirectContentUrlSigner
{
    /** @var list<array{ImmutableContentIdentity, DateTimeImmutable}> */
    public array $signed = [];

    public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl
    {
        $this->signed[] = [$content, $expiresAt];

        return new SignedContentUrl('https://storage.test/object/'.$content->sha256, $expiresAt, $content->fingerprint(), $content->storageVersion);
    }
}

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter.downloads.delivery_mode', 'direct_signed');
    config()->set('vaulter.downloads.direct_ttl_seconds', 120);
    app()->instance(DirectContentUrlSigner::class, $this->signer = new DriveRecordingSigner);
    $this->drive = V1::drive(userId: 1);
    $this->actingAs(V1::user(1));
});

it('redirects the head download to a signed URL without caching it', function (): void {
    $file = V1::file($this->drive, 'd.txt', 'direct bytes');
    $head = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();

    $response = $this->get(V1::url('files/'.$file->id.'/download'))
        ->assertStatus(302)
        ->assertHeader('Location', 'https://storage.test/object/'.$head->sha256)
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Vaulter-Api-Version', '1');

    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store')
        ->and($this->signer->signed)->toHaveCount(1)
        ->and($this->signer->signed[0][1]->getTimestamp() - time())->toBeLessThanOrEqual(120);
});

it('signs exactly the requested older revision', function (): void {
    $file = V1::file($this->drive, 'v.txt', 'first bytes');
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $this->drive->files()->addRevision($file->id, UploadSource::fromString('second bytes', 'v.txt'));

    $this->get(V1::url('files/'.$file->id.'/revisions/'.$first->getKey().'/download'))
        ->assertStatus(302)
        ->assertHeader('Location', 'https://storage.test/object/'.$first->sha256);

    expect($this->signer->signed[0][0]->sha256)->toBe($first->sha256);
});
