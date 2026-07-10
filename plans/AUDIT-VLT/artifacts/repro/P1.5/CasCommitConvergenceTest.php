<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vaulter\Contracts\Cas\BlobStore;
use Vaulter\Support\Config;
use Vaulter\Tests\TestCase;

// P1.5 C15/C5 repro — even though the dedup UNIQUE(tenant_id, sha256) does NOT
// fire for a NULL tenant, does BlobStore::commit still converge two identical
// uploads onto ONE row? The scope-qualified storage_key ("tnull/ab/cd/<sha>") is
// deterministic, and storage_key carries its OWN unique index, so it is the real
// backbone here.
uses(TestCase::class, RefreshDatabase::class);

function streamOf(string $bytes)
{
    $h = fopen('php://temp', 'r+b');
    fwrite($h, $bytes);
    rewind($h);

    return $h;
}

it('commit converges NULL-tenant identical bytes onto one row (storage_key backbone)', function () {
    config()->set('vaulter.dedup.scope', 'tenant');

    /** @var BlobStore $store */
    $store = app(BlobStore::class);

    $bytes = 'identical-cas-payload-'.str_repeat('x', 32);

    $b1 = $store->commit(streamOf($bytes), tenantId: null);
    $b2 = $store->commit(streamOf($bytes), tenantId: null);

    $rows = DB::table(Config::table('blobs'))->where('sha256', $b1->sha256)->count();

    dump("driver=".DB::connection()->getDriverName()." b1={$b1->getKey()} b2={$b2->getKey()} storage_key={$b1->storage_key} rows={$rows}");

    expect($b2->getKey())->toBe($b1->getKey())
        ->and($rows)->toBe(1);
});
