<?php

declare(strict_types=1);

use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;

return [
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
        'uploads_sweep' => 'hourlyAt:15',
        'trash_purge' => 'dailyAt:03:30',
        'drives_purge' => 'hourlyAt:25',
        'revisions_prune' => 'dailyAt:03:40',
        'scan_reconcile' => 'everyFiveMinutes',
        // Report-only drift check; off until an operator wants it.
        'drives_recount' => null,
    ],
    'models' => [
        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
    ],
];
