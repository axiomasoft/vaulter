<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;

/**
 * Mass-assignment hardening (P1.4 / B13): every vaulter-core model used to carry
 * `$guarded = []` (F-P1.8-02, C6 fail — 16/16 models unguarded). This pins that a
 * service/security column can no longer be set through fill() on any of them, so a
 * future `Model::create($request->validated())`/`->fill($input)` cannot leak a
 * caller-chosen value into tenant_id/token_hash/signing_secret/etc.
 *
 * Red-before: on the code prior to this item every model declared
 * `protected $guarded = [];`, so `fill()` accepted EVERY key below without
 * discarding or throwing — proven by `git stash` + rerun during development (see
 * phases/P1.md P1.4 Completion Notes).
 *
 * Two Eloquent behaviours are exercised, per model:
 *  - a CLOSED $fillable list (Node/Storage/File/FileRevision/NodeShare/
 *    WebhookEndpoint) silently DISCARDS a key outside the list — the attribute is
 *    simply absent afterwards;
 *  - a fully `$guarded = ['*']` model (Blob/FileRevisionBlob/NodeGrant/Activity/
 *    OutboxEvent/WebhookDelivery) is Eloquent's "totally guarded" state, which THROWS
 *    MassAssignmentException on ANY fill() with a non-empty array — an even
 *    stronger guarantee for these security-critical rows (CAS identity, ACL,
 *    audit trail, outbox/delivery bookkeeping).
 */
it('discards a service/security column via fill() on a closed-$fillable vaulter-core model (F-P1.8-02)', function (
    string $model,
    string $dangerousKey,
    mixed $dangerousValue,
): void {
    $instance = new $model;
    $instance->fill([$dangerousKey => $dangerousValue]);

    expect($instance->getAttributes())->not->toHaveKey($dangerousKey);
})->with([
    'Node.tenant_id' => [Node::class, 'tenant_id', 999],
    'Storage.owner_id' => [Drive::class, 'owner_id', 999],
    'File.size_bytes' => [File::class, 'size_bytes', 999_999_999],
    'FileRevision.blob_id' => [FileRevision::class, 'blob_id', 999],
    'NodeShare.token_hash' => [NodeShare::class, 'token_hash', 'attacker-hash'],
    'WebhookEndpoint.signing_secret' => [WebhookEndpoint::class, 'signing_secret', 'ATTACKER-SECRET'],
]);

it('rejects ANY fill() attribute on a fully-$guarded vaulter-core model with MassAssignmentException (F-P1.8-02)', function (
    string $model,
    string $dangerousKey,
    mixed $dangerousValue,
): void {
    $instance = new $model;

    expect(fn () => $instance->fill([$dangerousKey => $dangerousValue]))
        ->toThrow(MassAssignmentException::class);
})->with([
    'Blob.sha256' => [Blob::class, 'sha256', 'attacker-controlled-hash'],
    'FileRevisionBlob.blob_id' => [FileRevisionBlob::class, 'blob_id', 999],
    'NodeGrant.permissions' => [NodeGrant::class, 'permissions', 65535],
    'Activity.verb' => [Activity::class, 'verb', 'fake.verb'],
    'OutboxEvent.type' => [OutboxEvent::class, 'type', 'folder.created'],
    'WebhookDelivery.status' => [WebhookDelivery::class, 'status', 'delivered'],
]);

/**
 * F-P1.5-04: `Blob` is the CAS content address — sha256/storage_key mass-assignment
 * would let a caller point a committed blob's identity at arbitrary bytes. Blob is
 * fully guarded, so `fill()` throws outright; a fill()-only `create()` (which
 * internally calls fill() then save()) never reaches the INSERT at all.
 */
it('never mass-assigns sha256 or storage_key on Blob via fill() or create() (F-P1.5-04)', function (): void {
    $filled = new Blob;

    expect(fn () => $filled->fill([
        'sha256' => 'attacker-controlled-hash',
        'storage_key' => 'attacker/controlled/key',
        'size_bytes' => 1,
    ]))->toThrow(MassAssignmentException::class);

    expect(fn () => Blob::query()->create([
        'sha256' => 'attacker-controlled-hash-2',
        'storage_key' => 'attacker/controlled/key-2',
        'size_bytes' => 1,
    ]))->toThrow(MassAssignmentException::class);
});

/**
 * F-P1.7-04: `WebhookEndpoint::fill()` must never let a caller set the HMAC
 * signing_secret — the whole delivery-trust channel depends on it staying
 * server-generated (RegisterWebhookEndpointAction::execute()). WebhookEndpoint
 * carries a closed $fillable list (url/events/is_active), so the key is silently
 * discarded; the column is NOT NULL, so a fill()-only create() that drops it
 * fails the INSERT — a second, DB-level proof.
 */
it('never mass-assigns signing_secret on WebhookEndpoint via fill() or create() (F-P1.7-04)', function (): void {
    $filled = new WebhookEndpoint;
    $filled->fill([
        'url' => 'https://hooks.test/receive',
        'signing_secret' => 'ATTACKER-CHOSEN-SECRET',
    ]);

    expect($filled->getAttributes())->not->toHaveKey('signing_secret')
        ->and($filled->url)->toBe('https://hooks.test/receive');

    // 'events' is included so signing_secret is the ONLY column the guard drops —
    // isolating the NOT NULL failure to the attribute this test pins.
    // PostgreSQL marks the current transaction failed after a constraint error.
    // Let Laravel observe the exception inside a nested transaction so it rolls
    // back to the savepoint before Pest catches the expected QueryException.
    expect(fn () => DB::transaction(
        fn (): WebhookEndpoint => WebhookEndpoint::query()->create([
            'url' => 'https://hooks.test/receive-2',
            'events' => ['folder.created'],
            'signing_secret' => 'ATTACKER-CHOSEN-SECRET-2',
        ]),
    ))->toThrow(QueryException::class);
});
