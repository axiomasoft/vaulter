<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Idempotency;

use Packman\Attributes\Internal;
use Vaulter\Drive\Idempotency\ReceiptFields;

/**
 * What an endpoint deletion keeps in its receipt (a delete receipt carries the provable original authorization
 * scope): the id of the removed endpoint and the scope the right to manage was proven in — the `manage` ability
 * on the endpoints of a tenant (`tenantId`, null without tenancy) — never its url, events or secrets. The endpoint is
 * gone, so a replay checks that scope now: the ability still held, in the same tenant.
 */
#[Internal]
final readonly class EndpointDeleteReceipt
{
    public const string ABILITY = 'manage';

    public function __construct(
        public string $id,
        public ?string $tenantId,
        public string $ability = self::ABILITY,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'tenant_id' => $this->tenantId, 'ability' => $this->ability];
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            id: ReceiptFields::text($body, 'id', 'webhook endpoint'),
            tenantId: ReceiptFields::nullableText($body, 'tenant_id'),
            ability: ReceiptFields::text($body, 'ability', 'webhook endpoint'),
        );
    }
}
