<?php

declare(strict_types=1);

// S volume profile (authoritative characterisation tier).
// Source: reference-env.md §2.1/§2.2 (v_nodes/v_blobs/v_upload_sessions/v_outbox/
// v_webhook_endpoints/v_webhook_deliveries/v_node_grants/v_node_shares/
// v_node_comments/v_node_document_versions/v_activity — S column) + §4.1 numbers this
// profile shares with S0 by construction (load/env/S.env carries the matching four).
return [
    'tier' => 'S',

    'nodes' => [
        'total' => 100_000,
        'typical_depth' => 8,
        'typical_width' => 20,
        'backbone_depth' => 64, // DEFAULT_MAX_DEPTH — fixed across every tier.
        'wide_node_children' => 5_000,
    ],

    'blobs' => [
        'orphan' => 8_000, // split mark/sweep — see S0.php comment, same rule.
        'hot_set' => 20_000,
        'hot_set_avg_bytes' => 1_048_576, // ≈1 MiB avg => ≈20 GiB hot-set.
    ],

    'file_versions' => [
        'extra_version_share' => 0.5, // §2.1 "≈1.5-2 версии на файл".
    ],

    'upload_sessions' => [
        'expired' => 20_000,
        'healthy' => 30_000,
        'multipart_share' => 0.5,
        'avg_parts_per_multipart_session' => 4,
    ],

    'outbox' => [
        'backlog' => 10_000,
    ],

    'webhook_endpoints' => [
        'active' => 20,
    ],

    'webhook_deliveries' => [
        'due' => 5_000,
    ],

    'node_grants' => [
        'many_grant_anchors' => 20,
    ],

    'node_shares' => [
        'active' => 1_000,
        'revoked_share' => 0.1,
    ],

    'node_comments' => [
        'total' => 20_000,
    ],

    'node_document_versions' => [
        'nodes' => 5_000,
        'versions_per_node' => 2,
    ],

    'activity' => [
        'total' => 2_000_000,
        'sustained_write_rps' => 20,
    ],

    'max_fixture_bytes' => 5_368_709_120, // load/env/S.env MAX_FIXTURE_BYTES (5 GiB).
];
