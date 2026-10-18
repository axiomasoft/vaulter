<?php

declare(strict_types=1);

namespace Vaulter\Collaboration;

use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\Contracts\HostUserResolver;
use Vaulter\Collaboration\Exceptions\CollaborationEpochMismatchException;
use Vaulter\Collaboration\Exceptions\CollaborationSeedConflictException;
use Vaulter\Collaboration\Exceptions\CollaborationUnavailableException;
use Vaulter\Collaboration\Persistence\AppendResult;
use Vaulter\Collaboration\Persistence\UpdateEnvelope;
use Vaulter\Collaboration\Persistence\UpdateLog;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\Types\DocumentTypeRegistry;
use Vaulter\Drive\Access\AccessResolver;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\DriveStatus;
use Vaulter\Drive\Exceptions\Access\PermissionDeniedException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Repositories\NodeReadRepository;
use Vaulter\Support\Identity;

/**
 * The server side of the hub's protocol 2 calls on behalf of a room connection (authenticate, load, append). They
 * carry no user session — a room JWT and a body signature (ResolveCollaborationCall) — so they cannot go through
 * DocumentOperations; instead every call re-reads what the token was issued for: the node still exists, is not in the
 * trash, belongs to the token's drive and tenant, the drive is active (archived: read-only), the room's epoch is the
 * document's current one, and the token subject's rights now. A revoked Edit therefore takes effect on the hub's next
 * call.
 */
