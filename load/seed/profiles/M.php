<?php

declare(strict_types=1);

// VLT-LOAD P2.3 — M volume profile ("основной тир характеризации", §3), но на ЭТОМ
// lab-box'е гоняется БЕЗ cpuset (D25/open-questions.md Q4 — латентность недостоверна,
// класс A only). Source: reference-env.md §2.1/§2.2, M column.
return [
    'tier' => 'M',

    'nodes' => [
        'total' => 2_000_000,
        'typical_depth' => 16,
        'typical_width' => 50,
        'backbone_depth' => 64, // DEFAULT_MAX_DEPTH — fixed across every tier.
        'wide_node_children' => 20_000,
    ],

    'blobs' => [
        'orphan' => 150_000, // split mark/sweep — see S0.php comment, same rule.
        'hot_set' => 100_000,
        'hot_set_avg_bytes' => 838_860, // ≈800 KiB avg => ≈80 GiB hot-set (§2.2).
    ],

    'file_versions' => [
        'extra_version_share' => 0.6, // §2.1 "≈1.5-2 версии на файл".
    ],

    'upload_sessions' => [
        'expired' => 200_000,
        'healthy' => 300_000,
        'multipart_share' => 0.5,
        'avg_parts_per_multipart_session' => 4,
    ],

    'outbox' => [
        'backlog' => 200_000,
    ],

    'webhook_endpoints' => [
        'active' => 200,
    ],

    'webhook_deliveries' => [
        'due' => 50_000,
    ],

    'node_grants' => [
        'many_grant_anchors' => 200,
    ],

    'node_shares' => [
        'active' => 20_000,
        'revoked_share' => 0.1,
    ],

    'node_comments' => [
        'total' => 500_000,
    ],

    'node_document_versions' => [
        'nodes' => 100_000,
        'versions_per_node' => 2,
    ],

    'activity' => [
        'total' => 50_000_000,
        'sustained_write_rps' => 200,
    ],

    'max_fixture_bytes' => 5_368_709_120, // load/env/M.env MAX_FIXTURE_BYTES (5 GiB).
];
