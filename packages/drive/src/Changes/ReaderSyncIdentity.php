<?php

declare(strict_types=1);

namespace Vaulter\Drive\Changes;

use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Support\Identity;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The reader-bound sync identity of the change feed (PLAN4.D20): the typed principal a
 * cursor is bound to and the opaque sync_key of a node as one reader sees it.
 *
 * Every field is length-prefixed, lp(b) = uint32_be(strlen(b)) || b, never joined by a
 * delimiter. The key material K is the exact bytes of config('app.key'); a "base64:"
 * prefix is not decoded. sync_key = base64url(HMAC-SHA256(sync_subkey, message)) with
 * sync_subkey = HMAC-SHA256(K, lp(SYNC_DOMAIN) || lp(generation)) and a message of nine
 * fields: domain, format "2", generation, tagged tenant, drive, actor type, actor id
 * (empty for the system), system reason (empty for a user), node. The cursor signs with
 * its own subkey HMAC-SHA256(K, lp(CURSOR_DOMAIN)), so neither value stands for the other.
 * A key is a client-side matching handle, not a capability.
 */
#[Internal]
final readonly class ReaderSyncIdentity
{
    public const string SYNC_DOMAIN = 'vaulter.changes.sync_key';

    public const string CURSOR_DOMAIN = 'vaulter.changes.cursor.v2';

    public const string FORMAT = '2';

    /** @throws InvalidArgumentException when the key is empty or the generation is not positive */
    public function __construct(
        private string $appKey,
        public int $keyGeneration,
    ) {
        if ($appKey === '') {
            throw new InvalidArgumentException('[Vaulter] The sync identity requires a nonempty application key (app.key).');
        }
        if ($keyGeneration < 1) {
            throw new InvalidArgumentException('[Vaulter] The sync key generation must be a positive integer.');
        }
    }

    /** uint32 big-endian byte length, then the bytes. */
    public static function lp(string $bytes): string
    {
        return pack('N', strlen($bytes)).$bytes;
    }

    /** The tenant rule of the feed: Identity::stringify, "" is no tenant. */
    public static function tenant(mixed $tenant): ?string
    {
        $tenant = Identity::stringify($tenant);

        return $tenant === '' ? null : $tenant;
    }

    /** 0x00 for no tenant, 0x01 || tenant otherwise: null and "0" stay apart. */
    public static function tenantField(mixed $tenant): string
    {
        $tenant = self::tenant($tenant);

        return $tenant === null ? "\x00" : "\x01".$tenant;
    }

    /**
     * The typed principal of a reader: its type, id bytes (empty for the system) and
     * reason (empty for a user). An anonymous reader has no principal.
     *
     * @return array{type: string, id: string, reason: string}
     *
     * @throws InvalidArgumentException for an anonymous actor
     */
    public static function principal(ActorRef $actor): array
    {
        if ($actor->type === null) {
            throw new InvalidArgumentException('[Vaulter] An anonymous reader has no sync identity.');
        }
        if ($actor->type === ActorRef::SYSTEM_TYPE) {
            return ['type' => ActorRef::SYSTEM_TYPE, 'id' => '', 'reason' => (string) $actor->reason];
        }

        return ['type' => $actor->type, 'id' => Identity::stringify($actor->id), 'reason' => ''];
    }

    public function syncSubkey(): string
    {
        return hash_hmac('sha256', self::lp(self::SYNC_DOMAIN).self::lp((string) $this->keyGeneration), $this->appKey, true);
    }

    public function cursorSubkey(): string
    {
        return hash_hmac('sha256', self::lp(self::CURSOR_DOMAIN), $this->appKey, true);
    }

    /** The nine length-prefixed fields the sync key is computed over. */
    public function message(mixed $tenant, int|string $driveId, ActorRef $actor, int|string $nodeId): string
    {
        $principal = self::principal($actor);

        return self::lp(self::SYNC_DOMAIN)
            .self::lp(self::FORMAT)
            .self::lp((string) $this->keyGeneration)
            .self::lp(self::tenantField($tenant))
            .self::lp(Identity::stringify($driveId))
            .self::lp($principal['type'])
            .self::lp($principal['id'])
            .self::lp($principal['reason'])
            .self::lp(Identity::stringify($nodeId));
    }

    /** base64url, no padding, of all 32 bytes: 43 ASCII characters. */
    public function syncKey(mixed $tenant, int|string $driveId, ActorRef $actor, int|string $nodeId): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $this->message($tenant, $driveId, $actor, $nodeId), $this->syncSubkey(), true)), '+/', '-_'), '=');
    }
}
