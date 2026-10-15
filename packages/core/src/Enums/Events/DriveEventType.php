<?php

declare(strict_types=1);

namespace Vaulter\Enums\Events;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\EventSpec;
use Vaulter\Attributes\Label;
use Vaulter\Events\Concerns\DescribesEvent;
use Vaulter\Events\EventTypeContract;

/**
 * The event types of the drive module (D38), contributed to the event catalogue by the drive manifest.
 * Located in core until P4 moves it to the drive package. Dotted, namespaced values (folder.created,
 * file.uploaded, …) are the stored identities: the activity verb, the journal type and the outbox type.
 */
#[Api]
enum DriveEventType: string implements EventTypeContract
{
    use DescribesEvent;

    #[Label('Storage created')]
    #[EventSpec(
        subject: 'drive',
        data: ['root_node_id' => 'string', 'profile' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'A drive was created together with its root node.',
    )]
    case DriveCreated = 'drive.created';

    #[Label('Node created')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A folder was created.',
    )]
    case FolderCreated = 'folder.created';

    #[Label('File uploaded')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null', 'revision_id' => 'string', 'size' => 'int'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A file was created with its first revision.',
    )]
    case FileUploaded = 'file.uploaded';

    #[Label('Version created')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'number' => 'int', 'size' => 'int'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A new revision became the current content of the file.',
    )]
    case FileRevisionAdded = 'file.revision_added';

    #[Label('Upload session initiated')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        external: false,
        journal: null,
        description: 'A resumable upload session was opened (audited only).',
    )]
    case UploadInitiated = 'upload.initiated';

    #[Label('Upload session aborted')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        external: false,
        journal: null,
        description: 'An upload session was aborted (audited only).',
    )]
    case UploadAborted = 'upload.aborted';

    #[Label('Upload session expired')]
    #[EventSpec(
        subject: 'upload',
        data: ['upload_id' => 'string', 'size' => 'int'],
        activity: true,
        external: false,
        journal: null,
        description: 'An upload session expired and was reclaimed (audited only).',
    )]
    case UploadExpired = 'upload.expired';

    #[Label('Node moved')]
    #[EventSpec(
        subject: 'node',
        data: ['from_parent_id' => 'string|null', 'to_parent_id' => 'string|null'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A node moved to another parent.',
    )]
    case NodeMoved = 'node.moved';

    #[Label('Node renamed')]
    #[EventSpec(
        subject: 'node',
        data: [],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A node was renamed; the names stay in the activity context.',
    )]
    case NodeRenamed = 'node.renamed';

    #[Label('Node copied')]
    #[EventSpec(
        subject: 'node',
        data: ['source_id' => 'string', 'destination_id' => 'string|null'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A node was copied; the subject is the copy.',
    )]
    case NodeCopied = 'node.copied';

    #[Label('Node properties updated')]
    #[EventSpec(
        subject: 'node',
        data: ['changed' => 'list<string>'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'The properties of a node changed.',
    )]
    case NodeUpdated = 'node.updated';

    #[Label('Node trashed')]
    #[EventSpec(
        subject: 'node',
        data: [],
        activity: true,
        external: true,
        journal: 'remove',
        description: 'A node and its subtree were moved to the trash.',
    )]
    case NodeTrashed = 'node.trashed';

    #[Label('Node restored')]
    #[EventSpec(
        subject: 'node',
        data: ['parent_id' => 'string|null'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A trashed node was restored.',
    )]
    case NodeRestored = 'node.restored';

    #[Label('Node permanently deleted')]
    #[EventSpec(
        subject: 'drive',
        data: ['node_ids?' => 'list<string>', 'node_count' => 'int', 'freed_bytes' => 'int'],
        activity: true,
        external: true,
        journal: null,
        description: 'Trashed nodes were permanently deleted; node_ids is omitted above 1000 nodes.',
    )]
    case NodePurged = 'node.purged';

    #[Label('Grant created')]
    #[EventSpec(
        subject: 'node',
        data: ['grant_id' => 'string', 'subject' => 'object', 'role' => 'string', 'role_version' => 'int', 'scope' => 'string'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A role was granted on a node.',
    )]
    case GrantCreated = 'grant.created';

    #[Label('Grant revoked')]
    #[EventSpec(
        subject: 'node',
        data: ['subject' => 'object', 'revoked' => 'int'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'The grants of a subject on a node were revoked.',
    )]
    case GrantRevoked = 'grant.revoked';

    #[Label('Inheritance changed')]
    #[EventSpec(
        subject: 'node',
        data: ['inherits' => 'bool'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A node started or stopped inheriting grants from above.',
    )]
    case InheritanceChanged = 'access.inheritance_changed';

    #[Label('Share created')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string', 'role' => 'string', 'role_version' => 'int', 'audience' => 'string', 'scope' => 'string', 'expires_at' => 'string|null'],
        activity: true,
        external: true,
        journal: null,
        description: 'A share link was created.',
    )]
    case ShareCreated = 'share.created';

    #[Label('Share updated')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string', 'changed' => 'list<string>'],
        activity: true,
        external: true,
        journal: null,
        description: 'A live share link: audience, expiry, budget or password changed.',
    )]
    case ShareUpdated = 'share.updated';

    #[Label('Share rotated')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'The token of a share link was replaced.',
    )]
    case ShareRotated = 'share.rotated';

    #[Label('Share revoked')]
    #[EventSpec(
        subject: 'share',
        data: ['node_id' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'A share link was revoked.',
    )]
    case ShareRevoked = 'share.revoked';

    #[Label('Drive archived')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        external: true,
        journal: null,
        description: 'A drive became read-only.',
    )]
    case DriveArchived = 'drive.archived';

    #[Label('Drive unarchived')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        external: true,
        journal: null,
        description: 'An archived drive became writable again.',
    )]
    case DriveUnarchived = 'drive.unarchived';

    #[Label('Drive deleted')]
    #[EventSpec(
        subject: 'drive',
        data: [],
        activity: true,
        external: true,
        journal: null,
        description: 'A drive was deleted.',
    )]
    case DriveDeleted = 'drive.deleted';

    #[Label('File revision deleted')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'number' => 'int'],
        activity: true,
        external: true,
        journal: null,
        description: 'A non-current revision was deleted.',
    )]
    case FileRevisionDeleted = 'file.revision_deleted';
}
