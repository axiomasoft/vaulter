<?php

declare(strict_types=1);

namespace Vaulter\Enums\Events;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The audit verb vocabulary written to v_activity (master plan §2.9 /
 * M2c-activity). Each domain event maps to EXACTLY one verb; the verb is the
 * (verb, created_at desc) feed-filter axis. Dotted, namespaced values
 * (node.created, file.uploaded, …) keep the feed legible and stable for hosts.
 *
 * The map event → verb is owned by the ActivityRecorder; this enum is the closed
 * set of values it may write.
 */
enum EventType: string
{
    use HasLabel;

    #[Label('Storage created')]
    case DriveCreated = 'storage.created';

    #[Label('Node created')]
    case FolderCreated = 'node.created';

    #[Label('File uploaded')]
    case FileUploaded = 'file.uploaded';

    #[Label('Version created')]
    case FileRevisionAdded = 'version.created';

    #[Label('Upload session initiated')]
    case UploadInitiated = 'upload_session.initiated';

    #[Label('Upload session aborted')]
    case UploadAborted = 'upload_session.aborted';

    #[Label('Upload session expired')]
    case UploadExpired = 'upload_session.expired';

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
    case NodePurged = 'node.force_deleted';

    #[Label('Grant created')]
    case GrantCreated = 'grant.created';

    #[Label('Grant revoked')]
    case GrantRevoked = 'grant.revoked';

    #[Label('Share created')]
    case ShareCreated = 'share.created';

    #[Label('Share revoked')]
    case ShareRevoked = 'share.revoked';

    /** @return list<self> */
    public static function webhookTypes(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $type): bool => !in_array(
            $type,
            [self::UploadInitiated, self::UploadAborted, self::UploadExpired],
            true,
        )));
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::webhookTypes());
    }
}
