<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Events\Outbox\Models\OutboxEvent;
use Vaulter\Media\Models\Cas\Blob;

/**
 * Mass-assignment hardening: every vaulter-core model used to carry
 * `$guarded = []` (all 16 models unguarded). This pins that a
 * service/security column can no longer be set through fill() on any of them, so a
 * future `Model::create($request->validated())`/`->fill($input)` cannot leak a
 * caller-chosen value into tenant_id/token_hash/etc. The webhook models are pinned by the
 * webhooks package (tests/Schema/MassAssignmentHardeningTest.php).
 *
 * Red-before: on the code prior to this item every model declared
 * `protected $guarded = [];`, so `fill()` accepted EVERY key below without
 * discarding or throwing — proven by `git stash` + rerun during development.
 *
 * Two Eloquent behaviours are exercised, per model:
 *  - a CLOSED $fillable list (Node/Storage/File/FileRevision/NodeShare)
 *    silently DISCARDS a key outside the list — the attribute is
 *    simply absent afterwards;
 *  - a fully `$guarded = ['*']` model (Blob/FileRevisionBlob/NodeGrant/Activity/
 *    OutboxEvent) is Eloquent's "totally guarded" state, which THROWS
 *    MassAssignmentException on ANY fill() with a non-empty array — an even
 *    stronger guarantee for these security-critical rows (CAS identity, ACL,
 *    audit trail, outbox/delivery bookkeeping).
 */
it('discards a service/security column via fill() on a closed-$fillable vaulter-core model', function (
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
]);

it('rejects ANY fill() attribute on a fully-$guarded vaulter-core model with MassAssignmentException', function (
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
]);

/**
 * `Blob` is the CAS content address — sha256/storage_key mass-assignment
 * would let a caller point a committed blob's identity at arbitrary bytes. Blob is
 * fully guarded, so `fill()` throws outright; a fill()-only `create()` (which
 * internally calls fill() then save()) never reaches the INSERT at all.
 */
it('never mass-assigns sha256 or storage_key on Blob via fill() or create()', function (): void {
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
