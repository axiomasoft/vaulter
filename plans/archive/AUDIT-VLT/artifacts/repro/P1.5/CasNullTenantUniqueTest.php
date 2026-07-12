<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Vaulter\Support\Config;
use Vaulter\Tests\TestCase;

// P1.5 C15 repro — does unique(tenant_id, sha256) coalesce identical bytes when
// tenant_id is NULL (tenancy off, the DEFAULT scope='tenant')? In SQLite/PG a
// UNIQUE index treats NULLs as distinct, so the "portable race backbone" (the
// UNIQUE constraint) does NOT fire for a NULL tenant.
uses(TestCase::class, RefreshDatabase::class);

function insertBlob(?int $tenantId, string $sha256): void
{
    DB::table(Config::table('blobs'))->insert([
        'tenant_id' => $tenantId,
        'storage_id' => null,
        'hash_algo' => 'sha256',
        'sha256' => $sha256,
        'size_bytes' => 10,
        'disk' => 'local',
        'storage_key' => 'global/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2).'/'.$sha256.'-'.uniqid(),
        'status' => 'committed',
        'ref_count' => 0,
        'dedup_scope' => 'tenant',
        'chunked' => false,
        'first_seen_at' => now(),
        'committed_at' => now(),
    ]);
}

it('DEFECT: NULL tenant does not trigger the dedup UNIQUE (duplicates slip through)', function () {
    config()->set('vaulter.dedup.scope', 'tenant');

    $sha = str_repeat('a', 64);

    insertBlob(null, $sha);

    $threw = false;
    try {
        insertBlob(null, $sha); // same (NULL, sha) — SHOULD be blocked by dedup UNIQUE
    } catch (UniqueConstraintViolationException) {
        $threw = true;
    }

    $rows = DB::table(Config::table('blobs'))->where('sha256', $sha)->count();

    dump("driver=".DB::connection()->getDriverName()." null-tenant rows for same sha256 = {$rows}, unique_violation_thrown=".($threw ? 'yes' : 'no'));

    // Document the ACTUAL behaviour: two rows means the UNIQUE did NOT coalesce.
    expect($rows)->toBe(2)
        ->and($threw)->toBeFalse();
});

it('CONTROL: a concrete tenant_id DOES trigger the dedup UNIQUE', function () {
    config()->set('vaulter.dedup.scope', 'tenant');

    $sha = str_repeat('b', 64);

    insertBlob(7, $sha);

    $threw = false;
    try {
        insertBlob(7, $sha);
    } catch (UniqueConstraintViolationException) {
        $threw = true;
    }

    $rows = DB::table(Config::table('blobs'))->where('sha256', $sha)->count();

    dump("driver=".DB::connection()->getDriverName()." tenant=7 rows for same sha256 = {$rows}, unique_violation_thrown=".($threw ? 'yes' : 'no'));

    expect($threw)->toBeTrue()
        ->and($rows)->toBe(1);
});
