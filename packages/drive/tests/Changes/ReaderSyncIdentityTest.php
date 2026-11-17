<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Packman\Config\Exceptions\InvalidConfiguration;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Changes\ReaderSyncIdentity;
use Vaulter\Drive\Exceptions\Changes\CursorExpiredException;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;
use Vaulter\Drive\Repositories\Changes\ChangeHeadRepository;
use Vaulter\Drive\Testing\Access\AccessTestHelpers;
use Vaulter\Drive\Testing\Drive\DriveTestHelpers;
use Vaulter\Drive\Tests\TestCase;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The reader sync identity: the nine-field length-prefixed sync key
 * message, its subkeys and the cursor principal binding, checked against golden
 * vectors. The expected literals were computed
 * independently with Python stdlib hmac/hashlib/struct/base64 and are copied here by
 * hand, never produced by the helper under test. The app key is a public synthetic
 * fixture, used as the exact bytes of the config string ("base64:" is not decoded).
 * Then the committed C0 bootstrap on SQLite: real engine isolation and races are for the
 * owner qualification.
 */
uses(TestCase::class, DatabaseMigrations::class);

const SYNC_APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
const SYNC_CURSOR_SUBKEY_HEX = '9cbfcd8ce2ecccf20c57c0abd050f4ad44196ea77690f666c119fbf3ab70640b';
const SYNC_SUBKEY_HEX = '3ecf3974591c3e4fc4654c5ed2c34d8bb8049b91e04ac66ac0bbf99f72771d3f';

/** @return array<string, array{?string, ActorRef, string, string, string}> tenant, actor, message hex, digest hex, sync key */
function syncKeyGoldenVectors(): array
{
    return [
        'user, no tenant' => [
            null, new ActorRef('App\Models\User', '7'),
            '000000187661756c7465722e6368616e6765732e73796e635f6b657900000001320000000131000000010000000001390000000f4170705c4d6f64656c735c55736572000000013700000000000000023432',
            'ad1b40da44717227cc266cc00b50d334b87ecd4d66c877ba6fd1e887e774daf3',
            'rRtA2kRxcifMJmzAC1DTNLh-zU1myHe6b9Hoh-d02vM',
        ],
        'user, tenant with a delimiter' => [
            'acme|west', new ActorRef('App\Models\User', '7'),
            '000000187661756c7465722e6368616e6765732e73796e635f6b6579000000013200000001310000000a0161636d657c7765737400000001390000000f4170705c4d6f64656c735c55736572000000013700000000000000023432',
            '07f05d899f0f2d822d9fe9779c68b024876e51fc824d6bb9647ecfabca0dec3c',
            'B_BdiZ8PLYItn-l3nGiwJIduUfyCTWu5ZH7Pq8oN7Dw',
        ],
        'system with a reason' => [
            null, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'replication'),
            '000000187661756c7465722e6368616e6765732e73796e635f6b657900000001320000000131000000010000000001390000000e7661756c7465723a73797374656d000000000000000b7265706c69636174696f6e000000023432',
            'ebe68c7d5c355152d187826fe995226df98f4b6e11c2fee28530b608bd452552',
            '6-aMfVw1UVLRh4Jv6ZUibfmPS24Rwv7ihTC2CL1FJVI',
        ],
    ];
}

function syncIdentity(int $generation = 1): ReaderSyncIdentity
{
    return new ReaderSyncIdentity(SYNC_APP_KEY, $generation);
}

it('derives the golden sync and cursor subkeys from the raw app key bytes', function (): void {
    $identity = syncIdentity();
    $decoded = new ReaderSyncIdentity(base64_decode(substr(SYNC_APP_KEY, 7), true), 1);

    expect(bin2hex($identity->syncSubkey()))->toBe(SYNC_SUBKEY_HEX)
        ->and(bin2hex($identity->cursorSubkey()))->toBe(SYNC_CURSOR_SUBKEY_HEX)
        ->and($identity->cursorSubkey())->not->toBe($identity->syncSubkey())
        // The decoded Laravel key is another key material: not this contract.
        ->and(bin2hex($decoded->syncSubkey()))->not->toBe(SYNC_SUBKEY_HEX)
        ->and(bin2hex(syncIdentity(2)->syncSubkey()))->not->toBe(SYNC_SUBKEY_HEX)
        ->and(syncIdentity(2)->cursorSubkey())->toBe($identity->cursorSubkey());
});

