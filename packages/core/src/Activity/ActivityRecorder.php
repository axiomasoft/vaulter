<?php

declare(strict_types=1);

namespace Vaulter\Activity;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Data\Activity\RecordActivityCommand;
use Vaulter\Drive\Events\FileUploaded;
use Vaulter\Drive\Events\NodeCopied;
use Vaulter\Drive\Events\NodeCreated;
use Vaulter\Drive\Events\NodeForceDeleted;
use Vaulter\Drive\Events\NodeMoved;
use Vaulter\Drive\Events\NodeRenamed;
use Vaulter\Drive\Events\NodeRestored;
use Vaulter\Drive\Events\NodeTrashed;
use Vaulter\Drive\Events\NodeVersionCommitted;
use Vaulter\Drive\Events\StorageCreated;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Events\Access\NodeGrantCreated;
use Vaulter\Events\Access\NodeGrantRevoked;
use Vaulter\Events\Sharing\NodeShareCreated;
use Vaulter\Events\Sharing\NodeShareRevoked;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Activity\ActivityStoreRepository;
use Vaulter\Support\Identity;
use Vaulter\Support\RequestContext;

/**
 * Writes EXACTLY ONE activity row from a domain event (master plan §2.9 /
 * M2c-activity). It maps a known event into a {@see RecordActivityCommand}
 * (verb, actor, target morph, storage, context) and hands it to the
 * ActivityStoreRepository.
 *
 * The actor is taken from the event payload (every Drive event carries the acting
 * user's id) with a fallback to the authenticated host user; the actor MORPH TYPE
 * comes from that authenticated user. ip / user-agent / request-id are captured
 * from the current request via {@see RequestContext}, degrading to null off the
 * HTTP lifecycle (console/queue).
 *
 * This is the anti-NC-fan-out core (§1.5): ONE row per event, never a per-affected-
 * user fan-out. Recipients are resolved at READ by scoping the feed.
 */
