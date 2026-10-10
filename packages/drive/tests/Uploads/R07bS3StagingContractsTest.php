<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Exceptions\Uploads\StagedUploadMissingException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Tests\Uploads\UploadTestHelpers;
use Vaulter\Drive\Uploads\S3MultipartHandler;
use Vaulter\Drive\Uploads\StagedUpload;
use Vaulter\Facades\Vaulter;
use Vaulter\Models\Drive\Drive;
use Vaulter\Tests\Drive\DriveTestHelpers;
use Vaulter\Tests\Fixtures\TestUser;

// These tests drive handlers and repositories directly; Vaulter's entry points open the write scope, these calls do not.
beforeEach(fn () => DriveTestHelpers::insideOperation());

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed r07bS3 / r07bStage.
// Do NOT redeclare r07Storage, r07ActionStorage, r07SessionId defined elsewhere.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Handler-level: fresh S3Multipart UploadSession (no HTTP, no manager initiate).
 */
function r07bS3RawSession(int $totalSizeBytes): UploadSession
{
    return UploadTestHelpers::session(
        UploadTestHelpers::storage(),
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: $totalSizeBytes,
    );
}

/**
 * Controller-level: storage with root node created via the Vaulter handle.
 */
function r07bS3CtlStorage(): Drive
{
    return Vaulter::drive(DriveTestHelpers::host())->model();
}

/**
 * Controller-level: create an S3-multipart session via the API and return upload_id.
 *
 * @param array<string,mixed> $overrides
 */
function r07bS3CreateSession(Drive $storage, array $overrides = []): string
{
    return test()->postJson(
        'api/vaulter/v1/drives/'.$storage->getKey().'/uploads',
        ['protocol' => 's3-multipart', 'name' => 'r07b.bin', 'total_size_bytes' => 8, ...$overrides],
    )->assertCreated()->json('data.upload_id');
}

/**
 * Controller-level: POST one part to the S3 parts endpoint; returns TestResponse.
 *
 * @param array<string,string> $extraServer
 */
function r07bS3PutPart(string $uploadId, string $body, array $extraServer = []): TestResponse
{
    return test()->call(
        'POST',
        'api/vaulter/v1/uploads/'.$uploadId.'/parts?byte_offset=0',
        [], [], [],
        array_merge(
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_X_PART_NUMBER' => '1'],
            $extraServer,
        ),
        $body,
    );
}

// ─────────────────────────────────────────────────────────────────────────────

beforeEach(function (): void {
    Storage::fake('local');
    config()->set('vaulter-media.dedup.scope', 'tenant');
});

// ─────────────────────────────────────────────────────────────────────────────
// S3MultipartHandler — handler-level mutation kills
// ─────────────────────────────────────────────────────────────────────────────

// Kills: S3MultipartHandler L135 RemoveArrayItem
// The 'status' key must survive in the ValidationException errors array; removing it
// would make $e->errors() lack the key, silencing the diagnostic.
it('[r07bS3] putPart ValidationException for a non-open session carries the status error key', function (): void {
    $session = r07bS3RawSession(totalSizeBytes: 4);
    app(VaulterDatabase::class)->write(fn () => $session->forceFill(['status' => UploadStatus::Committed->value])->save());

    try {
        app(S3MultipartHandler::class)->putPart(
            $session->refresh(),
            partNo: 1,
            byteOffset: 0,
            chunk: UploadTestHelpers::streamFor('ab'),
        );
        expect(false)->toBeTrue('[r07bS3] ValidationException was expected but not thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('status');
    }
});

// Kills: S3MultipartHandler L200 RemoveArrayItem
// The 'parts' key must survive in the ValidationException thrown when no parts were
// recorded; removing the key makes the error unidentifiable to callers.
it('[r07bS3] complete ValidationException for an empty parts list carries the parts error key', function (): void {
    $session = r07bS3RawSession(totalSizeBytes: 4);

    try {
        app(S3MultipartHandler::class)->complete($session->refresh());
        expect(false)->toBeTrue('[r07bS3] ValidationException was expected but not thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('parts');
    }
});

// Kills: S3MultipartHandler L253 RemoveArrayItem
// Removing the 'parts' key from the total-mismatch exception loses the diagnostic;
// the message text ("assembled N bytes … M were declared") also pins the literals.
it('[r07bS3] complete ValidationException for total mismatch carries the parts key and exact byte counts', function (): void {
    // Declare 5 bytes but upload only 3 → assembled 3 ≠ declared 5.
    $session = r07bS3RawSession(totalSizeBytes: 5);
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('abc'));

    try {
        $handler->complete($session->refresh());
        expect(false)->toBeTrue('[r07bS3] ValidationException was expected but not thrown.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('parts')
            ->and($e->errors()['parts'][0])->toContain('assembled 3 bytes')
            ->and($e->errors()['parts'][0])->toContain('5 were declared');
    }
});

// Kills: S3MultipartHandler L297 IncrementInteger (staged.size > 0 → staged.size > 1)
// With exactly 1 staged byte, isComplete must be true; incrementing the literal to 1
// makes the condition "size > 1" which falsely rejects a legitimate 1-byte upload.
it('[r07bS3] isComplete returns true when exactly one byte is assembled after complete', function (): void {
    $session = r07bS3RawSession(totalSizeBytes: 1);
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor('X'));
    $handler->complete($session->refresh());

    expect($handler->isComplete($session->refresh()))->toBeTrue();
});

// Kills: S3MultipartHandler L326 TernaryNegated/GreaterToGreaterOrEqual/Increment
//        + L332 first GreaterToGreaterOrEqual/Decrement
// max_chunk_bytes = 0 means unlimited; the $max > 0 branch must be skipped entirely.
// Mutations that replace > with >= (making 0 ≥ 0 = true) or negate the ternary
// would limit the read to 1 byte — the etag/sha256 from a truncated stream would
// disagree with what was declared, catching both the read limit and the check.
it('[r07bS3] max_chunk_bytes zero is treated as unlimited and all bytes are recorded intact', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 0);
    $bytes = str_repeat('x', 20); // 20 bytes; truncated to 1 by the >=0 mutation
    $session = r07bS3RawSession(totalSizeBytes: strlen($bytes));

    $result = app(S3MultipartHandler::class)->putPart(
        $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor($bytes),
    );

    expect($result['size'])->toBe(20)
        ->and($result['etag'])->toBe('"'.md5($bytes).'"');
});

