<?php

declare(strict_types=1);

namespace Vaulter\Drive\Testing\Events;

use Packman\Attributes\Internal;
use PHPUnit\Framework\Assert;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Events\EventTypeContract;
use Vaulter\Events\Outbox\Models\OutboxEvent;

/**
 * Compares what the real Actions record with the EventSpec of each type: the keys and
 * value types of the webhook data, and the keys no envelope may ever carry.
 */
#[Internal]
final class EventConformance
{
    /** Keys that must not appear anywhere in an envelope (names, secrets, request details). */
    public const array FORBIDDEN = [
        'name', 'from_name', 'to_name', 'path', 'mime', 'content', 'token', 'token_hash', 'token_prefix',
        'secret', 'password', 'ip', 'user_agent', 'request_id', 'storage_path',
    ];

    /**
     * Assert every outbox row of the given types against its spec.
     *
     * @param list<EventTypeContract> $types
     */
    public static function assertRecorded(array $types): void
    {
        foreach ($types as $type) {
            $rows = OutboxEvent::query()->where('type', $type->value)->get();
            Assert::assertNotEmpty($rows, 'No outbox row was recorded for '.$type->value);
            foreach ($rows as $row) {
                self::assertEnvelope($type, $row->payload);
            }
        }
    }

    /** @param list<EventTypeContract> $types the types that are audited but never leave the process (not external) */
    public static function assertAuditedOnly(array $types): void
    {
        foreach ($types as $type) {
            Assert::assertFalse($type->spec()->external);
            Assert::assertTrue(Activity::query()->where('verb', $type->value)->exists(), 'No activity row was recorded for '.$type->value);
            Assert::assertFalse(OutboxEvent::query()->where('type', $type->value)->where('external', true)->exists());
        }
    }

    /** @param array<string, mixed> $payload */
    public static function assertEnvelope(EventTypeContract $type, array $payload): void
    {
        $spec = $type->spec();
        $data = $payload['data'];
        $subject = $payload['subject'];
        Assert::assertIsArray($data);
        Assert::assertIsArray($subject);
        Assert::assertSame($type->value, $payload['type']);
        Assert::assertSame($spec->subject, $subject['type']);
        /** @var array<string, mixed> $data */
        self::assertData($type, $data);
        self::assertNoForbiddenKeys($payload, $type->value);
    }

    /** @param array<string, mixed> $data */
    public static function assertData(EventTypeContract $type, array $data): void
    {
        $spec = $type->spec();
        $keys = array_keys($data);
        Assert::assertSame([], array_values(array_diff($spec->requiredKeys(), $keys)), $type->value.' misses spec keys');
        Assert::assertSame([], array_values(array_diff($keys, $spec->allowedKeys())), $type->value.' carries keys outside its spec');
        foreach ($spec->data as $key => $kind) {
            $key = rtrim($key, '?');
            if (array_key_exists($key, $data)) {
                Assert::assertTrue(self::matches($kind, $data[$key]), sprintf('%s.%s is not %s', $type->value, $key, $kind));
            }
        }
    }

    /** @param array<mixed> $value */
    public static function assertNoForbiddenKeys(array $value, string $context): void
    {
        foreach ($value as $key => $item) {
            Assert::assertNotContains($key, self::FORBIDDEN, sprintf('%s carries the forbidden key "%s"', $context, $key));
            if (is_array($item)) {
                self::assertNoForbiddenKeys($item, $context);
            }
        }
    }

    private static function matches(string $kind, mixed $value): bool
    {
        return match ($kind) {
            'string' => is_string($value),
            'string|null' => $value === null || is_string($value),
            'int' => is_int($value),
            'bool' => is_bool($value),
            'list<string>' => is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => !is_string($item)) === [],
            'object' => is_array($value) && !array_is_list($value),
            default => false,
        };
    }
}
