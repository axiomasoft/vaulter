# P2.3 original source

Captured current workspace source before the product pass. Repository HEAD: `4b72efd3a909f441eb415182218140575e61e4c5`.

This is the original root baseline, not a new P2.2 closure commit.

## packages/documents/src/Actions/Collaboration/PersistYjsSnapshotAction.php

SHA256: `96f7a2f0333b657343e9f35a0a86cb90cea66fe833e9eb09c40df9ee59b0a896`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Collaboration;

use Illuminate\Support\Carbon;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Collaboration\PersistYjsSnapshotData;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Documents\Support\DocumentContentHasher;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\LoadTier;

/**
 * Persist a Yjs snapshot (master plan §7 / ADR-0001 / M4b) — the write the Node
 * Hocuspocus hub's debounced onStoreDocument drives. The Yjs binary CRDT state is the
 * SOURCE OF TRUTH: it is stored verbatim as yjs_state (bytea) with its byte length in
 * yjs_state_size; the optional TipTap JSON is the DERIVED projection (we never
 * reconstruct Yjs from JSON — when the hub omits the projection the prior projection
 * is left intact and only the Yjs state advances).
 *
 * The persistence is append-only with debounce, the SAME policy as the autosave path
 * (the hub already debounces, so a rapid snapshot UPDATES the head autosave version in
 * place rather than flooding the history); outside the window a fresh is_autosave
 * version is appended and the head repoints. One outer transaction; prune runs inside
 * it (respecting keep_forever and the current head); a single after-commit
 * DocumentContentUpdated event integrates with the activity/outbox subscribers.
 */
final readonly class PersistYjsSnapshotAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
    ) {}

    public function execute(PersistYjsSnapshotData $data): NodeDocumentVersion
    {
        $node = $data->node;

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time. The Yjs binary state is the source of truth and is unbounded here by
        // design (§7); only the optional TipTap JSON projection is size-checked.
        $data->content?->ensureWithin(Config::maxDocumentSizeKb());

        return $this->metrics->timing(
            name: 'vaulter_collab_snapshot_duration_seconds',
            operation: fn (): NodeDocumentVersion => $this->database->transaction(function () use ($node, $data): NodeDocumentVersion {
                $head = $this->read->head($node);

                if ($head !== null && $this->shouldOverwrite($head)) {
                    $version = $this->store->update($head, $this->payload($data, $head));

                    event(new DocumentContentUpdated(
                        node: $node,
                        version: $version,
                        created: false,
                        actorId: $data->actorId,
                    ));

                    return $version;
                }

                $version = $this->store->create([
                    'node_id' => $node->getKey(),
                    'version_no' => $this->store->nextVersionNo($node),
                    // A new snapshot row on an existing document CONTINUES its format —
                    // never a schema default (P0.4/L3, D4/D12). Falls back to the
                    // rich-text default only for the (practically unreachable) case of a
                    // document node with no prior version at all.
                    'format' => $head->format ?? DocumentType::RichText->value,
                    'is_autosave' => true,
                    'status' => 'committed',
                    'created_at' => now(),
                    ...$this->payload($data, null),
                ]);

                $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

                $this->store->prune(
                    node: $node,
                    max: Config::maxDocumentVersions(),
                    headId: $version->getKey(),
                );

                event(new DocumentContentUpdated(
                    node: $node,
                    version: $version,
                    created: true,
                    actorId: $data->actorId,
                ));

                return $version;
            }),
            labels: ['tier' => LoadTier::label()],
        );
    }

    /**
     * Whether to overwrite the head in place: it must be an AUTOSAVE version created
     * within the debounce window (the same rule as the autosave path). A committed/
     * named head, or an autosave older than the window, forces a fresh row so the
     * history keeps the older Yjs state.
     */
    private function shouldOverwrite(NodeDocumentVersion $head): bool
    {
        if (!$head->is_autosave) {
            return false;
        }

        $debounce = Config::autosaveDebounceSeconds();

        if ($debounce === 0) {
            return true;
        }

        $createdAt = $head->created_at;

        if (!$createdAt instanceof Carbon) {
            return true;
        }

        return $createdAt->greaterThanOrEqualTo(Carbon::now()->subSeconds($debounce));
    }

    /**
     * The snapshot payload. yjs_state is the source of truth — always written, with
     * its byte length cached in yjs_state_size. The TipTap projection is written ONLY
     * when the hub supplied it; otherwise the prior projection is preserved (when
     * appending a new row with no projection, content stays null — the Yjs state is
     * authoritative and the projection backfills on the next snapshot that carries it).
     *
     * @return array<string, mixed>
     */
    private function payload(PersistYjsSnapshotData $data, ?NodeDocumentVersion $head): array
    {
        $payload = [
            'yjs_state' => $data->yjsState,
            'yjs_state_size' => mb_strlen($data->yjsState, '8bit'),
        ];

        if ($data->content !== null) {
            $payload['content'] = $data->content->toArray();
            $payload['content_hash'] = DocumentContentHasher::hash($data->content);

            return $payload;
        }

        // No projection in this snapshot: keep the head's existing projection when
        // overwriting in place; leave it null when appending (Yjs remains the truth).
        if ($head !== null) {
            $payload['content'] = $head->content;
            $payload['content_hash'] = $head->content_hash;
        }

        return $payload;
    }
}
```

## packages/documents/src/Actions/Comments/AddNodeCommentAction.php

SHA256: `242ce17eaf5a7f8e845adff5260d4a5b3b0e2d0d568740bcf3b77b5c99e498a7`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Comments\CreateNodeCommentData;
use Vaulter\Documents\Events\Comments\NodeCommentAdded;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;
use Vaulter\Models\Drive\Node;

/**
 * Add a comment to a node (ADR-0014 шаг 1). Inserts the row in a transaction, then
 * dispatches {@see NodeCommentAdded} AFTER commit (the event body reads a committed
 * row, and the broadcast lands on the node's collaboration channel).
 */
final readonly class AddNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(Node $node, CreateNodeCommentData $data): NodeComment
    {
        $comment = $this->database->transaction(fn (): NodeComment => $this->comments->create([
            'node_id' => $node->getKey(),
            'parent_id' => $data->parentId,
            'author_type' => $data->authorType,
            'author_id' => $data->authorId,
            'body' => $data->body,
            'anchor' => $data->anchor,
        ]));

        event(new NodeCommentAdded($comment));

        return $comment;
    }
}
```

## packages/documents/src/Actions/Comments/DeleteNodeCommentAction.php

SHA256: `fa788b5b1012cfdd62fd1728dc2ce452a7a5113b58e8bbcc39a1492684c1afc1`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Events\Comments\NodeCommentDeleted;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;

/**
 * Permanently delete a node comment (ADR-0014 шаг 1 — no soft-delete). Deletes in a
 * transaction, then dispatches {@see NodeCommentDeleted} after commit (the in-memory
 * model still carries its id/node_id for the broadcast payload).
 */
final readonly class DeleteNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(NodeComment $comment, int|string|null $actorId = null): void
    {
        $this->database->transaction(fn () => $this->comments->delete($comment));

        event(new NodeCommentDeleted($comment, $actorId));
    }
}
```

## packages/documents/src/Actions/Comments/ResolveNodeCommentAction.php

SHA256: `e7aaa3d955c79e8e7426da791b6723b2295aa462aa5b68cf0c22bad01333ac8c`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Comments;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Events\Comments\NodeCommentResolved;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentStoreRepository;

/**
 * Mark a node comment thread resolved (ADR-0014 шаг 1). Stamps (resolved_at,
 * resolved_by) in a transaction, then dispatches {@see NodeCommentResolved} after
 * commit.
 */
final readonly class ResolveNodeCommentAction
{
    public function __construct(
        private NodeCommentStoreRepository $comments,
        private VaulterDatabase $database,
    ) {}

    public function execute(NodeComment $comment, int|string|null $resolvedBy = null): NodeComment
    {
        $resolved = $this->database->transaction(
            fn (): NodeComment => $this->comments->resolve($comment, $resolvedBy),
        );

        event(new NodeCommentResolved($resolved, $resolvedBy));

        return $resolved;
    }
}
```

## packages/documents/src/Actions/Documents/CreateDocumentNodeAction.php

SHA256: `02bd4f56da3962ceb8c5d9160a6248cafc1348b45785db0d54d7976744ca2aba`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Events\Documents\DocumentNodeCreated;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Nodes\InvalidDestinationException;
use Vaulter\Exceptions\Nodes\NodeNameConflictException;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;
use Vaulter\Support\NodeName;
use Vaulter\Support\TreeGuard;

/**
 * Create a kind=document node under a folder (or the storage root) with its initial
 * v1 document version from the given content (§2.5 / M4a). The parent row is locked
 * and must be a live folder of the same storage; no parent means the root folder,
 * never a parentless node. One outer transaction: the
 * node row, the v1 version and the head repoint commit atomically, then a single
 * after-commit event fires.
 *
 * The node mirrors the file-node shape so the unified tree + Drive Actions (move/
 * rename/trash) apply unchanged — quota and the sibling-unique guard come for free.
 * The node carries the mime its resolved handler declares (the request resolves it):
 * the synthetic Vaulter document mime routes to the TipTap editor, while an xlsx mime
 * routes a spreadsheet node to the Univer handler — both via the same registry (§7).
 *
 * Authorization is enforced UPSTREAM at the controller (canCreate on the
 * destination) — the Action stays pure and only does the row work.
 */
