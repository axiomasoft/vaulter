<?php

declare(strict_types=1);

// VLT-LOAD P2.3 — L volume profile. SPEC ONLY, no lab-box to run it on (D25,
// reference-env.md §3: "24 ядра, NVMe ≥ 500 ГиБ — или экстраполяция"). SeedLoadDatasetCommand
// refuses `--tier=L` outright (Scope Excluded, P2.3) — this file exists so the numbers
// are versioned and ready the day hardware shows up, not invented then. Source:
// reference-env.md §2.1/§2.2, L column.
return [
    'tier' => 'L',
    'run' => false,

    'nodes' => [
        'total' => 20_000_000,
        'typical_depth' => 32,
        'typical_width' => 100,
        'backbone_depth' => 64,
        'wide_node_children' => 100_000,
    ],

    'blobs' => [
        'orphan' => 1_500_000,
        'hot_set' => 300_000,
        'hot_set_avg_bytes' => 894_784, // ≈250 GiB / 300 000 objects (§2.2).
    ],

    'file_versions' => [
        'extra_version_share' => 0.8,
    ],

    'upload_sessions' => [
        'expired' => 1_000_000,
        'healthy' => 1_000_000,
        'multipart_share' => 0.5,
        'avg_parts_per_multipart_session' => 4,
    ],

    'outbox' => [
        'backlog' => 2_000_000,
    ],

    'webhook_endpoints' => [
        'active' => 1_000,
    ],

    'webhook_deliveries' => [
        'due' => 500_000,
    ],

    'node_grants' => [
        'many_grant_anchors' => 1_000,
    ],

    'node_shares' => [
        'active' => 200_000,
        'revoked_share' => 0.1,
    ],

    'node_comments' => [
        'total' => 5_000_000,
    ],

    'node_document_versions' => [
        'nodes' => 1_000_000,
        'versions_per_node' => 2,
    ],

    'activity' => [
        'total' => 500_000_000,
        'sustained_write_rps' => 1_000,
    ],

    'max_fixture_bytes' => 5_368_709_120,
];
