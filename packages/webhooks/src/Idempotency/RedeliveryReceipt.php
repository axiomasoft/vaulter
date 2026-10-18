<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Idempotency;

use Vaulter\Attributes\Internal;
use Vaulter\Drive\Idempotency\ReceiptFields;

/**
 * What a redelivery keeps in its receipt (D25): the id of the re-armed delivery and of its endpoint, never the payload.
 * A keyed retry is answered from it with the delivery as it is now, and queues nothing: the delivery is sent again once
 * per key, even when it was delivered before the retry came.
 */
#[Internal]
final readonly class RedeliveryReceipt
{
    private const string RECEIPT = 'webhook redelivery';

    public function __construct(public string $id, public string $endpointId) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'endpoint_id' => $this->endpointId];
    }

    /** @param array<array-key, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            id: ReceiptFields::text($body, 'id', self::RECEIPT),
            endpointId: ReceiptFields::text($body, 'endpoint_id', self::RECEIPT),
        );
    }
}