it('builds the golden nine-field message and sync key', function (?string $tenant, ActorRef $actor, string $messageHex, string $digestHex, string $syncKey): void {
    $identity = syncIdentity();
    $message = $identity->message($tenant, 9, $actor, 42);
    $key = $identity->syncKey($tenant, 9, $actor, 42);

    // Walk the fields: nine uint32-be prefixed values, nothing else.
    $fields = [];
    for ($offset = 0; $offset < strlen($message);) {
        $length = unpack('N', substr($message, $offset, 4))[1];
        $fields[] = substr($message, $offset + 4, $length);
        $offset += 4 + $length;
    }

    expect(bin2hex($message))->toBe($messageHex)
        ->and($fields)->toHaveCount(9)
        ->and($key)->toBe($syncKey)
        ->and(strlen($key))->toBe(43)
        ->and($key)->toMatch('/^[A-Za-z0-9_-]{43}$/')
        ->and(bin2hex(base64_decode(strtr($key, '-_', '+/'), true)))->toBe($digestHex);
})->with(syncKeyGoldenVectors());

it('normalizes a null and an empty tenant alike and keeps a present tenant apart', function (): void {
    $user = new ActorRef('App\Models\User', '7');
    $identity = syncIdentity();

    expect($identity->syncKey('', 9, $user, 42))->toBe($identity->syncKey(null, 9, $user, 42))
        ->and(ReaderSyncIdentity::tenantField(''))->toBe("\x00")
        ->and(ReaderSyncIdentity::tenantField(null))->toBe("\x00")
        ->and(ReaderSyncIdentity::tenantField('0'))->toBe("\x010")
        ->and(ReaderSyncIdentity::tenantField(0))->toBe("\x010")
        ->and($identity->syncKey('0', 9, $user, 42))->not->toBe($identity->syncKey(null, 9, $user, 42))
        ->and(ReaderSyncIdentity::tenant(''))->toBeNull()
        ->and(ReaderSyncIdentity::tenant(5))->toBe('5');
});

it('differs for every other part of the tuple and is stable for the same one', function (): void {
    $identity = syncIdentity();
    $user = new ActorRef('App\Models\User', 7);
    $base = $identity->syncKey(null, 9, $user, 42);

    expect($identity->syncKey(null, '9', new ActorRef('App\Models\User', '7'), '42'))->toBe($base)
        ->and($identity->syncKey(null, 9, new ActorRef('App\Models\User', '07'), 42))->not->toBe($base)
        ->and($identity->syncKey(null, 9, new ActorRef('App\Models\Admin', 7), 42))->not->toBe($base)
        ->and($identity->syncKey(null, 9, new ActorRef('App\Models\User', 8), 42))->not->toBe($base)
        ->and($identity->syncKey(null, 10, $user, 42))->not->toBe($base)
        ->and($identity->syncKey(null, 9, $user, 43))->not->toBe($base)
        ->and($identity->syncKey('acme', 9, $user, 42))->not->toBe($base)
        ->and(syncIdentity(2)->syncKey(null, 9, $user, 42))->not->toBe($base)
        ->and($identity->syncKey(null, 9, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'replication'), 42))
        ->not->toBe($identity->syncKey(null, 9, new ActorRef(ActorRef::SYSTEM_TYPE, null, 'backup'), 42));
});

it('cannot collide adjacent fields and keeps the exact bytes of every value', function (): void {
    $identity = syncIdentity();
    $user = new ActorRef('App\Models\User', '23');

    expect($identity->syncKey(null, '1', $user, 4))->not->toBe($identity->syncKey(null, '12', new ActorRef('App\Models\User', '3'), 4))
        ->and($identity->syncKey(null, 9, new ActorRef('a|b', 'c'), 1))->not->toBe($identity->syncKey(null, 9, new ActorRef('a', 'b|c'), 1))
        // Composed and decomposed é are different bytes, so different keys: no NFC.
        ->and($identity->syncKey("caf\u{00E9}", 9, $user, 1))->not->toBe($identity->syncKey("cafe\u{0301}", 9, $user, 1))
        ->and(ReaderSyncIdentity::lp("caf\u{00E9}"))->toBe("\x00\x00\x00\x05caf\u{00E9}")
        ->and(ReaderSyncIdentity::lp(''))->toBe("\x00\x00\x00\x00");
});

it('has no sync identity for an anonymous reader, an empty key or a non-positive generation', function (Closure $build): void {
    $build();
})->with([
    'anonymous' => fn (): array => ReaderSyncIdentity::principal(new ActorRef(null, null)),
    'empty key' => fn (): ReaderSyncIdentity => new ReaderSyncIdentity('', 1),
    'zero generation' => fn (): ReaderSyncIdentity => new ReaderSyncIdentity(SYNC_APP_KEY, 0),
])->throws(InvalidArgumentException::class);

