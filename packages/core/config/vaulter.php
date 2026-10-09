<?php

declare(strict_types=1);

use Vaulter\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Access\Drivers\GatePermissionDriver;
use Vaulter\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Activity\Activity;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Content\ScanBackfillRun;
use Vaulter\Models\Content\ScanEvidenceRecord;
use Vaulter\Models\Content\ScanObligation;
use Vaulter\Models\Content\ScanPolicyState;
use Vaulter\Models\Content\ScanRequest;
use Vaulter\Models\Drive\Drive;
use Vaulter\Models\Drive\File;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Models\Webhooks\Outbox;
use Vaulter\Models\Webhooks\WebhookDelivery;
use Vaulter\Models\Webhooks\WebhookEndpoint;
use Vaulter\Profiles\DefaultProfile;
use Vaulter\Schema\AutoIncrementIds;
use Vaulter\Schema\UlidIds;
use Vaulter\Schema\Uuid7Ids;
use Vaulter\Support\RandomShareTokenGenerator;
use Vaulter\Testing\NullPermissionDriver;
use Vaulter\Webhooks\DefaultOutboundUrlGuard;

/** Publish with the vaulter-config tag. */
return [
    'database' => [
        'connection' => env('VAULTER_DB_CONNECTION'),
        'table_prefix' => env('VAULTER_TABLE_PREFIX', 'v_'),
        // Refuse writes to Vaulter models outside the Vaulter operations (defence in depth, not a sandbox).
        'write_guard' => env('VAULTER_DB_WRITE_GUARD', true),
    ],
    'ids' => [
        'strategy' => env('VAULTER_ID_STRATEGY', 'ulid'),
        'host_keys' => env('VAULTER_HOST_KEYS', 'string'),
        'strategies' => [
            'bigint' => AutoIncrementIds::class,
            'ulid' => UlidIds::class,
            'uuid7' => Uuid7Ids::class,
        ],
    ],
    'tenancy' => ['enabled' => env('VAULTER_TENANT_ENABLED', false), 'resolver' => null],
    'profiles' => ['default' => DefaultProfile::class, 'providers' => []],
    'scan' => [
        'mode' => null, 'required' => null, 'scanner' => null, 'scanner_key' => null,
        'scanner_version' => null, 'policy_version' => null, 'rollout_key' => null,
    ],
    'features' => [
        'grants' => true,
        'activity' => true,
        'webhooks' => true,
        'share_links' => false,
        'public_listing' => false,
    ],
    'drives' => [
        // HasDrives: delete the drives of an owner when the owner is deleted.
        'delete_with_owner' => env('VAULTER_DRIVES_DELETE_WITH_OWNER', false),
    ],
    'access' => [
        'permission_driver' => env('VAULTER_PERMISSION_DRIVER', 'database'),
        'permission_drivers' => [
            'database' => DatabasePermissionDriver::class,
            'gate' => GatePermissionDriver::class,
            'spatie' => SpatiePermissionDriver::class,
            'null' => NullPermissionDriver::class,
        ],
        'gate' => [
            'abilities' => [
                'previewer' => 'vaulter.preview',
                'reader' => 'vaulter.view',
                'commenter' => 'vaulter.comment',
                'writer' => 'vaulter.edit',
                'organizer' => 'vaulter.organize',
                'owner' => 'vaulter.manage',
            ],
            'super_ability' => env('VAULTER_GATE_SUPER_ABILITY', 'vaulter.super'),
            'create_ability' => env('VAULTER_GATE_CREATE_ABILITY', 'vaulter.create'),
        ],
        'spatie' => [
            'permissions' => [
                'previewer' => 'vaulter.preview',
                'reader' => 'vaulter.view',
                'commenter' => 'vaulter.comment',
                'writer' => 'vaulter.edit',
                'organizer' => 'vaulter.organize',
                'owner' => 'vaulter.manage',
            ],
            'super_permission' => env('VAULTER_SPATIE_SUPER_PERMISSION', 'vaulter.super'),
            'create_permission' => env('VAULTER_SPATIE_CREATE_PERMISSION', 'vaulter.create'),
            'teams' => env('VAULTER_SPATIE_TEAMS', false),
            'role_subject_type' => 'role',
            'team_subject_type' => 'team',
        ],
    ],
    'routes' => [
        'prefix' => env('VAULTER_ROUTE_PREFIX', 'api/vaulter'),
        'middleware' => [
            'authenticated' => ['api', 'auth:sanctum'],
            'public' => ['api'],
            'hub' => ['api'],
        ],
        'rate_limits' => [
            'public' => env('VAULTER_RATE_LIMIT_PUBLIC_PER_MINUTE', 60),
            'uploads' => env('VAULTER_RATE_LIMIT_UPLOADS_PER_MINUTE', 120),
        ],
    ],
    'listing' => ['scan_budget' => 1000],
    'sharing' => [
        'token_generator' => RandomShareTokenGenerator::class,
        'url_base' => env('VAULTER_SHARE_URL_BASE', '/share'),
        'prefix_length' => 8,
        'default_ttl_hours' => env('VAULTER_SHARE_DEFAULT_TTL_HOURS', 72),
        'max_ttl_hours' => env('VAULTER_SHARE_MAX_TTL_HOURS', 720),
    ],
    'downloads' => [
        'delivery_mode' => 'strict',
        'direct_ttl_seconds' => 60,
        'inline_mime_types' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ],
    ],
    'webhooks' => [
        'timeout' => 5,
        'max_attempts' => 5,
        'backoff' => [10, 30, 60, 300, 1800],
        'disable_after_failures' => 10,
        'signature_header' => 'X-Vaulter-Signature',
        'timestamp_header' => 'X-Vaulter-Timestamp',
        'event_header' => 'X-Vaulter-Event',
        'retention_days' => 30,
        'retry_claim_seconds' => 60,
        // The outbox relay leases a row for claim_seconds while it materializes
        // deliveries fanout_chunk endpoints at a time (1..1000); a crashed relay's
        // row is picked up again once the lease lapses.
        'relay' => [
            'claim_seconds' => 60,
            'fanout_chunk' => 100,
        ],
        'manage_ability' => env('VAULTER_WEBHOOKS_MANAGE_ABILITY', 'vaulter.webhooks.manage'),
        'delivery' => [
            'url_guard' => DefaultOutboundUrlGuard::class,
            'allowed_schemes' => ['http', 'https'],
            'blocked_ip_ranges' => null,
            'allowed_ip_ranges' => [],
            'connect_timeout' => 2,
            'max_response_bytes' => 65536,
        ],
    ],
    'handlers' => [],
    'observability' => [
        'enabled' => env('VAULTER_OBSERVABILITY_ENABLED', false),
        'recorder' => env('VAULTER_OBSERVABILITY_RECORDER'),
        'tracing' => [
            'enabled' => env('VAULTER_TRACING_ENABLED', false),
            'recorder' => env('VAULTER_TRACING_RECORDER'),
        ],
    ],
    'models' => [
        'drive' => Drive::class,
        'node' => Node::class,
        'file' => File::class,
        'scan_obligation' => ScanObligation::class,
        'scan_request' => ScanRequest::class,
        'scan_evidence' => ScanEvidenceRecord::class,
        'scan_policy_state' => ScanPolicyState::class,
        'scan_backfill_run' => ScanBackfillRun::class,
        'file_revision' => FileRevision::class,
        'blob' => Blob::class,
        'file_revision_blob' => FileRevisionBlob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,
        'activity' => Activity::class,
        'outbox' => Outbox::class,
        'webhook_endpoint' => WebhookEndpoint::class,
        'webhook_delivery' => WebhookDelivery::class,
    ],
];
