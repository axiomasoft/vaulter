<?php

declare(strict_types=1);

namespace Vaulter\Webhooks;

use Vaulter\Enums\Webhooks\WebhookEvent;
use Vaulter\Events\FileUploaded;
use Vaulter\Events\NodeCopied;
use Vaulter\Events\NodeCreated;
use Vaulter\Events\NodeForceDeleted;
use Vaulter\Events\NodeGrantCreated;
use Vaulter\Events\NodeGrantRevoked;
use Vaulter\Events\NodeMoved;
use Vaulter\Events\NodeRenamed;
use Vaulter\Events\NodeRestored;
use Vaulter\Events\NodeShareCreated;
use Vaulter\Events\NodeShareRevoked;
use Vaulter\Events\NodeTrashed;
use Vaulter\Events\NodeVersionCommitted;
use Vaulter\Events\StorageCreated;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The SYNCHRONOUS half of the transactional outbox (master plan §2.11 / D14 / M3b),
 * ported from chatom's WebhookDispatcher. It maps a known Vaulter domain event into a
 * SLIM webhook payload and durably stages it as ONE v_outbox row — idempotent by
 * event_id, so a re-fired event never stages a duplicate. The actual HTTP delivery
 * (retries, backoff, DLQ, signed POST) is the relay's job.
 *
 * Slim payloads (HARDENING): only ids, names, sizes and enum values travel — never
 * blob bytes or secrets (a share's token_hash/secret is never serialized; only the
 * non-secret token_prefix). The event_id is STABLE and event-derived so the relay
 * and downstream consumers can dedupe at-least-once delivery on it.
 *
 * Thin side-effect service (store-only): it persists an outbox row and nothing else.
 * It makes no business decisions and never accepts a Request. Not final — a host may
 * subclass it to enrich payloads, and the subscriber's resilience is test-mockable.
 */
