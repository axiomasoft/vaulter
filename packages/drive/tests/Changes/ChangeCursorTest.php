<?php

declare(strict_types=1);

use Vaulter\Drive\Changes\ChangeCursor;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;

/**
 * The change cursor, format 2: an opaque position bound to tenant, drive,
 * typed principal and key generation, signed with the cursor.v2 subkey. Any change to
 * its bytes, a token signed with another subkey, a format 1 token or a payload of another
 * shape is invalid_cursor.
 */
const CHANGE_CURSOR_APP_KEY = 'base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';

/** HMAC-SHA256(K, lp("vaulter.changes.cursor.v2")) for the key above, computed independently. */
const CHANGE_CURSOR_SUBKEY_HEX = '9cbfcd8ce2ecccf20c57c0abd050f4ad44196ea77690f666c119fbf3ab70640b';

function changeCursorV2Sign(array $payload, ?string $subkey = null): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return $b64($json).'.'.$b64(hash_hmac('sha256', $json, $subkey ?? hex2bin(CHANGE_CURSOR_SUBKEY_HEX), true));
}

function changeCursorV2Payload(array $override = []): array
{
    return [...['v' => 2, 't' => null, 'd' => '7', 'pt' => 'App\Models\User', 'pi' => '1', 'pr' => '', 'g' => 1, 'e' => 1, 'a' => 5, 'i' => 100, 'n' => 200], ...$override];
}

it('round-trips its scoped position through an opaque url-safe token', function (): void {
    $subkey = hex2bin(CHANGE_CURSOR_SUBKEY_HEX);
    $token = new ChangeCursor('t-1', '42', 'App\Models\User', '7', '', 3, 2, 17, 100, 86500)->encode($subkey);
    $cursor = ChangeCursor::decode($token, $subkey);
    $system = ChangeCursor::decode(new ChangeCursor(null, '42', 'vaulter:system', '', 'replication', 1, 1, 0, 0, 1)->encode($subkey), $subkey);

    expect($token)->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/')
        ->and([$cursor->tenantId, $cursor->driveId, $cursor->actorType, $cursor->actorId, $cursor->actorReason, $cursor->keyGeneration, $cursor->epoch, $cursor->after, $cursor->issuedAt, $cursor->notAfter])
        ->toBe(['t-1', '42', 'App\Models\User', '7', '', 3, 2, 17, 100, 86500])
        ->and([$system->tenantId, $system->actorType, $system->actorId, $system->actorReason])->toBe([null, 'vaulter:system', '', 'replication']);
});

it('accepts a token built exactly as documented with the independent cursor subkey', function (): void {
    $cursor = ChangeCursor::decode(changeCursorV2Sign(changeCursorV2Payload()), hex2bin(CHANGE_CURSOR_SUBKEY_HEX));

    expect([$cursor->driveId, $cursor->actorType, $cursor->keyGeneration, $cursor->epoch, $cursor->after])->toBe(['7', 'App\Models\User', 1, 1, 5]);
});

it('rejects a token that is not a format 2 cursor this application signed', function (string $token): void {
    ChangeCursor::decode($token, hex2bin(CHANGE_CURSOR_SUBKEY_HEX));
})->with([
    'empty' => '',
    'no signature' => 'eyJ2IjoyfQ',
    'three parts' => 'a.b.c',
    'not base64url' => 'a+b.c/d',
    'altered payload' => (static function (): string {
        [, $signature] = explode('.', changeCursorV2Sign(changeCursorV2Payload()));

        return rtrim(strtr(base64_encode(json_encode(changeCursorV2Payload(['a' => 500]))), '+/', '-_'), '=').'.'.$signature;
    })(),
    'altered signature' => changeCursorV2Sign(changeCursorV2Payload()).'A',
    'other subkey' => changeCursorV2Sign(changeCursorV2Payload(), 'another-subkey'),
    'v2 payload under the v1 subkey' => changeCursorV2Sign(changeCursorV2Payload(), hash_hmac('sha256', 'vaulter.changes.cursor', CHANGE_CURSOR_APP_KEY, true)),
    'raw app key as subkey' => changeCursorV2Sign(changeCursorV2Payload(), CHANGE_CURSOR_APP_KEY),
    'format 1 signed with the v2 subkey' => changeCursorV2Sign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5]),
    'format 1 token as issued before' => (static function (): string {
        $json = json_encode(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5]);
        $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(hash_hmac('sha256', $json, hash_hmac('sha256', 'vaulter.changes.cursor', CHANGE_CURSOR_APP_KEY, true), true));
    })(),
    'unknown format' => changeCursorV2Sign(changeCursorV2Payload(['v' => 3])),
    'missing tenant' => changeCursorV2Sign(array_diff_key(changeCursorV2Payload(), ['t' => 1])),
    'empty tenant' => changeCursorV2Sign(changeCursorV2Payload(['t' => ''])),
    'numeric drive' => changeCursorV2Sign(changeCursorV2Payload(['d' => 7])),
    'missing principal type' => changeCursorV2Sign(array_diff_key(changeCursorV2Payload(), ['pt' => 1])),
    'empty principal type' => changeCursorV2Sign(changeCursorV2Payload(['pt' => ''])),
    'numeric principal id' => changeCursorV2Sign(changeCursorV2Payload(['pi' => 1])),
    'missing reason' => changeCursorV2Sign(array_diff_key(changeCursorV2Payload(), ['pr' => 1])),
    'zero generation' => changeCursorV2Sign(changeCursorV2Payload(['g' => 0])),
    'string generation' => changeCursorV2Sign(changeCursorV2Payload(['g' => '1'])),
    'zero epoch' => changeCursorV2Sign(changeCursorV2Payload(['e' => 0])),
    'negative position' => changeCursorV2Sign(changeCursorV2Payload(['a' => -1])),
    'string position' => changeCursorV2Sign(changeCursorV2Payload(['a' => '5'])),
    'missing issued_at' => changeCursorV2Sign(array_diff_key(changeCursorV2Payload(), ['i' => 1])),
    'deadline before issue' => changeCursorV2Sign(changeCursorV2Payload(['i' => 200, 'n' => 100])),
])->throws(InvalidCursorException::class);

it('maps to 400 invalid_cursor', function (): void {
    $exception = InvalidCursorException::malformed();

    expect([$exception->problemCode(), $exception->httpStatus()])->toBe(['invalid_cursor', 400]);
});
