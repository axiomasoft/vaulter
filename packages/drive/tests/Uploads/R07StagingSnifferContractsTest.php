<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\Inspection\MimeSniffer;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Drive\Uploads\TusUploadHandler;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * Minimal 1×1 PNG (finfo-detectable as image/png).
 */
function r07PngBytes(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAD0lEQVR42mP8z5BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true) ?: '';
}

it('resolves the staging disk from drive_id when the storage relation is not loaded', function (): void {
    $storage = UploadTestHelpers::storage();
    $session = UploadTestHelpers::session($storage);
    $session->unsetRelation('storage');

    $staged = app(StagedUpload::class);

    expect($staged->diskName($session->fresh()))->toBe('local')
        ->and($staged->size($session))->toBe(0);
});

it('reports staged size zero when the tmp object is absent', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());

    expect(app(StagedUpload::class)->size($session))->toBe(0);
});

it('rejects a stat-able chunk one byte over max_chunk_bytes before staging', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $stream = UploadTestHelpers::streamFor('abcde');

    try {
        expect(fn () => $staged->append($session, $stream, 0))
            ->toThrow(ValidationException::class);
    } finally {
        fclose($stream);
    }

    expect($staged->size($session))->toBe(0);
});

it('includes the measured byte count in an oversize stat-able chunk error', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 3);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $stream = UploadTestHelpers::streamFor('abcd');

    try {
        $staged->append($session, $stream, 0);
        $this->fail('Expected chunk ceiling rejection.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['chunk'][0])->toContain('4 bytes')
            ->and($exception->errors()['chunk'][0])->toContain('maximum chunk size of 3 bytes');
    } finally {
        fclose($stream);
    }
});

it('rangeMatches requires staged length to cover offset plus chunk length exactly', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $staged->write($session, '1234');
    $match = UploadTestHelpers::streamFor('34');
    $short = UploadTestHelpers::streamFor('34');

    try {
        expect($staged->rangeMatches($session, $match, 2))->toBeTrue()
            ->and($staged->rangeMatches($session, $short, 3))->toBeFalse();
    } finally {
        fclose($match);
        fclose($short);
    }
});

it('HEAD offset uses the smaller of staged bytes and recorded received_bytes', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 20);
    app(StagedUpload::class)->write($session, str_repeat('a', 10));
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['received_bytes' => 18])->save());

    expect(app(TusUploadHandler::class)->offset($session->refresh()))->toBe(10);
});

it('rejects a PATCH that would grow past max_bytes after a partial upload', function (): void {
    config()->set('vaulter-drive.uploads.max_file_size', 6);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 20);
    $handler = app(TusUploadHandler::class);

    $handler->patch($session->refresh(), UploadTestHelpers::streamFor('123456'), 0);

    expect(fn () => $handler->patch($session->refresh(), UploadTestHelpers::streamFor('7'), 6))
        ->toThrow(ValidationException::class);

    expect($session->refresh()->received_bytes)->toBe(6);
});

it('accepts an idempotent retransmit when the upload is already complete at the server offset', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 5);
    $handler = app(TusUploadHandler::class);
    $handler->patch($session->refresh(), UploadTestHelpers::streamFor('12345'), 0);

    $retry = $handler->patch($session->refresh(), UploadTestHelpers::streamFor('12345'), 0);

    expect($retry)->toBe(['offset' => 5, 'complete' => true]);
});

it('MagicByteSniffer accepts generic declared mime without requiring agreement', function (string $declared): void {
    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor(r07PngBytes());

    try {
        expect($sniffer->matches($stream, $declared))->toBeTrue();
    } finally {
        fclose($stream);
    }
})->with([
    'octet-stream' => ['application/octet-stream'],
    'empty' => [''],
    'text/plain' => ['text/plain'],
]);

