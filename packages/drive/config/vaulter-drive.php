<?php

declare(strict_types=1);

use Vaulter\Drive\Access\Drivers\DatabasePermissionDriver;
use Vaulter\Drive\Access\Drivers\GatePermissionDriver;
use Vaulter\Drive\Access\Drivers\SpatiePermissionDriver;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Models\Activity\Activity;
use Vaulter\Drive\Models\Content\ScanBackfillRun;
use Vaulter\Drive\Models\Content\ScanEvidenceRecord;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Content\ScanPolicyState;
use Vaulter\Drive\Models\Content\ScanRequest;
use Vaulter\Drive\Models\Drive;
use Vaulter\Drive\Models\File;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\FileRevisionBlob;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Models\Sharing\NodeShare;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Profiles\DefaultProfile;
use Vaulter\Drive\Support\RandomShareTokenGenerator;
use Vaulter\Drive\Testing\NullPermissionDriver;

return [
    'profiles' => ['default' => DefaultProfile::class, 'providers' => []],
    'scan' => [
        'mode' => null, 'required' => null, 'scanner' => null, 'scanner_key' => null,
        'scanner_version' => null, 'policy_version' => null, 'rollout_key' => null,
    ],
    'features' => [
        'grants' => true,
        'activity' => true,
        // The drive change journal behind DriveHandle::changes() (PLAN2.D50).
        'changes' => true,
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
    'changes' => [
        // Days a journal entry stays readable; vaulter:changes:prune deletes older ones
        // and a cursor behind them expires (410 cursor_expired).
        'retention_days' => 30,
        // The largest page DriveHandle::changes()->list() returns.
        'max_page_size' => 1000,
    ],
    'handlers' => [],
    'uploads' => [
        'scan' => [
            'required' => null, 'mode' => null, 'scanner' => null, 'scanner_key' => null,
            'scanner_version' => null, 'policy_version' => null, 'rollout_key' => null,
            'queue' => 'default', 'lease_seconds' => 120, 'scanner_timeout_seconds' => 60,
            'max_attempts' => 5, 'publish_cooldown_seconds' => 30, 'recovery_batch' => 100, 'cleanup_batch' => 100,
        ],
        'max_file_size' => env('VAULTER_UPLOAD_MAX_BYTES', 5_368_709_120),
        'allowed_mime_types' => [],
        'resumable' => [
            'enabled' => env('VAULTER_UPLOAD_TUS_ENABLED', true),
            'chunk_size' => env('VAULTER_UPLOAD_CHUNK_SIZE', 8_388_608),
            'max_chunk_size' => env('VAULTER_UPLOAD_MAX_CHUNK_BYTES', 67_108_864),
            'session_ttl_hours' => env('VAULTER_UPLOAD_SESSION_TTL_HOURS', 24),
            // Finite monotonic budget of one completion request, DB retries included (1..300 s).
            'complete_timeout_seconds' => env('VAULTER_UPLOAD_COMPLETE_TIMEOUT_SECONDS', 60),
            'max_active_sessions_per_actor' => env('VAULTER_UPLOAD_MAX_ACTIVE_SESSIONS', 20),
            'metadata' => [
                'allowed_keys' => ['filename', 'filetype', 'name', 'mime_type'],
                'max_value_length' => 512,
            ],
        ],
    ],
    'trash' => ['retention_days' => 30],
    // Same value grammar and vaulter.schedule.enabled switch as the core schedule keys.
    'schedule' => [
        'changes_prune' => 'dailyAt:03:20',
        'uploads_sweep' => 'hourlyAt:15',
        'trash_purge' => 'dailyAt:03:30',
        'drives_purge' => 'hourlyAt:25',
        'revisions_prune' => 'dailyAt:03:40',
        'scan_reconcile' => 'everyFiveMinutes',
        // Report-only drift check; off until an operator wants it.
        'drives_recount' => null,
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
        'file_revision_blob' => FileRevisionBlob::class,
        'grant' => NodeGrant::class,
        'node_share' => NodeShare::class,
        'activity' => Activity::class,
        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
    ],
];
