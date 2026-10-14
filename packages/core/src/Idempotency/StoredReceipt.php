<?php

declare(strict_types=1);

namespace Vaulter\Idempotency;

use stdClass;

/**
 * A row of the idempotency table with typed fields: what {@see IdempotencyStore::claim()} returns for a digest
 * that already has a committed (or still claimed) receipt.
 *
 * @internal
 */
final readonly class StoredReceipt
{
    /** The columns of the scope tuple that identify who asked for what (the key itself is stored apart). */
    public const array TUPLE = ['tenant_kind', 'tenant_id', 'scope_kind', 'scope_id', 'actor_kind', 'actor_id', 'operation'];

    /**
     * @param array<string, string|null> $tuple the stored scope tuple, by column
     */
    public function __construct(
        public string $status,
        public string $expiresAt,
        public string $idempotencyKey,
        public string $requestHash,
        public array $tuple,
        public string $kind,
        public int $codecVersion,
        public string $body,
        public ?string $headers,
        public ?ResourceRef $resource,
        public ?ResourceRef $scope,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        $tuple = [];
        foreach (self::TUPLE as $column) {
            $tuple[$column] = self::nullable($row->{$column} ?? null);
        }

        return new self(
            status: self::text($row->status ?? null),
            expiresAt: self::text($row->expires_at ?? null),
            idempotencyKey: self::text($row->idempotency_key ?? null),
            requestHash: self::text($row->request_hash ?? null),
            tuple: $tuple,
            kind: self::text($row->receipt_kind ?? null),
            codecVersion: is_numeric($row->codec_version ?? null) ? (int) $row->codec_version : 0,
            body: self::text($row->receipt_body ?? null),
            headers: self::nullable($row->receipt_headers ?? null),
            resource: self::ref($row->resource_kind ?? null, $row->resource_id ?? null),
            scope: self::ref($row->auth_scope_kind ?? null, $row->auth_scope_id ?? null),
        );
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function nullable(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function ref(mixed $kind, mixed $id): ?ResourceRef
    {
        $kind = self::text($kind);
        $id = self::text($id);

        return $kind !== '' && $id !== '' ? new ResourceRef($kind, $id) : null;
    }
}