it('MagicByteSniffer accepts null declared mime as generic', function (): void {
    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor(r07PngBytes());

    try {
        expect($sniffer->matches($stream, null))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('MagicByteSniffer accepts matching PNG bytes for image/png including charset trimming and case', function (): void {
    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor(r07PngBytes());

    try {
        expect($sniffer->matches($stream, ' IMAGE/PNG ; charset=binary '))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('MagicByteSniffer folds image/jpg to image/jpeg for agreement', function (): void {
    $sniffer = app(MimeSniffer::class);
    $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA//2Q==', true) ?: '';
    $stream = UploadTestHelpers::streamFor($jpeg);

    try {
        expect($sniffer->matches($stream, 'image/jpg'))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('MagicByteSniffer rejects a concrete declared mime that contradicts PNG magic', function (): void {
    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor(r07PngBytes());

    try {
        expect($sniffer->matches($stream, 'application/zip'))->toBeFalse();
    } finally {
        fclose($stream);
    }
});

it('MagicByteSniffer rejects PNG declared when the head is a ZIP archive', function (): void {
    $zipPath = tempnam(sys_get_temp_dir(), 'r07_zip_').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('inner.txt', 'payload');
    $zip->close();
    $zipBytes = (string) file_get_contents($zipPath);
    @unlink($zipPath);

    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor($zipBytes);

    try {
        expect($sniffer->matches($stream, 'image/png'))->toBeFalse();
    } finally {
        fclose($stream);
    }
});

it('MagicByteSniffer accepts an empty stream head without contradicting a concrete declared mime', function (): void {
    $sniffer = app(MimeSniffer::class);
    $stream = UploadTestHelpers::streamFor('');

    try {
        expect($sniffer->matches($stream, 'image/png'))->toBeTrue();
    } finally {
        fclose($stream);
    }
});

it('rejects unsafe ZIP central directory metadata before reading a member', function (string $case): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-zip-');
    expect($path)->toBeString();
    try {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::OVERWRITE))->toBeTrue();
        $archive->addFromString('ordinary.txt', 'plain content');
        match ($case) {
            'symlink' => $archive->setExternalAttributesName('ordinary.txt', ZipArchive::OPSYS_UNIX, 0120777 << 16),
            'traversal' => $archive->addFromString('nested/../outside.txt', 'plain content'),
            'duplicate' => $archive->addFromString('ORDINARY.TXT', 'plain content'),
            'ratio' => $archive->addFromString('data.txt', str_repeat('a', 128 * 1024)),
            'nul' => $archive->addFromString('nul-name.txt', 'plain content'),
        };
        $archive->close();
        if ($case === 'nul') {
            file_put_contents($path, str_replace('nul-name.txt', "nul\0name.txt", (string) file_get_contents($path)));
        }

        expect(fn () => app(MimeSniffer::class)->detect($path))->toThrow(ValidationException::class);
    } finally {
        unlink($path);
    }
})->with(['symlink', 'traversal', 'duplicate', 'ratio', 'nul']);

it('guards ZIP metadata independently of OOXML classification', function (bool $oversized, ?string $relationship, bool $accepted): void {
    $path = tempnam(sys_get_temp_dir(), 'vaulter-zip-');
    expect($path)->toBeString();
    try {
        $archive = new ZipArchive;
        expect($archive->open($path, ZipArchive::OVERWRITE))->toBeTrue();
        $archive->addFromString('ordinary.txt', 'plain content');
        if ($oversized) {
            $archive->addFromString('[Content_Types].xml', str_pad('<Types/>', 65537));
            expect($archive->setCompressionName('[Content_Types].xml', ZipArchive::CM_STORE))->toBeTrue();
        }
        if ($relationship !== null) {
            $target = $relationship === 'hyperlink' ? 'https://example.invalid/' : 'urn:test:template';
            $archive->addFromString('_rels/.rels', sprintf(
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/%s" TargetMode="External" Target="%s"/></Relationships>',
                $relationship,
                $target,
            ));
        }
        expect($archive->close())->toBeTrue();

        $detect = fn () => app(MimeSniffer::class)->detect($path);
        if ($accepted) {
            expect($detect())->toBe('application/zip');
        } else {
            expect($detect)->toThrow(ValidationException::class);
        }
    } finally {
        unlink($path);
    }
})->with([
    'forbidden relationship without content types' => [false, 'attachedTemplate', false],
    'forbidden relationship with oversized content types' => [true, 'attachedTemplate', false],
    'oversized content types alone' => [true, null, false],
    'allowed relationship without content types' => [false, 'hyperlink', true],
    'ordinary ZIP without OOXML metadata' => [false, null, true],
]);

it('append still refuses offset>0 when staged object was removed', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 12);
    $staged = app(StagedUpload::class);
    $staged->write($session, '0123456789');
    Storage::disk('local')->delete($staged->keyFor($session));

    expect(fn () => $staged->append($session, UploadTestHelpers::streamFor('next'), 10))
        ->toThrow(StagedUploadMissingException::class);
});

it('uses the session storage disk name when the relation is a loaded Storage model', function (): void {
    $storage = UploadTestHelpers::storage();
    $storage->forceFill(['disk' => 'local'])->save();
    $session = UploadTestHelpers::session($storage);
    $session->setRelation('storage', $storage);

    expect($session->storage)->toBeInstanceOf(Drive::class)
        ->and(app(StagedUpload::class)->diskName($session))->toBe('local');
});