final readonly class CreateDocumentNodeAction
{
    public function __construct(
        private NodeReadRepository $nodes,
        private NodeStoreRepository $store,
        private NodeDocumentVersionStoreRepository $versions,
        private VaulterDatabase $database,
        private TreeGuard $tree,
    ) {}

    public function execute(CreateDocumentNodeData $data): Node
    {
        $name = NodeName::normalize($data->name);
        $parentId = $this->tree->destinationId(storage: $data->storage, into: $data->parent);

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Content
        // is handler-agnostic (DocumentContent); the limit only applies to the
        // TipTap shape, not an opaque non-rich-text snapshot (e.g. a spreadsheet).
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin(Config::maxDocumentSizeKb());
        }

        return $this->database->transaction(function () use ($data, $name, $parentId): Node {
            $this->database->lockTreeShared($data->storage->id);

            $parent = $this->tree->locked($this->tree->lock([$parentId]), $parentId);
            if (!$this->tree->sameStorage($parent, $data->storage->id)) {
                throw InvalidDestinationException::otherDrive(destination: $parent, storageId: $data->storage->id);
            }

            $this->tree->assertActiveFolder($parent);

            if ($this->nodes->siblingNameExists(storage: $data->storage, parentId: $parentId, name: $name)) {
                throw NodeNameConflictException::forName(name: $name, parentId: $parentId);
            }

            $node = $this->store->create([
                'drive_id' => $data->storage->id,
                'parent_id' => $parentId,
                'kind' => NodeKind::Document,
                'name' => $name,
                'mime_type' => $data->mimeType,
                'size_bytes' => $data->content->sizeInBytes(),
                'owner_id' => $data->actorId,
                'created_by' => $data->actorId,
            ]);

            $version = $this->commitInitialVersion(node: $node, data: $data);

            // Repoint the head to v1 — reusing the file-node head pointer so the
            // unified tree carries the document head the same way it carries a file.
            $this->store->update($node, ['current_version_id' => $version->getKey()]);

            $node = $node->refresh();

            event(new DocumentNodeCreated(node: $node, version: $version, actorId: $data->actorId));

            return $node;
        });
    }

    private function commitInitialVersion(Node $node, CreateDocumentNodeData $data): NodeDocumentVersion
    {
        return $this->versions->create([
            'node_id' => $node->id,
            'version_no' => 1,
            'content' => $data->content->toArray(),
            'format' => $data->format,
            'content_hash' => $data->content->contentHash(),
            'is_autosave' => false,
            'status' => 'committed',
            'created_by' => $data->actorId,
            'created_at' => now(),
        ]);
    }
}
```

## packages/documents/src/Actions/Versions/CreateNamedVersionAction.php

SHA256: `4e0ca54331634263bd494ecf1e12906c0977e6e112ce843c01d9252c5abe48a1`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Versions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Data\Versions\CreateNamedVersionData;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Documents\Events\Versions\DocumentVersionCreated;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

/**
 * Snapshot a document node's current head content as a NAMED (non-autosave) version
 * with a label (§2.5 / M4a). The named version becomes the new head; keepForever
 * pins it out of the prune window. One outer transaction; prune runs inside it
 * (named keep_forever rows never count against the window); a single after-commit
 * event fires.
 *
 * A named snapshot CONVERTS the working state into a durable checkpoint — distinct
 * from autosave churn (a named version is never overwritten by the debounce path).
 */
final readonly class CreateNamedVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
    ) {}

    public function execute(CreateNamedVersionData $data): NodeDocumentVersion
    {
        $node = $data->node;

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        return $this->database->transaction(function () use ($node, $data): NodeDocumentVersion {
            $head = $this->read->head($node) ?? $this->read->latest($node);

            $version = $this->store->create([
                'node_id' => $node->getKey(),
                'version_no' => $this->store->nextVersionNo($node),
                'content' => $head instanceof NodeDocumentVersion ? $head->content : null,
                'yjs_state' => $head?->yjs_state,
                'yjs_state_size' => $head?->yjs_state_size,
                'format' => $head instanceof NodeDocumentVersion ? $head->format : DocumentType::RichText->value,
                'content_hash' => $head?->content_hash,
                'snapshot_label' => $data->label,
                'is_autosave' => false,
                'keep_forever' => $data->keepForever,
                'status' => 'committed',
                'created_by' => $data->actorId,
                'created_at' => now(),
            ]);

            $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

            $this->store->prune(
                node: $node,
                max: Config::maxDocumentVersions(),
                headId: $version->getKey(),
            );

            event(new DocumentVersionCreated(
                node: $node,
                version: $version,
                restored: false,
                actorId: $data->actorId,
            ));

            return $version;
        });
    }
}
```

## packages/documents/src/Actions/Versions/ReleaseKeptVersionAction.php

SHA256: `d871a03fe2e12438b2fde99b6650a31f9aba9d342d8da0f84aaca0ad801c039b`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Versions;

use Vaulter\Documents\Exceptions\NodeDocumentVersionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Models\Drive\Node;

/**
 * Release a version's `keep_forever` pin (§2.5 / M4a, F-P1.12-03) — the missing
 * inverse of the pin set by {@see CreateNamedVersionAction} (`keepForever: true`).
 * Before this action existed, no code path ever cleared the flag, so a pinned
 * version was excluded from {@see NodeDocumentVersionStoreRepository::prune()}
 * FOREVER — the released version becomes ordinary again and re-enters the prune
 * window on the node's next snapshot/restore. The version-to-release is resolved
 * through the repository bounded to the node, so a version id from another node
 * never releases here (mirrors {@see RestoreDocumentVersionAction}).
 */
final readonly class ReleaseKeptVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
    ) {}

    public function execute(Node $node, int|string $versionId): NodeDocumentVersion
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $version = $this->read->forNode($node, $versionId);

        if (!$version instanceof NodeDocumentVersion) {
            throw NodeDocumentVersionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return $this->store->update($version, ['keep_forever' => false]);
    }
}
```

## packages/documents/src/Actions/Versions/RestoreDocumentVersionAction.php

SHA256: `a4ab15738bb7a8c6b202d604d791ab824c68b2f83e47c724f2ae367b2e37c19f`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Versions;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Events\Versions\DocumentVersionCreated;
use Vaulter\Documents\Exceptions\NodeDocumentVersionNotFoundException;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionStoreRepository;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeStoreRepository;
use Vaulter\Support\Config;

/**
 * Restore a document node's head to a prior version (§2.5 / M4a). Append-only: a
 * restore never mutates the target version — it COPIES its content into a fresh
 * version that becomes the new head (so the history stays an immutable log and a
 * restore is itself a versioned, auditable event). The new version inherits the
 * restored snapshot's content/yjs and labels its provenance.
 *
 * One outer transaction; prune runs inside it; a single after-commit event fires
 * with restored=true. The version-to-restore is resolved through the repository
 * bounded to the node, so a version id from another node never restores here.
 */
final readonly class RestoreDocumentVersionAction
{
    public function __construct(
        private NodeDocumentVersionReadRepository $read,
        private NodeDocumentVersionStoreRepository $store,
        private NodeStoreRepository $nodes,
        private VaulterDatabase $database,
    ) {}

    public function execute(Node $node, int|string $versionId, int|string|null $actorId = null): NodeDocumentVersion
    {
        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $source = $this->read->forNode($node, $versionId);

        if (!$source instanceof NodeDocumentVersion) {
            throw NodeDocumentVersionNotFoundException::forNode($node->getKey(), $versionId);
        }

        return $this->database->transaction(function () use ($node, $source, $actorId): NodeDocumentVersion {
            $version = $this->store->create([
                'node_id' => $node->getKey(),
                'version_no' => $this->store->nextVersionNo($node),
                'content' => $source->content,
                'yjs_state' => $source->yjs_state,
                'yjs_state_size' => $source->yjs_state_size,
                'format' => $source->format,
                'content_hash' => $source->content_hash,
                'snapshot_label' => sprintf('Restored from v%d', $source->version_no),
                'is_autosave' => false,
                'status' => 'committed',
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            $this->nodes->update($node, ['current_version_id' => $version->getKey()]);

            $this->store->prune(
                node: $node,
                max: Config::maxDocumentVersions(),
                headId: $version->getKey(),
            );

            event(new DocumentVersionCreated(
                node: $node,
                version: $version,
                restored: true,
                actorId: $actorId,
            ));

            return $version;
        });
    }
}
```

## packages/documents/src/Collaboration/HocuspocusNodeCollaborationDriver.php

SHA256: `3c3372f79166b243fbf9800c5af38fa6fa5d18020a93d13fbb22b27dfc09acee`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Firebase\JWT\JWT;
use Vaulter\Documents\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationSessionData;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Documents\Exceptions\CollaborationConfigException;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * The optional node-collaboration transport (M4b) targeting an external Node
 * Hocuspocus hub (see hocuspocus-server/). The client connects to the hub URL with a
 * signed room JWT; the hub's onAuthenticate verifies the JWT (or calls the
 * collab/authenticate webhook) and its onStoreDocument debounces a snapshot back into
 * collab/store. Requires firebase/php-jwt to mint the room token.
 *
 * The JWT carries {room, sub, readOnly, iat, exp} signed HS256 with the shared secret:
 *   - room     — the room name (channel_prefix.nodeId), so the token is bound to
 *                exactly one node; a token for node A can never address node B.
 *   - sub      — the connecting host user's id.
 *   - readOnly — encoded from the actor's ability (View/Comment → true, Edit → false);
 *                the hub refuses writes from a read-only connection.
 *   - exp      — now + token_ttl.
 */
