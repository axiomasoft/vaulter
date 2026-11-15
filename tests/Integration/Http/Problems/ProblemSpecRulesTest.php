<?php

declare(strict_types=1);

use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;
use Vaulter\Http\Problems\Retryable;

require_once __DIR__.'/ProblemCatalogHelpers.php';

// Architecture#errors: a refusal of authentication, visibility, validation, precondition, rate,
// dependency or the kernel is rolled back with the idempotency claim and never cached; a misclassified code would
// replay a 403/404 for 24 hours.

it('never caches the auth, visibility, validation, precondition, rate and kernel refusals', function (string $code): void {
    $spec = fullProblemCatalog()->spec($code);

    expect($spec)->not->toBeNull($code)
        ->and($spec->receipt)->toBe(ReceiptPolicy::Never, $code);
})->with([
    'permission_denied', 'actor_required', 'node_not_found', 'drive_not_found', 'validation_failed', 'precondition_required',
    'rate_limited', 'dependency_unavailable', 'module_graph_invalid', 'module_not_ready', 'extension_failed',
    'idempotency_key_reused', 'operation_in_progress', 'concurrent_update',
]);

it('never caches a 5xx, a 401, a 403 or a 404', function (): void {
    foreach (fullProblemCatalog()->all() as $code => $entry) {
        if ($entry['status'] >= 500 || in_array($entry['status'], [401, 403, 404], true)) {
            expect($entry['receipt'])->toBe(ReceiptPolicy::Never, $code);
        }
    }
});

it('caches a stale precondition and asks the client to refetch', function (string $code): void {
    $spec = fullProblemCatalog()->spec($code);

    expect($spec->status)->toBe(412)
        ->and($spec->retryable)->toBe(Retryable::AfterRefetch)
        ->and($spec->receipt)->toBe(ReceiptPolicy::Cacheable);
})->with(['node_sequence_mismatch', 'document_state_mismatch', 'stale_write']);

it('caches the deterministic domain refusals of the owners', function (string $code): void {
    expect(fullProblemCatalog()->spec($code)->receipt)->toBe(ReceiptPolicy::Cacheable, $code);
})->with(['node_name_conflict', 'quota_exceeded', 'payload_too_large', 'invalid_node_name', 'node_move_cycle', 'unknown_document_type']);

it('never caches a 409 whose answer depends on a concurrent request or the host configuration', function (string $code): void {
    expect(fullProblemCatalog()->spec($code)->receipt)->toBe(ReceiptPolicy::Never, $code);
})->with(['restore_destination_changed', 'upload_conflict', 'upload_offset_mismatch', 'collaboration_unavailable', 'blob_unavailable']);

it('marks the busy and unavailable codes for a retry with backoff', function (string $code): void {
    expect(fullProblemCatalog()->spec($code)->retryable)->toBe(Retryable::WithBackoff, $code);
})->with(['operation_in_progress', 'rate_limited', 'dependency_unavailable', 'concurrent_update']);

it('refuses a spec that would cache a server failure or that is not an error status', function (int $status, ReceiptPolicy $receipt): void {
    expect(fn () => new ProblemSpec($status, null, Retryable::Never, $receipt))->toThrow(InvalidArgumentException::class);
})->with([
    '500 cached' => [500, ReceiptPolicy::Cacheable],
    '503 cached' => [503, ReceiptPolicy::Cacheable],
    '200' => [200, ReceiptPolicy::Never],
    '600' => [600, ReceiptPolicy::Never],
]);

it('defaults a spec to no retry and no receipt', function (): void {
    $spec = new ProblemSpec(409);

    expect($spec->retryable)->toBe(Retryable::Never)
        ->and($spec->receipt)->toBe(ReceiptPolicy::Never)
        ->and($spec->exception)->toBeNull();
});
