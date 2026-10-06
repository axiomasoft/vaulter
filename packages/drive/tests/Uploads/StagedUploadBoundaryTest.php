<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\StagedUpload;

beforeEach(function (): void {
    Storage::fake('local');
});

it('compares a retransmit at its actual offset and length', function (string $bytes, string $chunk, int $offset, bool $matches): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $staged->write($session, $bytes);
    $stream = UploadTestHelpers::streamFor($chunk);

    try {
        expect($staged->rangeMatches($session, $stream, $offset))->toBe($matches)
            ->and(Storage::disk('local')->get($staged->keyFor($session)))->toBe($bytes);
    } finally {
        fclose($stream);
    }
})->with([
    'nonzero offset, exact end' => ['prefix-tail', 'tail', 7, true],
    'nonzero offset, unequal bytes' => ['prefix-tail', 'fail', 7, false],
    'range extends past end' => ['prefix-tail', 'tails', 7, false],
    'empty retransmit' => ['prefix-tail', '', 7, true],
    'discard crosses buffer boundary' => [str_repeat('p', 2_097_153).'tail', 'tail', 2_097_153, true],
]);

it('truncates an unacknowledged suffix before appending at the authoritative offset', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $staged->write($session, 'prefix-stale');
    $stream = UploadTestHelpers::streamFor('new');

    try {
        expect($staged->append($session, $stream, 7))->toBe(10)
            ->and($staged->size($session))->toBe(10)
            ->and(Storage::disk('local')->get($staged->keyFor($session)))->toBe('prefix-new');
    } finally {
        fclose($stream);
    }
});

it('accepts the exact chunk ceiling for statable and non-statable streams', function (bool $socket): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);

    if ($socket) {
        [$stream, $writer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($writer, 'abcd');
        fclose($writer);
    } else {
        $stream = UploadTestHelpers::streamFor('abcd');
    }

    try {
        expect($staged->append($session, $stream, 0))->toBe(4)
            ->and(Storage::disk('local')->get($staged->keyFor($session)))->toBe('abcd');
    } finally {
        fclose($stream);
    }
})->with(['statable' => [false], 'non-statable' => [true]]);