final readonly class HocuspocusNodeCollaborationDriver implements NodeCollaborationDriver
{
    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Hocuspocus;
    }

    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSessionData
    {
        if (!class_exists(JWT::class)) {
            throw CollaborationConfigException::missingJwt();
        }

        $secret = Config::collaborationJwtSecret();

        if ($secret === null) {
            throw CollaborationConfigException::missingSecret();
        }

        $room = Config::collaborationRoom($node->getKey());
        $readOnly = $ability !== Ability::Edit;

        $issuedAt = time();
        $token = JWT::encode(
            payload: [
                'room' => $room,
                'sub' => Identity::stringify($actorId),
                'readOnly' => $readOnly,
                'iat' => $issuedAt,
                'exp' => $issuedAt + Config::collaborationTokenTtl(),
            ],
            key: $secret,
            alg: 'HS256',
        );

        return new CollaborationSessionData(
            driver: $this->type(),
            room: $room,
            nodeId: Identity::key($node->getKey()) ?? Identity::stringify($node->getKey()),
            readOnly: $readOnly,
            token: $token,
            url: Config::collaborationHubUrl(),
            connection: null,
        );
    }
}
```

## packages/documents/src/Collaboration/NodeCollaborationDriverManager.php

SHA256: `d7728b152cd4dcb39b969db4b77e27847ba6fe4d5fe3c1326f982ec78b20da08`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Illuminate\Contracts\Container\Container;
use Vaulter\Documents\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Config;

/**
 * Resolves the active node-collaboration transport (M4b) from
 * config('vaulter.collaboration.driver'). The driver class map is keyed by the
 * CollaborationDriverType so adding a transport is one enum case + one binding, no
 * stringly-typed lookup. Distinct from VaulterManager::driver(), which resolves the
 * LEGACY Document-bound collaboration driver — this is the node-tree lane.
 */
final readonly class NodeCollaborationDriverManager
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The node-collaboration driver for the configured (or given) transport.
     */
    public function driver(?string $name = null): NodeCollaborationDriver
    {
        $type = $this->type($name ?? Config::collaborationDriverName());

        $class = match ($type) {
            CollaborationDriverType::Reverb => ReverbNodeCollaborationDriver::class,
            CollaborationDriverType::Hocuspocus => HocuspocusNodeCollaborationDriver::class,
        };

        return $this->container->make($class);
    }

    private function type(string $name): CollaborationDriverType
    {
        $type = CollaborationDriverType::tryFrom($name);

        if ($type === null) {
            throw new VaulterException(
                sprintf('[Vaulter] Unknown collaboration driver [%s]. ', $name)
                .'Fix: set config(vaulter.collaboration.driver) to one of: reverb, hocuspocus. '
                .'Docs: https://github.com/axiomasoft/vaulter/blob/main/docs/guide/errors.md#unknown-collaboration-driver',
            );
        }

        return $type;
    }
}
```

## packages/documents/src/Collaboration/ReverbNodeCollaborationDriver.php

SHA256: `e6ff0a5da8bd062cfc4853ae49cbd9c2efbd5a64a9362a88b1ee39ec7433636d`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Collaboration;

use Vaulter\Documents\Contracts\Collaboration\NodeCollaborationDriver;
use Vaulter\Documents\Data\Collaboration\CollaborationSessionData;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * @deprecated Not recommended for Yjs document collaboration — use the 'hocuspocus'
 *             transport (the Yjs backend TipTap documents and maintains). Reverb is
 *             Laravel's general broadcast bus (Pusher protocol): great for app
 *             notifications/presence/activity, but it relays peer client-events without
 *             server-side content gating, so it can enforce neither read-only on the
 *             socket nor server-debounced persistence the way a Yjs backend does.
 *             Pushing Yjs through it is a custom protocol, not a standard TipTap
 *             integration. Kept only for backward compatibility.
 *
 * The (legacy) node-collaboration-over-Reverb transport (M4b): the Vue client joins the
 * PRIVATE broadcast channel for the room (channel_prefix.nodeId) over the configured
 * Echo connection; Laravel's channel-auth endpoint authorizes the subscription through
 * the AccessResolver-backed broadcast callback. There is no opaque room JWT — the
 * session's `token` IS the private channel name the auth endpoint gates.
 *
 * The room is bound to exactly one node (channel_prefix.nodeId), so a session can only
 * ever address its own node — there is no cross-node access through a Reverb session.
 */
final readonly class ReverbNodeCollaborationDriver implements NodeCollaborationDriver
{
    public function type(): CollaborationDriverType
    {
        return CollaborationDriverType::Reverb;
    }

    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSessionData
    {
        $room = Config::collaborationRoom($node->getKey());

        return new CollaborationSessionData(
            driver: $this->type(),
            room: $room,
            nodeId: Identity::key($node->getKey()) ?? Identity::stringify($node->getKey()),
            readOnly: $ability !== Ability::Edit,
            token: $room,
            url: null,
            connection: Config::reverbConnection(),
        );
    }
}
```

## packages/documents/src/Contracts/Collaboration/HostUserResolver.php

SHA256: `324c1c6d11affbca1d80ee16812474c6a4ec9974403e0e9598c94610696e52ea`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts\Collaboration;

use Illuminate\Contracts\Auth\Access\Authorizable;

/**
 * Materialises a host user (an Authorizable) by its id (master plan §7 / M4b) so the
 * server-to-server collaboration webhook — which carries only a user id, no session —
 * can run the AccessResolver against a real actor. The host owns its users (Vaulter
 * has no users table), so this is a genuine variance seam: the default
 * {@see \Vaulter\Documents\Collaboration\HostUserResolver} defers to the configured auth user-provider,
 * and a host (or a test) may bind its own.
 *
 * Returns null when the id resolves to nothing (the webhook then answers allowed=false
 * rather than failing open).
 */
interface HostUserResolver
{
    public function find(int|string $id): ?Authorizable;
}
```

## packages/documents/src/Contracts/Collaboration/NodeCollaborationDriver.php

SHA256: `2881a68c76c7bc3c3e62cd9a8681fe45e45e63d8af71e61f78c8b2843a73f65e`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts\Collaboration;

use Vaulter\Documents\Data\Collaboration\CollaborationSessionData;
use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;
use Vaulter\Enums\Access\Ability;
use Vaulter\Models\Drive\Node;

/**
 * The node-attached realtime-collaboration transport (master plan §7 / ADR-0001 /
 * M4b). It abstracts how a document NODE (kind=document, its content on
 * v_node_document_versions) is joined for realtime editing: the default Reverb driver
 * keeps everything inside Laravel (private broadcast channel); the Hocuspocus driver
 * targets an external Node hub and mints a signed room JWT.
 *
 * This is the NODE-tree seam (M4b) — DISTINCT from the legacy
 * {@see CollaborationDriver}, which is bound to the document_id-attached Document
 * model (the v_documents path that stays intact). The two coexist; a host selects the
 * same transport for both via config('vaulter.collaboration.driver').
 *
 * The authorization decision (may this actor connect, and at what level) is NOT the
 * driver's job — the controller resolves it through the Vaulter-owned AccessResolver
 * and passes the resulting ability in. The driver only ENCODES that decision into the
 * transport's session descriptor (readOnly = the ability is not Edit).
 */
interface NodeCollaborationDriver
{
    public function type(): CollaborationDriverType;

    /**
     * Issue a collaboration session for a document node at the granted ability level.
     * The ability is the HIGHEST the AccessResolver granted the actor on the node
     * (View/Comment → read-only, Edit → writable); the driver encodes it into the
     * returned descriptor's readOnly flag and (for Hocuspocus) the room JWT claim.
     *
     * @param int|string $actorId the connecting host user's id (the JWT `sub`)
     */
    public function issueSession(Node $node, Ability $ability, int|string $actorId): CollaborationSessionData;
}
```

## packages/documents/src/Contracts/Export/ExportFilenameResolver.php

SHA256: `552960638db97672ae3fc7ecfd9685d414800287608d3a9455a2b34849eeaf20`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Contracts\Export;

use Vaulter\Models\Drive\Node;

/**
 * The export filename scheme for {@see \Vaulter\Documents\Export\
 * NodeExportService::export()} (P4.5 / F-P3.1-04). A genuine variance seam: a host
 * with a different naming convention (e.g. `{name}-v{version}.{ext}`) binds its own
 * implementation over this contract instead of forking `NodeExportService`, which
 * stays `final` (H.5, `ArchTest::the_document_handler_registry_and_its_handlers_
 * are_final` covers it alongside `Vaulter\Documents\Engines\Handlers`).
 *
 * The default {@see DefaultExportFilenameResolver} reproduces the pre-P4.5
 * hardcoded scheme (node basename + exporter extension) unchanged.
 */
interface ExportFilenameResolver
{
    public function resolve(Node $node, string $extension): string;
}
```

## packages/documents/src/Data/Collaboration/CollaborationSessionData.php

SHA256: `220521601dd0b4b63eabcf10a8c391af5c161c8a1b44bfae8d29644778303c88`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Documents\Enums\Collaboration\CollaborationDriverType;

/**
 * The unified realtime-collaboration session descriptor (master plan §7 / ADR-0001 /
 * M4b) a {@see NodeCollaborationDriver} issues for a document node
 * and the collab-token endpoint returns. It is the ONE shape the Vue Yjs binding and
 * the Node hub consume, regardless of transport:
 *
 *   - driver   — the chosen transport (reverb|hocuspocus).
 *   - room     — the room/channel name, ALWAYS channel_prefix.nodeId, so a session is
 *                bound to exactly one node (no cross-node access via a token).
 *   - nodeId   — the document node id the room maps to.
 *   - readOnly — encoded from the actor's ability on the node (View/Comment → true,
 *                Edit → false). The client mounts a read-only editor when true.
 *   - url      — the websocket URL the client connects to (the Hocuspocus hub); null
 *                for Reverb, which connects via Echo over `connection`.
 *   - token    — the room access handle. For Hocuspocus a signed JWT carrying
 *                {room, sub, readOnly, exp}; for Reverb the private channel name the
 *                broadcast auth endpoint gates (Reverb authenticates out-of-band).
 *   - connection — the Laravel broadcast connection name for the Reverb transport;
 *                null for Hocuspocus.
 */
