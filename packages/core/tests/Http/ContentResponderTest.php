<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Content\ImmutableContentIdentity;
use Vaulter\Content\SignedContentUrl;
use Vaulter\Contracts\Cas\DirectContentUrlSigner;
use Vaulter\Data\Drive\UploadSource;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Testing\Http\V1;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Http\Support\ContentResponder;
use Vaulter\Http\Support\DownloadHeaders;

/**
 * The one HTTP egress point: fresh authorization and scan check per request, the exact
 * revision, safe headers in strict mode, and an uncached redirect to a signed URL of
 * that exact revision in direct_signed mode.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('local');
    $this->drive = V1::drive(userId: 1);
    $this->user = V1::user(1);
});

final class RecordingSigner implements DirectContentUrlSigner
{
    /** @var list<ImmutableContentIdentity> */
    public array $signed = [];

    public function sign(ImmutableContentIdentity $content, DateTimeImmutable $expiresAt): SignedContentUrl
    {
        $this->signed[] = $content;

        return new SignedContentUrl('https://storage.test/object/'.$content->sha256, $expiresAt, $content->fingerprint(), $content->storageVersion);
    }
}

function streamedBody(Response $response): string
{
    ob_start();
    $response->sendContent();

    return (string) ob_get_clean();
}

it('streams in strict mode with RFC 6266, nosniff, sandbox, no ranges and no storing', function (): void {
    $file = V1::file($this->drive, 'отчёт 2026.html', '<script>alert(1)</script>', 'text/html');

    $response = app(ContentResponder::class)->file(Request::create('/'), $this->drive->actingAs($this->user), $file->id, null);

    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))->toBe('application/octet-stream')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe("sandbox; default-src 'none'")
        ->and($response->headers->get('Accept-Ranges'))->toBe('none')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Content-Length'))->toBe('25')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('attachment; filename=')
        ->toContain("filename*=utf-8''%D0%BE%D1%82%D1%87%D1%91%D1%82%202026.html")
        ->and(streamedBody($response))->toBe('<script>alert(1)</script>');
});

it('strips CR/LF, control characters and path separators from the disposition', function (): void {
    $header = DownloadHeaders::disposition('attachment', "отчёт\r\nX: y/..\\evil.pdf");

    expect($header)->not->toContain("\r")->not->toContain("\n")->not->toContain('/')
        ->and($header)->toStartWith('attachment; filename="')
        ->and(DownloadHeaders::disposition('inline', "\x00\x1F"))->toBe('inline; filename=download')
        ->and(fn () => DownloadHeaders::disposition('form-data', 'a'))->toThrow(InvalidArgumentException::class);
});

it('redirects to a signed URL of the exact requested revision in direct_signed mode, without caching', function (): void {
    config()->set('vaulter.downloads.delivery_mode', 'direct_signed');
    app()->instance(DirectContentUrlSigner::class, $signer = new RecordingSigner);
    $file = V1::file($this->drive, 'v.txt', 'first bytes');
    $first = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    $this->drive->files()->addRevision($file->id, UploadSource::fromString('second bytes', 'v.txt'));

    $response = app(ContentResponder::class)->file(Request::create('/'), $this->drive->actingAs($this->user), $file->id, (string) $first->getKey());

    expect($response)->toBeInstanceOf(RedirectResponse::class)
        ->and($response->getStatusCode())->toBe(302)
        ->and($response->headers->get('Location'))->toBe('https://storage.test/object/'.$first->sha256)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($signer->signed)->toHaveCount(1)
        ->and($signer->signed[0]->sha256)->toBe($first->sha256);
});

it('answers 423 before any byte or URL when the revision is not clean', function (string $mode): void {
    config()->set('vaulter.downloads.delivery_mode', $mode);
    app()->instance(DirectContentUrlSigner::class, $signer = new RecordingSigner);
    $file = V1::file($this->drive, 'q.txt', 'quarantined');
    $revision = DriveTestHelpers::model($file)->file()->firstOrFail()->currentRevision()->firstOrFail();
    app(VaulterDatabase::class)->write(static fn () => $revision->forceFill(['scan_status' => 'infected'])->save());

    expect(fn () => app(ContentResponder::class)->file(Request::create('/'), $this->drive->actingAs($this->user), $file->id, null))
        ->toThrow(ContentUnavailableException::class)
        ->and($signer->signed)->toBe([]);
})->with(['strict', 'direct_signed']);

it('gives a previewer 403 and a stranger 404, in both modes', function (string $mode): void {
    config()->set('vaulter.downloads.delivery_mode', $mode);
    app()->instance(DirectContentUrlSigner::class, $signer = new RecordingSigner);
    $file = V1::file($this->drive, 'p.txt', 'preview only');
    AccessTestHelpers::grant(DriveTestHelpers::model($file), RoleRegistry::PREVIEWER, subjectId: 5);

    expect(fn () => app(ContentResponder::class)->file(Request::create('/'), $this->drive->actingAs(V1::user(5)), $file->id, null))
        ->toThrow(PermissionDeniedException::class)
        ->and(fn () => app(ContentResponder::class)->file(Request::create('/'), $this->drive->actingAs(V1::user(6)), $file->id, null))
        ->toThrow(NodeNotFoundException::class)
        ->and($signer->signed)->toBe([]);
})->with(['strict', 'direct_signed']);
