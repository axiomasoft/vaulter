<?php

declare(strict_types=1);

use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;

return [
    'uploads' => [
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
    'names' => ['max_length' => 255],
    'trash' => ['retention_days' => 30],
    'models' => [
        'upload_session' => UploadSession::class,
        'upload_part' => UploadPart::class,
    ],
];