final readonly class CollaborationSessionData
{
    public function __construct(
        public CollaborationDriverType $driver,
        public string $room,
        public int|string $nodeId,
        public bool $readOnly,
        public string $token,
        public ?string $url = null,
        public ?string $connection = null,
    ) {}

    /**
     * The wire shape the collab-token endpoint returns and the Vue client binds
     * against (a stable contract for the Node + Vue edge slices).
     *
     * @return array{
     *     driver: string,
     *     room: string,
     *     node_id: int|string,
     *     read_only: bool,
     *     token: string,
     *     url: string|null,
     *     connection: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'driver' => $this->driver->value,
            'room' => $this->room,
            'node_id' => $this->nodeId,
            'read_only' => $this->readOnly,
            'token' => $this->token,
            'url' => $this->url,
            'connection' => $this->connection,
        ];
    }
}
```

## packages/documents/src/Data/Collaboration/PersistYjsSnapshotData.php

SHA256: `6004e519dcc1912815064b2d67c8cc5e38a609fd956aae57acecaeeb7abb8d14`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Models\Drive\Node;

/**
 * Command for {@see PersistYjsSnapshotAction} (§7 / M4b) —
 * the Node Hocuspocus hub's debounced onStoreDocument snapshot. The Yjs binary CRDT
 * state is the SOURCE OF TRUTH and is always present; the TipTap projection is the
 * derived view the hub may also send (the Action NEVER reconstructs Yjs from JSON).
 *
 *   - node     — the document node the snapshot belongs to (route/webhook resolved).
 *   - yjsState — the RAW DECODED binary Yjs state (bytea), already base64-decoded by
 *                the webhook controller. The source of truth.
 *   - content  — the optional TipTap JSON projection; null leaves the projection as it
 *                was (the Yjs state still advances).
 *   - actorId  — the host user the hub attributes the snapshot to, when known.
 */
final readonly class PersistYjsSnapshotData
{
    public function __construct(
        public Node $node,
        public string $yjsState,
        public ?TipTapContent $content = null,
        public int|string|null $actorId = null,
    ) {}
}
```

## packages/documents/src/Data/Comments/CreateNodeCommentData.php

SHA256: `388726fc737c42fb6c06d1dbf3c356c84ccef0feefd4f711b1fad729abacc35f`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Comments;

/**
 * The input to {@see AddNodeCommentAction} (ADR-0014 шаг 1).
 * authorType is a REGISTERED morph alias ($user->getMorphClass()), never a raw FQCN —
 * the HTTP request layer resolves it.
 */
final readonly class CreateNodeCommentData
{
    /**
     * @param array<string, mixed>|null $anchor
     */
    public function __construct(
        public string $body,
        public string $authorType,
        public int|string $authorId,
        public int|string|null $parentId = null,
        public ?array $anchor = null,
    ) {}
}
```

## packages/documents/src/Data/Documents/CreateDocumentNodeData.php

SHA256: `5279281697f3c67922c1993470a659d6db4eba44ca71b2754b858385486c5157`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Documents\Engines\Handlers\TipTapHandler;
use Vaulter\Documents\Enums\Documents\DocumentType;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;

/**
 * Command for CreateDocumentNodeAction (§2.5 / M4a): create a kind=document node
 * under a folder (or the storage root) with an initial v1 document version from the
 * given content. The content is handler-agnostic ({@see DocumentContent}) — a
 * rich-text TipTap doc OR an opaque snapshot (e.g. a Univer workbook, §7 level 2).
 *
 * mimeType is the value STAMPED on the node so a read re-resolves the same handler:
 * the TipTap synthetic document mime by default, or the format's concrete mime
 * (e.g. the xlsx sheet mime) for a non-rich-text handler. The destination parent /
 * target storage are route-resolved by the controller; the Action receives them
 * resolved.
 */
final readonly class CreateDocumentNodeData
{
    public function __construct(
        public Drive $storage,
        public string $name,
        public DocumentContent $content,
        public ?Node $parent = null,
        public string $format = DocumentType::RichText->value,
        public string $mimeType = TipTapHandler::DOCUMENT_MIME,
        public int|string|null $actorId = null,
    ) {}
}
```

## packages/documents/src/Data/Documents/UpdateDocumentContentData.php

SHA256: `bc96e9cbf657de11e51a000d3fd12d3c922983755790334bbe5e0074fffb7331`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Contracts\Handlers\DocumentContent;
use Vaulter\Models\Drive\Node;

/**
 * Command for UpdateDocumentContentAction (§2.5 / M4a) — the autosave path: write
 * the document node's content. Within the autosave-debounce window the head autosave
 * version is UPDATED in place; outside it a fresh is_autosave version is appended.
 * yjsState carries the optional binary Yjs CRDT update (the M4b realtime source of
 * truth). content is the handler's payload — a TipTap doc for rich-text handlers, or
 * an opaque JSON snapshot for non-rich-text ones (e.g. a spreadsheet workbook).
 */
final readonly class UpdateDocumentContentData
{
    public function __construct(
        public Node $node,
        public DocumentContent $content,
        public ?string $yjsState = null,
        public int|string|null $actorId = null,
    ) {}
}
```

## packages/documents/src/Data/Versions/CreateNamedVersionData.php

SHA256: `6ade3919debf340a0d3f16857bdb77d183a4cb5888b416993cfa72ddce5d1773`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Versions;

use Vaulter\Models\Drive\Node;

/**
 * Command for CreateNamedVersionAction (§2.5 / M4a): snapshot a document node's
 * current head content as a NAMED (non-autosave) version with a label. keepForever
 * pins the snapshot out of the prune window.
 */
final readonly class CreateNamedVersionData
{
    public function __construct(
        public Node $node,
        public string $label,
        public bool $keepForever = false,
        public int|string|null $actorId = null,
    ) {}
}
```

## packages/documents/src/Events/Comments/NodeCommentAdded.php

SHA256: `62472b993d0d2a6f7d5ffdeb2a221c3de7bcfc35e5c056e6a8c3132d6d28ccb3`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * A comment was added to a node (ADR-0014 шаг 1). Broadcast on the node's private
 * collaboration channel (channel_prefix.{node_id}) — the SAME room open editors join
 * (M4b) — so a live thread updates without a poll. Dispatched by the Action AFTER the
 * insert transaction commits.
 */
final readonly class NodeCommentAdded implements ShouldBroadcast
{
    public string $eventId;

    public function __construct(
        public NodeComment $comment,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->comment->node_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'node-comment.added';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
            'parent_id' => $this->comment->parent_id,
            'body' => $this->comment->body,
            'created_at' => $this->comment->created_at,
        ];
    }
}
```

## packages/documents/src/Events/Comments/NodeCommentDeleted.php

SHA256: `7b9f43a699953c79fd20569029437fe30f74e19d8cd69ed2427a0ae3302e77d6`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * A node comment was permanently deleted (ADR-0014 шаг 1 — no soft-delete). Broadcast
 * on the node's private collaboration channel so open editors drop it live. The model
 * still carries its id/node_id in memory after the delete, so the payload is complete.
 */
final readonly class NodeCommentDeleted implements ShouldBroadcast
{
    public string $eventId;

    public function __construct(
        public NodeComment $comment,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->comment->node_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'node-comment.deleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
        ];
    }
}
```

## packages/documents/src/Events/Comments/NodeCommentResolved.php

SHA256: `b348ebe065b8b82a2ed3f2d561315d66e0eb599f06c032914c6c03d1baaac71c`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Comments;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Support\Config;
use Vaulter\Support\Identity;

/**
 * A node comment thread was marked resolved (ADR-0014 шаг 1). Broadcast on the node's
 * private collaboration channel so open editors reflect the resolution live. Carries
 * the actor who resolved it (also stamped as resolved_by on the row).
 */
final readonly class NodeCommentResolved implements ShouldBroadcast
{
    public string $eventId;

    public function __construct(
        public NodeComment $comment,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel(Config::channelPrefix().'.'.Identity::stringify($this->comment->node_id)),
        ];
    }

    public function broadcastAs(): string
    {
        return 'node-comment.resolved';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->comment->getKey(),
            'node_id' => $this->comment->node_id,
            'resolved_at' => $this->comment->resolved_at,
            'resolved_by' => $this->comment->resolved_by,
        ];
    }
}
```

## packages/documents/src/Events/Documents/DocumentNodeCreated.php

SHA256: `ba7120b63ce2d5ef5165d1aa60aa321cb19e77b215d5073cd634824f7715f856`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Documents;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * A kind=document node was created with its initial v1 version (§2.5 / M4a). Carries
 * both the node and the v1 version so a listener/webhook need not re-read either.
 */
final readonly class DocumentNodeCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public NodeDocumentVersion $version,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
```

## packages/documents/src/Events/Versions/DocumentVersionCreated.php

SHA256: `880d9108be60633e6f04bed8bcb6a490d6ecf75ab3d62589325810e622fded60`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Events\Versions;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * A NAMED document version was snapshotted, or a prior version restored to head
 * (§2.5 / M4a). $restored distinguishes the two: false for a fresh named snapshot,
 * true when a restore repointed the head to a copy of a prior version.
 */
final readonly class DocumentVersionCreated implements ShouldDispatchAfterCommit
{
    public string $eventId;

    public function __construct(
        public Node $node,
        public NodeDocumentVersion $version,
        public bool $restored = false,
        public int|string|null $actorId = null,
        ?string $eventId = null,
    ) {
        $this->eventId = $eventId ?? (string) Str::ulid();
    }
}
```

