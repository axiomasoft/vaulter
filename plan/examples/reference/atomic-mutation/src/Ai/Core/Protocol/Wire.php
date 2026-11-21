<?php

declare(strict_types=1);

namespace VaulterRef\Ai\Core\Protocol;

use VaulterRef\Ai\Core\Data\CommandOutcome;

/** Target: packages/core/src/Protocol/WireErrorMapper.php (subset for commit/status) in vaulter-ai. */
final class Wire
{
    private const ERRORS = [
        'resource_unavailable' => [404, false, 'stop'],
        'operation_unknown' => [404, true, 'retry_same'],
        'precondition_failed' => [409, false, 'reread'],
        'generation_changed' => [409, false, 'reread'],
        'schema_changed' => [409, false, 'reread'],
        'idempotency_conflict' => [409, false, 'stop'],
        'approval_required' => [409, false, 'await_approval'],
        'proposal_expired' => [410, false, 'reread'],
        'idempotency_window_expired' => [410, false, 'inspect_history'],
        'temporarily_unavailable' => [503, true, 'status'],
    ];

    /** @return array{http:int, body:array<string, mixed>} */
    public static function error(string $operation, string $code): array
    {
        [$http, $retryable, $next] = self::ERRORS[$code];

        return ['http' => $http, 'body' => ['version' => 1, 'operation' => $operation, 'ok' => false,
            'error' => ['code' => $code, 'retryable' => $retryable, 'next_action' => $next]]];
    }

    /** @return array{http:int, body:array<string, mixed>} */
    public static function outcome(string $operation, string $commandId, CommandOutcome $outcome): array
    {
        $ok = static fn (int $http, array $result): array => ['http' => $http,
            'body' => ['version' => 1, 'operation' => $operation, 'ok' => true, 'result' => $result]];

        return match ($outcome->state) {
            'committed' => $ok(200, ['command_id' => $commandId, 'receipt_id' => $outcome->receiptId, 'state' => 'committed',
                'marker' => ['generation' => $outcome->generation, 'position' => $outcome->position]]),
            'running' => $ok(202, ['command_id' => $commandId, 'state' => 'running']),
            'rejected' => $operation === 'office.status'
                ? $ok(200, ['command_id' => $commandId, 'state' => 'rejected', 'reason' => $outcome->reason])
                : self::error($operation, (string) $outcome->reason),
            'unavailable' => self::error($operation, 'resource_unavailable'),
            'conflict' => self::error($operation, 'idempotency_conflict'),
        };
    }
}
