<?php

declare(strict_types=1);

namespace Vaulter\Enums\Events;

use ReflectionEnumUnitCase;
use Vaulter\Attributes\EventSpec;
use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The closed catalogue of Vaulter event types: the activity verb, the outbox event
 * name and the webhook "type" are the same value. Dotted, namespaced values
 * (folder.created, file.uploaded, …) keep the feed legible and stable for hosts.
 *
 * Each case carries an {@see EventSpec}: the single source of which sink records the
 * type, the shape of its webhook data and the generated events reference. A disabled
 * feature still turns its whole sink off.
 */
enum EventType: string
{
    use HasLabel;

    #[Label('Storage created')]
    #[EventSpec(
        subject: 'drive',
        data: ['root_node_id' => 'string', 'profile' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A drive was created together with its root node.',
    )]
    case DriveCreated = 'drive.created';

    #[Label('Node created')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A folder was created.',
    )]
    case FolderCreated = 'folder.created';

    #[Label('File uploaded')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null', 'revision_id' => 'string', 'size' => 'int'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A file was created with its first revision.',
    )]
    case FileUploaded = 'file.uploaded';

    #[Label('Version created')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'number' => 'int', 'size' => 'int'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A new revision became the current content of the file.',
    )]
    case FileRevisionAdded = 'file.revision_added';

    #[Label('Upload session initiated')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        webhook: false,
        journal: null,
        description: 'A resumable upload session was opened (audited only).',
    )]
    case UploadInitiated = 'upload.initiated';

    #[Label('Upload session aborted')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        webhook: false,
        journal: null,
        description: 'An upload session was aborted (audited only).',
    )]
    case UploadAborted = 'upload.aborted';

    #[Label('Upload session expired')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        webhook: false,
        journal: null,
        description: 'An upload session expired and was reclaimed (audited only).',
    )]
    case UploadExpired = 'upload.expired';

    #[Label('Node moved')]
    #[EventSpec(
        subject: 'node',
        data: ['from_parent_id' => 'string|null', 'to_parent_id' => 'string|null'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A node moved to another parent.',
    )]
    case NodeMoved = 'node.moved';

    #[Label('Node renamed')]
    #[EventSpec(
        subject: 'node',
        data: [],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A node was renamed; the names stay in the activity context.',
    )]
    case NodeRenamed = 'node.renamed';

    #[Label('Node copied')]
    #[EventSpec(
        subject: 'node',
        data: ['source_id' => 'string', 'destination_id' => 'string|null'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A node was copied; the subject is the copy.',
    )]
    case NodeCopied = 'node.copied';

    #[Label('Node properties updated')]
    #[EventSpec(
        subject: 'node',
        data: ['changed' => 'list<string>'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'The properties of a node changed.',
    )]
    case NodeUpdated = 'node.updated';

    #[Label('Node trashed')]
    #[EventSpec(
        subject: 'node',
        data: [],
        activity: true,
        webhook: true,
        journal: 'remove',
        description: 'A node and its subtree were moved to the trash.',
    )]
    case NodeTrashed = 'node.trashed';

    #[Label('Node restored')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A trashed node was restored.',
    )]
    case NodeRestored = 'node.restored';

    #[Label('Node permanently deleted')]
    #[EventSpec(
        subject: 'drive',
        data: ['node_ids?' => 'list<string>', 'node_count' => 'int', 'freed_bytes' => 'int'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'Trashed nodes were permanently deleted; node_ids is omitted above 1000 nodes.',
    )]
    case NodePurged = 'node.purged';

    #[Label('Grant created')]
    #[EventSpec(
        subject: 'node',
        data: ['grant_id' => 'string', 'subject' => 'object', 'role' => 'string', 'role_version' => 'int', 'scope' => 'string'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A role was granted on a node.',
    )]
    case GrantCreated = 'grant.created';

    #[Label('Grant revoked')]
    #[EventSpec(
        subject: 'node',
        data: ['subject' => 'object', 'revoked' => 'int'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'The grants of a subject on a node were revoked.',
    )]
    case GrantRevoked = 'grant.revoked';

    #[Label('Inheritance changed')]
    #[EventSpec(
        subject: 'node',
        data: ['inherits' => 'bool'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A node started or stopped inheriting grants from above.',
    )]
    case InheritanceChanged = 'access.inheritance_changed';

    #[Label('Share created')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string', 'role' => 'string', 'role_version' => 'int', 'audience' => 'string', 'scope' => 'string', 'expires_at' => 'string|null'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A share link was created.',
    )]
    case ShareCreated = 'share.created';

    #[Label('Share updated')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string', 'changed' => 'list<string>'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A live share link: audience, expiry, budget or password changed.',
    )]
    case ShareUpdated = 'share.updated';

    #[Label('Share rotated')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'The token of a share link was replaced.',
    )]
    case ShareRotated = 'share.rotated';

    #[Label('Share revoked')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A share link was revoked.',
    )]
    case ShareRevoked = 'share.revoked';

    #[Label('Drive archived')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A drive became read-only.',
    )]
    case DriveArchived = 'drive.archived';

    #[Label('Drive unarchived')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        webhook: true,
        journal: null,
        description: 'An archived drive became writable again.',
    )]
    case DriveUnarchived = 'drive.unarchived';

    #[Label('Drive deleted')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A drive was deleted.',
    )]
    case DriveDeleted = 'drive.deleted';

    #[Label('File revision deleted')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'number' => 'int'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A non-current revision was deleted.',
    )]
    case FileRevisionDeleted = 'file.revision_deleted';

    #[Label('Document created')]
    #[EventSpec(
        subject: 'node',
        data: ['type' => 'string', 'revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A document was created.',
    )]
    case DocumentCreated = 'document.created';

    #[Label('Document updated')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id?' => 'string', 'checkpoint' => 'bool', 'state_version' => 'int', 'epoch' => 'int'],
        activity: false,
        webhook: true,
        journal: 'upsert',
        description: 'The content of a document was saved; webhooks receive only saves that took a checkpoint.',
    )]
    case DocumentUpdated = 'document.updated';

    #[Label('Document revision created')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int', 'restored' => 'bool'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A named or restored document revision was created.',
    )]
    case DocumentRevisionCreated = 'document.revision_created';

    #[Label('Document restored')]
    #[EventSpec(
        subject: 'node',
        data: ['from_revision_id' => 'string', 'revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int'],
        activity: true,
        webhook: true,
        journal: 'upsert',
        description: 'A document was restored from an earlier revision.',
    )]
    case DocumentRestored = 'document.restored';

    #[Label('Comment added')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A comment was added.',
    )]
    case CommentAdded = 'comment.added';

    #[Label('Comment resolved')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A comment was resolved.',
    )]
    case CommentResolved = 'comment.resolved';

    #[Label('Comment deleted')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        webhook: true,
        journal: null,
        description: 'A comment was deleted.',
    )]
    case CommentDeleted = 'comment.deleted';

    public function spec(): EventSpec
    {
        /** @var array<string, EventSpec> $specs */
        static $specs = [];

        return $specs[$this->value] ??= (new ReflectionEnumUnitCase(self::class, $this->name))
            ->getAttributes(EventSpec::class)[0]
            ->newInstance();
    }

    /**
     * Types written to the activity feed. Every content save of a document would
     * flood it, so document.updated is left out.
     *
     * @return list<self>
     */
    public static function activityTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isActivity()));
    }

    /**
     * Types a webhook endpoint may subscribe to. Upload sessions are an internal
     * lifecycle and are only audited.
     *
     * @return list<self>
     */
    public static function webhookTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type->isWebhook()));
    }

    public function isActivity(): bool
    {
        return $this->spec()->activity;
    }

    public function isWebhook(): bool
    {
        return $this->spec()->webhook;
    }

    /** The change-journal operation this type produces: 'upsert', 'remove' or null. */
    public function journalOperation(): ?string
    {
        return $this->spec()->journal;
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::webhookTypes());
    }
}
