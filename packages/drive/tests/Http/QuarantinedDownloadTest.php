<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Media\Content\ImmutableContentIdentity;
use Vaulter\Media\Content\SignedContentUrl;
use Vaulter\Media\Contracts\Cas\DirectContentUrlSigner;

/** A revision that is not scanned clean answers 423 content_unavailable before any byte or URL. */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
});

it('answers 423 for an infected head, in both delivery modes', function (string $mode): void {
    config()->set('vaulter-drive.downloads.delivery_mode', $mode);
    $signer = new class implements DirectContentUrlSigner
    {
        public int $calls = 0;

        public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl
        {
            $this->calls++;

            return new SignedContentUrl('https://storage.test/x', $expiresAt, $content->fingerprint(), $content->storageVersion);
        }
    };
    app()->instance(DirectContentUrlSigner::class, $signer);
    $drive = V1::drive(userId: 1);
    $file = V1::file($drive, 'q.txt', 'quarantined');
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(static fn () => $revision->forceFill(['scan_status' => 'infected'])->save());
    $this->actingAs(V1::user(1));

    $this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])
        ->assertStatus(423)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'content_unavailable');
    $this->get(V1::url('files/'.$file->id.'/revisions/'.$revision->getKey().'/download'), ['Accept' => 'application/json'])
        ->assertStatus(423);

    expect($signer->calls)->toBe(0);
})->with(['strict', 'direct_signed']);

it('answers 423 for a head whose blob the integrity scrub marked, in both delivery modes (D49)', function (string $mode): void {
    config()->set('vaulter-drive.downloads.delivery_mode', $mode);
    app()->instance(DirectContentUrlSigner::class, new class implements DirectContentUrlSigner
    {
        public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl
        {
            return new SignedContentUrl('https://storage.test/x', $expiresAt, $content->fingerprint(), $content->storageVersion);
        }
    });
    $drive = V1::drive(userId: 1);
    $file = V1::file($drive, 'damaged.txt', 'marked by the scrub');
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $this->actingAs(V1::user(1));

    expect($this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])->getStatusCode())->toBeLessThan(400);

    $blob = $revision->blob()->firstOrFail();
    expect($blob->isIntact())->toBeTrue();
    app(VaulterDatabase::class)->write(static fn () => $blob->forceFill(['integrity_failed_at' => now()])->save());

    $this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])
        ->assertStatus(423)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'content_unavailable');
    $this->get(V1::url('files/'.$file->id.'/revisions/'.$revision->getKey().'/download'), ['Accept' => 'application/json'])
        ->assertStatus(423);
    expect($blob->fresh()->isIntact())->toBeFalse();

    app(VaulterDatabase::class)->write(static fn () => $blob->forceFill(['integrity_failed_at' => null])->save());

    expect($this->get(V1::url('files/'.$file->id.'/download'), ['Accept' => 'application/json'])->getStatusCode())->toBeLessThan(400);
})->with(['strict', 'direct_signed']);