#[Internal]
final readonly class CollaborationHub
{
    public function __construct(
        private NodeReadRepository $nodes,
        private DocumentReadRepository $documents,
        private ContentDecoder $decoder,
        private DocumentTypeRegistry $types,
        private AccessResolver $access,
        private HostUserResolver $users,
        private UpdateLog $log,
    ) {}

    /**
     * The live room a call addresses.
     *
     * @throws PermissionDeniedException           the body names another room than the token
     * @throws NodeNotFoundException               gone, trashed, moved to another drive/tenant, the drive is not usable,
     *                                             or the subject cannot see a document whose generation moved
     * @throws CollaborationEpochMismatchException the document moved to another generation (a subject with View)
     * @throws CollaborationUnavailableException   the type cannot collaborate
     */
    public function open(CollaborationCall $call, mixed $room): HubRoom
    {
        if (!is_string($room) || $room !== $call->room) {
            throw PermissionDeniedException::forAbility(Ability::View->value);
        }

        $ref = $call->ref;
        $node = $this->nodes->find($ref->nodeId);
        if (!$node instanceof Node
            || Identity::stringify($node->getRawOriginal('drive_id')) !== $ref->driveId
            || $node->trashed_at !== null
            || $this->tenant($node) !== $call->tenant) {
            throw NodeNotFoundException::forId($ref->nodeId);
        }
        if (!$node->kind->isDocument()) {
            throw NotADocumentException::forNode($node->getKey());
        }

        $driveStatus = $node->storage()->value('status');
        if ($driveStatus !== DriveStatus::Active && $driveStatus !== DriveStatus::Archived) {
            throw NodeNotFoundException::forId($ref->nodeId);
        }

        $document = $this->documents->forNode($node) ?? throw NotADocumentException::forNode($node->getKey());
        $type = $this->types->find($document->type);
        $ability = $this->ability($call->subject, $node);
        $unsupported = $type !== null && !$type instanceof SupportsCollaboration;
        // A subject without View learns neither the document's generation nor its type: what would tell them apart
        // from a missing document answers as one. Without View and nothing to tell, the call goes on and each
        // endpoint answers for itself (authenticate: allowed=false; load and append: 404).
        if ($ability === null && ($document->collab_epoch !== $ref->epoch || $unsupported)) {
            throw NodeNotFoundException::forId($ref->nodeId);
        }
        if ($document->collab_epoch !== $ref->epoch) {
            throw new CollaborationEpochMismatchException(expected: $ref->epoch, actual: $document->collab_epoch);
        }
        if ($type !== null && $unsupported) {
            throw CollaborationUnavailableException::unsupportedType($type->key());
        }

        // The same rule as DocumentOperations::collaborationSession(), re-evaluated now:
        // a token issued read-only stays read-only, and a writable one only writes while
        // every condition still holds.
        $writable = $call->mode === 'rw'
            && $ability === Ability::Edit
            && $driveStatus === DriveStatus::Active
            && $type !== null
            && $call->schemaVersion === $type->schemaVersion()
            && $this->decoder->editable($document);

        return new HubRoom(
            node: $node,
            document: $document,
            type: $type,
            ability: $ability,
            writable: $writable,
            driveActive: $driveStatus === DriveStatus::Active,
            userId: $call->subject,
        );
    }

    /**
     * Protocol 2: append one update of the room's generation to the document's log. Only a writable room appends; a
     * subject who cannot see the document gets the same 404 as a document that does not exist. The seed (an update
     * with a seed state version) is appended only as the first record of a generation without checkpoint.
     *
     * @throws NodeNotFoundException               the subject cannot see the document (or it is gone)
     * @throws PermissionDeniedException           the room may not write now
     * @throws CollaborationEpochMismatchException the update is of another generation than the room's or the document's
     * @throws CollaborationSeedConflictException  a seed over a log that is no longer empty, or over newer JSON
     */
    public function append(HubRoom $room, CollaborationCall $call, UpdateEnvelope $update): AppendResult
    {
        $this->assertVisible($room, $call);
        if (!$room->writable) {
            throw PermissionDeniedException::forAbility(Ability::Edit->value);
        }
        if ($update->generation !== $call->ref->epoch) {
            throw new CollaborationEpochMismatchException(expected: $update->generation, actual: $room->document->collab_epoch);
        }

        return $this->log->append($call->ref->nodeId, $update);
    }

    /**
     * Protocol 2 load: the checkpoint and the committed tail (C, H] the hub replays, read together under the document
     * and head locks. Before the first checkpoint there is no binary state: the log's first record is the hub's seed,
     * and while the generation has no record yet, the JSON projection, read through the type, is what the hub builds the
     * seed from (appended with `seed_state_version` = this `state_version`).
     *
     * @return array{generation: int, schema_version: int, type: string, state_version: int, checkpoint: string|null, checkpoint_seq: int, head_seq: int, content: array<string, mixed>|null, tail: list<array{seq: int, update_id: string, payload: string}>}
     */
    public function loadLog(HubRoom $room, CollaborationCall $call): array
    {
        $this->assertVisible($room, $call);
        $log = $this->log->snapshot($call->ref->nodeId, $call->ref->epoch);

        // Everything below comes from the locked snapshot, not from the room open() read before the locks: a write
        // between the two would pair one moment's content with another's state version.
        return [
            'generation' => $log->generation,
            'schema_version' => $room->type?->schemaVersion() ?? $log->schemaVersion,
            'type' => $log->type,
            'state_version' => $log->stateVersion,
            'checkpoint' => $log->checkpoint === null ? null : base64_encode($log->checkpoint),
            'checkpoint_seq' => $log->checkpointSeq,
            'head_seq' => $log->headSeq,
            'content' => $log->checkpoint === null
                ? $this->decoder->read($log->type, $log->content, $log->schemaVersion)->content->toArray()
                : null,
            'tail' => $log->tail,
        ];
    }

    /** A subject without View learns nothing a missing document would not tell (protocol 2 calls). */
    private function assertVisible(HubRoom $room, CollaborationCall $call): void
    {
        if ($room->ability === null) {
            throw NodeNotFoundException::forId($call->ref->nodeId);
        }
    }

    /** The highest collaboration ability the token subject holds now, or null without View. */
    private function ability(string $userId, Node $node): ?Ability
    {
        $actor = $this->users->find($userId);
        if ($actor === null) {
            return null;
        }

        foreach ([Ability::Edit, Ability::Comment, Ability::View] as $ability) {
            if ($this->access->can($actor, $ability, $node)) {
                return $ability;
            }
        }

        return null;
    }

    private function tenant(Node $node): ?string
    {
        $tenant = $node->getRawOriginal('tenant_id');

        return $tenant === null ? null : Identity::stringify($tenant);
    }
}