class OutboxRecorder
{
    public function recordStorageCreated(StorageCreated $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::StorageCreated,
            key: "storage.created:{$this->id($event->storage->getKey())}",
            panel: $event->storage->panel,
            topic: $this->id($event->storage->getKey()),
            payload: [
                'storage_id' => $this->id($event->storage->getKey()),
                'root_node_id' => $this->id($event->root->getKey()),
                'name' => $event->storage->name,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeCreated(NodeCreated $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeCreated,
            key: "node.created:{$this->id($event->node->getKey())}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'parent_id' => $this->id($event->node->parent_id),
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordFileUploaded(FileUploaded $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::FileUploaded,
            key: "file.uploaded:{$this->id($event->version->getKey())}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'version_id' => $this->id($event->version->getKey()),
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordVersionCommitted(NodeVersionCommitted $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::VersionCreated,
            key: "version.created:{$this->id($event->version->getKey())}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'version_id' => $this->id($event->version->getKey()),
                'version_no' => $event->versionNo,
                'size_bytes' => $event->version->size_bytes,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeMoved(NodeMoved $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeMoved,
            // The move target+destination uniquely identify this move event.
            key: "node.moved:{$this->id($event->node->getKey())}:{$this->id($event->toParentId)}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'from_parent_id' => $this->id($event->fromParentId),
                'to_parent_id' => $this->id($event->toParentId),
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeRenamed(NodeRenamed $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeRenamed,
            key: "node.renamed:{$this->id($event->node->getKey())}:{$event->toName}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'from' => $event->fromName,
                'to' => $event->toName,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeCopied(NodeCopied $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeCopied,
            key: "node.copied:{$this->id($event->copy->getKey())}",
            panel: $event->copy->panel,
            topic: $this->id($event->copy->getKey()),
            payload: [
                'node_id' => $this->id($event->copy->getKey()),
                'storage_id' => $this->id($event->copy->storage_id),
                'source_id' => $this->id($event->source->getKey()),
                'name' => $event->copy->name,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeTrashed(NodeTrashed $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeTrashed,
            key: "node.trashed:{$this->id($event->node->getKey())}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordNodeRestored(NodeRestored $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeRestored,
            key: "node.restored:{$this->id($event->node->getKey())}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'parent_id' => $this->id($event->node->parent_id),
                'name' => $event->node->name,
            ],
            actorId: $event->actorId,
        );
    }

    /**
     * The node objects are gone after a force-delete, so the slim payload carries
     * the deleted ids + freed bytes (the relay still fans this out so a host can
     * reconcile its own mirror). The panel is the default panel — the storage is the
     * only surviving scope and the event does not carry it.
     */
    public function recordNodeForceDeleted(NodeForceDeleted $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::NodeForceDeleted,
            key: "node.force_deleted:{$this->id($event->storageId)}:".md5(implode(',', array_map(Identity::stringify(...), $event->nodeIds))),
            panel: 'default',
            topic: $this->id($event->storageId),
            payload: [
                'storage_id' => $this->id($event->storageId),
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordGrantCreated(NodeGrantCreated $event): ?Outbox
    {
        $node = $event->grant->node;

        return $this->stage(
            event: WebhookEvent::GrantCreated,
            key: "grant.created:{$this->id($event->grant->getKey())}",
            panel: $node->panel,
            topic: $this->id($node->getKey()),
            payload: [
                'grant_id' => $this->id($event->grant->getKey()),
                'node_id' => $this->id($node->getKey()),
                'storage_id' => $this->id($node->storage_id),
                'subject_type' => $event->grant->subject_type,
                'subject_id' => $this->id($event->grant->subject_id),
                'level' => $event->grant->level->value,
                'effect' => $event->grant->effect->value,
                'scope' => $event->grant->scope->value,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordGrantRevoked(NodeGrantRevoked $event): ?Outbox
    {
        return $this->stage(
            event: WebhookEvent::GrantRevoked,
            key: "grant.revoked:{$this->id($event->node->getKey())}:{$event->subject->type}:{$this->id($event->subject->id)}",
            panel: $event->node->panel,
            topic: $this->id($event->node->getKey()),
            payload: [
                'node_id' => $this->id($event->node->getKey()),
                'storage_id' => $this->id($event->node->storage_id),
                'subject_type' => $event->subject->type,
                'subject_id' => $this->id($event->subject->id),
                'revoked' => $event->revoked,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordShareCreated(NodeShareCreated $event): ?Outbox
    {
        $node = $event->share->node;

        return $this->stage(
            event: WebhookEvent::ShareCreated,
            key: "share.created:{$this->id($event->share->getKey())}",
            panel: $node->panel,
            topic: $this->id($node->getKey()),
            // The raw token / token_hash is NEVER serialized — only the non-secret
            // token_prefix travels (slim-payload hardening).
            payload: [
                'share_id' => $this->id($event->share->getKey()),
                'node_id' => $this->id($node->getKey()),
                'storage_id' => $this->id($node->storage_id),
                'token_prefix' => $event->share->token_prefix,
                'level' => $event->share->level->value,
                'scope' => $event->share->scope->value,
                'permissions' => $event->share->permissions,
            ],
            actorId: $event->actorId,
        );
    }

    public function recordShareRevoked(NodeShareRevoked $event): ?Outbox
    {
        $node = $event->share->node;

        return $this->stage(
            event: WebhookEvent::ShareRevoked,
            key: "share.revoked:{$this->id($event->share->getKey())}",
            panel: $node->panel,
            topic: $this->id($node->getKey()),
            payload: [
                'share_id' => $this->id($event->share->getKey()),
                'node_id' => $this->id($node->getKey()),
                'storage_id' => $this->id($node->storage_id),
                'token_prefix' => $event->share->token_prefix,
            ],
            actorId: $event->actorId,
        );
    }

    /**
     * Stage ONE outbox row for an event. Idempotent by event_id: when a row for this
     * key already exists (a retried send, a re-fired event), this is a no-op and
     * returns null, so the relay never fans the same event out twice.
     *
     * @param array<string, mixed> $payload
     */
    private function stage(
        WebhookEvent $event,
        string $key,
        string $panel,
        ?string $topic,
        array $payload,
        int|string|null $actorId,
    ): ?Outbox {
        /** @var class-string<Outbox> $outboxClass */
        $outboxClass = Config::outboxModel();

        if ($outboxClass::query()->where('event_id', $key)->exists()) {
            return null;
        }

        // The actor id rides inside the payload so consumers see who caused the event
        // without a separate column on the wire.
        $payload['event_id'] = $key;
        $payload['actor_id'] = Identity::key($actorId);

        return $outboxClass::query()->create([
            'event_id' => $key,
            'panel' => $panel,
            'topic' => $topic,
            'event' => $event->value,
            'payload' => $payload,
            'available_at' => now(),
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }

    private function id(mixed $value): ?string
    {
        $string = Identity::stringify($value);

        return $string === '' ? null : $string;
    }
}