describe('committed C0 bootstrap', function (): void {
    beforeEach(function (): void {
        config()->set('vaulter-drive.access.permission_driver', 'database');
        $this->drive = DriveTestHelpers::drive();
        $this->shared = $this->drive->folders()->create('shared');
        AccessTestHelpers::grant($this->shared, RoleRegistry::READER);
        $this->reader = $this->drive->actingAs(AccessTestHelpers::user(1));
        $this->heads = app(ChangeHeadRepository::class);
        $this->driveId = $this->drive->model()->getKey();
    });

    afterEach(fn () => Carbon::setTestNow());

    it('creates and commits the head before it issues the cursor', function (): void {
        $this->heads->delete($this->driveId);

        $cursor = $this->reader->changes()->startCursor();

        expect(DB::connection()->transactionLevel())->toBe(0)
            ->and($this->heads->find($this->driveId))->toBe(['last_seq' => 0, 'pruned_through' => 0, 'epoch' => 1])
            ->and($this->reader->changes()->list($cursor)->changes)->toBe([]);
    });

    it('refuses an outer transaction and leaves no uncommitted head behind', function (): void {
        $this->heads->delete($this->driveId);

        expect(fn () => DB::transaction(fn () => $this->reader->changes()->startCursor()))->toThrow(LogicException::class, 'cannot run inside an open transaction')
            ->and($this->heads->find($this->driveId))->toBeNull();
    });

    it('is a cursor at the committed head that catches up what changed after it', function (): void {
        $c0 = $this->reader->changes()->startCursor();
        $doc = $this->drive->folders()->create('doc', $this->shared);

        $page = $this->reader->changes()->list($c0);

        expect(array_map(fn ($change) => $change->nodeId, $page->changes))->toBe([$doc->id])
            ->and($this->reader->changes()->list($page->cursor)->changes)->toBe([]);
    });

    it('advances an empty filtered page past hidden rows', function (): void {
        $c0 = $this->reader->changes()->startCursor();
        $this->drive->folders()->create('hidden');

        $page = $this->reader->changes()->list($c0);

        expect($page->changes)->toBe([])
            ->and($page->cursor)->not->toBe($c0)
            ->and($page->hasMore)->toBeFalse();
    });

    it('binds the cursor to its typed principal', function (): void {
        $cursor = $this->reader->changes()->startCursor();

        expect(fn () => $this->drive->actingAs(AccessTestHelpers::user(2))->changes()->list($cursor))->toThrow(InvalidCursorException::class)
            ->and(fn () => $this->drive->changes()->list($cursor))->toThrow(InvalidCursorException::class);
    });

    it('expires the cursor when the key generation changes', function (): void {
        $cursor = $this->reader->changes()->startCursor();
        config()->set('vaulter-drive.changes.key_generation', 2);

        expect(fn () => $this->reader->changes()->list($cursor))->toThrow(CursorExpiredException::class);
    });

    it('is invalid after app key rotation', function (): void {
        $cursor = $this->reader->changes()->startCursor();
        config()->set('app.key', SYNC_APP_KEY);

        expect(fn () => $this->reader->changes()->list($cursor))->toThrow(InvalidCursorException::class);
    });

    it('expires at its deadline, which a smaller cap shortens and never extends', function (): void {
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_000));
        $cursor = $this->reader->changes()->startCursor();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_050));
        $next = $this->reader->changes()->list($cursor)->cursor;

        config()->set('vaulter-drive.changes.cursor_max_age_seconds', 60);
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_060));
        $shortened = $this->reader->changes()->list($next)->cursor;
        Carbon::setTestNow(Carbon::createFromTimestamp(1_000_061));
        expect(fn () => $this->reader->changes()->list($next))->toThrow(CursorExpiredException::class);

        config()->set('vaulter-drive.changes.cursor_max_age_seconds', 86400);
        expect(fn () => $this->reader->changes()->list($shortened))->toThrow(CursorExpiredException::class);
    });

    it('validates the typed cursor config', function (array $values): void {
        config()->set($values);

        $this->reader->changes()->startCursor();
    })->with([
        'zero generation' => [['vaulter-drive.changes.key_generation' => 0]],
        'zero max age' => [['vaulter-drive.changes.cursor_max_age_seconds' => 0]],
        'max age over 30 days' => [['vaulter-drive.changes.cursor_max_age_seconds' => 2_592_001]],
        'max age over the retention' => [['vaulter-drive.changes.retention_days' => 1, 'vaulter-drive.changes.cursor_max_age_seconds' => 86_401]],
    ])->throws(InvalidConfiguration::class);
});
