<?php

declare(strict_types=1);

namespace Vaulter\Changes;

use JsonException;
use Vaulter\Attributes\Internal;
use Vaulter\Exceptions\Changes\InvalidCursorException;

/**
 * The position of a client in a drive's change journal: the drive, its
 * tenant, the journal epoch and the last seq the client has applied. The token is
 * opaque to clients: base64url(json) "." base64url(HMAC-SHA256(json)), signed with a
 * key derived from the application key, so a client can neither forge a position
 * nor carry it to another drive.
 */
#[Internal]
final readonly class ChangeCursor
{
    private const int VERSION = 1;

    public function __construct(
        public ?string $tenantId,
        public string $driveId,
        public int $epoch,
        public int $after,
    ) {}

    public function encode(string $appKey): string
    {
        $json = json_encode(['v' => self::VERSION, 't' => $this->tenantId, 'd' => $this->driveId, 'e' => $this->epoch, 'a' => $this->after], JSON_THROW_ON_ERROR);

        return self::base64url($json).'.'.self::base64url(self::signature($json, $appKey));
    }

    /** @throws InvalidCursorException when the token is not a cursor this application signed */
    public static function decode(string $token, string $appKey): self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw InvalidCursorException::malformed();
        }
        $json = self::unbase64url($parts[0]);
        $signature = self::unbase64url($parts[1]);
        if ($json === null || $signature === null || !hash_equals(self::signature($json, $appKey), $signature)) {
            throw InvalidCursorException::malformed();
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCursorException::malformed();
        }
        if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION
            || !array_key_exists('t', $data) || !(is_string($data['t']) || $data['t'] === null)
            || !is_string($data['d'] ?? null) || $data['d'] === ''
            || !is_int($data['e'] ?? null) || $data['e'] < 1
            || !is_int($data['a'] ?? null) || $data['a'] < 0) {
            throw InvalidCursorException::malformed();
        }

        return new self(tenantId: $data['t'], driveId: $data['d'], epoch: $data['e'], after: $data['a']);
    }

    private static function signature(string $json, string $appKey): string
    {
        return hash_hmac('sha256', $json, hash_hmac('sha256', 'vaulter.changes.cursor', $appKey, true), true);
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function unbase64url(string $text): ?string
    {
        if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/'), true);

        return $bytes === false ? null : $bytes;
    }
}