## packages/documents/src/Exceptions/InvalidTipTapContentException.php

SHA256: `ed26fab6bb5b14cadf479e5785a0a45dd02b632e2ec8a2f94737f5054dd691cb`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;

final class InvalidTipTapContentException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'invalid_document_content';

    private const int HTTP_STATUS = 422;

    public static function missingDocNode(): self
    {
        return new self(
            '[Vaulter] Invalid TipTap content. '
            .'Why: the root node must be {"type":"doc", ...}. '
            .'Fix: pass a TipTap JSON document, or use TipTapContent::empty(). '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function tooLarge(float $sizeKb, int $maxKb): self
    {
        $size = number_format($sizeKb, 1);

        return new self(
            sprintf('[Vaulter] Document content is too large (%s KB > %d KB). ', $size, $maxKb)
            .'Why: it exceeds config(vaulter.max_document_size_kb). '
            .'Fix: split the document or raise the limit. '
            .'Docs: '.self::docs('content-too-large'),
        );
    }

    public static function unknownNodeType(string $type): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: unknown node type "%s". ', $type)
            .'Why: the store-time validator only accepts a closed set of node types. '
            .'Fix: use one of the supported TipTap nodes, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function unknownMarkType(string $type): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: unknown mark type "%s". ', $type)
            .'Why: the store-time validator only accepts a closed set of marks. '
            .'Fix: use one of the supported TipTap marks, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function disallowedAttribute(string $nodeType, string $attribute): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: attribute "%s" is not allowed on node/mark "%s". ', $attribute, $nodeType)
            .'Why: the store-time validator rejects attributes outside the closed allow-list per type. '
            .'Fix: remove the attribute, or extend the allow-list deliberately. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public static function disallowedUrlScheme(string $href): self
    {
        return new self(
            sprintf('[Vaulter] Invalid TipTap content: link href "%s" uses a disallowed URL scheme. ', $href)
            .'Why: only http, https and mailto schemes are allowed (F-P1.11-01, stored XSS). '
            .'Fix: use an http(s)/mailto link. '
            .'Docs: '.self::docs('invalid-tiptap-content'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return array<string, mixed>
     */
    protected function renderExtras(): array
    {
        return ['reason' => 'invalid_tiptap'];
    }
}
```

## packages/documents/src/Exceptions/NodeDocumentVersionNotFoundException.php

SHA256: `0c34f659495ccce23f797072ebeb5dcc1590848127bc15d714304ee7f30d88f7`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document version lookup did not resolve a version belonging to the node (§2.5 /
 * M4a) — either it does not exist or it belongs to another node. "Not found" and "no
 * access to another node's version" are deliberately indistinguishable to the caller.
 */
final class NodeDocumentVersionNotFoundException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'document_revision_not_found';

    private const int HTTP_STATUS = 404;

    public static function forNode(mixed $nodeId, mixed $versionId): self
    {
        return new self(
            sprintf(
                '[Vaulter] Document version [%s] was not found on node [%s]. ',
                Identity::stringify($versionId),
                Identity::stringify($nodeId),
            )
            .'Why: the version does not exist or belongs to a different node. '
            .'Fix: list the node history (GET .../versions) and restore an id from it. '
            .'Docs: '.self::docs('document-version-not-found'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
```

## packages/documents/src/Exceptions/NotADocumentNodeException.php

SHA256: `b878aeea8568df0c2ec5aa2968b71252766bca638ae83f763368895f39a377c7`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Exceptions;

use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Support\Identity;

/**
 * A document content/version operation targeted a node that is not kind=document
 * (§2.5 / M4a). A folder/file/shortcut has no v_node_document_versions payload, so
 * the operation is rejected rather than silently writing an orphan version.
 */
final class NotADocumentNodeException extends VaulterException implements ProblemException
{
    private const string PROBLEM_CODE = 'not_a_document';

    private const int HTTP_STATUS = 409;

    public static function forNode(mixed $nodeId): self
    {
        return new self(
            sprintf('[Vaulter] Node [%s] is not a document node. ', Identity::stringify($nodeId))
            .'Why: document content/version operations require a kind=document node '
            .'(its payload lives in v_node_document_versions). '
            .'Fix: create the document via CreateDocumentNodeAction first, or target a document node. '
            .'Docs: '.self::docs('not-a-document-node'),
        );
    }

    public function problemCode(): string
    {
        return self::PROBLEM_CODE;
    }

    public function httpStatus(): int
    {
        return self::HTTP_STATUS;
    }
}
```

## packages/documents/src/Http/Controllers/DocumentNodeController.php

SHA256: `7ce68bda84281649eafc057973cc8e42a86fe57bf0d7bed5d6ff74bf47e7a6fc`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Vaulter\Access\AccessResolver;
use Vaulter\Contracts\Handlers\NodePresenter;
use Vaulter\Documents\Actions\Documents\CreateDocumentNodeAction;
use Vaulter\Documents\Actions\Documents\UpdateDocumentContentAction;
use Vaulter\Documents\Actions\Versions\CreateNamedVersionAction;
use Vaulter\Documents\Actions\Versions\ReleaseKeptVersionAction;
use Vaulter\Documents\Actions\Versions\RestoreDocumentVersionAction;
use Vaulter\Documents\Data\Documents\CreateDocumentNodeData;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentData;
use Vaulter\Documents\Data\Versions\CreateNamedVersionData;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Export\NodeExportService;
use Vaulter\Documents\Http\Requests\CreateDocumentNodeRequest;
use Vaulter\Documents\Http\Requests\CreateNamedVersionRequest;
use Vaulter\Documents\Http\Requests\UpdateDocumentContentRequest;
use Vaulter\Documents\Http\Resources\NodeDocumentVersionResource;
use Vaulter\Documents\Repositories\Versions\NodeDocumentVersionReadRepository;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Http\Resources\Drive\NodeResource;
use Vaulter\Http\Support\TrashGuard;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;

/**
 * The Documents REST surface over the M4a document-node domain (§5 / §7): create a
 * document node, read its content + version history, autosave its content, snapshot
 * a named version, restore a prior version, resolve its handler descriptor and export
 * it. Thin — every write delegates to a final readonly Action, every read to a
 * repository / read-only service; no model queries live here.
 *
 * Authorization (H.2): every method guards the relevant Ability through the
 * AccessResolver before acting (Create on the destination, View to read, Edit to
 * autosave/version/restore); a denial 403s via PermissionDeniedException.
 */
final class DocumentNodeController extends Controller
{
    public function __construct(
        private readonly NodeReadRepository $nodes,
        private readonly NodeDocumentVersionReadRepository $versions,
        private readonly AccessResolver $access,
        private readonly CreateDocumentNodeAction $createDocument,
        private readonly UpdateDocumentContentAction $updateContent,
        private readonly CreateNamedVersionAction $createNamedVersion,
        private readonly RestoreDocumentVersionAction $restoreVersion,
        private readonly ReleaseKeptVersionAction $releaseKeptVersion,
        private readonly NodePresenter $handlers,
        private readonly NodeExportService $exports,
    ) {}

    /**
     * Create a kind=document node under a folder (or storage root) with its v1
     * version.
     */
    public function store(CreateDocumentNodeRequest $request, Drive $storage): JsonResponse
    {
        $parent = $this->resolveParentById($request->parentId(), $storage);

        $this->guardCreate($request, $storage, $parent);

        $node = $this->createDocument->execute(new CreateDocumentNodeData(
            storage: $storage,
            name: $request->name(),
            content: $request->content(),
            parent: $parent,
            format: $request->versionFormat(),
            mimeType: $request->nodeMimeType(),
            actorId: $this->actorId($request),
        ));

        return NodeResource::make($node)->response()->setStatusCode(201);
    }

    /**
     * The document node plus its current head content.
     */
    public function content(Request $request, Node $node): NodeDocumentVersionResource
    {
        $this->guardDocument($request, Ability::View, $node);

        $head = $this->versions->head($node) ?? $this->versions->latest($node);

        if ($head === null) {
            abort(404);
        }

        return NodeDocumentVersionResource::make($head)->withContent();
    }

    /**
     * The version history of a document node, newest first.
     */
    public function versions(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardDocument($request, Ability::View, $node);

        $history = $this->versions
            ->history($node)
            ->cursorPaginate(perPage: $this->perPage($request))
            ->withQueryString();

        return NodeDocumentVersionResource::collection($history);
    }

    /**
     * Autosave the document node's content (the debounce path).
     */
    public function update(UpdateDocumentContentRequest $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $version = $this->updateContent->execute(new UpdateDocumentContentData(
            node: $node,
            content: $request->content(),
            yjsState: $request->yjsState(),
            actorId: $this->actorId($request),
        ));

        // Autosave is semantically an UPDATE (200) even when it appended a fresh
        // version row — the resource's wasRecentlyCreated 201 default is overridden.
        return NodeDocumentVersionResource::make($version)->response()->setStatusCode(200);
    }

    /**
     * Snapshot the current head as a NAMED version.
     */
    public function storeVersion(CreateNamedVersionRequest $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $version = $this->createNamedVersion->execute(new CreateNamedVersionData(
            node: $node,
            label: $request->label(),
            keepForever: $request->keepForever(),
            actorId: $this->actorId($request),
        ));

        return NodeDocumentVersionResource::make($version)->response()->setStatusCode(201);
    }

    /**
     * Restore a prior version: repoint the head to a copy of it.
     */
    public function restore(Request $request, Node $node, int|string $version): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $restored = $this->restoreVersion->execute(
            node: $node,
            versionId: $version,
            actorId: $this->actorId($request),
        );

        return NodeDocumentVersionResource::make($restored)->response()->setStatusCode(201);
    }

    /**
     * Release a version's keep_forever pin (§2.5 / M4a, F-P1.12-03) so it re-enters
     * the prune window on the node's next snapshot/restore. Same guard as setting
     * the pin (storeVersion) — Edit on the node.
     */
    public function releaseVersion(Request $request, Node $node, int|string $version): JsonResponse
    {
        $this->guardDocument($request, Ability::Edit, $node);

        $released = $this->releaseKeptVersion->execute($node, $version);

        return NodeDocumentVersionResource::make($released)->response();
    }

    /**
     * The resolved handler descriptor (id + capabilities + ui) for a node so the
     * client knows which editor/preview/download surface to mount (§4.5).
     */
    public function handler(Request $request, Node $node): JsonResponse
    {
        $this->guard($request, Ability::View, $node);

        return new JsonResponse(['data' => $this->handlers->present($node)->toArray()]);
    }

    /**
     * Export a document node's head content in the requested format (?format=html|
     * markdown|…) through the handler → exporter seam.
     */
    public function export(Request $request, Node $node): JsonResponse
    {
        $this->guardDocument($request, Ability::View, $node);

        $format = $request->string('format')->toString();
        $format = $format !== '' ? $format : 'html';

        return new JsonResponse(['data' => $this->exports->export($node, $format)->toArray()]);
    }

    /**
     * Resolve the destination parent from an already-extracted id (null = root).
     */
    private function resolveParentById(int|string|null $parentId, Drive $storage): ?Node
    {
        if ($parentId === null) {
            return null;
        }

        $node = $this->nodes->find($parentId);

        if (!$node instanceof Node || $node->drive_id !== $storage->getKey()) {
            abort(404);
        }

        return $node;
    }

    private function perPage(Request $request): int
    {
        $perPage = (int) $request->integer('per_page', 50);

        return max(1, min($perPage, 200));
    }

    /**
     * Guard an ability on a node that MUST be a document node — a non-document node
     * 404s before the ability check (a folder/file has no document payload here).
     * Item-safety (P2.2 / F-P1.12-01): a trashed node ALSO 404s before the ability
     * check — trash is orthogonal to the ACL, not encoded into it (mirrors
     * DownloadController::guardDownload on the file-content surface).
     */
    private function guardDocument(Request $request, Ability $ability, Node $node): void
    {
        if ($node->kind !== NodeKind::Document) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        TrashGuard::assertNotTrashed($node);

        $this->guard($request, $ability, $node);
    }

    /**
     * Authorize an ability on a node through the AccessResolver (H.2); a denial (or
     * no actor) 403s.
     */
    private function guard(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    /**
     * Authorize a document CREATE under a destination (§6): the workspace-create gate
     * AND the CREATE bit on the destination node.
     */
    private function guardCreate(Request $request, Drive $storage, ?Node $destination): void
    {
        $actor = $this->authorizable($request);
        $target = $destination ?? $this->driveRoot($storage);

        if ($actor === null
            || !$this->access->canCreateInWorkspace($actor, $storage)
            || !$this->access->can($actor, Ability::Create, $target)
        ) {
            throw PermissionDeniedException::forAbility(Ability::Create->value);
        }
    }

    /**
     * The storage's root folder node (the create destination when no parent given),
     * resolved through the read repository — never a controller model query.
     */
    private function driveRoot(Drive $storage): Node
    {
        $rootId = $storage->root_node_id;

        $root = $rootId === null ? null : $this->nodes->find($rootId);

        if (!$root instanceof Node) {
            abort(404);
        }

        return $root;
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
```

## packages/documents/src/Http/Controllers/NodeCollaborationController.php

SHA256: `e11a158ff666d4325f746dfa37f06b8c0761980d959d1f3c0e7e97e6d28a2167`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Actions\Collaboration\PersistYjsSnapshotAction;
use Vaulter\Documents\Collaboration\CollaborationCall;
use Vaulter\Documents\Collaboration\CollaborationSessionService;
use Vaulter\Documents\Contracts\Collaboration\HostUserResolver;
use Vaulter\Documents\Data\Collaboration\PersistYjsSnapshotData;
use Vaulter\Documents\Exceptions\NotADocumentNodeException;
use Vaulter\Documents\Http\Middleware\ResolveCollaborationCall;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;

/**
 * The M4b realtime-collaboration HTTP surface for document nodes (master plan §7 /
 * ADR-0001). Three thin endpoints:
 *
 *   - token()        — the client-facing connect handshake. Authorizes via the
 *                      CollaborationSessionService (≥ View/Comment to connect,
 *                      read-only unless Edit) and returns the transport-agnostic
 *                      CollaborationSessionData descriptor.
 *   - authenticate() — the server-to-server hook the Node hub's onAuthenticate calls.
 *                      The call is already authenticated by ResolveCollaborationCall;
 *                      this resolves the user's ability on the node and answers
 *                      {allowed, readOnly, user}.
 *   - store()        — the server-to-server hook the hub's onStoreDocument calls. The
 *                      call is authenticated AND bound to the node (a JWT call may only
 *                      store to its own room); the Yjs snapshot is persisted through
 *                      the PersistYjsSnapshotAction.
 *
 * Thin: token authorization lives in the read-only service, persistence in the Action;
 * no model queries here (nodes resolved via the read repository / route binding).
 */
final class NodeCollaborationController extends Controller
{
    public function __construct(
        private readonly NodeReadRepository $nodes,
        private readonly CollaborationSessionService $sessions,
        private readonly AccessResolver $access,
        private readonly HostUserResolver $users,
    ) {}

    /**
     * Issue a realtime collaboration session for the route-bound document node. The
     * service 403s an actor without at least View/Comment and encodes read-only from
     * the actor's ability (Edit → writable).
     */
    public function token(Request $request, Node $node): JsonResponse
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        $session = $this->sessions->issue($actor, $node);

        return new JsonResponse(['data' => $session->toArray()]);
    }

    /**
     * The Node hub's onAuthenticate hook: resolve the user + node from the (already
     * authenticated) call and answer whether the connection is allowed and at what
     * level. allowed=false (rather than a 403) when the user holds no view access, so
     * the hub can refuse the socket cleanly.
     */
    public function authenticate(Request $request): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);
        $node = $this->resolveNode($request, $call);

        $userId = $this->callUserId($request, $call);
        $actor = $userId === null ? null : $this->users->find($userId);

        if ($actor === null) {
            return new JsonResponse(['allowed' => false, 'readOnly' => true, 'user' => null]);
        }

        $ability = $this->grantedAbility($actor, $node);

        if ($ability === null) {
            return new JsonResponse(['allowed' => false, 'readOnly' => true, 'user' => null]);
        }

        return new JsonResponse([
            'allowed' => true,
            'readOnly' => $ability !== Ability::Edit,
            'user' => ['id' => $userId],
        ]);
    }

    /**
     * The Node hub's onStoreDocument hook: persist the debounced Yjs snapshot. The
     * call must be authenticated, bound to the node (a JWT for node A may not store to
     * node B), and not read-only. The Yjs binary is the source of truth; the TipTap
     * JSON, when present, updates the projection.
     */
    public function store(Request $request, PersistYjsSnapshotAction $action): JsonResponse
    {
        $call = ResolveCollaborationCall::call($request);

        if (!$call->mayStore()) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }

        $node = $this->resolveNode($request, $call);
        $yjsState = $this->yjsState($request);

        $version = $action->execute(new PersistYjsSnapshotData(
            node: $node,
            yjsState: $yjsState,
            content: $this->projection($request),
            actorId: $this->callUserId($request, $call),
        ));

        return new JsonResponse([
            'data' => [
                'node_id' => $node->getKey(),
                'version_no' => $version->version_no,
                'yjs_state_size' => $version->yjs_state_size,
            ],
        ]);
    }

    /**
     * Resolve the document node a webhook call targets from the body (room or
     * node_id), then ASSERT the call is allowed to address its room (a JWT call may
     * only act on its own room — node binding). A non-document or unbound node 403/404s.
     */
    private function resolveNode(Request $request, CollaborationCall $call): Node
    {
        $nodeId = $this->nodeId($request);
        $node = $this->nodes->find($nodeId);

        if (!$node instanceof Node) {
            abort(404);
        }

        if (!$node->kind->isDocument()) {
            throw NotADocumentNodeException::forNode($node->getKey());
        }

        $room = Config::collaborationRoom($node->getKey());

        if (!$call->mayAddressRoom($room)) {
            // The presented JWT was minted for a DIFFERENT node's room — refuse,
            // never let a token cross node boundaries.
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        return $node;
    }

    /**
     * The node id a webhook body addresses: the `node_id` field, or the `room` field,
     * each normalised back through the channel prefix (the hub may send either, and it
     * sends node_id EQUAL to the room name — see hocuspocus-server/src/webhook.js).
     */
    private function nodeId(Request $request): int|string
    {
        $nodeId = $request->input('node_id');

        if (is_int($nodeId)) {
            return $nodeId;
        }

        // Хаб шлёт node_id РАВНЫМ имени комнаты (vaulter.documents.<id>), а не голым
        // ключом — нормализуем через префикс канала; для голого id nodeIdFromRoom()
        // возвращает значение как есть (без префикса — no-op).
        if (is_string($nodeId) && $nodeId !== '') {
            return Config::nodeIdFromRoom($nodeId);
        }

        $room = $request->input('room');

        if (is_string($room) && $room !== '') {
            return Config::nodeIdFromRoom($room);
        }

        abort(422, 'A collaboration call must carry node_id or room.');
    }

    /**
     * The raw decoded binary Yjs state from a base64 `yjs_state` body field. The Yjs
     * binary is the source of truth; an absent/empty/invalid state is a 422 (we never
     * reconstruct it from the TipTap JSON).
     */
    private function yjsState(Request $request): string
    {
        $encoded = $request->input('yjs_state');

        if (!is_string($encoded) || $encoded === '') {
            abort(422, 'A collaboration store call must carry a base64 yjs_state.');
        }

        $decoded = base64_decode($encoded, strict: true);

        if ($decoded === false || $decoded === '') {
            abort(422, 'The yjs_state must be valid base64-encoded binary.');
        }

        return $decoded;
    }

    /**
     * The optional TipTap projection from a `content`/`tiptap` body field. Null leaves
     * the prior projection intact (the Yjs state still advances).
     */
    private function projection(Request $request): ?TipTapContent
    {
        $content = $request->input('content', $request->input('tiptap'));

        if (!is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return null;
        }

        /** @var array<string, mixed> $content */
        return TipTapContent::fromArray($content);
    }

    /**
     * The host user id a webhook call is attributed to: the JWT `sub` when the call
     * authenticated by JWT, else a `user_id` body field (a shared-secret call carries
     * the user out-of-band).
     */
    private function callUserId(Request $request, CollaborationCall $call): int|string|null
    {
        if ($call->subject !== null && $call->subject !== '') {
            return $call->subject;
        }

        $userId = $request->input('user_id');

        return is_int($userId) || (is_string($userId) && $userId !== '') ? $userId : null;
    }

    /**
     * The HIGHEST collaboration ability the actor holds on the node (Edit > Comment >
     * View), or null when they hold no view access — the webhook answers allowed=false.
     */
    private function grantedAbility(Authorizable $actor, Node $node): ?Ability
    {
        foreach ([Ability::Edit, Ability::Comment, Ability::View] as $ability) {
            if ($this->access->can($actor, $ability, $node)) {
                return $ability;
            }
        }

        return null;
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
```

## packages/documents/src/Http/Controllers/NodeCommentController.php

SHA256: `e34040272508e6b4a8b425a351a406911dd85c7fd3411df47056ce85cfd18f16`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Controllers;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Access\AccessResolver;
use Vaulter\Documents\Actions\Comments\AddNodeCommentAction;
use Vaulter\Documents\Actions\Comments\DeleteNodeCommentAction;
use Vaulter\Documents\Actions\Comments\ResolveNodeCommentAction;
use Vaulter\Documents\Data\Comments\CreateNodeCommentData;
use Vaulter\Documents\Http\Requests\StoreNodeCommentRequest;
use Vaulter\Documents\Http\Resources\NodeCommentResource;
use Vaulter\Documents\Models\Comments\NodeComment;
use Vaulter\Documents\Repositories\Comments\NodeCommentReadRepository;
use Vaulter\Enums\Access\Ability;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Http\Controllers\Controller;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Identity;

/**
 * The NODE-attached comment HTTP surface (ADR-0014 шаг 1), the additive twin of the
 * legacy {@see CommentController} (document_id-bound, left
 * untouched). Thin: authorization runs through the Vaulter-owned AccessResolver,
 * persistence through the Actions, reads through the repository — no static model
 * queries here (the M1c-http arch rule).
 *
 * Authorization (§2.6 / H.2):
 *   - index   → Ability::View    (anyone who can see the node reads its thread);
 *   - store   → Ability::Comment (the dedicated comment bit);
 *   - resolve → the AUTHOR of the comment OR Ability::Manage on the node;
 *   - destroy → the AUTHOR of the comment OR Ability::Manage on the node.
 */
final class NodeCommentController extends Controller
{
    public function __construct(
        private readonly AccessResolver $access,
        private readonly NodeCommentReadRepository $comments,
    ) {}

    public function index(Request $request, Node $node): AnonymousResourceCollection
    {
        $this->guardAbility($request, Ability::View, $node);

        return NodeCommentResource::collection($this->comments->forNode($node)->get());
    }

    public function store(StoreNodeCommentRequest $request, Node $node, AddNodeCommentAction $action): JsonResponse
    {
        $this->guardAbility($request, Ability::Comment, $node);

        $data = $this->withValidatedParent($request->toData(), $node);

        return NodeCommentResource::make($action->execute($node, $data))
            ->response()
            ->setStatusCode(201);
    }

    public function resolve(
        Request $request,
        Node $node,
        int|string $comment,
        ResolveNodeCommentAction $action,
    ): NodeCommentResource {
        $this->authorOrManageActor($request, $node, $comment);

        $resolved = $action->execute($this->findComment($node, $comment), $this->actorId($request));

        return NodeCommentResource::make($resolved);
    }

    public function destroy(
        Request $request,
        Node $node,
        int|string $comment,
        DeleteNodeCommentAction $action,
    ): Response {
        $this->authorOrManageActor($request, $node, $comment);

        $action->execute($this->findComment($node, $comment), $this->actorId($request));

        return response()->noContent();
    }

    /**
     * A reply must attach to a comment that lives on the SAME node — a cross-node
     * parent_id would thread a reply onto a foreign thread. An unknown/foreign parent
     * is a 404 (the parent is not addressable under this node).
     */
    private function withValidatedParent(CreateNodeCommentData $data, Node $node): CreateNodeCommentData
    {
        if ($data->parentId === null) {
            return $data;
        }

        // findComment throws a 404 when the parent does not belong to this node.
        $this->findComment($node, $data->parentId);

        return $data;
    }

    /**
     * Assert the actor may resolve/delete the comment: they are its author OR hold
     * Manage on the node. An unauthenticated actor, or a non-author without Manage,
     * 403s.
     */
    private function authorOrManageActor(Request $request, Node $node, int|string $commentId): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null) {
            throw PermissionDeniedException::forAbility(Ability::Manage->value);
        }

        $comment = $this->findComment($node, $commentId);

        if ($this->isAuthor($actor, $comment) || $this->access->can($actor, Ability::Manage, $node)) {
            return;
        }

        throw PermissionDeniedException::forAbility(Ability::Manage->value);
    }

    private function findComment(Node $node, int|string $commentId): NodeComment
    {
        $comment = $this->comments->find($commentId);

        if ($comment === null || Identity::stringify($comment->node_id) !== Identity::stringify($node->getKey())) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }

    private function isAuthor(Authorizable $actor, NodeComment $comment): bool
    {
        if (!$actor instanceof Model) {
            return false;
        }

        return $actor->getMorphClass() === $comment->author_type
            && Identity::stringify($actor->getKey()) === Identity::stringify($comment->author_id);
    }

    private function guardAbility(Request $request, Ability $ability, Node $node): void
    {
        $actor = $this->authorizable($request);

        if ($actor === null || !$this->access->can($actor, $ability, $node)) {
            throw PermissionDeniedException::forAbility($ability->value);
        }
    }

    private function authorizable(Request $request): ?Authorizable
    {
        $user = $request->user();

        return $user instanceof Authorizable ? $user : null;
    }
}
```

## packages/documents/src/Http/Resources/NodeDocumentVersionResource.php

SHA256: `54dbfd6fe26a056fcdaf795449b159039358b3df65003c94bf23e7e79c5cdfaa`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;

/**
 * The read shape of a node-attached document version (§2.5 / M4a). Read-only — it
 * transforms an already-loaded NodeDocumentVersion, never queries. The binary
 * yjs_state is NOT serialised (only its size) — it is fetched through the
 * collaboration transport, not the JSON history.
 *
 * @mixin NodeDocumentVersion
 */
final class NodeDocumentVersionResource extends JsonResource
{
    /**
     * Whether to include the full TipTap content (the single-version fetch); the
     * history listing omits it to stay light.
     */
    private bool $withContent = false;

    public function withContent(bool $with = true): self
    {
        $this->withContent = $with;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var NodeDocumentVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->getKey(),
            'node_id' => $version->node_id,
            'version_no' => $version->version_no,
            'format' => $version->format,
            'snapshot_label' => $version->snapshot_label,
            'is_autosave' => $version->is_autosave,
            'keep_forever' => $version->keep_forever,
            'status' => $version->status,
            'content_hash' => $version->content_hash,
            'yjs_state_size' => $version->yjs_state_size,
            'created_by' => $version->created_by,
            'created_at' => $version->created_at,
            'content' => $this->withContent ? $this->readableContent($version) : null,
        ];
    }

    /**
     * The version's content as the CLIENT may safely mount it (VLT-REMED P5.2 / R-12).
     *
     * This resource is the primary read path — GET nodes/{node}/content feeds the editor
     * component directly — and it used to return the RAW persisted row. Both XSS echelons
     * (D4) miss exactly here: the store-time validator never saw the rows written before it
     * existed, and the HTML sanitizer guards only TipTapRenderer::toHtml() (export/preview).
     * So a legacy `link.href="javascript:…"` travelled straight into the host's editor and
     * into the DOM as an <a href>.
     *
     * TipTap content is therefore DECAYED (unknown nodes/marks/attribute keys stripped — the
     * same contract {@see NodeDocumentVersion::tipTapContent()} promises) and its URL
     * attributes are scheme-scrubbed. A non-rich-text handler's opaque snapshot (a Univer
     * workbook, §7 level 2) is NOT a TipTap doc and is still returned verbatim — the shape,
     * not the `format` label, decides, because an autosave row inherits the column default
     * regardless of the handler that wrote it.
     *
     * @return array<string, mixed>|null
     */
    private function readableContent(NodeDocumentVersion $version): ?array
    {
        $content = $version->content;

        if (!is_array($content) || ($content['type'] ?? null) !== 'doc') {
            return $content;
        }

        return $version->tipTapContent()->withSanitizedUrls()->toArray();
    }
}
```

## packages/documents/src/Models/Versions/NodeDocumentVersion.php

SHA256: `ce63294ada61307a4a0d6235b0f0c20d468d8d83d2f356d5142e2247251a60c1`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Models\Versions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Vaulter\Documents\Casts\BinaryString;
use Vaulter\Documents\ValueObjects\OpaqueDocumentContent;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * An append-only snapshot of a DOCUMENT NODE's content (master plan §2.5 / M4a) —
 * the NEW node-attached document-version path. It is DISTINCT from the legacy
 * {@see DocumentVersion} (document_id-attached, the v_documents path that stays
 * intact); this one hangs off a kind=document {@see Node} via node_id and lives in
 * v_node_document_versions.
 *
 * Append-only (no updated_at / no soft-delete): a committed version is immutable;
 * the autosave HEAD is the one exception the Action overwrites in place within the
 * debounce window. version_no is monotonic per node (the unique index is the
 * backbone). The TipTap JSON projection lives in `content`; the binary Yjs CRDT
 * state (the M4b collaboration source of truth) in `yjs_state`.
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $node_id
 * @property int                       $version_no
 * @property array<string, mixed>|null $content
 * @property string|null               $yjs_state
 * @property int|null                  $yjs_state_size
 * @property string                    $format
 * @property string|null               $content_hash
 * @property string|null               $snapshot_label
 * @property bool                      $is_autosave
 * @property string                    $status
 * @property bool                      $keep_forever
 * @property int|string|null           $created_by
 * @property Carbon|null               $created_at
 * @property-read Node $node
 */
class NodeDocumentVersion extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public const UPDATED_AT = null;

    /**
     * Closed allow-list (F-P1.8-02): the version's own document content/label.
     * id/tenant_id/node_id/version_no/yjs_state/yjs_state_size/content_hash/
     * status/created_by are structural/server-computed — every write path that
     * sets them already uses forceFill() (NodeDocumentVersionStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'content',
        'format',
        'snapshot_label',
        'is_autosave',
        'keep_forever',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('node_document_versions');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'node_id');
    }

    /**
     * The TipTap content of this version (the structural JSON projection).
     *
     * READING NEVER THROWS (VLT-REMED D29 / R-01). A snapshot written before today's
     * allow-list existed — or by a host extension since switched off — DECAYS: unknown
     * nodes/marks/attribute keys are stripped and a valid doc comes back. Store-time
     * validation is the strict gate ({@see TipTapContent::fromArray()}); making the read
     * path equally strict meant a single legacy document threw on preview, export and in
     * the listing UI, i.e. an XSS barrier bought at the price of denial of service.
     *
     * Attribute VALUES survive the decay by design: a legacy `href="javascript:…"` reaches
     * the RENDERER, where the render-time HTML sanitizer (second echelon, D4) neutralises
     * it — that is the echelon's only real input.
     *
     * That covers the HTML paths (export/preview) and NOTHING else, so the JSON read path —
     * NodeDocumentVersionResource, which hands the content straight to the editor with no
     * renderer in between — scrubs the URL values itself through
     * {@see TipTapContent::withSanitizedUrls()} (VLT-REMED P5.2 / R-12). A caller that uses
     * tipTapContent() to build its OWN client-facing payload must do the same.
     */
    public function tipTapContent(): TipTapContent
    {
        $content = $this->content;

        return is_array($content) ? TipTapContent::decayFrom($content) : TipTapContent::empty();
    }

    /**
     * This version's content as an opaque, unvalidated snapshot (§7 level 2) — the
     * shape a non-rich-text handler's content round-trips as, e.g. a Univer workbook.
     */
    public function opaqueContent(): OpaqueDocumentContent
    {
        $content = $this->content;

        return OpaqueDocumentContent::fromArray(is_array($content) ? $content : []);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'content' => 'array',
            // Postgres returns bytea as a stream resource; normalise to a string on every
            // driver so consumers (copy/restore actions, collab load) never see a resource.
            'yjs_state' => BinaryString::class,
            'version_no' => 'integer',
            'yjs_state_size' => 'integer',
            'is_autosave' => 'boolean',
            'keep_forever' => 'boolean',
        ];
    }
}
```

## packages/documents/src/Repositories/Versions/NodeDocumentVersionReadRepository.php

SHA256: `0f041f969dd99509cc01fbf244eba730d4cf8a70bb22baa9637892002624afa9`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Versions;

use Illuminate\Database\Eloquent\Builder;
use Vaulter\Documents\DocumentsModels;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * Read-side lookups over the append-only v_node_document_versions (§2.5 / M4a). The
 * head version a document node points at, the latest autosave version (for the
 * debounce window), the named-version listing and the full history — all scoped to
 * the node's own id so a version from another node never resolves.
 *
 * Opens no transactions, makes no access decisions — the Actions wrap writes and the
 * controller authorizes via the AccessResolver.
 */
final class NodeDocumentVersionReadRepository
{
    /**
     * The current head a document node points at (its current_version_id), or null
     * when the node has no committed document version yet.
     */
    public function head(Node $node): ?NodeDocumentVersion
    {
        $headId = $node->current_version_id;

        if ($headId === null) {
            return null;
        }

        return $this->query()
            ->where('node_id', $node->getKey())
            ->whereKey($headId)
            ->first();
    }

    /**
     * The most recent version of a node (highest version_no) — the row a restore
     * targets a prior version relative to, and the autosave-debounce baseline.
     */
    public function latest(Node $node): ?NodeDocumentVersion
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * A specific version of the node's document by the version's primary key,
     * bounded to the node so a foreign version id never resolves.
     */
    public function forNode(Node $node, int|string $versionId): ?NodeDocumentVersion
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->whereKey($versionId)
            ->first();
    }

    /**
     * Every version of a document node, newest version_no first (the history
     * listing). Returns a Builder so the controller paginates it.
     *
     * @return Builder<NodeDocumentVersion>
     */
    public function history(Node $node): Builder
    {
        return $this->query()
            ->where('node_id', $node->getKey())
            ->orderByDesc('version_no');
    }

    /**
     * @return Builder<NodeDocumentVersion>
     */
    private function query(): Builder
    {
        $model = DocumentsModels::documentVersion();

        return $model::query();
    }
}
```

## packages/documents/src/Repositories/Versions/NodeDocumentVersionStoreRepository.php

SHA256: `60fc25b5434a530a7ef35a6473d722030ecfe4b53cf6c23ec99e9f9618644d04`

```php
<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Versions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Vaulter\Documents\DocumentsModels;
use Vaulter\Documents\Models\Versions\NodeDocumentVersion;
use Vaulter\Models\Drive\Node;

