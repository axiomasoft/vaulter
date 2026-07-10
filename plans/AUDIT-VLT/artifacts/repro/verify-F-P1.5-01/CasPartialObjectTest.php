<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Contracts\Cas\ContentStore;
use Vaulter\Support\Config;
use Vaulter\Tests\TestCase;

// P1.5 C11 repro — FlysystemStorageDriver::put() writes the stream DIRECTLY to the
// final canonical key (no temp-file + atomic rename). promote() short-circuits on
// exists(). So a PARTIAL object already sitting at the canonical key (a crashed
// prior promote, or a truncated write) is TRUSTED: a fresh commit of the full bytes
// creates a committed row but never overwrites the truncated object -> CAS serves
// corrupt bytes under a "committed" blob.
uses(TestCase::class, RefreshDatabase::class);

function streamOf2(string $bytes)
{
    $h = fopen('php://temp', 'r+b');
    fwrite($h, $bytes);
    rewind($h);

    return $h;
}

it('DEFECT: a pre-existing partial object at the canonical key is trusted, commit serves truncated bytes', function () {
    config()->set('vaulter.dedup.scope', 'tenant');
    config()->set('vaulter.storage.default_disk', 'local');

    $full = 'the-complete-canonical-payload-'.str_repeat('Z', 64);
    $sha = hash('sha256', $full);

    /** @var ContentStore $content */
    $content = app(ContentStore::class);
    // scope segment for NULL tenant under scope=tenant is "tnull".
    $key = $content->keyFor(scope: 'tnull', sha256: $sha);

    // Simulate a crashed/half promote: a TRUNCATED object already at the final key.
    Storage::disk('local')->put($key, 'TRUNC'); // 5 bytes, not the full payload

    /** @var BlobStore $store */
    $store = app(BlobStore::class);
    $blob = $store->commit(streamOf2($full), tenantId: null);

    // What the CAS now serves for this "committed" blob:
    $served = Storage::disk('local')->get($blob->storage_key);

    dump("sha=".$sha." blob_sha=".$blob->sha256." served_len=".strlen((string) $served)." expected_len=".strlen($full)." status=".$blob->status->value);

    // The served bytes are the TRUNCATED 5 bytes, not the full payload => corruption.
    expect($blob->sha256)->toBe($sha)
        ->and($served)->toBe('TRUNC')
        ->and(strlen((string) $served))->not->toBe(strlen($full));
});
