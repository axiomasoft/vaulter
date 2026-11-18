<?php

declare(strict_types=1);

namespace Vaulter\Webhooks\Idempotency;

use Illuminate\Contracts\Encryption\StringEncrypter;
use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Idempotency\ReceiptFields;
use Vaulter\Idempotency\ReceiptCodec;
use Vaulter\Idempotency\ResourceRef;
use Vaulter\Webhooks\Data\MintedEndpointSecret;
use Vaulter\Webhooks\Data\WebhookEndpointSnapshot;

/**
 * The receipt of an endpoint write: `endpoint` — the {@see WebhookEndpointSnapshot} of update and deactivate, built at
 * commit field by field (its version is historical); `minted` — the {@see MintedEndpointSecret} of register and
 * rotate_secret, whose raw signing secret is stored only as the application's ciphertext (`encryptString`, APP_KEY:
 * ID5) and decrypted for a replay, which the runner gives only to the same scope digest — the same actor — after its
 * current authorization; `deleted` — the {@see EndpointDeleteReceipt} of delete; `redelivered` — the
 * {@see RedeliveryReceipt} of redeliver (ids only). Version 1.
 */
#[Internal]
final readonly class EndpointReceiptCodec implements ReceiptCodec
{
    public const string KIND = 'webhooks.endpoint-receipt';

    private const string RECEIPT = 'webhook endpoint';

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
            $result instanceof EndpointDeleteReceipt => ['type' => 'deleted', 'deleted' => $result->toArray()],
            $result instanceof RedeliveryReceipt => ['type' => 'redelivered', 'redelivered' => $result->toArray()],
            $result instanceof MintedEndpointSecret => ['type' => 'minted', 'endpoint' => self::endpoint($result->endpoint), 'secret' => $this->encrypter->encryptString($result->secret)],
            $result instanceof WebhookEndpointSnapshot => ['type' => 'endpoint', 'endpoint' => self::endpoint($result)],
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s encodes an endpoint, a minted secret, an EndpointDeleteReceipt or a RedeliveryReceipt, %s given.', self::KIND, get_debug_type($result))),
        };
    }

    public function decode(array $body, int $version): WebhookEndpointSnapshot|MintedEndpointSecret|EndpointDeleteReceipt|RedeliveryReceipt
    {
        ReceiptFields::assertVersion($body, $version, self::KIND);

        return match ($body['type'] ?? null) {
            'deleted' => EndpointDeleteReceipt::fromArray(ReceiptFields::object($body, 'deleted', self::RECEIPT)),
            'redelivered' => RedeliveryReceipt::fromArray(ReceiptFields::object($body, 'redelivered', self::RECEIPT)),
            'minted' => new MintedEndpointSecret(
                endpoint: self::snapshot(ReceiptFields::object($body, 'endpoint', self::RECEIPT)),
                secret: $this->encrypter->decryptString(ReceiptFields::text($body, 'secret', self::RECEIPT)),
            ),
            'endpoint' => self::snapshot(ReceiptFields::object($body, 'endpoint', self::RECEIPT)),
            default => throw new LogicException('[Vaulter] A webhook endpoint receipt has no known type.'),
        };
    }

    public function resource(mixed $result): ?ResourceRef
    {
        return match (true) {
            // The endpoint is gone: the replay is judged on the recorded scope.
            $result instanceof EndpointDeleteReceipt => null,
            $result instanceof MintedEndpointSecret => new ResourceRef('webhook_endpoint', $result->endpoint->id),
            // The endpoint must still be there, as for a rotation; the delivery is read again for the answer.
            $result instanceof RedeliveryReceipt => new ResourceRef('webhook_endpoint', $result->endpointId),
            $result instanceof WebhookEndpointSnapshot => new ResourceRef('webhook_endpoint', $result->id),
            default => throw new InvalidArgumentException(sprintf('[Vaulter] %s has no resource for %s.', self::KIND, get_debug_type($result))),
        };
    }

    /** @return array<string, mixed> every field of the snapshot; it never holds a secret */
    private static function endpoint(WebhookEndpointSnapshot $endpoint): array
    {
        return [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'drive_id' => $endpoint->driveId,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
            'disabled_at' => ReceiptFields::time($endpoint->disabledAt),
            'created_at' => ReceiptFields::time($endpoint->createdAt),
            'version' => $endpoint->version,
            'tenant_id' => $endpoint->tenantId,
        ];
    }

    /** @param array<array-key, mixed> $fields */
    private static function snapshot(array $fields): WebhookEndpointSnapshot
    {
        $events = [];
        foreach (ReceiptFields::object($fields, 'events', self::RECEIPT) as $event) {
            if (is_string($event)) {
                $events[] = $event;
            }
        }

        return new WebhookEndpointSnapshot(
            id: ReceiptFields::text($fields, 'id', self::RECEIPT),
            url: ReceiptFields::text($fields, 'url', self::RECEIPT),
            driveId: ReceiptFields::nullableText($fields, 'drive_id'),
            events: $events,
            active: ReceiptFields::bool($fields, 'active', self::RECEIPT),
            disabledAt: ReceiptFields::parse($fields, 'disabled_at', self::RECEIPT),
            createdAt: ReceiptFields::parse($fields, 'created_at', self::RECEIPT),
            version: ReceiptFields::int($fields, 'version', self::RECEIPT),
            tenantId: ReceiptFields::nullableText($fields, 'tenant_id'),
        );
    }
}
