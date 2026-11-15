<?php

declare(strict_types=1);

namespace Vaulter\Drive\Idempotency;

use InvalidArgumentException;
use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Snapshots\GrantSnapshot;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;

/**
 * The receipt of a grant write: `grant` — the {@see GrantSnapshot} of drive.access.grant, field by field, so a
 * replay returns what the first request returned; `revoke` — the {@see RevokeReceipt} of drive.access.revoke, ids and
 * the node the right was proven on only. Both are about the node, which a replay authorizes again. Version 1.
 */
#[Internal]
final class AccessReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'drive.access-receipt';

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
        if ($result instanceof RevokeReceipt) {
            return ['type' => 'revoke', 'revoke' => $result->toArray()];
        }
        $grant = self::grant($result);

        return ['type' => 'grant', 'grant' => [
            'id' => $grant->id,
            'node_id' => $grant->nodeId,
            'subject_type' => $grant->subject->type,
            'subject_id' => $grant->subject->id,
            'role' => $grant->role,
            'role_version' => $grant->roleVersion,
            'scope' => $grant->scope->value,
            'expires_at' => ReceiptFields::time($grant->expiresAt),
            'created_at' => ReceiptFields::time($grant->createdAt),
            'granted_by' => $grant->grantedBy,
        ]];
    }

    public function decode(array $body, int $version): GrantSnapshot|RevokeReceipt
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);
        $type = $body['type'] ?? null;
        if ($type === 'revoke') {
            return RevokeReceipt::fromArray(ReceiptFields::object($body, 'revoke', 'access'), 'access');
        }
        if ($type !== 'grant') {
            throw new LogicException('[Vaulter] An access receipt has no known type.');
        }
        $grant = ReceiptFields::object($body, 'grant', 'access');
        $subjectId = $grant['subject_id'] ?? null;

        return new GrantSnapshot(
            id: ReceiptFields::text($grant, 'id', 'access'),
            nodeId: ReceiptFields::text($grant, 'node_id', 'access'),
            subject: new Subject(ReceiptFields::text($grant, 'subject_type', 'access'), is_int($subjectId) || is_string($subjectId) ? $subjectId : throw new LogicException('[Vaulter] An access receipt has no "subject_id".')),
            role: ReceiptFields::text($grant, 'role', 'access'),
            roleVersion: ReceiptFields::int($grant, 'role_version', 'access'),
            scope: GrantScope::from(ReceiptFields::text($grant, 'scope', 'access')),
            expiresAt: ReceiptFields::parse($grant, 'expires_at', 'access'),
            createdAt: ReceiptFields::requiredTime($grant, 'created_at', 'access'),
            grantedBy: ReceiptFields::nullableText($grant, 'granted_by'),
        );
    }

    public function resource(mixed $result): ResourceRef
    {
        return new ResourceRef('node', $result instanceof RevokeReceipt ? $result->nodeId : self::grant($result)->nodeId);
    }

    private static function grant(mixed $result): GrantSnapshot
    {
        return $result instanceof GrantSnapshot ? $result : throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes a GrantSnapshot or a RevokeReceipt, %s given.', self::KIND, get_debug_type($result)));
    }
}
