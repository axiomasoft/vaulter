<?php

declare(strict_types=1);

return [
    'cross_drive_operation' => [
        'title' => 'Cross-drive operation',
        'detail' => 'The operation cannot cross drives.',
    ],
    'node_move_cycle' => [
        'title' => 'Move would create a cycle',
        'detail' => 'A folder cannot be moved into itself or its descendants.',
    ],
    'upload_conflict' => [
        'title' => 'Upload conflict',
        'detail' => 'The upload session is being modified by another request.',
    ],
    'upload_not_found' => [
        'title' => 'Upload not found',
        'detail' => 'The upload session does not exist or is not visible to you.',
    ],
    'upload_offset_mismatch' => [
        'title' => 'Upload offset mismatch',
        'detail' => 'Upload-Offset differs from the server offset; resume from the offset in the response.',
    ],
    'upload_rejected' => [
        'title' => 'Upload rejected',
        'detail' => 'The uploaded content was rejected.',
    ],
];
