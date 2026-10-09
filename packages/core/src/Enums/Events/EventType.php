<?php

declare(strict_types=1);

namespace Vaulter\Enums\Events;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The closed catalogue of Vaulter event types: the activity verb, the outbox event
 * name and the webhook "type" are the same value. Dotted, namespaced values
 * (folder.created, file.uploaded, …) keep the feed legible and stable for hosts.
 *
 * activityTypes() and webhookTypes() say which sink records a type; a disabled
 * feature still turns its whole sink off.
 */
enum EventType: string
{
    use HasLabel;

    #[Label('Storage created')]
    case DriveCreated = 'drive.created';

    #[Label('Node created')]
    case FolderCreated = 'folder.created';

    #[Label('File uploaded')]
    case FileUploaded = 'file.uploaded';

    #[Label('Version created')]
    case FileRevisionAdded = 'file.revision_added';

    #[Label('Upload session initiated')]
    case UploadInitiated = 'upload.initiated';

    #[Label('Upload session aborted')]
    case UploadAborted = 'upload.aborted';

    #[Label('Upload session expired')]
    case UploadExpired = 'upload.expired';

    #[Label('Node moved')]
    case NodeMoved = 'node.moved';

    #[Label('Node renamed')]
    case NodeRenamed = 'node.renamed';

    #[Label('Node copied')]
    case NodeCopied = 'node.copied';

    #[Label('Node trashed')]
    case NodeTrashed = 'node.trashed';

    #[Label('Node restored')]
    case NodeRestored = 'node.restored';

    #[Label('Node permanently deleted')]
    case NodePurged = 'node.purged';

    #[Label('Grant created')]
    case GrantCreated = 'grant.created';

    #[Label('Grant revoked')]
    case GrantRevoked = 'grant.revoked';

    #[Label('Share created')]
    case ShareCreated = 'share.created';

    #[Label('Share revoked')]
    case ShareRevoked = 'share.revoked';

    #[Label('Drive archived')]
    case DriveArchived = 'drive.archived';

    #[Label('Drive unarchived')]
    case DriveUnarchived = 'drive.unarchived';

    #[Label('Drive deleted')]
    case DriveDeleted = 'drive.deleted';

    #[Label('File revision deleted')]
    case FileRevisionDeleted = 'file.revision_deleted';

    #[Label('Document created')]
    case DocumentCreated = 'document.created';

    #[Label('Document updated')]
    case DocumentUpdated = 'document.updated';

    #[Label('Document revision created')]
    case DocumentRevisionCreated = 'document.revision_created';

    #[Label('Document restored')]
    case DocumentRestored = 'document.restored';

    #[Label('Comment added')]
    case CommentAdded = 'comment.added';

    #[Label('Comment resolved')]
    case CommentResolved = 'comment.resolved';

    #[Label('Comment deleted')]
    case CommentDeleted = 'comment.deleted';

    /**
     * Types written to the activity feed. Every content save of a document would
     * flood it, so document.updated is left out.
     *
     * @return list<self>
     */
    public static function activityTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => $type !== self::DocumentUpdated));
    }

    /**
     * Types a webhook endpoint may subscribe to. Upload sessions are an internal
     * lifecycle and are only audited.
     *
     * @return list<self>
     */
    public static function webhookTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => !in_array(
            $type,
            [self::UploadInitiated, self::UploadAborted, self::UploadExpired],
            true,
        )));
    }

    public function isActivity(): bool
    {
        return in_array($this, self::activityTypes(), true);
    }

    public function isWebhook(): bool
    {
        return in_array($this, self::webhookTypes(), true);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::webhookTypes());
    }
}
