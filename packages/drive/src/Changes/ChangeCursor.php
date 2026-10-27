<?php

declare(strict_types=1);

namespace Vaulter\Drive\Changes;

use JsonException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Exceptions\Changes\InvalidCursorException;

/**
 * The position of one reader in a drive's change journal (format 2, PLAN4.D20): the
 * tenant, the drive, the typed principal it was issued to (type, id, system reason), the
 * sync key generation, the journal epoch, the last seq applied and the issued_at/not_after
 * clock. The token is opaque to clients and authenticated, not encrypted:
 * base64url(json) "." base64url(HMAC-SHA256(cursor_subkey, json)), with the cursor subkey
 * of {@see ReaderSyncIdentity}. A format 1 token, another principal or another scope is
 * invalid; the cursor is a position, never an authorization. The page limit is not part
 * of it.
 */
#[Internal]
final readonly class ChangeCursor
{
    private const int VERSION = 2;

    public function __construct(
        public ?string $tenantId,
        public string $driveId,
        public ?string $actorType,
        public string $actorId,
        public string $actorReason,
        public int $keyGeneration,
        public int $epoch,
        public int $after,
        public int $issuedAt,
        public int $notAfter,
    ) {}

    public function encode(string $cursorSubkey): string
    {
        $json = json_encode([
            'v' => self::VERSION, 't' => $this->tenantId, 'd' => $this->driveId,
            'pt' => $this->actorType, 'pi' => $this->actorId, 'pr' => $this->actorReason,
            'g' => $this->keyGeneration, 'e' => $this->epoch, 'a' => $this->after,
            'i' => $this->issuedAt, 'n' => $this->notAfter,
        ], JSON_THROW_ON_ERROR);

        return self::base64url($json).'.'.self::base64url(hash_hmac('sha256', $json, $cursorSubkey, true));
    }

    /** @throws InvalidCursorException when the token is not a format 2 cursor this application signed */
    public static function decode(string $token, string $cursorSubkey): self
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            throw InvalidCursorException::malformed();
        }
        $json = self::unbase64url($parts[0]);
        $signature = self::unbase64url($parts[1]);
        if ($json === null || $signature === null || !hash_equals(hash_hmac('sha256', $json, $cursorSubkey, true), $signature)) {
            throw InvalidCursorException::malformed();
        }
        try {
            $data = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidCursorException::malformed();
        }
        if (!is_array($data) || ($data['v'] ?? null) !== self::VERSION
            || !array_key_exists('t', $data) || !(is_string($data['t']) || $data['t'] === null) || $data['t'] === ''
            || !is_string($data['d'] ?? null) || $data['d'] === ''
            || !array_key_exists('pt', $data) || !(is_string($data['pt']) || $data['pt'] === null) || $data['pt'] === ''
            || !is_string($data['pi'] ?? null) || !is_string($data['pr'] ?? null)
            || !is_int($data['g'] ?? null) || $data['g'] < 1
            || !is_int($data['e'] ?? null) || $data['e'] < 1
            || !is_int($data['a'] ?? null) || $data['a'] < 0
            || !is_int($data['i'] ?? null) || $data['i'] < 0
            || !is_int($data['n'] ?? null) || $data['n'] < $data['i']) {
            throw InvalidCursorException::malformed();
        }

        return new self(
            tenantId: $data['t'], driveId: $data['d'],
            actorType: $data['pt'], actorId: $data['pi'], actorReason: $data['pr'],
            keyGeneration: $data['g'], epoch: $data['e'], after: $data['a'],
            issuedAt: $data['i'], notAfter: $data['n'],
        );
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