// Kills: S3MultipartHandler L332 second GreaterToGreaterOrEqual (strlen($bytes) >= $max
//        would reject a chunk equal to max) and Decrement (strlen > max-1 same effect).
// A chunk exactly at the ceiling must succeed; the > must remain strict.
it('[r07bS3] exactly max_chunk_bytes is accepted without triggering the ceiling', function (): void {
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);
    $session = r07bS3RawSession(totalSizeBytes: 100);

    $result = app(S3MultipartHandler::class)->putPart(
        $session->refresh(),
        partNo: 1,
        byteOffset: 0,
        chunk: UploadTestHelpers::streamFor('abcd'), // exactly 4 = max
    );

    expect($result['size'])->toBe(4);
});

// Kills: S3MultipartHandler L368 RemoveFunctionCall (fwrite in private streamFor)
// Removing fwrite makes each part stream empty; the assembled staged object would
// contain 0 bytes per part, which stream_copy_to_stream catches, but verifying the
// exact reassembled bytes guarantees the write survived.
it('[r07bS3] complete assembles parts in order and the staged object contains the exact original bytes', function (): void {
    $part1 = 'Hello';
    $part2 = 'World';
    $combined = $part1.$part2;
    $session = r07bS3RawSession(totalSizeBytes: strlen($combined));
    $handler = app(S3MultipartHandler::class);

    $handler->putPart($session->refresh(), partNo: 1, byteOffset: 0, chunk: UploadTestHelpers::streamFor($part1));
    $handler->putPart($session->refresh(), partNo: 2, byteOffset: strlen($part1), chunk: UploadTestHelpers::streamFor($part2));
    $handler->complete($session->refresh());

    $stream = app(StagedUpload::class)->readStream($session->refresh());
    expect($stream)->not->toBeNull();

    try {
        expect(stream_get_contents($stream))->toBe($combined);
    } finally {
        fclose($stream);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// StagedUpload — handler-level mutation kills
// ─────────────────────────────────────────────────────────────────────────────

// Kills: StagedUpload L120 IncrementInteger ($offset > 0 → $offset > 1)
// At offset = 1 the fail-closed R-03 guard must still fire even though 1 > 1 is
// false under the mutant; the existing test uses offset = 10 which passes either way.
it('[r07bStage] append at byte offset one rejects immediately when no staged object exists', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 5);

    expect(fn () => app(StagedUpload::class)->append($session, UploadTestHelpers::streamFor('bc'), 1))
        ->toThrow(StagedUploadMissingException::class);
});

// Kills: StagedUpload L138 Increment ($offset > 0 → $offset > 1)
// At offset = 1 the mutant skips reading the existing prefix, so only the new chunk
// is buffered; the result must have 3 bytes and the content must be 'ABC', not 'BC'.
it('[r07bStage] append at offset one preserves the pre-existing byte before writing the chunk', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage(), totalSizeBytes: 3);
    $staged = app(StagedUpload::class);
    $staged->write($session, 'A'); // 1 byte already staged

    $stream = UploadTestHelpers::streamFor('BC');
    try {
        $newSize = $staged->append($session, $stream, 1);
    } finally {
        fclose($stream);
    }

    expect($newSize)->toBe(3)
        ->and(Storage::disk('local')->get($staged->keyFor($session)))->toBe('ABC');
});

