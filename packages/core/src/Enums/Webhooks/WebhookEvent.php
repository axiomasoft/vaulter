<?php

declare(strict_types=1);

namespace Vaulter\Enums\Webhooks;

/**
 * The Vaulter domain events that fan out to webhook endpoints (master plan §2.11 /
 * D14 / M3b). The backing value is the WIRE name carried in the outbox row and the
 * delivery payload, and matched against an endpoint's subscription list.
 *
 * The values are 1:1 with ActivityVerb (node.*, file.*, version.*, grant.*,
 * share.*, storage.*), so the audit feed and the webhook fan-out speak the SAME
 * vocabulary — a host that subscribes to 'node.moved' gets exactly what the activity
 * feed recorded under that verb.
 */
enum WebhookEvent: string
{
    case StorageCreated = 'storage.created';
    case NodeCreated = 'node.created';
    case FileUploaded = 'file.uploaded';
    case VersionCreated = 'version.created';
    case NodeMoved = 'node.moved';
    case NodeRenamed = 'node.renamed';
    case NodeCopied = 'node.copied';
    case NodeTrashed = 'node.trashed';
    case NodeRestored = 'node.restored';
    case NodeForceDeleted = 'node.force_deleted';
    case GrantCreated = 'grant.created';
    case GrantRevoked = 'grant.revoked';
    case ShareCreated = 'share.created';
    case ShareRevoked = 'share.revoked';

    /**
     * Every subscribable event name — the closed set an endpoint may subscribe to.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $event): string => $event->value, self::cases());
    }
}