/**
 * Write-side row work for the append-only v_node_document_versions (§2.5 / M4a):
 * create a version, update the head autosave in place (the debounce path), and the
 * prune that keeps the history bounded. Opens NO transactions and dispatches NO
 * events — the document-node Actions wrap every call in VaulterDatabase::transaction and emit the
 * domain event.
 *
 * version_no is monotonic per node (the unique (node_id, version_no) index is the
 * backbone); nextVersionNo reads the current max under the caller's transaction so
 * two concurrent commits serialise on the index.
 */
final class NodeDocumentVersionStoreRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): NodeDocumentVersion
    {
        // forceFill (P1.4/F-P1.8-02): $attributes is a server-built literal array
        // from the document-node Actions, never raw request input.
        $model = DocumentsModels::documentVersion();
        $version = new $model;
        $version->forceFill($attributes);
        $version->save();

        return $version->refresh();
    }

    /**
     * Overwrite an existing version's content/yjs in place — the autosave-debounce
     * path (§2.5): a rapid autosave UPDATES the head autosave row rather than
     * appending a new one, so a typing session does not flood the history.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(NodeDocumentVersion $version, array $attributes): NodeDocumentVersion
    {
        $version->forceFill($attributes)->save();

        return $version;
    }

    /**
     * The next monotonic version_no for a node (§2.5) — current max + 1, or 1 for the
     * first version. Read inside the caller's transaction so two concurrent commits
     * serialise on the unique (node_id, version_no) index.
     */
    public function nextVersionNo(Node $node): int
    {
        $model = DocumentsModels::documentVersion();

        $max = $model::query()
            ->where('node_id', $node->getKey())
            ->max('version_no');

        return is_numeric($max) ? ((int) $max) + 1 : 1;
    }

    /**
     * Prune a node's document history down to the $max most recent versions (§2.5),
     * NEVER deleting a keep_forever version nor the current head. Returns the number
     * of pruned rows. $max <= 0 means unlimited (no pruning).
     */
    public function prune(Node $node, int $max, mixed $headId): int
    {
        if ($max <= 0) {
            return 0;
        }

        $model = DocumentsModels::documentVersion();

        // The prunable rows: NOT keep_forever, NOT the current head, newest first.
        /** @var Collection<int, NodeDocumentVersion> $prunable */
        $prunable = $model::query()
            ->where('node_id', $node->getKey())
            ->where('keep_forever', false)
            ->when(
                $headId !== null,
                static fn (Builder $query): Builder => $query->whereKeyNot($headId),
            )
            ->orderByDesc('version_no')
            ->get();

        // Keep the newest $max; everything past the window is pruned. keep_forever
        // rows and the head were already excluded, so they never count against the
        // window and are never deleted.
        /** @var Collection<int, NodeDocumentVersion> $excess */
        $excess = $prunable->slice($max)->values();

        if ($excess->isEmpty()) {
            return 0;
        }

        $model::query()->whereKey($excess->modelKeys())->delete();

        return $excess->count();
    }
}
```