final readonly class ActivityRecorder
{
    public function __construct(
        private ActivityStoreRepository $store,
    ) {}

    /**
     * Record the storage.created event: the disk root was provisioned. The target
     * is the storage's root folder node; the storage scope is the storage itself.
     */
    public function recordStorageCreated(StorageCreated $event): Activity
    {
        return $this->write(
            verb: ActivityVerb::StorageCreated,
            storageId: Identity::key($event->storage->getKey()),
            tenantId: $event->storage->tenant_id,
            actorId: $event->actorId,
            target: $event->root,
            context: [
                'storage_id' => Identity::stringify($event->storage->getKey()),
                'owner_type' => $event->storage->owner_type,
                'owner_id' => Identity::stringify($event->storage->owner_id),
                'name' => $event->storage->name,
            ],
        );
    }

    public function recordNodeCreated(NodeCreated $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::NodeCreated,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'kind' => $event->node->kind->value,
                'name' => $event->node->name,
                'parent_id' => Identity::stringify($event->node->parent_id),
            ],
        );
    }

    public function recordFileUploaded(FileUploaded $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::FileUploaded,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'name' => $event->node->name,
                'size_bytes' => $event->version->size_bytes,
                'mime_type' => $event->version->mime_type,
                'version_id' => Identity::stringify($event->version->getKey()),
            ],
        );
    }

    public function recordVersionCommitted(NodeVersionCommitted $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::VersionCreated,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'version_no' => $event->versionNo,
                'version_id' => Identity::stringify($event->version->getKey()),
                'size_bytes' => $event->version->size_bytes,
            ],
        );
    }

    public function recordNodeMoved(NodeMoved $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::NodeMoved,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'from_parent_id' => Identity::stringify($event->fromParentId),
                'to_parent_id' => Identity::stringify($event->toParentId),
            ],
        );
    }

    public function recordNodeRenamed(NodeRenamed $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::NodeRenamed,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'from' => $event->fromName,
                'to' => $event->toName,
            ],
        );
    }

    public function recordNodeCopied(NodeCopied $event): Activity
    {
        // The target is the NEW copy; the source is recorded in context.
        return $this->writeForNode(
            verb: ActivityVerb::NodeCopied,
            node: $event->copy,
            actorId: $event->actorId,
            context: [
                'source_id' => Identity::stringify($event->source->getKey()),
                'name' => $event->copy->name,
            ],
        );
    }

    public function recordNodeTrashed(NodeTrashed $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::NodeTrashed,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'name' => $event->node->name,
            ],
        );
    }

    public function recordNodeRestored(NodeRestored $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::NodeRestored,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'name' => $event->node->name,
                'parent_id' => Identity::stringify($event->node->parent_id),
            ],
        );
    }

    /**
     * Record the node.force_deleted event. The node objects are gone, so there is
     * no target model: the storage scope plus the deleted ids + freed bytes travel
     * in the context (the row is the only surviving trace).
     */
    public function recordNodeForceDeleted(NodeForceDeleted $event): Activity
    {
        return $this->write(
            verb: ActivityVerb::NodeForceDeleted,
            storageId: $event->storageId,
            actorId: $event->actorId,
            context: [
                'node_ids' => array_map(Identity::stringify(...), $event->nodeIds),
                'freed_bytes' => $event->freedBytes,
            ],
        );
    }

    public function recordGrantCreated(NodeGrantCreated $event): Activity
    {
        $node = $event->grant->node;

        return $this->write(
            verb: ActivityVerb::GrantCreated,
            storageId: $node->storage_id,
            tenantId: $event->grant->tenant_id,
            actorId: $event->actorId,
            target: $node,
            context: [
                'grant_id' => Identity::stringify($event->grant->getKey()),
                'subject_type' => $event->grant->subject_type,
                'subject_id' => Identity::stringify($event->grant->subject_id),
                'level' => $event->grant->level->value,
                'effect' => $event->grant->effect->value,
                'scope' => $event->grant->scope->value,
            ],
        );
    }

    public function recordGrantRevoked(NodeGrantRevoked $event): Activity
    {
        return $this->writeForNode(
            verb: ActivityVerb::GrantRevoked,
            node: $event->node,
            actorId: $event->actorId,
            context: [
                'subject_type' => $event->subject->type,
                'subject_id' => Identity::stringify($event->subject->id),
                'revoked' => $event->revoked,
            ],
        );
    }

    public function recordShareCreated(NodeShareCreated $event): Activity
    {
        $node = $event->share->node;

        return $this->write(
            verb: ActivityVerb::ShareCreated,
            storageId: $node->storage_id,
            tenantId: $event->share->tenant_id,
            actorId: $event->actorId,
            target: $node,
            context: [
                'share_id' => Identity::stringify($event->share->getKey()),
                'token_prefix' => $event->share->token_prefix,
                'level' => $event->share->level->value,
                'scope' => $event->share->scope->value,
                'permissions' => $event->share->permissions,
            ],
        );
    }

    public function recordShareRevoked(NodeShareRevoked $event): Activity
    {
        $node = $event->share->node;

        return $this->write(
            verb: ActivityVerb::ShareRevoked,
            storageId: $node->storage_id,
            tenantId: $event->share->tenant_id,
            actorId: $event->actorId,
            target: $node,
            context: [
                'share_id' => Identity::stringify($event->share->getKey()),
                'token_prefix' => $event->share->token_prefix,
            ],
        );
    }

    /**
     * Build + persist a row whose target is a node (the common Drive case): the
     * storage scope and tenant are read from the node.
     *
     * @param array<string, mixed>|null $context
     */
    private function writeForNode(
        ActivityVerb $verb,
        Node $node,
        int|string|null $actorId,
        ?array $context = null,
    ): Activity {
        return $this->write(
            verb: $verb,
            storageId: $node->storage_id,
            tenantId: $node->tenant_id,
            actorId: $actorId,
            target: $node,
            context: $context,
        );
    }

    /**
     * Resolve the actor morph + request context, then persist EXACTLY ONE row.
     *
     * @param array<string, mixed>|null $context
     */
    private function write(
        ActivityVerb $verb,
        int|string|null $storageId = null,
        int|string|null $tenantId = null,
        int|string|null $actorId = null,
        ?Model $target = null,
        ?array $context = null,
    ): Activity {
        [$actorType, $resolvedActorId] = $this->resolveActor($actorId);
        $request = RequestContext::capture();

        return $this->store->record(new RecordActivityCommand(
            verb: $verb,
            tenantId: $tenantId,
            storageId: $storageId,
            actorType: $actorType,
            actorId: $resolvedActorId,
            targetType: $target?->getMorphClass(),
            targetId: $target === null ? null : Identity::key($target->getKey()),
            context: $context,
            ip: $request->ip,
            userAgent: $request->userAgent,
            requestId: $request->requestId,
        ));
    }

    /**
     * Resolve the (actor_type, actor_id) morph. The event payload's actorId wins
     * for the id; the morph type comes from the authenticated host user (events
     * carry only a scalar id, not the morph). When the event carries no id we fall
     * back to the authenticated user entirely. With neither, the actor is null (a
     * system write).
     *
     * @return array{0: ?string, 1: int|string|null}
     */
    private function resolveActor(int|string|null $actorId): array
    {
        $user = $this->authenticatedUser();

        if ($actorId !== null) {
            // The morph type is the auth user's when its id matches; otherwise we
            // know the id but not a model, so the type stays null (still a valid,
            // queryable actor_id row).
            $type = $user !== null && Identity::stringify($user->getKey()) === Identity::stringify($actorId)
                ? $user->getMorphClass()
                : null;

            return [$type, $actorId];
        }

        if ($user !== null) {
            return [$user->getMorphClass(), Identity::key($user->getKey())];
        }

        return [null, null];
    }

    private function authenticatedUser(): ?Model
    {
        $user = auth()->user();

        return $user instanceof Model ? $user : null;
    }
}
