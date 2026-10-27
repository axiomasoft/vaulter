<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use InvalidArgumentException;
use Vaulter\Attributes\Internal;
use Vaulter\Operations\OperationContext;
use Vaulter\ValueObjects\Access\ActorRef;

/**
 * The canonical forms of D25. The scope digest is SHA-256 over the tagged tuple (tenant kind/id, scope kind/id,
 * actor kind/id, operation, key), each field length-prefixed (`<len>:<bytes>`, a null as `~`), so no two
 * tuples share an encoding and a null tenant or system actor stays a distinct value; the actor id itself prefixes
 * the length of the actor type, so a type or id holding the delimiter stays apart. The request hash is
 * SHA-256 over the canonical JSON of the fingerprint (keys sorted recursively, unescaped slashes and unicode).
 * Both are stored as lowercase hex (64 characters).
 */
#[Internal]
final class IdempotencyDigest
{
    /**
     * The scope tuple of a keyed operation, as stored for the collision check.
     *
     * @return array{tenant_kind: string, tenant_id: string|null, scope_kind: string, scope_id: string, actor_kind: string, actor_id: string|null, operation: string, idempotency_key: string}
     */
    public static function tuple(OperationContext $context, string $operation, string $key): array
    {
        return [
            'tenant_kind' => $context->tenant->kind,
            'tenant_id' => $context->tenant->id,
            'scope_kind' => $context->scope->kind,
            'scope_id' => $context->scope->id,
            ...self::actor($context->actor->ref()),
            'operation' => $operation,
            'idempotency_key' => $key,
        ];
    }

    /**
     * The actor fields of a tuple.
     *
     * @return array{actor_kind: string, actor_id: string|null}
     */
    public static function actor(ActorRef $actor): array
    {
        return ['actor_kind' => $actor->type === ActorRef::SYSTEM_TYPE ? 'system' : 'user', 'actor_id' => self::actorId($actor)];
    }

    /** The request hash of a keyed write whose transport fingerprinted the request itself (HTTP). */
    public static function transport(string $fingerprint): string
    {
        return self::request(['transport' => $fingerprint]);
    }

    /**
     * @param array{tenant_kind: string, tenant_id: string|null, scope_kind: string, scope_id: string, actor_kind: string, actor_id: string|null, operation: string, idempotency_key: string} $tuple
     */
    public static function scope(array $tuple): string
    {
        $encoded = '';
        foreach (['tenant_kind', 'tenant_id', 'scope_kind', 'scope_id', 'actor_kind', 'actor_id', 'operation', 'idempotency_key'] as $field) {
            $value = $tuple[$field];
            $encoded .= $value === null ? '~' : strlen($value).':'.$value;
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<array-key, mixed> $fingerprint
     */
    public static function request(array $fingerprint): string
    {
        return hash('sha256', self::canonicalJson($fingerprint));
    }

    /**
     * @param array<array-key, mixed> $value
     */
    public static function canonicalJson(array $value): string
    {
        return json_encode(self::sorted($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function sorted(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sorted($item);
            } elseif ($item !== null && !is_scalar($item)) {
                throw new InvalidArgumentException(sprintf('[Vaulter] A request fingerprint holds scalars, null and arrays only; "%s" is %s.', (string) $key, get_debug_type($item)));
            }
        }

        return $value;
    }

    /**
     * A user actor is its type and id, the type length-prefixed (`<byte length>:<type>|<id>`) so a delimiter inside
     * a morph alias or an id cannot make two actors one; a system actor has no id (its reason does not make it
     * another actor). A value longer than a column is stored as its own digest, the scope digest still covers it.
     */
    private static function actorId(ActorRef $actor): ?string
    {
        if ($actor->type === null || $actor->type === ActorRef::SYSTEM_TYPE || $actor->id === null) {
            return null;
        }
        $id = strlen($actor->type).':'.$actor->type.'|'.$actor->id;

        return strlen($id) <= 191 ? $id : 'sha256:'.hash('sha256', $id);
    }
}
