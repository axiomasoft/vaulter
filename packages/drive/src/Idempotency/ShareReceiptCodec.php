<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use Illuminate\Contracts\Encryption\StringEncrypter;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Data\Snapshots\MintedShareLink;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a share-link write: `link` — the {@see ShareLinkSnapshot} of drive.shares.update; `minted` —
 * the {@see MintedShareLink} of drive.shares.create and drive.shares.rotate, whose raw token is stored only as the
 * application's ciphertext (`encryptString`, APP_KEY: ID5, (5)) and decrypted for a replay, which the runner gives
 * only to the same scope digest — the same actor — after its current authorization; `revoke` — the
 * {@see RevokeReceipt} of drive.shares.revoke. All are about the link's node. Version 1.
 */
#[Internal]
final readonly class ShareReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'drive.share-receipt';

    public function __construct(private StringEncrypter $encrypter) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function version(): int
    {
        return 1;
    }

    public function encode(mixed $result): array
    {
        return match (true) {
            $result instanceof RevokeReceipt => ['type' => 'revoke', 'revoke' => $result->toArray()],
            $result instanceof MintedShareLink => ['type' => 'minted', 'link' => self::link($result->link), 'token' => $this->encrypter->encryptString($result->token)],
            $result instanceof ShareLinkSnapshot => ['type' => 'link', 'link' => self::link($result)],
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a share link, a minted link or a RevokeReceipt, %s given.', self::KIND, get_debug_type($result))),
        };
    }

    public function decode(array $body, int $version): ShareLinkSnapshot|MintedShareLink|RevokeReceipt
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);

        return match ($body['type'] ?? null) {
            'revoke' => RevokeReceipt::fromArray(ReceiptFields::object($body, 'revoke', 'share'), 'share'),
            'minted' => new MintedShareLink(link: self::snapshot(ReceiptFields::object($body, 'link', 'share')), token: $this->encrypter->decryptString(ReceiptFields::text($body, 'token', 'share'))),
            'link' => self::snapshot(ReceiptFields::object($body, 'link', 'share')),
            default => throw new LogicException('[Vaulter] A share receipt has no known type.'),
        };
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', match (true) {
            $result instanceof RevokeReceipt => $result->nodeId,
            $result instanceof MintedShareLink => $result->link->nodeId,
            $result instanceof ShareLinkSnapshot => $result->nodeId,
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s has no resource for %s.', self::KIND, get_debug_type($result))),
        });
    }

    /** @return array<string, mixed> every field of the snapshot; it never holds the token */
    private static function link(ShareLinkSnapshot $link): array
    {
        return [
            'id' => $link->id,
            'node_id' => $link->nodeId,
            'role' => $link->role,
            'role_version' => $link->roleVersion,
            'audience' => $link->audience->value,
            'scope' => $link->scope->value,
            'expires_at' => ReceiptFields::time($link->expiresAt),
            'password_protected' => $link->passwordProtected,
            'max_uses' => $link->maxUses,
            'use_count' => $link->useCount,
            'token_prefix' => $link->tokenPrefix,
            'token_generation' => $link->tokenGeneration,
            'revoked_at' => ReceiptFields::time($link->revokedAt),
            'created_at' => ReceiptFields::time($link->createdAt),
            'created_by' => $link->createdBy,
            'version' => $link->version,
        ];
    }

    /** @param array<array-key, mixed> $link */
    private static function snapshot(array $link): ShareLinkSnapshot
    {
        return new ShareLinkSnapshot(
            id: ReceiptFields::text($link, 'id', 'share'),
            nodeId: ReceiptFields::text($link, 'node_id', 'share'),
            role: ReceiptFields::text($link, 'role', 'share'),
            roleVersion: ReceiptFields::int($link, 'role_version', 'share'),
            audience: ShareAudience::from(ReceiptFields::text($link, 'audience', 'share')),
            scope: GrantScope::from(ReceiptFields::text($link, 'scope', 'share')),
            expiresAt: ReceiptFields::parse($link, 'expires_at', 'share'),
            passwordProtected: ReceiptFields::bool($link, 'password_protected', 'share'),
            maxUses: ReceiptFields::nullableInt($link, 'max_uses'),
            useCount: ReceiptFields::int($link, 'use_count', 'share'),
            tokenPrefix: ReceiptFields::text($link, 'token_prefix', 'share'),
            tokenGeneration: ReceiptFields::int($link, 'token_generation', 'share'),
            revokedAt: ReceiptFields::parse($link, 'revoked_at', 'share'),
            createdAt: ReceiptFields::requiredTime($link, 'created_at', 'share'),
            createdBy: ReceiptFields::nullableText($link, 'created_by'),
            version: ReceiptFields::int($link, 'version', 'share'),
        );
    }
}
