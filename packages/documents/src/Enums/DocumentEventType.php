<?php

declare(strict_types=1);

namespace Vaulter\Documents\Enums;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\EventSpec;
use Vaulter\Attributes\Label;
use Vaulter\Events\Concerns\DescribesEvent;
use Vaulter\Events\EventTypeContract;

/**
 * The event types of the documents module, contributed to the event catalogue by the documents
 * manifest. The values are the stored identities shared with the drive's activity feed and change journal.
 */
#[Api]
enum DocumentEventType: string implements EventTypeContract
{
    use DescribesEvent;

    #[Label('Document created')]
    #[EventSpec(
        subject: 'node',
        data: ['type' => 'string', 'revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A document was created.',
    )]
    case DocumentCreated = 'document.created';

    #[Label('Document updated')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id?' => 'string', 'checkpoint' => 'bool', 'state_version' => 'int', 'epoch' => 'int'],
        activity: false,
        external: true,
        journal: 'upsert',
        description: 'The content of a document was saved; webhooks receive only saves that took a checkpoint.',
    )]
    case DocumentUpdated = 'document.updated';

    #[Label('Document revision created')]
    #[EventSpec(
        subject: 'node',
        data: ['revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int', 'restored' => 'bool'],
        activity: true,
        external: true,
        journal: null,
        description: 'A named or restored document revision was created.',
    )]
    case DocumentRevisionCreated = 'document.revision_created';

    #[Label('Document restored')]
    #[EventSpec(
        subject: 'node',
        data: ['from_revision_id' => 'string', 'revision_id' => 'string', 'state_version' => 'int', 'epoch' => 'int'],
        activity: true,
        external: true,
        journal: 'upsert',
        description: 'A document was restored from an earlier revision.',
    )]
    case DocumentRestored = 'document.restored';

    #[Label('Comment added')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'A comment was added.',
    )]
    case CommentAdded = 'comment.added';

    #[Label('Comment resolved')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'A comment was resolved.',
    )]
    case CommentResolved = 'comment.resolved';

    #[Label('Comment deleted')]
    #[EventSpec(
        subject: 'comment',
        data: ['comment_id' => 'string', 'node_id' => 'string'],
        activity: true,
        external: true,
        journal: null,
        description: 'A comment was deleted.',
    )]
    case CommentDeleted = 'comment.deleted';
}