// Kills: StagedUpload L192 BooleanOrToBooleanAnd
// When no staged object exists (disk->exists = false), calling disk->size on a
// missing file under the BooleanAnd mutant causes an exception instead of false.
// The test proves the no-file path returns false, not an exception.
it('[r07bStage] rangeMatches returns false without exception when no staged object exists', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());

    $stream = UploadTestHelpers::streamFor('abc');
    try {
        expect(app(StagedUpload::class)->rangeMatches($session, $stream, 0))->toBeFalse();
    } finally {
        fclose($stream);
    }
});

// Kills: StagedUpload L203 Increment ($offset > 0 → $offset > 1)
// At offset = 1 the mutant skips discardBytes; fread then reads from the start of
// the stream and compares 'ABC' against 'BCD' — a false negative. The match at
// offset = 1 must be true (and the non-match must be false) to pin both sides.
it('[r07bStage] rangeMatches at offset one correctly skips the first byte before comparing', function (): void {
    $session = UploadTestHelpers::session(UploadTestHelpers::storage());
    $staged = app(StagedUpload::class);
    $staged->write($session, 'ABCD');

    $match = UploadTestHelpers::streamFor('BCD');
    try {
        expect($staged->rangeMatches($session, $match, 1))->toBeTrue();
    } finally {
        fclose($match);
    }

    $nomatch = UploadTestHelpers::streamFor('ACD');
    try {
        expect($staged->rangeMatches($session, $nomatch, 1))->toBeFalse();
    } finally {
        fclose($nomatch);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// S3MultipartController — HTTP-layer mutation kills
// ─────────────────────────────────────────────────────────────────────────────

// Kills: S3MultipartController L88 RemoveMethodCall (assertContentLengthWithinLimit)
// Without the pre-check the controller reads the body and the handler throws a 422,
// not a 413; the Content-Length gate must fire before any bytes are consumed.
it('[r07bS3] controller rejects a declared Content-Length above max_chunk_bytes with 413', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);

    $storage = r07bS3CtlStorage();
    $uploadId = r07bS3CreateSession($storage, ['total_size_bytes' => 100]);

    r07bS3PutPart($uploadId, 'abcde', ['CONTENT_LENGTH' => '5'])->assertStatus(413);
});

// Kills: S3MultipartController L128 TernaryNegated/IdenticalToNotIdentical
// (target_parent_id === null → rootOf ; mutant: !== null → findNodeInStorage(null) → 404)
// A session with null target_parent_id MUST resolve the storage root, not 404.
// The session is created directly (bypassing the API that always sets the root id)
// to guarantee target_parent_id is null; created_by = null so ownership guard skips.
it('[r07bS3] controller resolves the storage root when session target_parent_id is null', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));

    $storage = r07bS3CtlStorage();

    // Force target_parent_id = null — the API never produces this value because it
    // resolves the root node and stores its id; we need it to exercise the null branch.
    $session = UploadTestHelpers::session(
        $storage,
        protocol: UploadProtocol::S3Multipart,
        totalSizeBytes: 3,
        parentId: null,   // target_parent_id = null
        createdBy: 1,  // the current actor owns this legacy-shaped session
    );

    expect($session->target_parent_id)->toBeNull();

    r07bS3PutPart($session->upload_id, 'abc')->assertCreated();
});

// Kills: S3MultipartController L132 RemoveMethodCall (guardOwnership)
// Without the ownership guard any authenticated actor can modify a session belonging
// to a different user; the second actor must receive 403.
it('[r07bS3] controller forbids a different authenticated user from accessing another users session', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    $storage = r07bS3CtlStorage();
    $uploadId = r07bS3CreateSession($storage, ['total_size_bytes' => 3]);

    // Switch to a completely different user who did not create the session.
    $this->actingAs((new TestUser)->forceFill(['id' => 99]));

    r07bS3PutPart($uploadId, 'abc')->assertForbidden();
});

// Kills: S3MultipartController L224 SmallerOrEqualToSmaller (declared <= max → declared < max)
// At exactly max the mutant would abort(413) instead of returning early; the response
// must be 201, proving the <= is preserved.
it('[r07bS3] controller accepts a declared Content-Length exactly equal to max_chunk_bytes', function (): void {
    $this->actingAs((new TestUser)->forceFill(['id' => 1]));
    config()->set('vaulter-drive.uploads.resumable.max_chunk_size', 4);

    $storage = r07bS3CtlStorage();
    $uploadId = r07bS3CreateSession($storage, ['total_size_bytes' => 100]);

    r07bS3PutPart($uploadId, 'abcd', ['CONTENT_LENGTH' => '4'])->assertCreated();
});
