<?php

declare(strict_types=1);

use Vaulter\Changes\ChangeCursor;
use Vaulter\Exceptions\Changes\InvalidCursorException;

/**
 * The change cursor (PLAN2.D50): an opaque, signed position. Any change to its bytes,
 * a token signed with another key or a payload of another shape is invalid_cursor.
 */
const CHANGE_CURSOR_KEY = 'base64:tJ4mVwShl8LETWdRtTCwcoKS7MS2ipekchk9seHrIVc=';

function changeCursorSign(array $payload, string $key = CHANGE_CURSOR_KEY): string
{
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $b64 = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return $b64($json).'.'.$b64(hash_hmac('sha256', $json, hash_hmac('sha256', 'vaulter.changes.cursor', $key, true), true));
}

it('round-trips its position through an opaque url-safe token', function (): void {
    $token = (new ChangeCursor(tenantId: 't-1', driveId: '42', epoch: 3, after: 17))->encode(CHANGE_CURSOR_KEY);
    $cursor = ChangeCursor::decode($token, CHANGE_CURSOR_KEY);

    expect($token)->toMatch('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/')
        ->and([$cursor->tenantId, $cursor->driveId, $cursor->epoch, $cursor->after])->toBe(['t-1', '42', 3, 17])
        ->and(ChangeCursor::decode((new ChangeCursor(null, '42', 1, 0))->encode(CHANGE_CURSOR_KEY), CHANGE_CURSOR_KEY)->tenantId)->toBeNull();
});

it('accepts a token built exactly as documented', function (): void {
    $cursor = ChangeCursor::decode(changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 2, 'a' => 5]), CHANGE_CURSOR_KEY);

    expect([$cursor->driveId, $cursor->epoch, $cursor->after])->toBe(['7', 2, 5]);
});

it('rejects a token that is not a cursor this application signed', function (string $token): void {
    ChangeCursor::decode($token, CHANGE_CURSOR_KEY);
})->with([
    'empty' => '',
    'no signature' => 'eyJ2IjoxfQ',
    'three parts' => 'a.b.c',
    'not base64url' => 'a+b.c/d',
    'altered payload' => (static function (): string {
        [, $signature] = explode('.', changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5]));
        $payload = rtrim(strtr(base64_encode('{"v":1,"t":null,"d":"7","e":1,"a":500}'), '+/', '-_'), '=');

        return $payload.'.'.$signature;
    })(),
    'altered signature' => changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5]).'A',
    'other key' => changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5], 'another-key'),
    'other version' => changeCursorSign(['v' => 2, 't' => null, 'd' => '7', 'e' => 1, 'a' => 5]),
    'missing tenant' => changeCursorSign(['v' => 1, 'd' => '7', 'e' => 1, 'a' => 5]),
    'numeric drive' => changeCursorSign(['v' => 1, 't' => null, 'd' => 7, 'e' => 1, 'a' => 5]),
    'zero epoch' => changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 0, 'a' => 5]),
    'negative position' => changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => -1]),
    'string position' => changeCursorSign(['v' => 1, 't' => null, 'd' => '7', 'e' => 1, 'a' => '5']),
])->throws(InvalidCursorException::class);

it('maps to 400 invalid_cursor', function (): void {
    $exception = InvalidCursorException::malformed();

    expect([$exception->problemCode(), $exception->httpStatus()])->toBe(['invalid_cursor', 400]);
});
